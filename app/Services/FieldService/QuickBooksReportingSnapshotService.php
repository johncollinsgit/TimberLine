<?php

namespace App\Services\FieldService;

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use App\Services\Integrations\QuickBooks\QuickBooksOnlineClient;
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
    ): QuickBooksReportingSnapshot {
        $accountingMethod = strcasecmp($accountingMethod, 'cash') === 0 ? 'Cash' : 'Accrual';
        $payload = $client->report('ProfitAndLoss', [
            'accounting_method' => $accountingMethod,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);
        $rows = $this->reportRows($payload);

        return QuickBooksReportingSnapshot::query()->updateOrCreate(
            [
                'tenant_id' => (int) $tenant->id,
                'range_key' => $rangeKey,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ],
            [
                'integration_connection_id' => (int) $connection->id,
                'metrics' => [
                    'accounting_method' => $accountingMethod,
                    'total_income' => $this->namedTotal($rows, ['total income', 'total revenue']),
                    'total_expenses' => $this->namedTotal($rows, ['total expenses', 'total expense']),
                    'net_income' => $this->namedTotal($rows, ['net income']),
                    'account_lines' => array_values(array_filter($rows, fn (array $row): bool => $row['row_type'] === 'data')),
                    'mapping_suggestions' => $this->mappingSuggestions($rows),
                ],
                'observed_at' => now(),
            ]
        );
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
                $amount = collect($columns)->pluck('value')->last(fn (mixed $value): bool => is_numeric($value));
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
        $row = collect($rows)->first(fn (array $row): bool => in_array($row['normalized_label'], $names, true));

        return $row ? (float) $row['amount'] : null;
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
