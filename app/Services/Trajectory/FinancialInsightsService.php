<?php

namespace App\Services\Trajectory;

/** Deterministic private assistant answers. Accounting snapshots never leave the app. */
class FinancialInsightsService
{
    public function respondsTo(string $question, array $data): bool
    {
        return ($data['space']['kind'] ?? null) === 'business' && empty($data['scope_filter']['account_id'])
            && ! preg_match('/\b(?:set|change|update|reduce|increase|cut)\b.*\b(?:budget|spending target)\b/i', $question)
            && (bool) preg_match('/profit|gross\s*margin|net\s*margin|payroll|recognized\s*(?:income|revenue)|revenue|expense\s*categor|operating\s*(?:income|expense)|financial\s*insight/i', $question);
    }

    public function answer(string $question, array $data): array
    {
        $report = $data['business']['profit_loss'] ?? [];
        $currency = $report['currency'] ?? null;
        $money = fn (?int $value): string => app(ProfitLossExportService::class)->amount($value, $currency);
        $period = $report['period'] ?? $data['range'];
        $reply = ($report['source'] ?? 'Accounting report unavailable').' for '.$period['start'].' through '.$period['end'].'. ';
        $reply .= ($report['basis'] ?? null) ? ucfirst($report['basis']).' accounting basis. ' : 'Accounting basis is unknown. ';
        $q = strtolower($question);
        $figures = ['period' => $period, 'snapshot_id' => $report['snapshot_id'] ?? null, 'basis' => $report['basis'] ?? null, 'currency' => $currency];
        if (! ($report['snapshot_id'] ?? null)) {
            $reply .= 'No saved accounting report matches this exact period and requested basis. Recognized revenue and profit are unavailable. Bank collections, loan proceeds and net bank payroll cannot establish a P&L.';
        } elseif (preg_match('/highest|best.*month/', $q)) {
            $points = collect($report['history']['monthly'] ?? [])->filter(fn ($point) => ($point['net_cents'] ?? null) !== null)->sortByDesc('net_cents')->values();
            $best = $points->first();
            $reply .= $best ? 'The highest reported monthly net profit among '.$points->count().' available monthly reports is '.$money($best['net_cents']).' for '.$best['period']['start'].' through '.$best['period']['end'].'. Missing months are excluded; this does not establish a complete ranking.' : 'Matching monthly accounting reports are unavailable, so a highest-profit month cannot be established.';
            $figures['monthly_reports'] = $points->all();
        } elseif (preg_match('/average.*margin|margin.*average/', $q)) {
            $points = collect($report['history']['monthly'] ?? [])->filter(fn ($point) => isset($point['gross_profit_cents'], $point['income_cents']) && $point['income_cents'] > 0);
            $margins = $points->map(fn ($point) => $point['gross_profit_cents'] / $point['income_cents'] * 100);
            $reply .= $margins->isNotEmpty() ? 'The arithmetic average monthly gross margin is '.number_format($margins->avg(), 2).'% across '.$margins->count().' available months with positive revenue. Each monthly margin = (revenue - COGS) / revenue × 100. Missing months and non-positive revenue months are excluded.' : 'An average monthly gross margin is unavailable because matching monthly revenue and COGS reports are missing or revenue is not positive.';
            $figures['monthly_reports'] = $points->values()->all();
        } elseif (str_contains($q, 'payroll')) {
            $reply .= 'Reported revenue is '.$money($report['income_cents'] ?? null).'. Payroll must come from identified accounting payroll accounts in this same report. Net bank payroll is cash evidence and is never added to accrual payroll. A reconciled payroll-account mapping and comparative payroll figures are not available here, so payroll growth relative to revenue remains unavailable.';
            $figures['income_cents'] = $report['income_cents'] ?? null;
        } elseif (preg_match('/decreas|increas|chang|why|compar/', $q)) {
            $comparisonKey = preg_match('/previous year|last year|year.over.year/', $q) ? 'previous_year' : ($data['profit_loss_comparison'] ?? 'previous_period');
            $prior = $report['comparison'][$comparisonKey] ?? [];
            $metrics = ['income_cents' => 'Revenue', 'cogs_cents' => 'Cost of goods sold', 'expenses_cents' => 'Operating expenses', 'other_income_cents' => 'Other income', 'other_expenses_cents' => 'Other expenses', 'net_cents' => 'Net profit'];
            if ($prior['available'] ?? false) {
                $reply .= 'Comparison: '.$prior['period']['start'].' through '.$prior['period']['end'].'. ';
                foreach ($metrics as $key => $label) {
                    $current = $report[$key] ?? null;
                    $previous = $prior['metrics'][$key] ?? null;
                    $reply .= $label.': '.$money($current).' versus '.$money($previous).'; change '.($current !== null && $previous !== null ? $money($current - $previous) : 'unavailable').'. ';
                    $figures[$key] = ['current' => $current, 'previous' => $previous];
                }
                $reply .= 'These source figures explain the arithmetic change. They do not establish business causes. Account-level expense increases require comparable account detail.';
            } else {
                $reply .= 'A same-source, same-currency and same-basis comparison report is unavailable. A change or explanation cannot be established from the selected report alone.';
            }
        } else {
            foreach (['income_cents' => 'Revenue', 'cogs_cents' => 'COGS', 'gross_profit_cents' => 'Gross profit', 'expenses_cents' => 'Operating expenses', 'net_cents' => 'Net profit'] as $key => $label) {
                $figures[$key] = $report[$key] ?? null;
                $reply .= $label.': '.$money($figures[$key]).'. ';
            }
            $reply .= 'Gross profit = revenue - COGS. Net profit is the reported source result after applicable accounting expenses; bank cash is separate.';
        }
        $reply .= ' Coverage: '.($report['coverage']['status'] ?? 'unavailable').'. Missing data stays unavailable. No unsupported cause or recommendation has been inferred.';

        return ['reply' => $reply, 'links' => [['label' => 'Review Profit & Loss source statement', 'tab' => 'profit']], 'proposals' => [],
            'mode' => 'Bud Core · Financial Insights', 'space_id' => $data['space']['id'], 'local_financial_insight' => true,
            'scope' => ['kind' => 'business', 'range' => $period, 'provisional' => ! ($report['complete'] ?? false)],
            'sources' => $figures, 'execution' => 'Read-only source calculations. No changes made.'];
    }
}
