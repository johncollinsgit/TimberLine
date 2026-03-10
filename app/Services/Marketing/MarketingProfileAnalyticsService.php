<?php

namespace App\Services\Marketing;

use App\Models\MarketingOrderEventAttribution;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\SquareOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MarketingProfileAnalyticsService
{
    /**
     * @var array<int,array<string,mixed>>
     */
    protected array $cache = [];

    /**
     * @return array<string,mixed>
     */
    public function metricsForProfile(MarketingProfile $profile): array
    {
        $profileId = (int) $profile->id;
        if (isset($this->cache[$profileId])) {
            return $this->cache[$profileId];
        }

        $links = $profile->links()->get(['source_type', 'source_id']);
        $sourceTypes = $links->pluck('source_type')->filter()->unique()->values()->all();
        $sourceChannels = collect(is_array($profile->source_channels) ? $profile->source_channels : [])
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $orderIds = $links->where('source_type', 'order')
            ->map(fn ($link) => (int) $link->source_id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
        $orders = $orderIds->isEmpty()
            ? collect()
            : Order::query()
                ->with('event:id,name,display_name')
                ->whereIn('id', $orderIds->all())
                ->get(['id', 'event_id', 'order_type', 'ordered_at']);

        $squareOrderIds = $links->where('source_type', 'square_order')
            ->pluck('source_id')
            ->filter()
            ->unique()
            ->values();
        $squareOrders = $squareOrderIds->isEmpty()
            ? collect()
            : SquareOrder::query()
                ->whereIn('square_order_id', $squareOrderIds->all())
                ->get(['square_order_id', 'closed_at', 'total_money_amount', 'source_name']);

        $squareOrderIdList = $squareOrders->pluck('square_order_id')->filter()->values();
        $attributions = $squareOrderIdList->isEmpty()
            ? collect()
            : MarketingOrderEventAttribution::query()
                ->with('eventInstance:id,title,starts_at')
                ->where('source_type', 'square_order')
                ->whereIn('source_id', $squareOrderIdList->all())
                ->get(['id', 'source_id', 'event_instance_id', 'created_at']);

        $operationalOrderDates = $orders->map(fn (Order $order) => $order->ordered_at?->timestamp)->filter();
        $squareOrderDates = $squareOrders->map(fn (SquareOrder $order) => $order->closed_at?->timestamp)->filter();
        $latestOrderTs = collect([...$operationalOrderDates->all(), ...$squareOrderDates->all()])->filter()->max();
        $latestOrderAt = $latestOrderTs ? CarbonImmutable::createFromTimestamp((int) $latestOrderTs) : null;
        $daysSinceLastOrder = $latestOrderAt ? now()->diffInDays($latestOrderAt) : null;

        $eventNames = [];
        foreach ($orders as $order) {
            if ($order->event_id && $order->event) {
                $eventNames[] = (string) ($order->event->display_name ?: $order->event->name);
            }
        }
        foreach ($attributions as $attribution) {
            if ($attribution->eventInstance?->title) {
                $eventNames[] = (string) $attribution->eventInstance->title;
            }
        }
        $eventNames = collect($eventNames)->filter()->unique()->values()->all();

        $lastEvent = collect()
            ->merge($orders->filter(fn (Order $order) => $order->event_id !== null && $order->event !== null)->map(function (Order $order): array {
                return [
                    'name' => (string) ($order->event?->display_name ?: $order->event?->name ?: ''),
                    'timestamp' => $order->ordered_at?->timestamp ?? 0,
                ];
            }))
            ->merge($attributions->filter(fn ($a) => $a->eventInstance !== null)->map(function ($a): array {
                return [
                    'name' => (string) ($a->eventInstance?->title ?: ''),
                    'timestamp' => $a->eventInstance?->starts_at?->timestamp ?? ($a->created_at?->timestamp ?? 0),
                ];
            }))
            ->sortByDesc('timestamp')
            ->first();

        $externalStats = $profile->externalCampaignStats()->get();
        $sends = (int) $externalStats->sum('sends_count');
        $opens = (int) $externalStats->sum('opens_count');
        $clicks = (int) $externalStats->sum('clicks_count');
        $lastEngagedAt = $externalStats->max('last_engaged_at');
        $lastEngagedDays = $lastEngagedAt ? now()->diffInDays($lastEngagedAt) : null;

        $totalSquareSpend = (float) $squareOrders->sum(fn (SquareOrder $order) => ((int) ($order->total_money_amount ?? 0)) / 100);
        $totalOrders = $orderIds->count() + $squareOrderIds->count();

        $metrics = [
            'profile_id' => $profileId,
            'total_orders' => $totalOrders,
            'total_spent' => round($totalSquareSpend, 2),
            'days_since_last_order' => $daysSinceLastOrder,
            'last_order_at' => $latestOrderAt?->toIso8601String(),
            'source_channels' => $sourceChannels,
            'profile_sources' => $sourceTypes,
            'has_email_consent' => (bool) $profile->accepts_email_marketing,
            'has_sms_consent' => (bool) $profile->accepts_sms_marketing,
            'has_square_link' => collect($sourceTypes)->contains(fn ($type) => str_starts_with((string) $type, 'square_')),
            'has_shopify_link' => in_array('shopify_order', $sourceTypes, true),
            'purchased_at_event' => !empty($eventNames),
            'purchased_event_names' => $eventNames,
            'last_event_name' => (string) ($lastEvent['name'] ?? ''),
            'source_diversity' => count(array_unique($sourceTypes)),
            'external_sends' => $sends,
            'external_opens' => $opens,
            'external_clicks' => $clicks,
            'days_since_last_engagement' => $lastEngagedDays,
            'event_attribution_count' => $attributions->count(),
            'days_since_last_event_purchase' => isset($lastEvent['timestamp']) && (int) $lastEvent['timestamp'] > 0
                ? now()->diffInDays(CarbonImmutable::createFromTimestamp((int) $lastEvent['timestamp']))
                : null,
        ];

        $this->cache[$profileId] = $metrics;

        return $metrics;
    }

    /**
     * @param Collection<int,MarketingProfile> $profiles
     * @return array<int,array<string,mixed>>
     */
    public function metricsForProfiles(Collection $profiles): array
    {
        $result = [];
        foreach ($profiles as $profile) {
            $result[(int) $profile->id] = $this->metricsForProfile($profile);
        }

        return $result;
    }
}
