<?php

namespace App\Services\Trajectory;

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Trajectory\Space;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Read-only accounting reports. Bank settlements and payroll are support, never additions. */
class ProfitLossReportService
{
    private const SECTIONS = [
        'Income' => ['income', 'Income', 'income_cents'],
        'COGS' => ['cogs', 'Cost of goods sold', 'cogs_cents'],
        'Expenses' => ['expenses', 'Operating expenses', 'expenses_cents'],
        'OtherIncome' => ['other_income', 'Other income', 'other_income_cents'],
        'OtherExpenses' => ['other_expenses', 'Other expenses', 'other_expenses_cents'],
    ];

    public function build(Space $space, CarbonImmutable $start, CarbonImmutable $end, Collection $entries, string $basis = 'accrual'): array
    {
        $basis = strtolower($basis) === 'cash' ? 'cash' : 'accrual';
        $previousEnd = $start->subDay();
        $previousStart = $previousEnd->subDays((int) $start->diffInDays($end));
        $sparkStart = $end->startOfMonth()->subMonths(11);
        $earliest = $start->subYearNoOverflow()->min($previousStart)->min($sparkStart->subYearNoOverflow());
        $connectionIds = IntegrationConnection::query()->forTenantId($space->tenant_id)->where('provider', 'quickbooks')->pluck('id');
        // Load encrypted metrics once. Reading a dashboard never requests a provider
        // import. All history is the already-authorized accounting snapshot cache.
        $snapshots = QuickBooksReportingSnapshot::query()->forTenantId($space->tenant_id)
            ->whereIn('integration_connection_id', $connectionIds)->whereDate('period_start', '>=', $earliest->toDateString())->whereDate('period_end', '<=', $end->toDateString())
            ->orderByDesc('observed_at')->orderByDesc('id')->get()->filter(fn ($row) => $this->validIdentity($row));
        $exact = $snapshots->filter(fn ($row) => $row->period_start->toDateString() === $start->toDateString() && $row->period_end->toDateString() === $end->toDateString());
        $snapshot = $exact->first(fn ($row) => $this->basis($row) === $basis);
        // Historical imports without declared basis can still be displayed as
        // unknown, but cannot establish cash accounting or comparative identity.
        $snapshot ??= $basis === 'accrual' ? $exact->first(fn ($row) => $this->basis($row) === null) : null;
        $ledger = $this->ledger($space, $snapshot);
        $report = app(TaxReviewService::class)->profitLoss($entries, $ledger);
        $metrics = $this->metrics($ledger);
        $period = ['start' => $start->toDateString(), 'end' => $end->toDateString()];
        $currency = $ledger['currency'];
        $identity = $snapshot && $ledger['basis'] && $currency;
        $comparable = $identity ? $snapshots->filter(fn ($row) => $row->integration_connection_id === $snapshot->integration_connection_id
            && $this->basis($row) === $ledger['basis'] && $this->currency($row) === $currency) : collect();
        $comparison = [
            'previous_period' => $this->comparison($comparable, $previousStart, $previousEnd, $metrics, $space),
            'previous_year' => $this->comparison($comparable, $start->subYearNoOverflow(), $end->subYearNoOverflow(), $metrics, $space),
        ];
        $canReadAccounts = $identity && AccountLedgerService::ready($snapshot);
        $statement = $this->statement($snapshot, $metrics, (bool) $canReadAccounts);
        $accountChecks = array_column($statement['sections'], 'accounts_reconcile');
        $detailsComplete = in_array(false, $accountChecks, true) || ($snapshot?->metrics['account_hierarchy_complete'] ?? null) === false
            ? false : ($statement['details_available'] && ! in_array(null, $accountChecks, true) ? true : null);
        $complete = $snapshot && ! in_array(null, array_intersect_key($metrics, array_flip(['income_cents', 'cogs_cents', 'expenses_cents', 'other_income_cents', 'other_expenses_cents', 'net_cents'])), true);
        $reconciles = $complete && $metrics['net_cents'] === $metrics['income_cents'] - $metrics['cogs_cents'] - $metrics['expenses_cents'] + $metrics['other_income_cents'] - $metrics['other_expenses_cents'];
        $operatingReconciles = isset($metrics['gross_profit_cents'], $metrics['expenses_cents'], $ledger['net_operating_income_cents'])
            ? $ledger['net_operating_income_cents'] === $metrics['gross_profit_cents'] - $metrics['expenses_cents'] : null;
        $notes = [];
        if (! $snapshot) {
            $notes[] = 'No saved QuickBooks report matches this exact period and requested accounting basis. Bank settlements are cash support and do not establish recognized revenue.';
        }
        if ($snapshot && ! $identity) {
            $notes[] = 'The saved report does not declare its accounting basis or currency. Comparisons and historical charts remain unavailable until report identity is confirmed.';
        }
        if ($snapshot && ! $complete) {
            $notes[] = 'Some accounting totals are missing. Missing values remain unavailable, including absent legacy cost sections.';
        }
        if ($complete && ! $reconciles) {
            $notes[] = 'Reported section totals do not reconcile to reported net profit. Review the source report; no balancing adjustment has been invented.';
        }
        if (! $statement['details_available']) {
            $notes[] = 'Account hierarchy is unavailable in this saved report. Legacy unsectioned accounts are shown separately without inferred classifications.';
        }
        if ($detailsComplete === false) {
            $notes[] = 'Available account detail does not reconcile to every reported section total. Section totals remain source-reported; the incomplete account drill-down is not a replacement ledger.';
        }
        if ($operatingReconciles === false) {
            $notes[] = 'Reported operating profit does not equal revenue less cost of goods sold and operating expenses. The reported amount is retained and requires source review.';
        }
        $notes[] = 'Bank and native payroll evidence is separate from QuickBooks accounting. Net bank payroll, shipping source charges, settlements, debt principal and owner draws are never added to this statement.';

        return ['ledger' => $ledger, 'profit_loss' => [
            ...$report, ...$metrics, 'authoritative' => (bool) $snapshot, 'complete' => (bool) ($complete && $reconciles && $identity && $detailsComplete !== false && $operatingReconciles !== false),
            'source' => $snapshot ? 'QuickBooks · selected report period' : 'Accounting report unavailable',
            'snapshot_id' => $snapshot?->id, 'period' => $period, 'basis' => $ledger['basis'], 'requested_basis' => $basis, 'currency' => $currency,
            'gross_margin_percent' => $this->percent($metrics['gross_profit_cents'], $metrics['income_cents']),
            'net_margin_percent' => $this->percent($metrics['net_cents'], $metrics['income_cents']),
            'statement' => $statement, 'comparison' => $comparison,
            'history' => $this->history($comparable, $space, $start, $end),
            'sparkline_history' => $this->history($comparable, $space, $sparkStart, $end)['monthly'],
            'capabilities' => ['cash' => $exact->contains(fn ($row) => $this->basis($row) === 'cash'), 'accrual' => $exact->contains(fn ($row) => $this->basis($row) === 'accrual'), 'budget' => false, 'dimensions' => false, 'account_transactions' => $statement['transaction_detail_available'], 'report_refresh' => ProfitLossRefreshService::available($space)],
            'coverage' => ['status' => ! $snapshot ? 'unavailable' : ($complete && $reconciles && $identity && $detailsComplete !== false && $operatingReconciles !== false ? 'complete' : 'partial'), 'notes' => $notes, 'reconciles' => (bool) $reconciles, 'account_details_reconcile' => $detailsComplete, 'operating_profit_reconciles' => $operatingReconciles],
            'insights' => $this->insights($metrics, $comparison, $period),
        ]];
    }

