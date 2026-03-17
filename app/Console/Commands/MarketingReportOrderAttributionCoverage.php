<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingOrderAttributionCoverageReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class MarketingReportOrderAttributionCoverage extends Command
{
    protected $signature = 'marketing:report-order-attribution-coverage
        {--since= : Only include orders on or after this datetime}
        {--until= : Only include orders on or before this datetime}
        {--store= : Restrict to one Shopify store key}
        {--chunk=500 : Number of orders to inspect per batch}
        {--with-attribution-only : Only include orders with attribution_meta}
        {--missing-only : Only include orders without attribution_meta}
        {--detail : Show missing-field and provenance breakdowns}';

    protected $description = 'Report order-level attribution coverage and missing-field patterns from persisted orders.attribution_meta.';

    public function handle(MarketingOrderAttributionCoverageReport $reporter): int
    {
        $since = $this->parseDateOption('since');
        $until = $this->parseDateOption('until');
        $store = $this->stringOption('store');
        $chunk = max(25, (int) $this->option('chunk'));
        $withAttributionOnly = (bool) $this->option('with-attribution-only');
        $missingOnly = (bool) $this->option('missing-only');
        $detail = (bool) $this->option('detail');

        $report = $reporter->report([
            'since' => $since?->toIso8601String(),
            'until' => $until?->toIso8601String(),
            'store' => $store,
            'chunk' => $chunk,
            'with_attribution_only' => $withAttributionOnly,
            'missing_only' => $missingOnly,
        ]);

        $this->line('since=' . ($report['scope']['since'] ?? 'none'));
        $this->line('until=' . ($report['scope']['until'] ?? 'none'));
        $this->line('store=' . ($report['scope']['store'] ?? 'any'));
        $this->line('chunk=' . ($report['scope']['chunk'] ?? $chunk));
        $this->line('with_attribution_only=' . (($report['scope']['with_attribution_only'] ?? false) ? 'yes' : 'no'));
        $this->line('missing_only=' . (($report['scope']['missing_only'] ?? false) ? 'yes' : 'no'));

        foreach ((array) $report['totals'] as $key => $value) {
            $this->line($key . '=' . $value);
        }

        if (! $detail) {
            return self::SUCCESS;
        }

        foreach ((array) $report['missing_fields'] as $field => $row) {
            $this->line(sprintf(
                'missing_field.%s.count=%d',
                $field,
                (int) ($row['count'] ?? 0)
            ));
            $this->line(sprintf(
                'missing_field.%s.rate=%.1f',
                $field,
                (float) ($row['rate'] ?? 0)
            ));
        }

        foreach ((array) $report['quality'] as $group => $rows) {
            foreach ((array) $rows as $value => $row) {
                $sanitized = $this->sanitizeKey((string) $value);
                $this->line(sprintf(
                    '%s.%s.count=%d',
                    $group,
                    $sanitized,
                    (int) ($row['count'] ?? 0)
                ));
                $this->line(sprintf(
                    '%s.%s.rate=%.1f',
                    $group,
                    $sanitized,
                    (float) ($row['rate'] ?? 0)
                ));
            }
        }

        return self::SUCCESS;
    }

    protected function parseDateOption(string $key): ?CarbonImmutable
    {
        $value = trim((string) $this->option($key));
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            $this->warn("Invalid --{$key} value '{$value}', ignoring.");

            return null;
        }
    }

    protected function stringOption(string $key): ?string
    {
        $value = trim((string) $this->option($key));

        return $value !== '' ? $value : null;
    }

    protected function sanitizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: 'unknown';

        return trim($value, '_') ?: 'unknown';
    }
}
