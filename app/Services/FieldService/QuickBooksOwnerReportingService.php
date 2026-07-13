<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\IntegrationConnection;
use App\Models\MarketingProfile;
use App\Models\QuickBooksReportingSetting;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\QuickBooksSyncRun;
use App\Models\Tenant;
use App\Services\Dashboard\DashboardDateRange;
use App\Services\Integrations\ConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class QuickBooksOwnerReportingService
{
    public function __construct(
        protected DashboardDateRange $dateRanges,
        protected ConnectionManager $connections,
        protected QuickBooksReportingSnapshotService $snapshots,
    ) {}

    /** @return array<string,mixed> */
    public function report(Tenant $tenant, ?string $rangeKey = null, bool $allowRefresh = true): array
    {
        $range = $this->dateRanges->resolve($rangeKey);
        $priorStart = $range['starts_at']->subYearNoOverflow();
        $priorEnd = $range['ends_at']->subYearNoOverflow();
        $connection = IntegrationConnection::query()->forTenantId((int) $tenant->id)
            ->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)->latest('id')->first();
        $settings = QuickBooksReportingSetting::query()->forTenantId((int) $tenant->id)->first();
        $currentSnapshot = $this->snapshot($tenant, $connection, $range['key'], $range['starts_at'], $range['ends_at'], $allowRefresh);
        $priorSnapshot = $this->snapshot($tenant, $connection, $range['key'].':prior_year', $priorStart, $priorEnd, $allowRefresh);

        $invoices = FieldServiceFinancialDocument::query()->forTenantId((int) $tenant->id)
            ->where('source', 'quickbooks')->where('document_type', 'invoice');
        $currentInvoices = (clone $invoices)->whereBetween('transaction_date', [$range['starts_at']->toDateString(), $range['ends_at']->toDateString()]);
        $priorInvoices = (clone $invoices)->whereBetween('transaction_date', [$priorStart->toDateString(), $priorEnd->toDateString()]);
        $completed = FieldServiceJob::query()->forTenantId((int) $tenant->id)
            ->whereNotNull('completed_at')->whereBetween('completed_at', [$range['starts_at'], $range['ends_at']]);
        $priorCompleted = FieldServiceJob::query()->forTenantId((int) $tenant->id)
            ->whereNotNull('completed_at')->whereBetween('completed_at', [$priorStart, $priorEnd]);
        $unpaid = (clone $invoices)->where('balance', '>', 0);
        $nowDate = now()->toDateString();
        $financials = $this->financialMetrics($currentSnapshot, $settings, $range['starts_at'], $range['ends_at']);
        $priorFinancials = $this->financialMetrics($priorSnapshot, $settings, $priorStart, $priorEnd);
        $workValue = (float) (clone $currentInvoices)->sum('total_amount');
        $priorWorkValue = (float) (clone $priorInvoices)->sum('total_amount');

        return [
            'range' => [
                'key' => $range['key'], 'label' => $range['short_label'],
                'starts_at' => $range['starts_at']->toDateString(), 'ends_at' => $range['ends_at']->toDateString(),
                'options' => $range['options'],
            ],
            'prior_range' => ['starts_at' => $priorStart->toDateString(), 'ends_at' => $priorEnd->toDateString()],
            'cards' => [
                'unpaid_invoices' => [
                    'count' => (clone $unpaid)->count(),
                    'amount' => round((float) (clone $unpaid)->sum('balance'), 2),
                    'overdue_amount' => round((float) (clone $unpaid)->whereNotNull('due_date')->where('due_date', '<', $nowDate)->sum('balance'), 2),
                ],
                'supplies' => ['amount' => $financials['supplies'], 'prior_amount' => $priorFinancials['supplies']],
                'employee_labor' => [
                    'including_owner' => $financials['wages'],
                    'excluding_owner' => $financials['wages_excluding_owner'],
                    'including_owner_percent' => $this->percent($financials['wages'], $financials['income']),
                    'excluding_owner_percent' => $this->percent($financials['wages_excluding_owner'], $financials['income']),
                    'owner_compensation' => $financials['owner_compensation'],
                    'separable' => $financials['owner_separable'],
                ],
                'contract_labor' => [
                    'amount' => $financials['contract_labor'],
                    'percent' => $this->percent($financials['contract_labor'], $financials['income']),
                ],
                'combined_labor' => [
                    'amount' => $this->nullableSum($financials['wages'], $financials['contract_labor']),
                    'percent' => $this->percent($this->nullableSum($financials['wages'], $financials['contract_labor']), $financials['income']),
                ],
                'work_billed' => [
                    'count' => (clone $currentInvoices)->count(), 'amount' => round($workValue, 2),
                    'prior_count' => (clone $priorInvoices)->count(), 'prior_amount' => round($priorWorkValue, 2),
                    'year_over_year_percent' => $this->changePercent($workValue, $priorWorkValue),
                ],
                'jobs_completed' => ['count' => $completed->count(), 'prior_count' => $priorCompleted->count()],
            ],
            'quote_aging' => $this->quoteAging($tenant),
            'largest_customers' => $this->largestCustomers($currentInvoices),
            'upcoming_jobs' => $this->upcomingJobs($tenant),
            'sync_health' => $this->syncHealth($tenant, $connection),
            'mapping_state' => [
                'reviewed' => $settings?->mappings_reviewed_at !== null,
                'suggestions' => (array) data_get($currentSnapshot?->metrics, 'mapping_suggestions', []),
            ],
        ];
    }

    protected function snapshot(
        Tenant $tenant,
        ?IntegrationConnection $connection,
        string $rangeKey,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $allowRefresh
    ): ?QuickBooksReportingSnapshot {
        $snapshot = QuickBooksReportingSnapshot::query()->forTenantId((int) $tenant->id)
            ->where('range_key', $rangeKey)
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString())
            ->latest('observed_at')->first();
        if ($snapshot && $snapshot->observed_at?->isAfter(now()->subHour())) {
            return $snapshot;
        }
        if (! $allowRefresh || ! $connection || ! $this->connections->hasConnector('quickbooks')) {
            return $snapshot;
        }

        try {
            return $this->snapshots->refresh(
                $tenant,
                $connection,
                $this->connections->connector('quickbooks')->client($connection),
                $rangeKey,
                $start,
                $end
            );
        } catch (Throwable) {
            return $snapshot;
        }
    }

    /** @return array{income:?float,supplies:?float,wages:?float,wages_excluding_owner:?float,owner_compensation:?float,owner_separable:bool,contract_labor:?float} */
    protected function financialMetrics(?QuickBooksReportingSnapshot $snapshot, ?QuickBooksReportingSetting $settings, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $metrics = (array) ($snapshot?->metrics ?? []);
        $lines = collect((array) ($metrics['account_lines'] ?? []));
        $reviewed = $settings?->mappings_reviewed_at !== null;
        $wages = $reviewed ? $this->mappedTotal($lines, (array) $settings?->wage_account_mappings) : null;
        $mappedOwner = $reviewed ? $this->mappedTotal($lines, (array) $settings?->owner_compensation_account_mappings) : null;
        $manualOwner = $reviewed ? $this->ownerAdjustments((array) $settings?->owner_compensation_adjustments, $start, $end) : null;
        $owner = $this->nullableSum($mappedOwner, $manualOwner);

        return [
            'income' => is_numeric($metrics['total_income'] ?? null) ? (float) $metrics['total_income'] : null,
            'supplies' => $reviewed ? $this->mappedTotal($lines, (array) $settings?->supplies_account_mappings) : null,
            'wages' => $wages,
            'wages_excluding_owner' => $wages !== null && $owner !== null ? max(0, $wages - $owner) : null,
            'owner_compensation' => $owner,
            'owner_separable' => $wages !== null && $owner !== null,
            'contract_labor' => $reviewed ? $this->mappedTotal($lines, (array) $settings?->contract_labor_account_mappings) : null,
        ];
    }

    protected function mappedTotal(Collection $lines, array $mappings): ?float
    {
        if ($mappings === []) {
            return null;
        }

        $matches = $lines->filter(function (array $line) use ($mappings): bool {
            foreach ($mappings as $mapping) {
                $id = is_array($mapping) ? trim((string) ($mapping['id'] ?? '')) : '';
                $label = is_array($mapping) ? trim((string) ($mapping['label'] ?? '')) : trim((string) $mapping);
                if (($id !== '' && $id === trim((string) ($line['id'] ?? '')))
                    || ($label !== '' && strcasecmp($label, trim((string) ($line['label'] ?? ''))) === 0)) {
                    return true;
                }
            }

            return false;
        });

        return round((float) $matches->sum('amount'), 2);
    }

    protected function ownerAdjustments(array $adjustments, CarbonImmutable $start, CarbonImmutable $end): ?float
    {
        if ($adjustments === []) {
            return null;
        }

        $total = 0.0;
        $matched = false;
        foreach ($adjustments as $month => $amount) {
            if (! is_numeric($amount)) {
                continue;
            }
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m', (string) $month);
            } catch (Throwable) {
                continue;
            }
            if ($date->endOfMonth()->gte($start) && $date->startOfMonth()->lte($end)) {
                $matched = true;
                $total += (float) $amount;
            }
        }

        return $matched ? round($total, 2) : null;
    }

    /** @return array<string,int|float> */
    protected function quoteAging(Tenant $tenant): array
    {
        $rows = FieldServiceFinancialDocument::query()->forTenantId((int) $tenant->id)
            ->where('source', 'quickbooks')->where('document_type', 'estimate')
            ->whereIn('status', ['pending', 'accepted', 'estimate'])
            ->get(['transaction_date', 'total_amount']);

        return [
            'count' => $rows->count(),
            'amount' => round((float) $rows->sum('total_amount'), 2),
            'under_30_days' => $rows->filter(fn ($row): bool => $row->transaction_date?->gte(now()->subDays(30)) ?? false)->count(),
            'days_31_to_90' => $rows->filter(fn ($row): bool => $row->transaction_date?->between(now()->subDays(90), now()->subDays(31)) ?? false)->count(),
            'over_90_days' => $rows->filter(fn ($row): bool => $row->transaction_date?->lt(now()->subDays(90)) ?? false)->count(),
        ];
    }

    /** @return array<int,array{name:string,amount:float,documents:int}> */
    protected function largestCustomers($invoiceQuery): array
    {
        $rows = (clone $invoiceQuery)
            ->whereNotNull('marketing_profile_id')
            ->selectRaw('marketing_profile_id, SUM(total_amount) as amount, COUNT(*) as documents')
            ->groupBy('marketing_profile_id')->orderByDesc('amount')->limit(5)->get();
        $profiles = MarketingProfile::query()->whereIn('id', $rows->pluck('marketing_profile_id'))->get()->keyBy('id');

        return $rows->map(function ($row) use ($profiles): array {
            $profile = $profiles->get($row->marketing_profile_id);
            $name = trim((string) (($profile?->first_name ?? '').' '.($profile?->last_name ?? '')));

            return ['name' => $name ?: ($profile?->email ?: 'QuickBooks customer'), 'amount' => round((float) $row->amount, 2), 'documents' => (int) $row->documents];
        })->all();
    }

    /** @return array<int,array<string,mixed>> */
    protected function upcomingJobs(Tenant $tenant): array
    {
        return FieldServiceJob::query()->forTenantId((int) $tenant->id)
            ->whereNotNull('scheduled_for')->where('scheduled_for', '>=', now())->where('status', '!=', 'done')
            ->with('assignedUser:id,name')->orderBy('scheduled_for')->limit(5)->get()
            ->map(fn (FieldServiceJob $job): array => [
                'id' => (int) $job->id,
                'title' => (string) $job->title,
                'scheduled_for' => $job->scheduled_for?->toIso8601String(),
                'address' => trim(implode(', ', array_filter([$job->service_address_line_1, $job->service_city, $job->service_state]))),
                'assigned_to' => $job->assignedUser?->name,
            ])->all();
    }

    /** @return array<string,mixed> */
    protected function syncHealth(Tenant $tenant, ?IntegrationConnection $connection): array
    {
        $run = QuickBooksSyncRun::query()->forTenantId((int) $tenant->id)->latest('started_at')->first();
        $reviewCount = FieldServiceFinancialDocument::query()->forTenantId((int) $tenant->id)
            ->where('source', 'quickbooks')->whereNull('field_service_job_id')
            ->where('metadata->quickbooks->job_link_status', 'needs_review')->count();

        return [
            'connected' => $connection?->isConnected() ?? false,
            'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
            'last_run_status' => $run?->status,
            'last_run_at' => $run?->started_at?->toIso8601String(),
            'review_count' => $reviewCount,
        ];
    }

    protected function percent(?float $numerator, ?float $denominator): ?float
    {
        return $numerator !== null && $denominator !== null && abs($denominator) > 0.0001
            ? round(($numerator / $denominator) * 100, 2)
            : null;
    }

    protected function changePercent(float $current, float $prior): ?float
    {
        return abs($prior) > 0.0001 ? round((($current - $prior) / $prior) * 100, 2) : null;
    }

    protected function nullableSum(?float ...$values): ?float
    {
        $present = array_values(array_filter($values, fn (?float $value): bool => $value !== null));

        return $present === [] ? null : round(array_sum($present), 2);
    }
}