    private function basis(QuickBooksReportingSnapshot $snapshot): ?string
    {
        $value = strtolower((string) ($snapshot->metrics['accounting_method'] ?? data_get($snapshot->metrics, 'source_report.ReportBasis', '')));

        return in_array($value, ['accrual', 'cash'], true) ? $value : null;
    }

    private function validIdentity(QuickBooksReportingSnapshot $snapshot): bool
    {
        $header = (array) ($snapshot->metrics['source_report'] ?? []);

        return (! isset($header['ReportName']) || $header['ReportName'] === 'ProfitAndLoss')
            && (! isset($header['StartPeriod']) || $header['StartPeriod'] === $snapshot->period_start->toDateString())
            && (! isset($header['EndPeriod']) || $header['EndPeriod'] === $snapshot->period_end->toDateString())
            && (! isset($header['ReportBasis']) || ! isset($snapshot->metrics['accounting_method']) || strcasecmp((string) $header['ReportBasis'], (string) $snapshot->metrics['accounting_method']) === 0)
            && (! isset($header['SummarizeColumnsBy']) || $header['SummarizeColumnsBy'] === 'Total');
    }

    private function currency(QuickBooksReportingSnapshot $snapshot): ?string
    {
        $value = data_get($snapshot->metrics, 'source_report.Currency');

        return is_string($value) && preg_match('/^[A-Z]{3}$/D', $value) ? $value : null;
    }

