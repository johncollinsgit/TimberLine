<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Monthly plans only: these targets are not historical actuals or verified funding capacity. */
class MonthlyBudgetService
{
    private const PURPOSES = ['spending', 'income', 'debt_payment', 'owner_support', 'savings'];

    public function build(Space $space, Collection $records, CarbonImmutable $month, bool $includeIncomePolicies = true): array
    {
        $records = $records->filter(fn ($record) => $record instanceof Record && $record->kind === 'budget' && $record->active && (int) $record->space_id === (int) $space->id);
        $projection = app(BudgetDefaultsService::class)->project($space, $records, $month);
        $records = $projection['records'];
        $superseded = $records->filter(fn ($record) => $record instanceof Record && $record->kind === 'budget' && $record->active
            && (int) $record->space_id === (int) $space->id && (! empty($record->data['supersedes_record_id']) || ! empty($record->data['supersedes_record_ids']))
            && $record->data['effective_on'] <= $month->endOfMonth()->toDateString() && ($record->data['ends_on'] ?? '9999') >= $month->toDateString())
            ->flatMap(fn ($record) => [...($record->data['supersedes_record_ids'] ?? []), ...(isset($record->data['supersedes_record_id']) ? [$record->data['supersedes_record_id']] : [])])->unique()->all();
        $records = $records->reject(fn ($record) => $record instanceof Record && in_array($record->id, $superseded, true));
        $policyIds = [];
        $policyPlans = [];
        if ($includeIncomePolicies && $space->kind === 'household') {
            $policies = Record::where('space_id', $space->id)->where('kind', 'income_percentage_budget')->where('active', true)->get()
                ->filter(fn ($record) => ($record->data['effective_on'] ?? '9999') <= $month->endOfMonth()->toDateString())
                ->groupBy(fn ($record) => ($record->data['program_record_id'] ?? 0).'|'.$record->data['category']);
            foreach ($policies as $key => $group) {
                $latest = $group->max(fn ($record) => $record->data['effective_on']);
                $active = $group->filter(fn ($record) => $record->data['effective_on'] === $latest
                    && (empty($record->data['ends_on']) || $record->data['ends_on'] >= $month->toDateString()));
                if ($active->isEmpty()) {
                    continue;
                }
                $same = fn ($record) => ($record->data['purpose'] ?? null) === 'spending' && (($record->data['program_record_id'] ?? 0).'|'.($record->data['category'] ?? '')) === $key;
                $fixed = $records->filter(fn ($record) => $same($record) && $this->validDate($record->data['effective_on'] ?? null)
                    && $record->data['effective_on'] <= $month->endOfMonth()->toDateString());
                $latestFixed = $fixed->max(fn ($record) => $record->data['effective_on']);
                $hasReviewedCurrent = empty($projection['defaults'][$key]) && $fixed->contains(fn ($record) => $record->data['effective_on'] === $latestFixed
                    && ($record->data['reviewed'] ?? false) === true && is_int($record->data['monthly_cents'] ?? null) && $record->data['monthly_cents'] >= 0
                    && (empty($record->data['ends_on']) || ($this->validDate($record->data['ends_on']) && $record->data['ends_on'] >= $month->toDateString())));
                if ($hasReviewedCurrent) {
                    continue;
                }
                $records = $records->reject($same);
                $policyIds = [...$policyIds, ...$active->pluck('id')->all()];
                $policy = $active->count() === 1 ? $active->first() : null;
                $policyPlans[] = ['key' => $key, 'category' => $active->first()->data['category'],
                    'program_id' => $active->first()->data['program_record_id'] ?? null,
                    'rate_bps' => $policy?->data['rate_bps'], 'basis' => 'household_take_home',
                    'reviewed' => $policy && ($policy->data['reviewed'] ?? false) === true,
                    'record_ids' => $active->pluck('id')->all()];
            }
        }
        $start = $month->startOfMonth()->toDateString();
        $end = $month->endOfMonth()->toDateString();
        $groups = [];
        $missing = [];
        $purposeMissing = array_fill_keys(self::PURPOSES, []);
        foreach ($records as $record) {
            if (! $record instanceof Record || $record->space_id !== $space->id || $record->kind !== 'budget' || ! $record->active) {
                continue;
            }
            $data = $record->data ?? [];
            $purpose = $data['purpose'] ?? null;
            $category = $data['category'] ?? null;
            if (! in_array($purpose, self::PURPOSES, true) || ! is_string($category) || trim($category) === '') {
                continue;
            }
            if (! $this->validDate($data['effective_on'] ?? null)) {
                $issue = $this->label($purpose, $category).' needs a valid effective date.';
                $missing[] = $issue;
                $purposeMissing[$purpose][] = $issue;

                continue;
            }
            if ($data['effective_on'] > $end) {
                continue;
            }
            if (($data['month_only'] ?? false) && ($data['ends_on'] ?? '') < $start) {
                continue;
            }
            $key = $purpose.'|'.($data['program_record_id'] ?? 0).'|'.$category;
            $groups[$key][] = ['record' => $record, 'data' => $data];
        }

        $selected = [];
        $expiredIds = [];
        foreach ($groups as $rows) {
            $latest = max(array_column(array_column($rows, 'data'), 'effective_on'));
            // Select the latest cohort before expiry checks. An expired replacement
            // must not bring an older holiday budget back into a later month.
            foreach ($rows as $row) {
                $record = $row['record'];
                $data = $row['data'];
                if ($data['effective_on'] !== $latest) {
                    continue;
                }
                $purpose = $data['purpose'];
                $label = $this->label($purpose, $data['category']);
                $endsOn = filled($data['ends_on'] ?? null) ? $data['ends_on'] : null;
                $issue = null;
                if (($purpose === 'owner_support' && $space->kind !== 'business')
                    || ! is_int($data['monthly_cents'] ?? null) || $data['monthly_cents'] < 0) {
                    $issue = $label.' needs a valid monthly target for this space.';
                } elseif ($endsOn !== null && (! $this->validDate($endsOn) || $endsOn < $data['effective_on'])) {
                    $issue = $label.' needs a valid end date.';
                } elseif ($endsOn !== null && $endsOn < $start) {
                    $issue = $label.' expired before '.$start.'; no older target was substituted.';
                    $expiredIds[] = $record->id;
                }
                if ($issue !== null) {
                    $missing[] = $issue;
                    $purposeMissing[$purpose][] = $issue;

                    continue;
                }
                if ($data['effective_on'] > $start || ($endsOn !== null && $endsOn < $end)) {
                    $issue = $label.' covers only part of '.$month->format('F Y').'; its monthly target was not prorated.';
                    $missing[] = $issue;
                    $purposeMissing[$purpose][] = $issue;
                }
                $selected[] = ['id' => $record->id, 'purpose' => $purpose, 'category' => $data['category'], 'monthly_cents' => $data['monthly_cents'],
                    'program_record_id' => $data['program_record_id'] ?? null,
                    'effective_on' => $data['effective_on'], 'ends_on' => $endsOn, 'reviewed' => ($data['reviewed'] ?? false) === true];
            }
        }

        $selected = collect($selected);
        $purposes = [];
        foreach (self::PURPOSES as $purpose) {
            $rows = $selected->where('purpose', $purpose);
            $reviewed = $rows->where('reviewed', true);
            $overlapIds = $this->payrollOverlapIds($rows, $start, $end);
            if ($overlapIds) {
                $issue = 'Payroll and role-specific targets overlap for '.str_replace('_', ' ', $purpose).' in '.$month->format('F Y').'. Choose one Payroll total or separate role targets.';
                $missing[] = $issue;
                $purposeMissing[$purpose][] = $issue;
            }
            $purposes[$purpose] = [
                'amount_cents' => $overlapIds || ($purpose === 'spending' && $policyIds) ? null : $this->total($rows),
                'reviewed_cents' => $overlapIds || ($purpose === 'spending' && $policyIds) ? null : $this->total($reviewed),
                'status' => $purpose === 'spending' && $policyIds ? 'income_percentage' : $this->status($rows, $purposeMissing[$purpose]),
                'record_ids' => $rows->pluck('id')->values()->all(),
                'overlapping_record_ids' => $overlapIds,
            ];
        }
        $spending = $selected->where('purpose', 'spending');

        return [
            'month' => $month->format('Y-m'), 'from' => $start, 'through' => $end,
            'defaults' => $projection['defaults'],
            'income_percentage_policy_ids' => $policyIds,
            'income_percentage_policies' => $policyPlans,
            'fixed_spending_cents' => $purposes['spending']['overlapping_record_ids'] ? null : $this->total($spending),
            'reviewed_fixed_spending_cents' => $purposes['spending']['overlapping_record_ids'] ? null : $this->total($spending->where('reviewed', true)),
            'fixed_spending_includes_drafts' => $spending->contains('reviewed', false),
            'reviewed_spending_cents' => $purposes['spending']['reviewed_cents'],
            'draft_spending_cents' => $spending->contains('reviewed', false) ? $purposes['spending']['amount_cents'] : null,
            'planned_income_cents' => $purposes['income']['amount_cents'],
            'debt_budget_cents' => $purposes['debt_payment']['amount_cents'],
            'owner_support_cents' => $purposes['owner_support']['amount_cents'],
            'savings_cents' => $purposes['savings']['amount_cents'],
            'status' => $policyIds ? 'income_percentage' : $this->status($selected, $missing),
            'record_ids' => $selected->pluck('id')->values()->all(),
            'expired_record_ids' => array_values(array_filter($expiredIds)),
            'missing' => array_values(array_unique($missing)),
            'purposes' => $purposes,
            'basis' => 'Monthly budget targets only. Reviewed plans are not historical spending or verified income, debt terms, or business funding capacity.',
        ];
    }

