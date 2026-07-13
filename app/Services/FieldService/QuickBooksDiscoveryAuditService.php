<?php

namespace App\Services\FieldService;

use App\Models\IntegrationConnection;
use App\Models\QuickBooksAuditRun;
use App\Models\QuickBooksSourceRecord;
use App\Models\Tenant;
use App\Services\Integrations\QuickBooks\QuickBooksOnlineClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class QuickBooksDiscoveryAuditService
{
    /** @var array<int,string> */
    public const CORE_ENTITIES = ['Customer', 'Invoice', 'Estimate', 'Item'];

    /** @var array<int,string> */
    public const FULL_ENTITIES = [
        'CompanyInfo', 'Preferences', 'Customer', 'Vendor', 'Employee', 'Item', 'Account',
        'Estimate', 'Invoice', 'SalesReceipt', 'Payment', 'CreditMemo', 'RefundReceipt',
        'Bill', 'Purchase', 'PurchaseOrder', 'BillPayment', 'VendorCredit', 'TimeActivity',
        'Attachable',
    ];

    /** @return array<string,mixed> */
    public function audit(
        Tenant $tenant,
        IntegrationConnection $connection,
        QuickBooksOnlineClient $client,
        bool $full = false,
        bool $dryRun = true
    ): array {
        $startedAt = now();
        $run = $dryRun ? null : QuickBooksAuditRun::query()->create([
            'tenant_id' => (int) $tenant->id,
            'integration_connection_id' => (int) $connection->id,
            'status' => 'running',
            'dry_run' => false,
            'started_at' => $startedAt,
        ]);

        $summary = $this->emptySummary($full, $dryRun);
        $lineStats = [];

        foreach ($full ? self::FULL_ENTITIES : self::CORE_ENTITIES as $entity) {
            try {
                $rows = $client->all($entity);
                $summary['entity_counts'][$entity] = count($rows);
                $this->profileRows($summary, $lineStats, $entity, $rows);

                if (! $dryRun) {
                    $this->storeSourceRows($tenant, $connection, $entity, $rows);
                }
            } catch (Throwable $exception) {
                $summary['entity_counts'][$entity] = null;
                $summary['errors'][$entity] = $this->safeError($exception);
            }
        }

        if ($full) {
            $this->profileReports($summary, $client);
        }

        $summary['price_patterns'] = $this->pricePatterns($lineStats);
        $summary['recommendations'] = $this->recommendations($summary);
        $summary['finished_at'] = now()->toIso8601String();

        if ($run instanceof QuickBooksAuditRun) {
            $run->forceFill([
                'status' => $summary['errors'] === [] ? 'completed' : 'completed_with_errors',
                'summary' => Arr::except($summary, ['errors']),
                'errors' => $summary['errors'],
                'finished_at' => now(),
            ])->save();
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    protected function emptySummary(bool $full, bool $dryRun): array
    {
        return [
            'mode' => $dryRun ? 'dry-run' : 'snapshot',
            'scope' => $full ? 'full' : 'core',
            'entity_counts' => [],
            'note_coverage' => [
                'invoices_with_private_notes' => 0,
                'invoices_with_customer_memos' => 0,
                'invoices_with_line_descriptions' => 0,
                'estimates_with_private_notes' => 0,
                'estimates_with_customer_memos' => 0,
                'estimates_with_line_descriptions' => 0,
            ],
            'financials' => [
                'invoice_total' => 0.0,
                'invoice_balance' => 0.0,
                'estimate_total' => 0.0,
                'sales_receipt_total' => 0.0,
                'purchase_total' => 0.0,
                'bill_total' => 0.0,
            ],
            'customer_completeness' => [
                'total' => 0,
                'with_email' => 0,
                'with_phone' => 0,
                'with_address' => 0,
            ],
            'linked_transactions' => 0,
            'attachment_links' => 0,
            'price_patterns' => [],
            'report_totals' => [],
            'report_periods' => [],
            'labor_signals' => [
                'profit_and_loss_wage_lines' => 0,
                'profit_and_loss_wages_total' => 0.0,
                'profit_and_loss_contract_labor_lines' => 0,
                'profit_and_loss_contract_labor_total' => 0.0,
            ],
            'recommendations' => [],
            'errors' => [],
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];
    }

    /** @param array<string,mixed> $summary
     * @param  array<string,array{count:int,total:float,min:float,max:float,name:string}>  $lineStats
     * @param  array<int,array<string,mixed>>  $rows
     */
    protected function profileRows(array &$summary, array &$lineStats, string $entity, array $rows): void
    {
        if ($entity === 'Customer') {
            $summary['customer_completeness']['total'] = count($rows);
            foreach ($rows as $row) {
                $summary['customer_completeness']['with_email'] += filled(data_get($row, 'PrimaryEmailAddr.Address')) ? 1 : 0;
                $summary['customer_completeness']['with_phone'] += filled(data_get($row, 'PrimaryPhone.FreeFormNumber')) ? 1 : 0;
                $summary['customer_completeness']['with_address'] += filled(data_get($row, 'BillAddr.Line1')) ? 1 : 0;
            }
        }

        if (in_array($entity, ['Invoice', 'Estimate'], true)) {
            $prefix = strtolower($entity).'s';
            foreach ($rows as $row) {
                $summary['note_coverage'][$prefix.'_with_private_notes'] += filled($row['PrivateNote'] ?? null) ? 1 : 0;
                $summary['note_coverage'][$prefix.'_with_customer_memos'] += filled(data_get($row, 'CustomerMemo.value')) ? 1 : 0;
                $hasDescriptions = collect((array) ($row['Line'] ?? []))
                    ->contains(fn (mixed $line): bool => filled(data_get($line, 'Description')));
                $summary['note_coverage'][$prefix.'_with_line_descriptions'] += $hasDescriptions ? 1 : 0;
                $summary['linked_transactions'] += count((array) ($row['LinkedTxn'] ?? []));
                $this->profileTransactionLines($lineStats, (array) ($row['Line'] ?? []));
            }
        }

        $financialKey = match ($entity) {
            'Invoice' => 'invoice_total',
            'Estimate' => 'estimate_total',
            'SalesReceipt' => 'sales_receipt_total',
            'Purchase' => 'purchase_total',
            'Bill' => 'bill_total',
            default => null,
        };
        if ($financialKey !== null) {
            $summary['financials'][$financialKey] = round(collect($rows)->sum(fn (array $row): float => (float) ($row['TotalAmt'] ?? 0)), 2);
        }
        if ($entity === 'Invoice') {
            $summary['financials']['invoice_balance'] = round(collect($rows)->sum(fn (array $row): float => (float) ($row['Balance'] ?? 0)), 2);
        }
        if ($entity === 'Attachable') {
            $summary['attachment_links'] = collect($rows)->sum(fn (array $row): int => count((array) ($row['AttachableRef'] ?? [])));
        }
    }

    /** @param array<string,array{count:int,total:float,min:float,max:float,name:string}> $lineStats
     * @param  array<int,mixed>  $lines
     */
    protected function profileTransactionLines(array &$lineStats, array $lines): void
    {
        foreach ($lines as $line) {
            if (! is_array($line) || (string) ($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue;
            }

            $name = trim((string) (data_get($line, 'SalesItemLineDetail.ItemRef.name') ?: $line['Description'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
            $amount = (float) ($line['Amount'] ?? 0);
            $quantity = max(1.0, (float) data_get($line, 'SalesItemLineDetail.Qty', 1));
            $unitPrice = is_numeric(data_get($line, 'SalesItemLineDetail.UnitPrice'))
                ? (float) data_get($line, 'SalesItemLineDetail.UnitPrice')
                : $amount / $quantity;

            $current = $lineStats[$key] ?? ['count' => 0, 'total' => 0.0, 'min' => $unitPrice, 'max' => $unitPrice, 'name' => $name];
            $current['count']++;
            $current['total'] += $unitPrice;
            $current['min'] = min($current['min'], $unitPrice);
            $current['max'] = max($current['max'], $unitPrice);
            $lineStats[$key] = $current;
        }
    }

    /** @param array<string,array{count:int,total:float,min:float,max:float,name:string}> $lineStats
     * @return array<int,array{name:string,count:int,average_unit_price:float,min_unit_price:float,max_unit_price:float}>
     */
    protected function pricePatterns(array $lineStats): array
    {
        return collect($lineStats)
            ->filter(fn (array $row): bool => $row['count'] >= 2)
            ->sortByDesc('count')
            ->take(25)
            ->map(fn (array $row): array => [
                'name' => $row['name'],
                'count' => $row['count'],
                'average_unit_price' => round($row['total'] / $row['count'], 2),
                'min_unit_price' => round($row['min'], 2),
                'max_unit_price' => round($row['max'], 2),
            ])
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $summary */
    protected function profileReports(array &$summary, QuickBooksOnlineClient $client): void
    {
        foreach (['ProfitAndLoss', 'AgedReceivables'] as $report) {
            try {
                $today = now()->toDateString();
                $parameters = $report === 'ProfitAndLoss'
                    ? ['accounting_method' => 'Accrual', 'start_date' => now()->startOfYear()->toDateString(), 'end_date' => $today]
                    : ['accounting_method' => 'Accrual', 'report_date' => $today];
                $payload = $client->report($report, $parameters);
                $summary['report_totals'][$report] = $this->reportTotal($payload);
                $summary['report_periods'][$report] = $parameters;
                if ($report === 'ProfitAndLoss') {
                    $contractLaborPattern = '/\b(contract( labor| labour)?|subcontract(or|ors|ed|ing)?|outside labor|outside labour)\b/i';
                    $contractLabor = $this->matchingReportLines($payload, $contractLaborPattern);
                    $wages = $this->matchingReportLines(
                        $payload,
                        '/\b(wages?|payroll|employee labor|employee labour)\b/i',
                        $contractLaborPattern
                    );
                    $summary['labor_signals']['profit_and_loss_wage_lines'] = count($wages);
                    $summary['labor_signals']['profit_and_loss_wages_total'] = round(array_sum($wages), 2);
                    $summary['labor_signals']['profit_and_loss_contract_labor_lines'] = count($contractLabor);
                    $summary['labor_signals']['profit_and_loss_contract_labor_total'] = round(array_sum($contractLabor), 2);
                }
            } catch (Throwable $exception) {
                $summary['errors']['report:'.$report] = $this->safeError($exception);
            }
        }
    }

    /** @param array<string,mixed> $payload */
    protected function reportTotal(array $payload): ?float
    {
        $columns = (array) data_get($payload, 'Rows.Row', []);
        $values = collect($columns)->flatMap(function (mixed $row): array {
            if (! is_array($row)) {
                return [];
            }

            return collect((array) data_get($row, 'Summary.ColData', []))
                ->pluck('value')
                ->filter(fn (mixed $value): bool => is_numeric($value))
                ->map(fn (mixed $value): float => (float) $value)
                ->all();
        });

        return $values->isEmpty() ? null : round((float) $values->last(), 2);
    }

    /** @return array<int,float> */
    protected function matchingReportLines(array $payload, string $pattern, ?string $excludePattern = null): array
    {
        $matches = [];
        $walk = function (mixed $node) use (&$walk, &$matches, $pattern, $excludePattern): void {
            if (! is_array($node)) {
                return;
            }

            $columns = (array) ($node['ColData'] ?? []);
            $label = trim((string) data_get($columns, '0.value', ''));
            $amount = collect($columns)->pluck('value')->last(fn (mixed $value): bool => is_numeric($value));
            if ($label !== ''
                && preg_match($pattern, $label) === 1
                && ($excludePattern === null || preg_match($excludePattern, $label) !== 1)
                && is_numeric($amount)) {
                $matches[] = (float) $amount;
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($payload);

        return $matches;
    }

    /** @param array<int,array<string,mixed>> $rows */
    protected function storeSourceRows(Tenant $tenant, IntegrationConnection $connection, string $entity, array $rows): void
    {
        foreach ($rows as $row) {
            $externalId = trim((string) ($row['Id'] ?? ''));
            if ($externalId === '') {
                $externalId = hash_hmac('sha256', json_encode($row) ?: '', (string) config('app.key'));
            }

            QuickBooksSourceRecord::query()->updateOrCreate(
                [
                    'tenant_id' => (int) $tenant->id,
                    'integration_connection_id' => (int) $connection->id,
                    'entity_type' => $entity,
                    'external_id' => $externalId,
                ],
                [
                    'payload' => $this->sanitizePayload($row),
                    'source_updated_at' => data_get($row, 'MetaData.LastUpdatedTime'),
                    'observed_at' => now(),
                ]
            );
        }
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function sanitizePayload(array $payload): array
    {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            if (preg_match('/tax.?identifier|social.?security|ssn|bank.?account|routing.?(number|num)?/i', (string) $key)) {
                continue;
            }
            $sanitized[$key] = is_array($value) ? $this->sanitizePayload($value) : $value;
        }

        return $sanitized;
    }

    /** @return array{code:string,status:?int} */
    protected function safeError(Throwable $exception): array
    {
        return [
            'code' => class_basename($exception),
            'status' => $exception instanceof RequestException ? $exception->response->status() : null,
        ];
    }

    /** @param array<string,mixed> $summary
     * @return array<int,array{key:string,title:string,reason:string}>
     */
    protected function recommendations(array $summary): array
    {
        $recommendations = [];
        $counts = $summary['entity_counts'];
        if (($counts['Estimate'] ?? 0) > 0) {
            $recommendations[] = ['key' => 'quote_follow_up', 'title' => 'Quote follow-up', 'reason' => 'QuickBooks contains estimates that can drive a pending-quote queue.'];
        }
        if (($summary['price_patterns'] ?? []) !== []) {
            $recommendations[] = ['key' => 'estimator', 'title' => 'Estimator Branch', 'reason' => 'Repeated priced lines can seed a Collins-specific price book.'];
        }
        if (($summary['financials']['invoice_balance'] ?? 0) > 0) {
            $recommendations[] = ['key' => 'open_receivables', 'title' => 'Open receivables', 'reason' => 'Outstanding invoice balances are present.'];
        }
        if (($counts['Purchase'] ?? 0) > 0 || ($counts['Bill'] ?? 0) > 0) {
            $recommendations[] = ['key' => 'supplies_spend', 'title' => 'Supplies and vendor spend', 'reason' => 'Purchases or bills can support cost visibility.'];
        }
        if (($counts['TimeActivity'] ?? 0) > 0) {
            $recommendations[] = ['key' => 'labor_utilization', 'title' => 'Labor utilization', 'reason' => 'Time activities are available for job-level labor analysis.'];
        }
        if (($summary['labor_signals']['profit_and_loss_wage_lines'] ?? 0) > 0) {
            $recommendations[] = ['key' => 'labor_cost_summary', 'title' => 'Owner labor-cost summary', 'reason' => 'Profit and loss contains aggregate wage or labor lines; keep this owner/admin-only and do not infer employee payroll records.'];
        }
        if (($summary['labor_signals']['profit_and_loss_contract_labor_lines'] ?? 0) > 0) {
            $recommendations[] = ['key' => 'contract_labor_summary', 'title' => 'Owner contract-labor summary', 'reason' => 'Profit and loss contains contract-labor or subcontractor costs; show year-to-date amount and percentage of revenue to owner/admin users only.'];
        }
        $recommendations[] = ['key' => 'quickbooks_sync_health', 'title' => 'QuickBooks sync health', 'reason' => 'Show connection, audit, sync, and review status before crews rely on imported data.'];

        return $recommendations;
    }
}