    private function ledger(Space $space, ?QuickBooksReportingSnapshot $snapshot): array
    {
        $metrics = $snapshot?->metrics ?? [];
        $cents = fn ($key) => isset($metrics[$key]) && is_numeric($metrics[$key]) && is_finite((float) $metrics[$key]) ? Money::cents($metrics[$key]) : null;
        $ledger = ['source' => 'QuickBooks', 'snapshot_id' => $snapshot?->id, 'tenant_id' => $space->tenant_id,
            'period_start' => $snapshot?->period_start?->toDateString(), 'period_end' => $snapshot?->period_end?->toDateString(),
            'observed_at' => $snapshot?->observed_at, 'basis' => $snapshot ? $this->basis($snapshot) : null, 'currency' => $snapshot ? $this->currency($snapshot) : null,
            'income_cents' => $cents('total_income'), 'cogs_cents' => $cents('total_cogs'), 'expenses_cents' => $cents('total_expenses'),
            'other_income_cents' => $cents('total_other_income'), 'other_expenses_cents' => $cents('total_other_expenses'),
            'net_operating_income_cents' => $cents('net_operating_income'), 'net_income_cents' => $cents('net_income')];
        $ledger['total_costs_cents'] = isset($ledger['cogs_cents'], $ledger['expenses_cents'], $ledger['other_expenses_cents']) ? $ledger['cogs_cents'] + $ledger['expenses_cents'] + $ledger['other_expenses_cents'] : null;

        return $ledger;
    }

    private function metrics(array $ledger): array
    {
        $metrics = array_intersect_key($ledger, array_flip(['income_cents', 'cogs_cents', 'expenses_cents', 'other_income_cents', 'other_expenses_cents', 'total_costs_cents']));
        $metrics['gross_profit_cents'] = isset($ledger['income_cents'], $ledger['cogs_cents']) ? $ledger['income_cents'] - $ledger['cogs_cents'] : null;
        $metrics['operating_profit_cents'] = $ledger['net_operating_income_cents'] ?? (isset($metrics['gross_profit_cents'], $ledger['expenses_cents']) ? $metrics['gross_profit_cents'] - $ledger['expenses_cents'] : null);
        $metrics['net_cents'] = $ledger['net_income_cents'];

        return $metrics;
    }

    private function percent(?int $value, ?int $base): ?float
    {
        return $value !== null && $base !== null && $base !== 0 ? Money::ratio($value, 10000, $base) / 100 : null;
    }

