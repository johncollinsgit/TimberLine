<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingConversionAttributionCoverageReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class MarketingReportConversionAttributionCoverage extends Command
{
    protected $signature = 'marketing:report-conversion-attribution-coverage
        {--since= : Only include conversions on or after this datetime}
        {--until= : Only include conversions on or before this datetime}
        {--campaign-channel= : Restrict to one campaign channel}
        {--detail : Show per-channel and missing-field breakdowns}';

    protected $description = 'Report durable attribution snapshot coverage and missing-field patterns for campaign conversions.';

    public function handle(MarketingConversionAttributionCoverageReport $reporter): int
    {
        $since = $this->parseDateOption('since');
        $until = $this->parseDateOption('until');
        $campaignChannel = $this->stringOption('campaign-channel');
        $detail = (bool) $this->option('detail');

        $report = $reporter->report([
            'since' => $since?->toIso8601String(),
            'until' => $until?->toIso8601String(),
            'campaign_channel' => $campaignChannel,
        ]);

        $this->line('since=' . ($report['scope']['since'] ?? 'none'));
        $this->line('until=' . ($report['scope']['until'] ?? 'none'));
        $this->line('campaign_channel=' . ($report['scope']['campaign_channel'] ?? 'any'));

        foreach ((array) $report['totals'] as $key => $value) {
            $this->line($key . '=' . $value);
        }

        if (! $detail) {
            return self::SUCCESS;
        }

        foreach ((array) $report['channels'] as $channel => $row) {
            $this->line(sprintf(
                'channel.%s.count=%d',
                $channel,
                (int) ($row['count'] ?? 0)
            ));
            $this->line(sprintf(
                'channel.%s.rate=%.1f',
                $channel,
                (float) ($row['rate'] ?? 0)
            ));
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
}