    private function payrollOverlapIds(Collection $rows, string $start, string $end): array
    {
        $ids = [];
        foreach ($rows->where('category', 'payroll') as $parent) {
            foreach ($rows->whereIn('category', ['owner_payroll', 'studio_payroll', 'event_payroll']) as $role) {
                if ((int) ($parent['program_record_id'] ?? 0) !== (int) ($role['program_record_id'] ?? 0)) {
                    continue;
                }
                if (max($start, $parent['effective_on'], $role['effective_on']) <= min($end, $parent['ends_on'] ?? $end, $role['ends_on'] ?? $end)) {
                    $ids[] = $parent['id'];
                    $ids[] = $role['id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function total(Collection $rows): ?int
    {
        return $rows->isEmpty() ? null : (int) $rows->sum('monthly_cents');
    }

    private function status(Collection $rows, array $missing): string
    {
        if ($rows->isEmpty()) {
            return 'unavailable';
        }
        if ($rows->contains('reviewed', false)) {
            return 'draft';
        }

        return $missing ? 'partial' : 'reviewed_plan';
    }

    private function label(string $purpose, string $category): string
    {
        return ucfirst(str_replace('_', ' ', $purpose)).' / '.str_replace('_', ' ', $category);
    }

    private function validDate(mixed $date): bool
    {
        if (! is_string($date) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
            return false;
        }

        return (int) $parts[1] >= 1900 && (int) $parts[1] < 2200 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
