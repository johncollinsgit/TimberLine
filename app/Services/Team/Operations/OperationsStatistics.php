<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;

class OperationsStatistics
{
    public function events(Tenant $tenant, User $user, TeamArea $area, int $year): array
    {
        $access = app(OperationsAccess::class);
        $access->financial($tenant, $user);
        $access->area($tenant, $user, $area->id);
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'financial'])->where('status', '!=', 'withdrawn')->get();
        $totals = ['net_sales_cents' => 0, 'expense_cents' => 0, 'profit_cents' => 0];
        $costs = [];
        $history = [];
        $events = [];
        $excluded = 0;
        $missingCosts = [];
        $comparisons = ['month' => [], 'series' => [], 'location' => [], 'year' => []];
        $discrepancies = [];
        $planning = 0;
        $groups = [];
        foreach ($rows as $r) {
            $identity = $r->data['event_id'] ?? ($r->title.'|'.$r->starts_on?->toDateString().'|'.$r->ends_on?->toDateString());
            $groups[$identity] = ($groups[$identity] ?? 0) + 1;
        }
        foreach ($rows as $row) {
            $data = $row->data;
            try {
                $access->record($tenant, $user, $row);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $excluded++;

                continue;
            }
            $identity = $data['event_id'] ?? ($row->title.'|'.$row->starts_on?->toDateString().'|'.$row->ends_on?->toDateString());
            if ($row->ends_on && $row->ends_on->toDateString() >= now()->toDateString()) {
                $planning++;

                continue;
            }
            if ($row->status !== 'accepted' || ($data['needs_allocation_review'] ?? true) || $groups[$identity] > 1 || ($data['net_sales_cents'] ?? null) === null || ($data['total_expense_cents'] ?? null) === null || ! $row->starts_on || ! $row->ends_on || $row->ends_on->isFuture()) {
                $excluded++;

                continue;
            }
            $key = mb_strtolower(trim($row->title));
            $computedProfit = $data['net_sales_cents'] - $data['total_expense_cents'];
            if (isset($data['profit_cents']) && abs($data['profit_cents'] - $computedProfit) > 1) {
                $excluded++;
                $discrepancies[] = ['id' => $row->id, 'title' => $row->title, 'recorded_profit_cents' => $data['profit_cents'], 'calculated_profit_cents' => $computedProfit];

                continue;
            }
            foreach (['year' => (string) $row->starts_on->year, ...($row->starts_on->year === $year ? ['month' => $row->starts_on->format('Y-m'), 'series' => $row->title, 'location' => $data['state'] ?? 'Unknown'] : [])] as $dimension => $group) {
                $bucket = $comparisons[$dimension][$group] ?? ['net_sales_cents' => 0, 'expense_cents' => 0, 'profit_cents' => 0, 'occurrences' => 0];
                $bucket['net_sales_cents'] += $data['net_sales_cents'];
                $bucket['expense_cents'] += $data['total_expense_cents'];
                $bucket['profit_cents'] += $computedProfit;
                $bucket['occurrences']++;
                $comparisons[$dimension][$group] = $bucket;
            }
            $days = max(1, $data['selling_days'] ?? 1);
            if (($data['selling_days'] ?? 0) > 0) {
                $history[$key][] = ['revenue_per_day' => intdiv($data['net_sales_cents'], $days), 'cost_per_day' => intdiv($data['total_expense_cents'], $days)];
            }
            if ($row->starts_on?->year !== $year) {
                continue;
            }
            $profit = $data['net_sales_cents'] - $data['total_expense_cents'];
            $totals['net_sales_cents'] += $data['net_sales_cents'];
            $totals['expense_cents'] += $data['total_expense_cents'];
            $totals['profit_cents'] += $profit;
            foreach ($data['costs'] ?? [] as $name => $cents) {
                if ($cents !== null) {
                    $costs[$name] = ($costs[$name] ?? 0) + $cents;
                } else {
                    $missingCosts[$name] = ($missingCosts[$name] ?? 0) + 1;
                }
            }
            $events[] = ['id' => $row->id, 'title' => $row->title, 'date' => $row->starts_on?->toDateString(), 'net_sales_cents' => $data['net_sales_cents'], 'profit_cents' => $profit, 'margin_percent' => $data['net_sales_cents'] > 0 ? round($profit / $data['net_sales_cents'] * 100, 2) : null];
        }
        $forecasts = [];
        $unknown = 0;
        $projection = ['low' => $totals['profit_cents'], 'base' => $totals['profit_cents'], 'high' => $totals['profit_cents']];
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event'])->whereNotIn('status', ['cancelled', 'withdrawn', 'completed'])->whereBetween('starts_on', [max(now()->toDateString(), $year.'-01-01'), $year.'-12-31'])->get() as $event) {
            try {
                $access->record($tenant, $user, $event);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $unknown++;

                continue;
            }
            $sample = $history[mb_strtolower(trim($event->title))] ?? [];
            $override = $event->data['_teams_enrichment']['forecast_override'] ?? null;
            if ($override && ($override['source_version'] ?? null) === ($event->data['source_version'] ?? null) && ($override['starts_on'] ?? null) === $event->starts_on?->toDateString() && ($override['ends_on'] ?? null) === $event->ends_on?->toDateString()) {
                foreach ($override['profit_cents'] as $scenario => $cents) {
                    $projection[$scenario] += $cents;
                } $forecasts[] = ['id' => $event->id, 'title' => $event->title, 'status' => 'reviewed_override', 'sample_size' => count($sample), 'profit_cents' => $override['profit_cents'], 'method' => $override['reason']];

                continue;
            }
            $confirmed = $event->connection_id ? in_array($event->data['reviewed_fields']['acceptance_status'] ?? '', ['yes', 'accepted', 'confirmed'], true) : in_array($event->status, ['accepted', 'staffing', 'ready'], true);
            if (! $confirmed || ! $event->ends_on) {
                $forecasts[] = ['id' => $event->id, 'title' => $event->title, 'status' => 'schedule_needs_confirmation', 'sample_size' => count($sample)];
                $unknown++;

                continue;
            }
            if (count($sample) < 3) {
                $forecasts[] = ['id' => $event->id, 'title' => $event->title, 'status' => 'insufficient_history', 'sample_size' => count($sample)];
                $unknown++;

                continue;
            }
            $revenues = array_column($sample, 'revenue_per_day');
            sort($revenues);
            $expenses = array_column($sample, 'cost_per_day');
            sort($expenses);
            $days = max(1, (int) ($event->starts_on?->diffInDays($event->ends_on ?? $event->starts_on) ?? 0) + 1);
            $cost = $expenses[(int) floor((count($expenses) - 1) / 2)];
            $estimates = [];
            foreach (['low' => .25, 'base' => .5, 'high' => .75] as $scenario => $q) {
                $estimates[$scenario] = ($revenues[(int) floor((count($revenues) - 1) * $q)] - $cost) * $days;
                $projection[$scenario] += $estimates[$scenario];
            }
            $forecasts[] = ['id' => $event->id, 'title' => $event->title, 'status' => 'estimate', 'sample_size' => count($sample), 'profit_cents' => $estimates, 'method' => 'Historical exact-title occurrence daily revenue quartiles minus median daily recorded costs. Review series identity and comparability.'];
        }
        $health = app(OperationsHealth::class)->area($area, $user->id, 'sheets');

        return [...$totals, 'currency' => 'USD', 'year' => $year, 'comparisons' => $comparisons, 'discrepancies' => $discrepancies, 'planning_rows' => $planning, 'margin_percent' => $totals['net_sales_cents'] > 0 ? round($totals['profit_cents'] / $totals['net_sales_cents'] * 100, 2) : null, 'costs' => $costs, 'missing_cost_categories' => $missingCosts, 'events' => $events, 'excluded_rows' => $excluded, 'forecast' => ['year_end_profit_cents' => $projection, 'events' => $forecasts, 'unknown_events' => $unknown, 'complete' => $unknown === 0 && $excluded === 0 && $health['complete']], 'basis' => 'Recorded spreadsheet event margin. Square is reconciliation evidence, never additional revenue.', 'complete' => $excluded === 0 && $health['complete'], 'freshness' => $health, 'as_of' => now()->toIso8601String()];
    }
}
