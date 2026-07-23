<?php

namespace App\Services\Accounting;

use App\Models\AccountingComplianceTask;
use App\Models\AccountingDebtSnapshot;
use App\Models\AccountingEventSourceImport;
use App\Models\AccountingProfile;
use App\Models\FieldServiceFinancialDocument;
use App\Models\IntegrationConnection;
use App\Models\Order;
use App\Models\QuickBooksReportingSetting;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\QuickBooksSyncRun;
use App\Models\SquarePayment;
use App\Models\Tenant;
use Illuminate\Support\Collection;

class AccountingCommandCenterService
{
    public function __construct(
        protected AccountingDateRangeService $dateRanges,
        protected MonthlyCloseService $monthlyClose,
    ) {}

    /** @return array<string,mixed> */
    public function dashboard(
        Tenant $tenant,
        ?string $rangeKey = null,
        ?string $customStart = null,
        ?string $customEnd = null,
    ): array {
        $range = $this->dateRanges->resolve($rangeKey, $customStart, $customEnd);
        $profile = AccountingProfile::query()->forTenantId((int) $tenant->id)->first();
        $connection = IntegrationConnection::query()->forTenantId((int) $tenant->id)
            ->where('provider', 'quickbooks')->latest('id')->first();
        $snapshot = QuickBooksReportingSnapshot::query()->forTenantId((int) $tenant->id)
            ->whereDate('period_start', $range['starts_at']->toDateString())
            ->whereDate('period_end', $range['ends_at']->toDateString())
            ->latest('observed_at')->first();
        $metrics = (array) ($snapshot?->metrics ?? []);
        $close = $this->monthlyClose->forMonth($tenant, $range['ends_at']);

        return [
            'range' => [
                'key' => $range['key'],
                'label' => $range['label'],
                'starts_at' => $range['starts_at']->toDateString(),
                'ends_at' => $range['ends_at']->toDateString(),
                'aggregation' => $range['aggregation'],
                'options' => $range['options'],
            ],
            'profile' => $this->profilePayload($profile),
            'ledger' => $this->ledgerPayload($snapshot, $metrics, $connection),
            'revenue_mix' => $this->revenueMix($tenant, $range, $profile),
            'payroll' => $this->payrollPayload($tenant, $metrics),
            'transactions' => $this->transactions($tenant, $range),
            'debt' => $this->debtPayload($tenant, $range),
            'compliance_tasks' => AccountingComplianceTask::query()->forTenantId((int) $tenant->id)
                ->orderByRaw("CASE WHEN status = 'overdue' THEN 0 WHEN due_at IS NULL THEN 2 ELSE 1 END")
                ->orderBy('due_at')->orderBy('name')->limit(12)->get(),
            'monthly_close' => $close,
            'event_source' => $this->eventSourcePayload($tenant, $profile),
            'sources' => $this->sourceHealth($tenant, $connection, $snapshot),
            'guardrails' => [
                'ledger_source' => 'QuickBooks Online',
                'write_back_enabled' => false,
                'automatic_filing_enabled' => false,
                'automatic_payments_enabled' => false,
                'operational_sources_added_to_ledger' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    protected function profilePayload(?AccountingProfile $profile): array
    {
        return [
            'configured' => $profile !== null,
            'setup_status' => $profile?->setup_status ?? 'not_started',
            'preset_key' => $profile?->preset_key,
            'entity_type' => $profile?->entity_type,
            'state_code' => $profile?->state_code,
            'accounting_basis' => $profile?->accounting_basis ?? 'accrual',
            'reviewed_at' => $profile?->reviewed_at,
            'configuration' => (array) ($profile?->configuration ?? []),
        ];
    }

    /** @return array<string,mixed> */
    protected function ledgerPayload(
        ?QuickBooksReportingSnapshot $snapshot,
        array $metrics,
        ?IntegrationConnection $connection,
    ): array {
        $available = $snapshot !== null
            && is_numeric($metrics['total_income'] ?? null)
            && is_numeric($metrics['total_expenses'] ?? null);
        $income = $available ? (float) $metrics['total_income'] : null;
        $expenses = $available ? (float) $metrics['total_expenses'] : null;
        $net = is_numeric($metrics['net_income'] ?? null)
            ? (float) $metrics['net_income']
            : ($available ? $income - $expenses : null);

        return [
            'available' => $available,
            'gross_income' => $income,
            'expenses' => $expenses,
            'net_operating_result' => $net,
            'accounting_basis' => strtolower((string) ($metrics['accounting_method'] ?? 'accrual')),
            'observed_at' => $snapshot?->observed_at,
            'stale' => $snapshot?->observed_at?->lt(now()->subHours(24)) ?? true,
            'connection_status' => $connection?->status ?? 'not_connected',
            'source' => 'QuickBooks Online Profit and Loss',
        ];
    }

    /** @param array<string,mixed> $range
     * @return array<string,mixed>
     */
    protected function revenueMix(Tenant $tenant, array $range, ?AccountingProfile $profile): array
    {
        $orders = Order::query()->forTenantId((int) $tenant->id)
            ->whereBetween('ordered_at', [$range['starts_at'], $range['ends_at']])->get();
        $square = SquarePayment::query()->forTenantId((int) $tenant->id)
            ->whereBetween('created_at_source', [$range['starts_at'], $range['ends_at']])
            ->whereIn('status', ['COMPLETED', 'completed'])->get();
        $configuration = (array) ($profile?->configuration ?? []);
        $mappingReviewed = ! empty($configuration['revenue_mappings_reviewed_at']);

        $streams = [
            'wholesale' => $orders->filter(fn (Order $order): bool => strtolower((string) $order->channel) === 'wholesale'),
            'online' => $orders->filter(fn (Order $order): bool => ! in_array(strtolower((string) $order->channel), ['wholesale', 'event'], true)),
        ];
        $operational = [
            'wholesale' => ['amount' => $this->orderTotal($streams['wholesale']), 'count' => $streams['wholesale']->count(), 'source' => 'Shopify and reviewed QuickBooks rules'],
            'online' => ['amount' => $this->orderTotal($streams['online']), 'count' => $streams['online']->count(), 'source' => 'Shopify'],
            'events' => ['amount' => round((float) $square->sum('amount_money') / 100, 2), 'count' => $square->count(), 'source' => 'Square mapped events'],
        ];
        $total = collect($operational)->sum('amount');

        foreach ($operational as $key => $stream) {
            $operational[$key]['percentage'] = $mappingReviewed && $total > 0
                ? round(((float) $stream['amount'] / $total) * 100, 1)
                : null;
            $operational[$key]['reconciliation_status'] = $mappingReviewed ? 'needs_reconciliation' : 'mapping_required';
        }

        return [
            'classification_reviewed' => $mappingReviewed,
            'basis' => 'Operational source coverage only; never added to QuickBooks revenue.',
            'streams' => $operational,
        ];
    }

    protected function orderTotal(Collection $orders): float
    {
        return round((float) $orders->sum(fn (Order $order): float => max(0, (float) $order->total_price - (float) $order->refund_total)), 2);
    }

    /** @return array<string,mixed> */
    protected function payrollPayload(Tenant $tenant, array $metrics): array
    {
        $settings = QuickBooksReportingSetting::query()->forTenantId((int) $tenant->id)->first();
        $reviewed = $settings?->mappings_reviewed_at !== null;
        $lines = collect((array) ($metrics['account_lines'] ?? []));
        $wages = $reviewed ? $this->mappedTotal($lines, (array) $settings?->wage_account_mappings) : null;
        $owner = $reviewed ? $this->mappedTotal($lines, (array) $settings?->owner_compensation_account_mappings) : null;
        $income = is_numeric($metrics['total_income'] ?? null) ? (float) $metrics['total_income'] : null;

        return [
            'mapping_reviewed' => $reviewed,
            'total_cost' => $wages,
            'owner_wages' => $owner,
            'excluding_owner' => $wages !== null && $owner !== null ? max(0, $wages - $owner) : null,
            'percentage_of_revenue' => $wages !== null && $income && $income > 0 ? round(($wages / $income) * 100, 1) : null,
            'included_accounts' => (array) ($settings?->wage_account_mappings ?? []),
            'last_reviewed_at' => $settings?->mappings_reviewed_at,
        ];
    }

    protected function mappedTotal(Collection $lines, array $mappings): ?float
    {
        if ($mappings === []) {
            return null;
        }

        $labels = collect($mappings)->map(fn ($mapping): string => strtolower(trim((string) (is_array($mapping) ? ($mapping['label'] ?? '') : $mapping))))->filter();
        $matched = $lines->filter(fn (array $line): bool => $labels->contains(strtolower(trim((string) ($line['label'] ?? '')))));

        return round((float) $matched->sum('amount'), 2);
    }

    /** @param array<string,mixed> $range
     * @return array<int,array<string,mixed>>
     */
    protected function transactions(Tenant $tenant, array $range): array
    {
        return FieldServiceFinancialDocument::query()->forTenantId((int) $tenant->id)
            ->where('source', 'quickbooks')
            ->whereBetween('transaction_date', [$range['starts_at']->toDateString(), $range['ends_at']->toDateString()])
            ->latest('transaction_date')->latest('id')->limit(30)->get()
            ->map(fn (FieldServiceFinancialDocument $row): array => [
                'id' => (int) $row->id,
                'date' => $row->transaction_date?->toDateString(),
                'type' => $row->document_type,
                'description' => $row->customer_memo ?: $row->document_number ?: ucfirst((string) $row->document_type),
                'amount' => $row->total_amount !== null ? (float) $row->total_amount : null,
                'balance' => $row->balance !== null ? (float) $row->balance : null,
                'status' => $row->status ?: 'posted',
                'source' => 'QuickBooks',
                'needs_review' => data_get($row->metadata, 'quickbooks.job_link_status') === 'needs_review',
            ])->all();
    }

    /** @param array<string,mixed> $range
     * @return array<string,mixed>
     */
    protected function debtPayload(Tenant $tenant, array $range): array
    {
        $snapshots = AccountingDebtSnapshot::query()->forTenantId((int) $tenant->id)
            ->whereBetween('observed_on', [$range['starts_at']->toDateString(), $range['ends_at']->toDateString()])
            ->orderBy('observed_on')->get();
        $latest = $snapshots->groupBy('source_account_id')->map->last()->values();

        return [
            'available' => $latest->isNotEmpty(),
            'total' => $latest->isNotEmpty() ? round((float) $latest->sum('balance'), 2) : null,
            'accounts' => $latest->map(fn (AccountingDebtSnapshot $row): array => [
                'name' => $row->account_name,
                'type' => $row->account_type,
                'balance' => (float) $row->balance,
                'credit_limit' => $row->credit_limit !== null ? (float) $row->credit_limit : null,
                'available_credit' => $row->available_credit !== null ? (float) $row->available_credit : null,
                'observed_on' => $row->observed_on?->toDateString(),
            ])->all(),
            'history_note' => $snapshots->isEmpty()
                ? 'Historical balances are unavailable until reviewed debt mappings begin durable daily snapshots.'
                : null,
        ];
    }

    /** @return array<string,mixed> */
    protected function eventSourcePayload(Tenant $tenant, ?AccountingProfile $profile): array
    {
        $latest = AccountingEventSourceImport::query()->forTenantId((int) $tenant->id)->latest('id')->first();
        $configured = (array) data_get($profile?->configuration, 'event_source', []);

        return [
            'preferred_source' => $configured['preferred_source'] ?? 'google_drive',
            'google_drive_file_id' => $configured['google_drive_file_id'] ?? null,
            'source_url' => $configured['source_url'] ?? null,
            'fallback_filename' => $configured['fallback_filename'] ?? null,
            'status' => $latest?->status ?? ($configured['verification_status'] ?? 'not_connected'),
            'last_imported_at' => $latest?->imported_at,
            'mapping_version' => $latest?->mapping_version,
            'message' => $latest
                ? 'The latest import is retained with source and mapping provenance.'
                : 'The Drive workbook is identified, but its live revision, sheets, columns, and formulas still require connection and mapping review.',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    protected function sourceHealth(
        Tenant $tenant,
        ?IntegrationConnection $quickBooks,
        ?QuickBooksReportingSnapshot $snapshot,
    ): array {
        $lastRun = QuickBooksSyncRun::query()->forTenantId((int) $tenant->id)->latest('started_at')->first();
        $shopifyLatest = Order::query()->forTenantId((int) $tenant->id)->max('updated_at');
        $squareLatest = SquarePayment::query()->forTenantId((int) $tenant->id)->max('synced_at');

        return [
            ['key' => 'quickbooks', 'name' => 'QuickBooks', 'required' => true, 'status' => $quickBooks?->status ?? 'not_connected', 'last_success_at' => $lastRun?->finished_at ?: $snapshot?->observed_at],
            ['key' => 'shopify', 'name' => 'Shopify', 'required' => false, 'status' => $shopifyLatest ? 'available' : 'not_connected', 'last_success_at' => $shopifyLatest],
            ['key' => 'square', 'name' => 'Square', 'required' => false, 'status' => $squareLatest ? 'available' : 'not_connected', 'last_success_at' => $squareLatest],
        ];
    }
}
