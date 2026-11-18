<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Dated targets less attributed purchases; budget allowance is never a bank balance. */
class BudgetEnvelopeService
{
    public function build(Space $space, string $month, ?int $programId = null): array
    {
        $start = CarbonImmutable::parse($month.'-01', $space->timezone)->startOfMonth();
        $end = $start->endOfMonth();
        $today = CarbonImmutable::now($space->timezone)->startOfDay();
        $programs = Record::where('space_id', $space->id)->where('kind', 'program')->where('active', true)->get()->keyBy('id');
        abort_if($programId !== null && ! $programs->has($programId), 404);
        $records = Record::where('space_id', $space->id)->where('kind', 'budget')->where('active', true)->get();
        $all = collect(app(AttributionService::class)->expandEntries($space, app(LedgerService::class)->entries($space, end: $end->min(CarbonImmutable::now($space->timezone))->toDateString()), $programId));
        $costs = app(EconomicActivityService::class)->expenses($all);
        $pending = collect(app(AttributionService::class)->expandEntries($space, $this->pending($space), $programId));
        $pending = app(EconomicActivityService::class)->expenses($pending)->where('amount_cents', '<', 0);
        $catalog = collect(app(AnalysisCategoryService::class)->definitions($space))->keyBy('key');
        $targets = $this->targets($space, $records, $start, $programId);
        $current = $costs->where('date', '>=', $start->toDateString())->where('date', '<=', $end->toDateString());
        $committed = $pending->where('date', '>=', $start->toDateString())->where('date', '<=', $end->toDateString());
        $keys = array_values(array_unique([...array_keys($targets), ...$current->map(fn ($e) => $this->entryKey($e))->all(), ...$committed->map(fn ($e) => $this->entryKey($e))->all()]));
        $rows = [];
        foreach ($keys as $key) {
            [$program, $category] = explode('|', $key, 2);
            $program = (int) $program ?: null;
            $target = $targets[$key] ?? null;
            $categoryKeys = [$category, ...$catalog->where('parent_key', $category)->keys()->all()];
            $matches = fn ($e) => (int) ($e['program_record_id'] ?? 0) === (int) $program && in_array($e['category'], $categoryKeys, true);
            $spent = $current->filter($matches);
            $holds = $committed->filter($matches);
            $carry = 0;
            $carryMissing = false;
            $carryStatus = 'needs_opening_balance';
            if ($target && ($target['rollover'] ?? false)) {
                $rolloverStart = $target['rollover_start_month'] ?? null;
                if (! $rolloverStart || ! isset($target['opening_rollover_cents'])) {
                    $carryMissing = true;
                } else {
                    $cursor = CarbonImmutable::parse($rolloverStart.'-01', $space->timezone)->startOfMonth();
                    if ($cursor->gt($start) || $cursor->diffInMonths($start) > 120) {
                        $carryMissing = true;
                    } else {
                        $carry = $target['opening_rollover_cents'];
                        while ($cursor->lt($start)) {
                            if ($cursor->endOfMonth()->gte($today)) {
                                $carryMissing = true;
                                $carryStatus = 'needs_prior_month_close';
                                break;
                            }
                            $past = $this->targets($space, $records, $cursor, $program)[$key] ?? null;
                            if (! $past || $past['target_cents'] === null) {
                                $carryMissing = true;
                                break;
                            }
                            $used = $costs->where('date', '>=', $cursor->toDateString())->where('date', '<=', $cursor->endOfMonth()->toDateString())->filter($matches);
                            $carry += $past['target_cents'] + (int) $used->sum('amount_cents');
                            $cursor = $cursor->addMonth();
                        }
                    }
                }
            }
            $amount = $target['target_cents'] ?? null;
            $used = -(int) $spent->sum('amount_cents');
            $reserved = -(int) $holds->sum('amount_cents');
            $rows[] = ['key' => $key, 'category' => $category, 'label' => $catalog->get($category)['label'] ?? ucfirst(str_replace('_', ' ', $category)),
                'program_id' => $program, 'program_label' => $programs->get($program)?->name, 'parent_key' => $catalog->get($category)['parent_key'] ?? null,
                'target_cents' => $amount, 'planned_cents' => $target['planned_cents'] ?? null,
                'spent_cents' => $used, 'pending_cents' => $reserved, 'carryover_cents' => $carryMissing ? null : $carry,
                'remaining_cents' => $amount === null || $carryMissing ? null : $amount + $carry - $used - $reserved,
                'rollover' => $target['rollover'] ?? false, 'status' => $carryMissing ? $carryStatus : ($target['status'] ?? 'not_budgeted'),
                'ids' => $spent->pluck('id')->unique()->values()->all(), 'pending_ids' => $holds->pluck('id')->unique()->values()->all(),
                'record_id' => count($target['record_ids'] ?? []) === 1 ? $target['record_ids'][0] : null,
                'version' => $target['version'] ?? null, 'record_ids' => $target['record_ids'] ?? []];
        }
        // If a parent has a target, its children share that allowance; do not add them twice.
        foreach ($rows as &$row) {
            if (! $row['parent_key']) {
                continue;
            }
            $parent = collect($rows)->first(fn ($p) => $p['category'] === $row['parent_key'] && $p['program_id'] === $row['program_id'] && $p['planned_cents'] !== null);
            if ($parent) {
                if ($row['planned_cents'] !== null) {
                    $row['remaining_cents'] = null;
                    $row['target_cents'] = null;
                    $row['status'] = 'overlapping_targets';
                } else {
                    $row['uses_parent_budget'] = $parent['key'];
                }
            }
        }
        unset($row);
        foreach ($rows as &$row) {
            if (collect($rows)->contains(fn ($child) => ($child['parent_key'] ?? null) === $row['category'] && $child['program_id'] === $row['program_id'] && $child['status'] === 'overlapping_targets')) {
                $row['remaining_cents'] = null;
                $row['target_cents'] = null;
                $row['status'] = 'overlapping_targets';
            }
        }
        unset($row);
        $complete = app(EconomicActivityService::class)->unresolved($all->where('date', '>=', $start->toDateString()))->isEmpty();
        if (! $complete) {
            foreach ($rows as &$row) {
                $row['remaining_cents'] = null;
                $row['status'] = 'unresolved_costs';
            }
            unset($row);
        }
        $summaryRows = collect($rows)->reject(fn ($r) => isset($r['uses_parent_budget']));

        return ['month' => $month, 'range' => ['from' => $start->toDateString(), 'through' => $end->toDateString()], 'program_id' => $programId,
            'as_of' => $today->toDateString(), 'actual_through' => $end->min($today)->toDateString(),
            'rows' => $rows, 'summary' => ['spent_cents' => -(int) $current->sum('amount_cents'), 'pending_cents' => -(int) $committed->sum('amount_cents'),
                'target_cents' => $summaryRows->isEmpty() || $summaryRows->contains('target_cents', null) ? null : (int) $summaryRows->sum('target_cents'),
                'remaining_cents' => $summaryRows->isEmpty() || $summaryRows->contains('remaining_cents', null) ? null : (int) $summaryRows->sum('remaining_cents')],
            'coverage' => ['complete' => false, 'costs_resolved' => $complete, 'notes' => ['Category allowances are plans, not available bank cash.', 'Pending purchases reserve budget until settled or removed. Missing history can change remaining amounts.', 'Ordinary categories reset monthly. Explicit funds carry their approved opening allowance and recorded activity.']],
            'program_options' => $programs->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all()];
    }