    private function comparison(Collection $snapshots, CarbonImmutable $start, CarbonImmutable $end, array $current, Space $space): array
    {
        $row = $snapshots->first(fn ($row) => $row->period_start->toDateString() === $start->toDateString() && $row->period_end->toDateString() === $end->toDateString());
        $metrics = $this->metrics($this->ledger($space, $row));
        $changes = [];
        foreach ($current as $key => $value) {
            $old = $metrics[$key] ?? null;
            $difference = $value !== null && $old !== null ? $value - $old : null;
            $changes[$key] = ['amount_cents' => $difference, 'percent' => $old !== null && $old !== 0 && $difference !== null ? Money::ratio($difference, 10000, abs($old)) / 100 : null];
        }

        return ['period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()], 'available' => (bool) $row, 'snapshot_id' => $row?->id, 'metrics' => $metrics, 'changes' => $changes];
    }

    private function statement(?QuickBooksReportingSnapshot $snapshot, array $metrics, bool $canReadAccounts = false): array
    {
        $source = (array) ($snapshot?->metrics['account_sections'] ?? []);
        $available = false;
        $line = function (array $row, int $depth = 0, bool $allowed = false) use (&$line, &$available): array {
            $supported = $allowed && empty($row['children']) && preg_match('/^[0-9]{1,30}$/D', (string) ($row['id'] ?? '')) && isset($row['amount']) && is_numeric($row['amount']);
            $available = $available || $supported;

            return ['key' => (string) ($row['key'] ?? ''), 'id' => $row['id'] ?? null, 'label' => (string) ($row['label'] ?? ''),
                'amount_cents' => isset($row['amount']) && is_numeric($row['amount']) ? Money::cents($row['amount']) : null,
                'depth' => $depth, 'source' => 'QuickBooks', 'transaction_detail_available' => (bool) $supported,
                'children' => array_map(fn ($child) => $line($child, $depth + 1, $allowed), (array) ($row['children'] ?? []))];
        };
        $sections = [];
        $unclassified = [];
        foreach (self::SECTIONS as $group => [$key, $label, $metric]) {
            $matching = array_values(array_filter($source, fn ($row) => ($row['group'] ?? null) === $group));
            $lines = [];
            foreach ($matching as $section) {
                foreach ((array) ($section['children'] ?? []) as $child) {
                    $lines[] = $line($child, 0, $canReadAccounts);
                }
            }
            $leafTotal = $this->leafTotal($lines);
            $sections[] = ['key' => $key, 'label' => $label, 'total_cents' => $metrics[$metric], 'lines' => $lines,
                'account_total_cents' => $leafTotal, 'accounts_reconcile' => $leafTotal !== null && $metrics[$metric] !== null ? $leafTotal === $metrics[$metric] : null];
        }
        foreach ($source as $row) {
            if (! isset(self::SECTIONS[$row['group'] ?? '']) && ! in_array($row['group'] ?? '', ['GrossProfit', 'NetOperatingIncome', 'NetOtherIncome', 'NetIncome'], true)) {
                $unclassified[] = $line($row);
            }
        }
        if ($source === []) {
            foreach ((array) ($snapshot?->metrics['account_lines'] ?? []) as $index => $row) {
                $unclassified[] = $line(['key' => 'legacy.'.$index, ...$row]);
            }
        }

        return ['sections' => $sections, 'unclassified_lines' => $unclassified, 'details_available' => $source !== [], 'transaction_detail_available' => (bool) $available];
    }

    private function leafTotal(array $lines): ?int
    {
        if ($lines === []) {
            return null;
        }
        $total = 0;
        foreach ($lines as $line) {
            $value = $line['children'] ? $this->leafTotal($line['children']) : $line['amount_cents'];
            if ($value === null) {
                return null;
            }
            $total += $value;
        }

        return $total;
    }

    private function history(Collection $snapshots, Space $space, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $history = [];
        foreach (['monthly' => 'Month', 'quarterly' => 'Quarter', 'annual' => 'Year'] as $key => $unit) {
            $cursor = $start->{'startOf'.$unit}();
            // Bound output for very long custom histories; never fill missing data
            // from adjacent or overlapping reports, nor extrapolate a partial month.
            $cursor = $cursor->max($end->{'startOf'.$unit}()->{'sub'.$unit.'s'}(23));
            $points = [];
            while ($cursor->lte($end) && count($points) < 24) {
                $through = $cursor->{'endOf'.$unit}()->startOfDay()->min($end);
                $from = $cursor->max($start);
                $row = $snapshots->first(fn ($row) => $row->period_start->toDateString() === $from->toDateString() && $row->period_end->toDateString() === $through->toDateString());
                $ledger = $this->ledger($space, $row);
                $metrics = $this->metrics($ledger);
                $sourceIds = $row ? [$row->id] : [];
                if (! $row && $key !== 'monthly') {
                    $months = [];
                    $month = $from;
                    while ($month->lte($through) && count($months) < 12) {
                        $monthEnd = $month->endOfMonth()->startOfDay()->min($through);
                        $part = $snapshots->first(fn ($candidate) => $candidate->period_start->toDateString() === $month->toDateString() && $candidate->period_end->toDateString() === $monthEnd->toDateString());
                        if (! $part) {
                            $months = [];
                            break;
                        }
                        $months[] = $part;
                        $month = $monthEnd->addDay();
                    }
                    if ($months !== []) {
                        $parts = array_map(fn ($part) => $this->metrics($this->ledger($space, $part)), $months);
                        foreach (array_keys($metrics) as $metric) {
                            $values = array_column($parts, $metric);
                            $metrics[$metric] = in_array(null, $values, true) ? null : array_sum($values);
                        }
                        $sourceIds = array_map(fn ($part) => $part->id, $months);
                        $ledger = $this->ledger($space, $months[0]);
                    }
                }
                $complete = $sourceIds !== [] && ! in_array(null, $metrics, true)
                    && $metrics['net_cents'] === $metrics['income_cents'] - $metrics['cogs_cents'] - $metrics['expenses_cents'] + $metrics['other_income_cents'] - $metrics['other_expenses_cents'];
                $previousEnd = $from->subDay();
                $previousStart = $previousEnd->subDays((int) $from->diffInDays($through));
                $comparisons = [
                    'previous_period' => $this->comparison($snapshots, $previousStart, $previousEnd, $metrics, $space),
                    'previous_year' => $this->comparison($snapshots, $from->subYearNoOverflow(), $through->subYearNoOverflow(), $metrics, $space),
                ];
                $points[] = ['period' => ['start' => $from->toDateString(), 'end' => $through->toDateString()],
                    'label' => $key === 'monthly' ? $cursor->format('M Y') : ($key === 'quarterly' ? 'Q'.$cursor->quarter.' '.$cursor->year : $cursor->format('Y')),
                    'snapshot_id' => $row?->id, 'source_snapshot_ids' => $sourceIds, 'available' => $sourceIds !== [], 'complete' => $complete,
                    'basis' => $ledger['basis'], 'currency' => $ledger['currency'], 'comparison' => $comparisons, ...$metrics];
                $cursor = $cursor->{'add'.$unit}()->{'startOf'.$unit}();
            }
            $history[$key] = $points;
        }

        return $history;
    }

    private function insights(array $metrics, array $comparison, array $period): array
    {
        $facts = [];
        if (isset($metrics['income_cents'], $metrics['cogs_cents'])) {
            $facts[] = ['title' => 'Gross profit', 'detail' => 'Revenue less cost of goods sold for the selected QuickBooks period.', 'kind' => 'fact',
                'figures' => ['period' => $period, 'income_cents' => $metrics['income_cents'], 'cogs_cents' => $metrics['cogs_cents'], 'gross_profit_cents' => $metrics['gross_profit_cents']]];
        }
        $prior = $comparison['previous_period'];
        if ($prior['available'] && $prior['changes']['net_cents']['amount_cents'] !== null) {
            $facts[] = ['title' => 'Change in net profit', 'detail' => 'Difference from the immediately preceding period of equal day count. These figures establish the change, not its business cause.', 'kind' => 'fact',
                'figures' => ['current_period' => $period, 'comparison_period' => $prior['period'], 'current_net_cents' => $metrics['net_cents'], 'previous_net_cents' => $prior['metrics']['net_cents'], 'change_cents' => $prior['changes']['net_cents']['amount_cents']]];
        }

        return $facts;
    }
}
