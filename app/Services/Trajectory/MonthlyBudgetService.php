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

    public function build(Space $space, Collection $records, CarbonImmutable $month): array
    {
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
            $key = $purpose.'|'.$category;
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
                'amount_cents' => $overlapIds ? null : $this->total($rows),
                'reviewed_cents' => $overlapIds ? null : $this->total($reviewed),
                'status' => $this->status($rows, $purposeMissing[$purpose]),
                'record_ids' => $rows->pluck('id')->values()->all(),
                'overlapping_record_ids' => $overlapIds,
            ];
        }
        $spending = $selected->where('purpose', 'spending');

        return [
            'month' => $month->format('Y-m'), 'from' => $start, 'through' => $end,
            'reviewed_spending_cents' => $purposes['spending']['reviewed_cents'],
            'draft_spending_cents' => $spending->contains('reviewed', false) ? $purposes['spending']['amount_cents'] : null,
            'planned_income_cents' => $purposes['income']['amount_cents'],
            'debt_budget_cents' => $purposes['debt_payment']['amount_cents'],
            'owner_support_cents' => $purposes['owner_support']['amount_cents'],
            'savings_cents' => $purposes['savings']['amount_cents'],
            'status' => $this->status($selected, $missing),
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
