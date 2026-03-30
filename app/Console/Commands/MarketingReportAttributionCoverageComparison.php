<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingAttributionCoverageComparisonReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class MarketingReportAttributionCoverageComparison extends Command
{
    protected $signature = 'marketing:report-attribution-coverage-comparison
        {--tenant-id= : Restrict to a tenant id (required)}
        {--since= : Only include orders/conversions on or after this datetime}
        {--until= : Only include orders/conversions on or before this datetime}
        {--store= : Restrict to one Shopify store key}
        {--campaign-channel= : Restrict conversions to one campaign channel}
        {--chunk=500 : Number of linked conversions to inspect per batch}
        {--detail : Show channel-pair and field-comparison breakdowns}';

    protected $description = 'Compare order-level attribution coverage against durable conversion snapshots.';

    public function handle(MarketingAttributionCoverageComparisonReport $reporter): int
    {
        $tenantId = is_numeric($this->option('tenant-id')) ? (int) $this->option('tenant-id') : null;
        if ($tenantId === null || $tenantId <= 0) {
            $this->error('Missing required --tenant-id. Attribution comparison reporting is tenant-scoped in MT-2C.');

            return self::FAILURE;
        }

        $since = $this->parseDateOption('since');
        $until = $this->parseDateOption('until');
        $store = $this->stringOption('store');
        $campaignChannel = $this->stringOption('campaign-channel');
        $chunk = max(25, (int) $this->option('chunk'));
        $detail = (bool) $this->option('detail');

        $report = $reporter->report([
            'since' => $since?->toIso8601String(),
            'until' => $until?->toIso8601String(),
            'tenant_id' => $tenantId,
            'store' => $store,
            'campaign_channel' => $campaignChannel,
            'chunk' => $chunk,
        ]);

        $this->line('since=' . ($report['scope']['since'] ?? 'none'));
        $this->line('until=' . ($report['scope']['until'] ?? 'none'));
        $this->line('tenant_id=' . ($report['scope']['tenant_id'] ?? 'none'));
        $this->line('store=' . ($report['scope']['store'] ?? 'any'));
        $this->line('campaign_channel=' . ($report['scope']['campaign_channel'] ?? 'any'));
        $this->line('chunk=' . ($report['scope']['chunk'] ?? $chunk));

        foreach ((array) $report['totals'] as $key => $value) {
            $this->line($key . '=' . $value);
        }

        foreach ((array) $report['rates'] as $key => $value) {
            $this->line($key . '=' . $value);
        }

        if (! $detail) {
            return self::SUCCESS;
        }

        foreach ((array) $report['channel_pairs'] as $pair => $row) {
            $safePair = $this->sanitizeKey($pair);
            $this->line(sprintf('channel_pair.%s.count=%d', $safePair, (int) ($row['count'] ?? 0)));
            $this->line(sprintf('channel_pair.%s.rate=%.1f', $safePair, (float) ($row['rate'] ?? 0)));
        }

        foreach ((array) ($report['leakage']['categories'] ?? []) as $category => $row) {
            $safeCategory = $this->sanitizeKey((string) $category);
            $this->line(sprintf('leakage.%s.count=%d', $safeCategory, (int) ($row['count'] ?? 0)));
            $this->line(sprintf('leakage.%s.rate=%.1f', $safeCategory, (float) ($row['rate'] ?? 0)));
        }

        foreach (['by_store' => 'store', 'by_campaign_channel' => 'campaign_channel', 'by_final_channel' => 'final_channel'] as $key => $label) {
            foreach ((array) ($report['leakage'][$key] ?? []) as $group => $rows) {
                $safeGroup = $this->sanitizeKey((string) $group);
                foreach ((array) $rows as $category => $row) {
                    $safeCategory = $this->sanitizeKey((string) $category);
                    $this->line(sprintf(
                        'leakage_%s.%s.%s.count=%d',
                        $label,
                        $safeGroup,
                        $safeCategory,
                        (int) ($row['count'] ?? 0)
                    ));
                }
            }
        }

        foreach ((array) $report['field_comparisons'] as $field => $rows) {
            foreach ((array) $rows as $bucket => $count) {
                $this->line(sprintf(
                    'field.%s.%s=%d',
                    $field,
                    $bucket,
                    (int) $count
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