    private function entryKey(array $entry): string
    {
        return ($entry['program_record_id'] ?? 0).'|'.$entry['category'];
    }

    private function targets(Space $space, Collection $records, CarbonImmutable $month, ?int $programId): array
    {
        $out = [];
        $groups = $records->filter(fn ($r) => ($r->data['purpose'] ?? null) === 'spending' && ($programId === null || ($r->data['program_record_id'] ?? null) === $programId))
            ->groupBy(fn ($r) => (string) ($r->data['program_record_id'] ?? 0));
        foreach ($groups as $program => $cohort) {
            $selected = app(MonthlyBudgetService::class)->build($space, $cohort, $month);
            $ids = $selected['record_ids'];
            foreach ($cohort->whereIn('id', $ids)->groupBy(fn ($r) => $r->data['category']) as $category => $rows) {
                $categoryReport = app(MonthlyBudgetService::class)->build($space, $rows, $month);
                $known = $rows->every(fn ($r) => ($r->data['reviewed'] ?? false) === true);
                $rollover = $rows->count() === 1 && ($rows->first()->data['rollover'] ?? false);
                $out[$program.'|'.$category] = [
                    'planned_cents' => (int) $rows->sum(fn ($r) => $r->data['monthly_cents']),
                    'target_cents' => $known && $categoryReport['purposes']['spending']['status'] !== 'partial' && empty($selected['purposes']['spending']['overlapping_record_ids']) ? (int) $rows->sum(fn ($r) => $r->data['monthly_cents']) : null,
                    'record_ids' => $rows->pluck('id')->all(), 'version' => $rows->count() === 1 ? $rows->first()->version : null,
                    'status' => $known ? $categoryReport['purposes']['spending']['status'] : 'draft', 'rollover' => $rollover,
                    ...($rollover ? array_intersect_key($rows->first()->data, array_flip(['rollover_start_month', 'opening_rollover_cents'])) : []),
                ];
            }
        }

        return $out;
    }

    private function pending(Space $space): array
    {
        return DB::table('trajectory_allocations as a')->join('trajectory_transactions as t', 't.id', '=', 'a.transaction_id')
            ->where('a.space_id', $space->id)->where('t.pending', true)->where('t.removed', false)
            ->select(['t.*', 'a.amount_cents as allocated_cents'])->get()->map(function ($r) use ($space) {
                $own = (int) $r->space_id === $space->id;

                return ['id' => (int) $r->id, 'date' => substr($r->posted_on, 0, 10), 'amount_cents' => (int) $r->allocated_cents,
                    'category' => $r->category, 'flow' => $r->flow, 'reviewed' => (bool) $r->reviewed, 'pending' => true,
                    'merchant' => $own ? decrypt($r->merchant, false) : 'Shared allocation', 'editable' => $own,
                    'version' => (int) $r->version, 'account_id' => $own ? (int) $r->account_id : null];
            })->all();
    }
}
