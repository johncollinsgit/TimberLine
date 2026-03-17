<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignConversion;
use Carbon\CarbonImmutable;

class MarketingConversionAttributionCoverageReport
{
    /**
     * @var array<int,string>
     */
    protected array $channels = [
        'text',
        'email',
        'instagram',
        'facebook',
        'google',
        'other',
        'direct',
        'unknown',
    ];

    /**
     * @var array<int,string>
     */
    protected array $trackedFields = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'referrer',
        'landing_site',
        'source_name',
        'source_identifier',
    ];

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function report(array $filters = []): array
    {
        $since = $this->dateValue($filters['since'] ?? null);
        $until = $this->dateValue($filters['until'] ?? null);
        $campaignChannel = $this->stringValue($filters['campaign_channel'] ?? null);

        $baseQuery = $this->scopedQuery($since, $until, $campaignChannel);

        $total = (clone $baseQuery)->count();
        $withSnapshot = (clone $baseQuery)->whereNotNull('attribution_snapshot')->count();
        $withoutSnapshot = max(0, $total - $withSnapshot);

        $channelCounts = array_fill_keys($this->channels, 0);
        $missingFields = array_fill_keys($this->trackedFields, 0);

        foreach ((clone $baseQuery)
            ->select(['id', 'attribution_snapshot'])
            ->orderBy('id')
            ->lazyById(500) as $conversion) {
            $snapshot = is_array($conversion->attribution_snapshot ?? null) ? $conversion->attribution_snapshot : [];

            if ($snapshot !== []) {
                $channel = strtolower(trim((string) ($snapshot['channel'] ?? 'unknown')));
                if (! array_key_exists($channel, $channelCounts)) {
                    $channel = 'other';
                }

                $channelCounts[$channel]++;
            }

            foreach ($this->trackedFields as $field) {
                if ($snapshot === [] || $this->missingValue($snapshot[$field] ?? null)) {
                    $missingFields[$field]++;
                }
            }
        }

        $channelRows = [];
        foreach ($this->channels as $channel) {
            $count = (int) ($channelCounts[$channel] ?? 0);
            $channelRows[$channel] = [
                'count' => $count,
                'rate' => $withSnapshot > 0 ? round(($count / $withSnapshot) * 100, 1) : 0.0,
            ];
        }

        $missingRows = [];
        foreach ($this->trackedFields as $field) {
            $count = (int) ($missingFields[$field] ?? 0);
            $missingRows[$field] = [
                'count' => $count,
                'rate' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        }

        uasort($missingRows, fn (array $left, array $right): int => $right['count'] <=> $left['count']);

        return [
            'scope' => [
                'since' => $since?->toIso8601String(),
                'until' => $until?->toIso8601String(),
                'campaign_channel' => $campaignChannel,
            ],
            'totals' => [
                'total_conversions' => $total,
                'with_snapshot' => $withSnapshot,
                'without_snapshot' => $withoutSnapshot,
                'snapshot_coverage_rate' => $total > 0 ? round(($withSnapshot / $total) * 100, 1) : 0.0,
                'unknown_snapshot_count' => (int) ($channelCounts['unknown'] ?? 0),
                'unknown_snapshot_rate' => $withSnapshot > 0 ? round(((int) ($channelCounts['unknown'] ?? 0) / $withSnapshot) * 100, 1) : 0.0,
                'other_snapshot_count' => (int) ($channelCounts['other'] ?? 0),
                'other_snapshot_rate' => $withSnapshot > 0 ? round(((int) ($channelCounts['other'] ?? 0) / $withSnapshot) * 100, 1) : 0.0,
            ],
            'channels' => $channelRows,
            'missing_fields' => $missingRows,
            'top_missing_fields' => array_slice($missingRows, 0, 5, true),
        ];
    }

    protected function scopedQuery(
        ?CarbonImmutable $since,
        ?CarbonImmutable $until,
        ?string $campaignChannel
    ) {
        return MarketingCampaignConversion::query()
            ->when($since, fn ($query) => $query->where('converted_at', '>=', $since))
            ->when($until, fn ($query) => $query->where('converted_at', '<=', $until))
            ->when($campaignChannel, function ($query, string $campaignChannel): void {
                $query->whereHas('campaign', fn ($campaignQuery) => $campaignQuery->where('channel', $campaignChannel));
            });
    }

    protected function dateValue(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    protected function stringValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function missingValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }
}
