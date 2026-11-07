<?php

namespace App\Services\Team\Studio;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Operations\OperationsWorkspace;

/** Permission-aware read model. Workbook totals and operational actuals never add together. */
class EventIntelligence
{
    public function __construct(private OperationsAccess $access) {}

    public function detail(Tenant $tenant, User $user, TeamOperationRecord $event): array
    {
        abort_unless($event->kind === 'event', 404);
        $this->access->record($tenant, $user, $event);
        $admin = $this->access->admin($this->access->actor($tenant, $user));
        $links = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $event->area_id, 'kind' => 'event_link', 'status' => 'accepted'])->get();
        $current = $links->first(fn ($r) => ($r->data['occurrence_id'] ?? null) === $event->id);
        if ($current) {
            $series = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $event->area_id, 'kind' => 'series'])->find($current->data['series_id']);
            try {
                abort_unless($series, 404);
                $this->access->record($tenant, $user, $series);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $current = null;
            }
        }
        $history = [];
        $issues = [];
        if ($current) {
            foreach ($links as $link) {
                if (($link->data['series_id'] ?? null) !== $current->data['series_id']) {
                    continue;
                }
                $item = $this->occurrence($tenant, $user, $link, $admin);
                if ($item) {
                    $history[] = $item;
                } else {
                    $issues[] = 'A historical occurrence is unavailable with your current source access.';
                }
            }
        }
        usort($history, fn ($a, $b) => strcmp($a['starts_on'], $b['starts_on']));
        $counts = array_count_values(array_map(fn ($item) => substr($item['starts_on'], 0, 4), $history));
        $previous = null;
        foreach ($history as &$item) {
            if ($admin) {
                $net = $item['finance']['net_sales_cents'];
                $profit = $item['finance']['profit_cents'];
                $year = (int) substr($item['starts_on'], 0, 4);
                $item['year_over_year'] = $previous && ($counts[(string) $year] ?? 0) === 1 && ($counts[(string) ($year - 1)] ?? 0) === 1 && $previous['year'] === $year - 1 && $item['finance']['complete'] && $previous['complete'] ? ['net_sales_change_cents' => $net - $previous['net'], 'net_sales_change_percent' => $previous['net'] > 0 ? round(($net - $previous['net']) / $previous['net'] * 100, 2) : null, 'profit_change_cents' => $profit - $previous['profit']] : null;
                $previous = ['year' => $year, 'net' => $net, 'profit' => $profit, 'complete' => $item['finance']['complete']];
            }
        }
        unset($item);
        $budgets = [];
        if ($admin) {
            $budgets = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $event->area_id, 'kind' => 'event_budget'])->orderBy('created_at')->get()->filter(fn ($r) => ($r->data['event_id'] ?? null) === $event->id)->map(fn ($r) => ['id' => $r->id, 'version' => $r->version, 'frozen_at' => $r->data['frozen_at'], 'policy_id' => $r->data['policy_id'], 'policy_version' => $r->data['policy_version'], 'inputs' => $r->data['inputs'], 'previous_budget_id' => $r->data['previous_budget_id'] ?? null, 'reason' => $r->data['reason'] ?? 'Initial event budget', 'preview' => $r->data['preview']])->values()->all();
        }
        $area = TeamArea::where('tenant_id', $tenant->id)->findOrFail($event->area_id);

        return ['contract_version' => 2, 'event' => app(OperationsWorkspace::class)->view($event, $this->access->mayWrite($tenant, $user, $event->area_id), $admin), 'authority' => app(TeamsStudio::class)->authority($tenant, $area), 'series_id' => $current?->data['series_id'] ?? null, 'source_task' => app(\App\Services\Team\Operations\EventTasks::class)->forOccurrence($tenant, $user, $event), 'history' => $history, 'annual_history' => $admin ? app(AnnualEventComparison::class)->summarize($history, ! $issues) : [], 'comparison_scope' => 'Reviewed occurrences available to the current user; unmapped source history is excluded.', 'budget_revisions' => $budgets, 'can_view_financials' => $admin, 'review_items' => $current ? $issues : ['Map this occurrence to a reviewed series to compare years.'], 'basis' => 'Recorded profit = recorded net sales minus recorded total expense, including costs already in that expense. Operational sales, refunds, rewards, inventory and payments retain their original services.', 'as_of' => now()->toIso8601String()];
    }

    private function occurrence(Tenant $tenant, User $user, TeamOperationRecord $link, bool $admin): ?array
    {
        $map = $link->data;
        $record = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $link->area_id])->find($map['occurrence_id']);
        if (! $record) {
            return null;
        }
        try {
            $this->access->record($tenant, $user, $record);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return null;
        }
        $item = ['id' => $record->id, 'title' => $record->title, 'starts_on' => $map['starts_on'] ?? $record->starts_on?->toDateString() ?? '', 'ends_on' => $map['ends_on'] ?? $record->ends_on?->toDateString(), 'venue' => $map['venue'], 'timezone' => $map['timezone'], 'daily_hours' => $map['daily_hours'] ?? [], 'mapping' => ['id' => $link->id, 'version' => $link->version, 'reviewed_at' => $map['reviewed_at'], 'source_evidence' => $map['reason']]];
        if (! $admin) {
            unset($item['mapping']['source_evidence']);

            return $item;
        }
        $finance = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $link->area_id, 'kind' => 'financial'])->find($map['financial_id'] ?? '');
        $issues = [];
        if ($finance) {
            try {
                $this->access->record($tenant, $user, $finance);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $finance = null;
            }
        }
        $data = $finance?->data ?? [];
        $net = $data['net_sales_cents'] ?? null;
        $expense = $data['total_expense_cents'] ?? null;
        $profit = $net !== null && $expense !== null ? $net - $expense : null;
        $partial = ! $item['ends_on'] || $item['ends_on'] >= now($map['timezone'])->toDateString();
        if ($item['starts_on'] !== ($record->starts_on?->toDateString() ?? '') || $item['ends_on'] !== $record->ends_on?->toDateString()) {
            $issues[] = 'Mapped occurrence dates changed at the source; review its identity before comparison.';
        }
        if (! $finance) {
            $issues[] = 'Recorded financial source unavailable.';
        }
        if ($finance && ($map['financial_version'] ?? null) !== $finance->version) {
            $issues[] = 'Recorded financial source changed after mapping review.';
        }
        if (($data['needs_allocation_review'] ?? false) && ! ($map['allocation_reviewed'] ?? false)) {
            $issues[] = 'Repeated staff/shared expenses need allocation review.';
        }
        if ($profit !== null && isset($data['profit_cents']) && abs($data['profit_cents'] - $profit) > 1) {
            $issues[] = 'Recorded profit does not reconcile to net sales less total expense.';
        }
        if ($net === null || $expense === null) {
            $issues[] = 'Net sales or total expense is unknown.';
        }
        if ($partial) {
            $issues[] = 'Current or incomplete occurrence; excluded from completed-year changes.';
        }
        $days = $map['verified_selling_days'] ?? null;
        $minutes = $map['verified_operating_minutes'] ?? null;
        $staff = [];
        foreach ($map['staff_ids'] ?? [] as $id) {
            $person = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $link->area_id])->whereIn('kind', ['staff', 'financial'])->find($id);
            if (! $person) {
                continue;
            }
            try {
                $this->access->record($tenant, $user, $person);
                $staff[] = ['id' => $person->id, 'name' => $person->data['person_name'] ?? $person->title, 'role' => $person->data['role'] ?? null, 'hours' => $person->data['hours'] ?? null, 'payroll_cents' => $person->data['payroll_cents'] ?? null, 'costs' => $person->data['costs'] ?? [], 'source' => ['source_tab' => $person->data['source_tab'] ?? null, 'source_row' => $person->data['source_row'] ?? null, 'version' => $person->version]];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $issues[] = 'Some recorded staffing evidence is unavailable.';
            }
        }
        $item['staff'] = $staff;
        $item['finance'] = ['net_sales_cents' => $net, 'refunds_cents' => $data['refunds_cents'] ?? null, 'total_expense_cents' => $expense, 'profit_cents' => $profit, 'margin_percent' => $net > 0 && $profit !== null ? round($profit / $net * 100, 2) : null, 'costs' => $data['costs'] ?? [], 'selling_days' => $days, 'operating_minutes' => $minutes, 'net_sales_per_day_cents' => $days && $net !== null ? (int) round($net / $days) : null, 'profit_per_hour_cents' => $minutes && $profit !== null ? (int) round($profit * 60 / $minutes) : null, 'daily_sales' => $data['daily_sales'] ?? null, 'complete' => ! $issues, 'partial' => $partial, 'review_items' => $issues, 'source' => $finance ? ['id' => $finance->id, 'version' => $finance->version, 'tab' => $data['source_tab'] ?? null, 'row' => $data['source_row'] ?? null, 'observed_at' => $data['observed_at'] ?? null] : null];

        return $item;
    }
}
