<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaignConversion;
use App\Services\Marketing\MarketingCampaignConversionAttributionSnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class MarketingBackfillConversionAttributionSnapshots extends Command
{
    protected $signature = 'marketing:backfill-conversion-attribution-snapshots
        {--dry-run : Report what would change without writing updates}
        {--chunk=200 : Number of conversions to inspect per batch}
        {--limit= : Maximum conversions to inspect}
        {--since= : Only inspect conversions on or after this datetime}
        {--until= : Only inspect conversions on or before this datetime}
        {--campaign-channel= : Restrict to one campaign channel}
        {--missing-only : Only inspect conversions without persisted snapshots}';

    protected $description = 'Backfill durable attribution snapshots on marketing campaign conversions.';

    public function handle(MarketingCampaignConversionAttributionSnapshotBuilder $builder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(25, (int) $this->option('chunk'));
        $limit = $this->integerOption('limit');
        $since = $this->parseDateOption('since');
        $until = $this->parseDateOption('until');
        $campaignChannel = $this->stringOption('campaign-channel');
        $missingOnly = (bool) $this->option('missing-only');

        $summary = [
            'examined' => 0,
            'already_having_snapshot' => 0,
            'newly_snapshotted' => 0,
            'updated_stronger_snapshot' => 0,
            'skipped_no_better_data' => 0,
            'failed' => 0,
        ];

        $query = $this->scopedQuery($since, $until, $campaignChannel, $missingOnly)
            ->orderBy('id');

        $stream = $query->lazyById($chunk);
        if ($limit !== null) {
            $stream = $stream->take($limit);
        }

        foreach ($stream as $conversion) {
            $summary['examined']++;

            $existing = is_array($conversion->attribution_snapshot ?? null) ? $conversion->attribution_snapshot : [];
            if ($existing !== []) {
                $summary['already_having_snapshot']++;
            }

            try {
                $snapshot = $builder->build(
                    campaignId: (int) $conversion->campaign_id,
                    profileId: (int) $conversion->marketing_profile_id,
                    sourceType: (string) ($conversion->source_type ?? ''),
                    sourceId: (string) ($conversion->source_id ?? ''),
                    existingSnapshot: $existing
                );
            } catch (Throwable $e) {
                $summary['failed']++;

                if ($this->getOutput()->isVerbose()) {
                    $this->warn(sprintf(
                        'failed conversion_id=%d error=%s',
                        (int) $conversion->id,
                        $e->getMessage()
                    ));
                }

                continue;
            }

            if ($snapshot === $existing) {
                $summary['skipped_no_better_data']++;
                continue;
            }

            if ($existing === []) {
                $summary['newly_snapshotted']++;
            } else {
                $summary['updated_stronger_snapshot']++;
            }

            if (! $dryRun) {
                $conversion->forceFill([
                    'attribution_snapshot' => $snapshot,
                ])->save();
            }
        }

        $this->line('mode=' . ($dryRun ? 'dry-run' : 'live'));
        $this->line('chunk=' . $chunk);
        $this->line('limit=' . ($limit ?? 'none'));
        $this->line('missing_only=' . ($missingOnly ? 'yes' : 'no'));
        $this->line('since=' . ($since?->toIso8601String() ?? 'none'));
        $this->line('until=' . ($until?->toIso8601String() ?? 'none'));
        $this->line('campaign_channel=' . ($campaignChannel ?? 'any'));

        foreach ($summary as $key => $value) {
            $this->line($key . '=' . $value);
        }

        return self::SUCCESS;
    }

    protected function scopedQuery(
        ?CarbonImmutable $since,
        ?CarbonImmutable $until,
        ?string $campaignChannel,
        bool $missingOnly
    ) {
        return MarketingCampaignConversion::query()
            ->when($since, fn ($query) => $query->where('converted_at', '>=', $since))
            ->when($until, fn ($query) => $query->where('converted_at', '<=', $until))
            ->when($campaignChannel, function ($query, string $campaignChannel): void {
                $query->whereHas('campaign', fn ($campaignQuery) => $campaignQuery->where('channel', $campaignChannel));
            })
            ->when($missingOnly, fn ($query) => $query->whereNull('attribution_snapshot'));
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

    protected function integerOption(string $key): ?int
    {
        $value = $this->option($key);
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(1, (int) $value) : null;
    }

    protected function stringOption(string $key): ?string
    {
        $value = trim((string) $this->option($key));

        return $value !== '' ? $value : null;
    }
}
