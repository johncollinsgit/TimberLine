<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Read-only monthly defaults. Expired partial plans and explicit stops never revive. */
class BudgetDefaultsService
{
    public function contextHash(Space $space, Collection $records, string $category, ?int $program): string
    {
        $versions = $records->filter(fn ($record) => $this->ownedBudget($space, $record) && ($record->data['purpose'] ?? null) === 'spending'
            && ($record->data['category'] ?? null) === $category && (int) ($record->data['program_record_id'] ?? 0) === (int) $program)
            ->sortBy('id')->map(fn ($record) => [$record->id, $record->version])->values()->all();

        return hash('sha256', json_encode($versions));
    }

    public function project(Space $space, Collection $records, CarbonImmutable $month): array
    {
        $end = $month->endOfMonth()->toDateString();
        $start = $month->startOfMonth()->toDateString();
        $defaults = [];
        $projected = $records->map(fn ($record) => is_object($record) ? clone $record : $record);
        $groups = $records->filter(fn ($record) => $this->ownedBudget($space, $record) && ($record->data['purpose'] ?? null) === 'spending'
            && is_string($record->data['category'] ?? null) && trim($record->data['category']) !== ''
            && $this->validDate($record->data['effective_on'] ?? null) && $record->data['effective_on'] <= $end
            && (! ($record->data['month_only'] ?? false) || ($record->data['ends_on'] ?? '0000') >= $start))
            ->groupBy(fn ($record) => ($record->data['program_record_id'] ?? 0).'|'.$record->data['category']);
        $funds = null;
        $catalog = null;
        foreach ($groups as $key => $group) {
            $latest = $group->max(fn ($record) => $record->data['effective_on']);
            $cohort = $group->filter(fn ($record) => $record->data['effective_on'] === $latest);
            // Keep malformed legacy evidence untouched for MonthlyBudgetService's
            // named validation reason; it must never crash or become a default.
            if ($cohort->contains(fn ($record) => ! empty($record->data['ends_on'])
                && (! $this->validDate($record->data['ends_on']) || $record->data['ends_on'] < $record->data['effective_on']))) {
                continue;
            }
            // Choose the newest cohort before considering expiry or eligibility.
            if ($cohort->contains(fn ($record) => empty($record->data['ends_on']) || $record->data['ends_on'] >= $start)) {
                continue;
            }
            $funds ??= Record::where('space_id', $space->id)->where('kind', 'goal')->where('active', true)
                ->whereDate('created_at', '<=', $end)->get()->filter(fn ($record) => in_array($record->data['goal_type'] ?? null, ['savings', 'emergency'], true))
                ->pluck('data.budget_category')->filter()->all();
            if ($cohort->contains(function ($record) use ($funds) {
                $data = $record->data;
                $from = CarbonImmutable::parse($data['effective_on']);
                $through = CarbonImmutable::parse($data['ends_on']);

                return ($data['carry_forward'] ?? true) === false || ($data['rollover'] ?? false)
                    || $from->day !== 1 || $through->toDateString() !== $through->endOfMonth()->toDateString()
                    || (empty($data['program_record_id']) && in_array($data['category'], $funds, true));
            })) {
                continue;
            }
            $catalog ??= collect(app(AnalysisCategoryService::class)->definitions($space))->keyBy('key');
            $source = $cohort->first()->data;
            $superseded = $groups->contains(function ($others, $otherKey) use ($key, $source, $start, $catalog) {
                if ($otherKey === $key) {
                    return false;
                }
                $latestOther = $others->max(fn ($record) => $record->data['effective_on']);

                return $others->contains(function ($record) use ($source, $latestOther, $start, $catalog) {
                    $data = $record->data;

                    return $data['effective_on'] === $latestOther && ((empty($data['ends_on']) || $data['ends_on'] >= $start) || $latestOther > $source['effective_on'])
                        && (int) ($data['program_record_id'] ?? 0) === (int) ($source['program_record_id'] ?? 0)
                        && ($this->ancestor($source['category'], $data['category'], $catalog) || $this->ancestor($data['category'], $source['category'], $catalog));
                });
            });
            if ($superseded) {
                continue;
            }
            $ids = $cohort->pluck('id')->all();
            $defaults[$key] = ['month' => $month->subMonth()->format('Y-m'), 'record_ids' => $ids,
                'source_month' => substr($cohort->first()->data['ends_on'], 0, 7)];
            $projected = $projected->map(function ($record) use ($space, $ids, $start, $end) {
                if (! $this->ownedBudget($space, $record) || ! in_array($record->id, $ids, true)) {
                    return $record;
                }
                $data = $record->data;
                $data['cash_spending_pending'] = ($data['cash_spending_pending'] ?? false) || isset($data['reported_cash_as_of']);
                unset($data['reported_cash_spent_cents'], $data['reported_cash_as_of']);
                $record->data = [...$data, 'effective_on' => $start, 'ends_on' => $end];

                return $record;
            });
        }

        return ['records' => $projected, 'defaults' => $defaults];
    }

    private function ownedBudget(Space $space, mixed $record): bool
    {
        return $record instanceof Record && $record->kind === 'budget' && $record->active
            && (int) $record->space_id === (int) $space->id;
    }

    private function validDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^(?:19|20|21)\d{2}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->toDateString() === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ancestor(string $parent, string $category, Collection $catalog): bool
    {
        $seen = [];
        while ($category = $catalog->get($category)['parent_key'] ?? null) {
            if ($category === $parent) {
                return true;
            }
            if (isset($seen[$category])) {
                break;
            }
            $seen[$category] = true;
        }

        return false;
    }
}
