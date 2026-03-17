<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignConversion;
use App\Models\Order;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardAttributionClassifier;
use Carbon\CarbonImmutable;

class MarketingAttributionCoverageComparisonReport
{
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

    public function __construct(
        protected ShopifyEmbeddedDashboardAttributionClassifier $classifier
    ) {
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function report(array $filters = []): array
    {
        $since = $this->dateValue($filters['since'] ?? null);
        $until = $this->dateValue($filters['until'] ?? null);
        $store = $this->stringValue($filters['store'] ?? null);
        $campaignChannel = $this->stringValue($filters['campaign_channel'] ?? null);
        $chunk = max(25, (int) ($filters['chunk'] ?? 500));

        $orderQuery = $this->ordersQuery($since, $until, $store);
        $conversionQuery = $this->conversionsQuery($since, $until, $campaignChannel, $store);

        $totalOrders = (clone $orderQuery)->count();
        $ordersWithAttribution = (clone $orderQuery)->whereNotNull('attribution_meta')->count();
        $totalConversions = (clone $conversionQuery)->count();
        $conversionsWithSnapshot = (clone $conversionQuery)->whereNotNull('attribution_snapshot')->count();

        $linkedOrderIds = (clone $conversionQuery)
            ->where('source_type', 'order')
            ->pluck('source_id')
            ->filter(fn ($value): bool => is_numeric($value) && (int) $value > 0)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();

        $orders = Order::query()
            ->whereIn('id', $linkedOrderIds->all())
            ->get(['id', 'shopify_store_key', 'shopify_store', 'attribution_meta'])
            ->keyBy('id');

        $summary = [
            'linked_conversions' => 0,
            'linked_conversions_with_order_attribution' => 0,
            'linked_conversions_with_snapshot' => 0,
            'linked_conversions_with_both' => 0,
            'order_truth_but_missing_snapshot' => 0,
            'order_truth_but_snapshot_unknown' => 0,
            'order_truth_but_snapshot_other' => 0,
            'matching_channels' => 0,
            'differing_channels' => 0,
            'degraded_weaker_than_order' => 0,
            'improved_stronger_than_order' => 0,
            'missing_order_attribution_but_snapshot_present' => 0,
            'unlinked_conversions' => max(0, $totalConversions - (clone $conversionQuery)->where('source_type', 'order')->count()),
        ];

        $channelPairs = [];
        $leakageCategories = [];
        $leakageByStore = [];
        $leakageByCampaignChannel = [];
        $leakageByFinalChannel = [];
        $fieldComparisons = [];
        foreach ($this->trackedFields as $field) {
            $fieldComparisons[$field] = [
                'match' => 0,
                'order_only' => 0,
                'conversion_only' => 0,
                'different' => 0,
                'missing_both' => 0,
            ];
        }

        foreach ((clone $conversionQuery)
            ->select(['id', 'campaign_id', 'source_type', 'source_id', 'attribution_snapshot'])
            ->with('campaign:id,channel')
            ->orderBy('id')
            ->lazyById($chunk) as $conversion) {
            if (strtolower(trim((string) $conversion->source_type)) !== 'order' || ! is_numeric($conversion->source_id)) {
                continue;
            }

            $order = $orders->get((int) $conversion->source_id);
            if (! $order) {
                continue;
            }

            $summary['linked_conversions']++;

            $orderMeta = is_array($order->attribution_meta ?? null) ? $order->attribution_meta : [];
            $snapshot = is_array($conversion->attribution_snapshot ?? null) ? $conversion->attribution_snapshot : [];
            $storeKey = (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown');
            $campaignKey = strtolower(trim((string) ($conversion->campaign?->channel ?? 'unknown')));

            $orderHasTruth = $orderMeta !== [];
            $snapshotExists = $snapshot !== [];

            if ($orderHasTruth) {
                $summary['linked_conversions_with_order_attribution']++;
            }

            if ($snapshotExists) {
                $summary['linked_conversions_with_snapshot']++;
            }

            if ($orderHasTruth && $snapshotExists) {
                $summary['linked_conversions_with_both']++;
            }

            if ($orderHasTruth && ! $snapshotExists) {
                $summary['order_truth_but_missing_snapshot']++;
                $this->recordLeakageCategory(
                    'missing_snapshot',
                    $storeKey,
                    $campaignKey,
                    'missing_snapshot',
                    $leakageCategories,
                    $leakageByStore,
                    $leakageByCampaignChannel,
                    $leakageByFinalChannel
                );
                continue;
            }

            if (! $orderHasTruth && $snapshotExists) {
                $summary['missing_order_attribution_but_snapshot_present']++;
                $this->recordLeakageCategory(
                    'missing_order_truth',
                    $storeKey,
                    $campaignKey,
                    strtolower(trim((string) ($snapshot['channel'] ?? 'unknown'))) ?: 'unknown',
                    $leakageCategories,
                    $leakageByStore,
                    $leakageByCampaignChannel,
                    $leakageByFinalChannel
                );
            }

            if (! $orderHasTruth || ! $snapshotExists) {
                continue;
            }

            $orderChannel = $this->classifyOrderChannel($orderMeta, (string) ($order->shopify_store_key ?: $order->shopify_store ?: ''), (string) $order->id);
            $conversionChannel = strtolower(trim((string) ($snapshot['channel'] ?? 'unknown')));
            if ($conversionChannel === '') {
                $conversionChannel = 'unknown';
            }

            $category = $this->leakageCategory($orderChannel, $conversionChannel);
            $this->recordLeakageCategory(
                $category,
                $storeKey,
                $campaignKey,
                $conversionChannel,
                $leakageCategories,
                $leakageByStore,
                $leakageByCampaignChannel,
                $leakageByFinalChannel
            );

            $pairKey = $orderChannel . '->' . $conversionChannel;
            $channelPairs[$pairKey] = ($channelPairs[$pairKey] ?? 0) + 1;

            if ($conversionChannel === 'unknown') {
                $summary['order_truth_but_snapshot_unknown']++;
            } elseif ($conversionChannel === 'other') {
                $summary['order_truth_but_snapshot_other']++;
            }

            if ($orderChannel === $conversionChannel) {
                $summary['matching_channels']++;
            } else {
                $summary['differing_channels']++;
            }

            $comparison = $this->compareChannelStrength($orderChannel, $conversionChannel);
            if ($comparison === 'degraded') {
                $summary['degraded_weaker_than_order']++;
            } elseif ($comparison === 'improved') {
                $summary['improved_stronger_than_order']++;
            }

            foreach ($this->trackedFields as $field) {
                $bucket = $this->compareFieldValue($orderMeta[$field] ?? null, $snapshot[$field] ?? null);
                $fieldComparisons[$field][$bucket]++;
            }
        }

        arsort($channelPairs);

        return [
            'scope' => [
                'since' => $since?->toIso8601String(),
                'until' => $until?->toIso8601String(),
                'store' => $store,
                'campaign_channel' => $campaignChannel,
                'chunk' => $chunk,
            ],
            'totals' => [
                'total_orders' => $totalOrders,
                'orders_with_attribution_meta' => $ordersWithAttribution,
                'order_attribution_coverage_rate' => $totalOrders > 0 ? round(($ordersWithAttribution / $totalOrders) * 100, 1) : 0.0,
                'total_conversions' => $totalConversions,
                'conversions_with_attribution_snapshot' => $conversionsWithSnapshot,
                'conversion_snapshot_coverage_rate' => $totalConversions > 0 ? round(($conversionsWithSnapshot / $totalConversions) * 100, 1) : 0.0,
                ...$summary,
            ],
            'rates' => [
                'linked_match_rate' => $summary['linked_conversions_with_both'] > 0
                    ? round(($summary['matching_channels'] / $summary['linked_conversions_with_both']) * 100, 1)
                    : 0.0,
                'linked_degraded_rate' => $summary['linked_conversions_with_both'] > 0
                    ? round(($summary['degraded_weaker_than_order'] / $summary['linked_conversions_with_both']) * 100, 1)
                    : 0.0,
                'linked_improved_rate' => $summary['linked_conversions_with_both'] > 0
                    ? round(($summary['improved_stronger_than_order'] / $summary['linked_conversions_with_both']) * 100, 1)
                    : 0.0,
                'linked_missing_snapshot_rate' => $summary['linked_conversions_with_order_attribution'] > 0
                    ? round(($summary['order_truth_but_missing_snapshot'] / $summary['linked_conversions_with_order_attribution']) * 100, 1)
                    : 0.0,
            ],
            'channel_pairs' => $this->formatCountsWithRate($channelPairs, $summary['linked_conversions_with_both']),
            'leakage' => [
                'categories' => $this->formatCountsWithRate($leakageCategories, $summary['linked_conversions']),
                'by_store' => $this->formatNestedCountsWithRate($leakageByStore),
                'by_campaign_channel' => $this->formatNestedCountsWithRate($leakageByCampaignChannel),
                'by_final_channel' => $this->formatNestedCountsWithRate($leakageByFinalChannel),
            ],
            'field_comparisons' => $fieldComparisons,
        ];
    }

    protected function ordersQuery(?CarbonImmutable $since, ?CarbonImmutable $until, ?string $store)
    {
        return Order::query()
            ->when($since, fn ($query) => $query->where('ordered_at', '>=', $since))
            ->when($until, fn ($query) => $query->where('ordered_at', '<=', $until))
            ->when($store, function ($query, string $store): void {
                $query->where(function ($nested) use ($store): void {
                    $nested->where('shopify_store_key', $store)
                        ->orWhere('shopify_store', $store);
                });
            });
    }

    protected function conversionsQuery(?CarbonImmutable $since, ?CarbonImmutable $until, ?string $campaignChannel, ?string $store)
    {
        return MarketingCampaignConversion::query()
            ->when($since, fn ($query) => $query->where('converted_at', '>=', $since))
            ->when($until, fn ($query) => $query->where('converted_at', '<=', $until))
            ->when($campaignChannel, function ($query, string $campaignChannel): void {
                $query->whereHas('campaign', fn ($campaignQuery) => $campaignQuery->where('channel', $campaignChannel));
            })
            ->when($store, function ($query, string $store): void {
                $query->where(function ($scoped) use ($store): void {
                    $scoped->where('source_type', 'order')
                        ->whereIn('source_id', Order::query()
                            ->where(function ($nested) use ($store): void {
                                $nested->where('shopify_store_key', $store)
                                    ->orWhere('shopify_store', $store);
                            })
                            ->select('id')
                        );
                });
            });
    }

    protected function classifyOrderChannel(array $orderMeta, string $storeKey, string $sourceId): string
    {
        $classification = $this->classifier->classify([
            'explicitChannel' => null,
            'sourceType' => 'order',
            'sourceId' => $sourceId,
            'sourceMeta' => array_merge($orderMeta, [
                'shopify_store_key' => $orderMeta['shopify_store_key'] ?? $storeKey ?: null,
            ]),
        ]);

        $channel = strtolower(trim((string) ($classification['channel'] ?? 'unknown')));

        return $channel !== '' ? $channel : 'unknown';
    }

    protected function compareChannelStrength(string $orderChannel, string $conversionChannel): string
    {
        $orderRank = $this->channelStrength($orderChannel);
        $conversionRank = $this->channelStrength($conversionChannel);

        if ($orderChannel === $conversionChannel) {
            return 'match';
        }

        if ($conversionRank > $orderRank) {
            return 'improved';
        }

        if ($conversionRank < $orderRank) {
            return 'degraded';
        }

        return 'different';
    }

    protected function leakageCategory(string $orderChannel, string $conversionChannel): string
    {
        if ($orderChannel === $conversionChannel) {
            return 'match';
        }

        if ($conversionChannel === 'unknown') {
            return 'degraded_to_unknown';
        }

        if ($conversionChannel === 'other' && $orderChannel !== 'other' && $orderChannel !== 'unknown') {
            return 'degraded_to_other';
        }

        return match ($this->compareChannelStrength($orderChannel, $conversionChannel)) {
            'improved' => 'improved_downstream',
            'degraded' => 'channel_mismatch',
            default => 'channel_mismatch',
        };
    }

    protected function channelStrength(string $channel): int
    {
        return match (strtolower(trim($channel))) {
            'text', 'email', 'instagram', 'facebook', 'google', 'direct' => 3,
            'other' => 2,
            'unknown', '' => 1,
            default => 2,
        };
    }

    protected function compareFieldValue(mixed $orderValue, mixed $conversionValue): string
    {
        $orderMissing = $this->missingValue($orderValue);
        $conversionMissing = $this->missingValue($conversionValue);

        if ($orderMissing && $conversionMissing) {
            return 'missing_both';
        }

        if (! $orderMissing && $conversionMissing) {
            return 'order_only';
        }

        if ($orderMissing && ! $conversionMissing) {
            return 'conversion_only';
        }

        return trim((string) $orderValue) === trim((string) $conversionValue) ? 'match' : 'different';
    }

    protected function formatCountsWithRate(array $counts, int $denominator): array
    {
        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[$key] = [
                'count' => (int) $count,
                'rate' => $denominator > 0 ? round(($count / $denominator) * 100, 1) : 0.0,
            ];
        }

        return $rows;
    }

    protected function formatNestedCountsWithRate(array $groups): array
    {
        $rows = [];

        foreach ($groups as $group => $counts) {
            $denominator = array_sum($counts);
            $rows[$group] = $this->formatCountsWithRate($counts, $denominator);
        }

        return $rows;
    }

    protected function recordLeakageCategory(
        string $category,
        string $storeKey,
        string $campaignChannel,
        string $finalChannel,
        array &$categories,
        array &$byStore,
        array &$byCampaignChannel,
        array &$byFinalChannel
    ): void {
        $categories[$category] = (int) ($categories[$category] ?? 0) + 1;
        $byStore[$storeKey][$category] = (int) ($byStore[$storeKey][$category] ?? 0) + 1;
        $byCampaignChannel[$campaignChannel][$category] = (int) ($byCampaignChannel[$campaignChannel][$category] ?? 0) + 1;
        $byFinalChannel[$finalChannel][$category] = (int) ($byFinalChannel[$finalChannel][$category] ?? 0) + 1;
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
        if ($value === null) {
            return null;
        }

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
