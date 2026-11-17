<?php

namespace App\Services\FieldService;

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use App\Services\Integrations\QuickBooks\QuickBooksOnlineClient;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class QuickBooksReportingSnapshotService
{
    public function refresh(
        Tenant $tenant,
        IntegrationConnection $connection,
        QuickBooksOnlineClient $client,
        string $rangeKey,
        CarbonInterface $start,
        CarbonInterface $end,
        string $accountingMethod = 'Accrual',
        ?callable $beforePersist = null,
    ): QuickBooksReportingSnapshot {
        abort_unless($connection->tenant_id === (int) $tenant->id && $connection->provider === 'quickbooks', 422, 'Use this workspace’s QuickBooks connection.');
        abort_if($start->gt($end), 422, 'The report period must end on or after its start.');
        $accountingMethod = strcasecmp($accountingMethod, 'cash') === 0 ? 'Cash' : 'Accrual';
        // Existing accrual cache keys stay compatible. Cash reports must never
        // overwrite the same period's accrual report through its legacy key.
        if ($accountingMethod === 'Cash') {
            $rangeKey = substr($rangeKey, 0, 26).':'.substr(hash('sha256', $rangeKey), 0, 8).':cash';
        }
        $payload = $client->report('ProfitAndLoss', [
            'accounting_method' => $accountingMethod,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);
        $header = (array) ($payload['Header'] ?? []);
        abort_if(isset($header['StartPeriod']) && $header['StartPeriod'] !== $start->toDateString()
            || isset($header['EndPeriod']) && $header['EndPeriod'] !== $end->toDateString()
            || isset($header['ReportName']) && $header['ReportName'] !== 'ProfitAndLoss'
            || isset($header['ReportBasis']) && strcasecmp((string) $header['ReportBasis'], $accountingMethod) !== 0,
            422, 'QuickBooks returned a different report period or accounting basis.');
        $rows = $this->unsupportedColumns($payload) ? [] : $this->reportRows($payload);
        $totals = $this->reportTotals($payload, $rows);
        if ($beforePersist !== null) {
            $beforePersist($payload);
        }

        return QuickBooksReportingSnapshot::query()
            ->whereDate('period_start', $start->toDateString())->whereDate('period_end', $end->toDateString())->updateOrCreate(
                [
                    'tenant_id' => (int) $tenant->id,
                    'range_key' => $rangeKey,
                ],
                [
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'integration_connection_id' => (int) $connection->id,
                    'metrics' => [
                        'accounting_method' => $accountingMethod,
                        ...$totals,
                        'source_report' => array_intersect_key($header, array_flip(['Time', 'ReportName', 'ReportBasis', 'StartPeriod', 'EndPeriod', 'Currency', 'SummarizeColumnsBy'])),
                        'account_lines' => array_values(array_filter($rows, fn (array $row): bool => $row['row_type'] === 'data')),
                        // Provider section identity is accounting evidence. Keep the
                        // original hierarchy rather than guessing from account names.
                        'account_sections' => $this->unsupportedColumns($payload) ? [] : $this->accountSections($payload),
                        'mapping_suggestions' => $this->mappingSuggestions($rows),
                    ],
                    'observed_at' => now(),
                ]
            );
    }

    protected function accountSections(array $payload): array
    {
        $walk = function (array $nodes, string $path = '') use (&$walk): array {
            $result = [];
            foreach ($nodes as $index => $node) {
                if (! is_array($node)) {
                    continue;
                }
                $columns = (array) ($node['ColData'] ?? data_get($node, 'Header.ColData', []));
                $summary = (array) data_get($node, 'Summary.ColData', []);
                $label = trim((string) data_get($columns, '0.value', data_get($summary, '0.value', '')));
                $amount = data_get($node, 'type') === 'Data' || isset($node['ColData'])
                    ? data_get($columns, '1.value') : data_get($summary, '1.value');
                $key = $path === '' ? (string) $index : $path.'.'.$index;
                $result[] = [
                    'key' => $key, 'id' => trim((string) data_get($columns, '0.id', '')) ?: null,
                    'label' => $label, 'group' => $node['group'] ?? null,
                    'amount' => $this->numeric($amount) ? round((float) $amount, 2) : null,
                    'children' => $walk((array) data_get($node, 'Rows.Row', []), $key),
                ];
            }

            return $result;
        };

        return $walk((array) data_get($payload, 'Rows.Row', []));
    }

    /** @return array<int,array{id:?string,label:string,normalized_label:string,amount:float,row_type:string}> */
    protected function reportRows(array $payload): array
    {
        $rows = [];
        $walk = function (mixed $node) use (&$walk, &$rows): void {
            if (! is_array($node)) {
                return;
            }

            foreach (['ColData' => 'data', 'Summary' => 'summary'] as $key => $rowType) {
                $columns = $key === 'Summary'
                    ? (array) data_get($node, 'Summary.ColData', [])
                    : (array) ($node['ColData'] ?? []);
                $label = trim((string) data_get($columns, '0.value', ''));
                $amount = collect(array_slice($columns, 1))->pluck('value')->last(fn (mixed $value): bool => $this->numeric($value));
                if ($label !== '' && is_numeric($amount)) {
                    $rows[] = [
                        'id' => trim((string) data_get($columns, '0.id', '')) ?: null,
                        'label' => $label,
                        'normalized_label' => $this->normalize($label),
                        'amount' => round((float) $amount, 2),
                        'row_type' => $rowType,
                    ];
                }
            }

            foreach ($node as $key => $value) {
                if ($key !== 'Summary' && is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk((array) data_get($payload, 'Rows.Row', []));

        return collect($rows)
            ->unique(fn (array $row): string => implode('|', [$row['row_type'], $row['id'], $row['normalized_label'], $row['amount']]))
            ->values()
            ->all();
    }

    /** @param array<int,array{id:?string,label:string,normalized_label:string,amount:float,row_type:string}> $rows */
    protected function namedTotal(array $rows, array $names): ?float
    {
        $matches = collect($rows)->filter(fn (array $row): bool => $row['row_type'] === 'summary' && in_array($row['normalized_label'], $names, true));
        $row = $matches->pluck('amount')->uniqueStrict()->count() === 1 ? $matches->first() : null;

        return $row ? (float) $row['amount'] : null;
    }

    /** Top-level report sections are distinct from account details and subtotals. */
    protected function reportTotals(array $payload, array $rows): array
    {
        if ($this->unsupportedColumns($payload)) {
            return array_fill_keys(['total_income', 'total_cogs', 'total_expenses', 'total_other_income', 'total_other_expenses', 'total_costs', 'net_operating_income', 'net_income'], null)
                + ['cost_components_complete' => false];
        }
        $groups = collect((array) data_get($payload, 'Rows.Row', []))->filter(fn ($row) => is_array($row) && is_string($row['group'] ?? null))->groupBy('group');
        $amount = function (string $group, array $names = []) use ($groups, $rows): ?float {
            if (! $groups->has($group)) {
                return $names ? $this->namedTotal($rows, $names) : null;
            }
            $values = $groups[$group]->map(function ($row): mixed {
                $columns = (array) data_get($row, 'Summary.ColData', []);

                return collect(array_slice($columns, 1))->pluck('value')->last(fn ($value) => $this->numeric($value));
            });
            if ($values->contains(null) || $values->map(fn ($value) => round((float) $value, 2))->uniqueStrict()->count() !== 1) {
                return null;
            }

            return round((float) $values->first(), 2);
        };
        $net = $amount('NetIncome', ['net income']);
        $header = (array) ($payload['Header'] ?? []);
        $singleTotal = ($header['SummarizeColumnsBy'] ?? null) === 'Total';
        $fullReport = ($header['ReportName'] ?? null) === 'ProfitAndLoss'
            && isset($header['StartPeriod'], $header['EndPeriod'], $header['ReportBasis'])
            && $singleTotal && $groups->has('NetIncome') && $net !== null;
        if ($fullReport) {
            $expectedNet = BigDecimal::of(0);
            foreach (['Income' => 1, 'COGS' => -1, 'Expenses' => -1, 'OtherIncome' => 1, 'OtherExpenses' => -1] as $group => $sign) {
                $value = $groups->has($group) ? $amount($group) : 0.0;
                if ($value === null) {
                    $fullReport = false;

                    break;
                }
                $expectedNet = $expectedNet->plus(BigDecimal::of((string) $value)->multipliedBy($sign));
            }
            $fullReport = $fullReport && $expectedNet->isEqualTo((string) $net);
        }
        $optional = function (string $group, array $names) use ($amount, $groups, $fullReport): ?float {
            if (! $groups->has($group) && $fullReport) {
                return 0.0;
            }
            $value = $amount($group, $names);

            // Only a complete single-total source report establishes that an absent
            // optional section is zero. A malformed present section remains unknown.
            return $value;
        };
        $cogs = $optional('COGS', ['total cost of goods sold']);
        $expenses = $optional('Expenses', ['total expenses', 'total expense']);
        $otherExpenses = $optional('OtherExpenses', ['total other expenses', 'total other expense']);
        $costsComplete = $cogs !== null && $expenses !== null && $otherExpenses !== null;

        return [
            'total_income' => $optional('Income', ['total income', 'total revenue']),
            'total_cogs' => $cogs, 'total_expenses' => $expenses,
            'total_other_income' => $optional('OtherIncome', ['total other income']),
            'total_other_expenses' => $otherExpenses,
            'total_costs' => $costsComplete ? BigDecimal::of((string) $cogs)->plus((string) $expenses)->plus((string) $otherExpenses)->toFloat() : null,
            'cost_components_complete' => $costsComplete,
            'net_operating_income' => $amount('NetOperatingIncome', ['net operating income']),
            'net_income' => $net,
        ];
    }

    private function numeric(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value))
            && preg_match('/^-?\d+(?:\.\d+)?$/', (string) $value) === 1 && is_finite((float) $value);
    }

    private function unsupportedColumns(array $payload): bool
    {
        $summary = data_get($payload, 'Header.SummarizeColumnsBy');

        return $summary !== null && $summary !== 'Total' || count((array) data_get($payload, 'Columns.Column', [])) > 2;
    }

    /** @param array<int,array{id:?string,label:string,normalized_label:string,amount:float,row_type:string}> $rows
     * @return array<string,array<int,array{id:?string,label:string}>>
     */
    protected function mappingSuggestions(array $rows): array
    {
        $patterns = [
            'supplies' => '/\b(suppl|material|parts?|job cost|cost of goods)\b/i',
            'wages' => '/\b(wages?|payroll|employee labor|employee labour)\b/i',
            'contract_labor' => '/\b(contract( labor| labour)?|subcontract|outside labor|outside labour)\b/i',
            'owner_compensation' => '/\b(owner|officer|shareholder|nathan)\b/i',
        ];

        return collect($patterns)->map(function (string $pattern) use ($rows): array {
            return collect($rows)
                ->filter(fn (array $row): bool => $row['row_type'] === 'data' && preg_match($pattern, $row['label']) === 1)
                ->map(fn (array $row): array => ['id' => $row['id'], 'label' => $row['label']])
                ->unique(fn (array $row): string => (string) ($row['id'] ?: $row['label']))
                ->values()
                ->all();
        })->all();
    }

    protected function normalize(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}
