<?php

namespace App\Services\Marketing;

use App\Models\MarketingMessageEngagementEvent;
use App\Models\MarketingMessageOrderAttribution;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MessageOrderAttributionService
{
    protected const ATTRIBUTION_MODEL = 'last_click';

    public function defaultWindowDays(): int
    {
        return max(1, (int) config('marketing.message_analytics.attribution_window_days', 7));
    }

    /**
     * @return array{processed:int,attributed:int,created:int,updated:int,cleared:int,skipped:int}
     */
    public function syncForClickEvent(MarketingMessageEngagementEvent $clickEvent, ?int $windowDays = null): array
    {
        $summary = [
            'processed' => 0,
            'attributed' => 0,
            'created' => 0,
            'updated' => 0,
            'cleared' => 0,
            'skipped' => 0,
        ];

        if (strtolower(trim((string) $clickEvent->event_type)) !== 'click') {
            $summary['skipped']++;

            return $summary;
        }

        $tenantId = $this->positiveInt($clickEvent->tenant_id);
        $profileId = $this->positiveInt($clickEvent->marketing_profile_id);
        $occurredAt = $this->resolveDate($clickEvent->occurred_at) ?? CarbonImmutable::now();
        $window = max(1, $windowDays ?? $this->defaultWindowDays());
        $storeKey = $this->nullableString($clickEvent->store_key);

        if ($tenantId === null || $profileId === null) {
            $summary['skipped']++;

            return $summary;
        }

        $orderIds = $this->orderIdsForProfile($tenantId, $profileId);
        if ($orderIds->isEmpty()) {
            $summary['skipped']++;

            return $summary;
        }

        $orders = Order::query()
            ->forTenantId($tenantId)
            ->whereIn('id', $orderIds->all())
            ->when(
                Schema::hasColumn('orders', 'shopify_store_key') && $storeKey !== null,
                fn (EloquentBuilder $query): EloquentBuilder => $query->where('shopify_store_key', $storeKey)
            )
            ->where(function (EloquentBuilder $query) use ($occurredAt, $window): void {
                $end = $occurredAt->addDays($window);

                $query->whereBetween('ordered_at', [$occurredAt, $end])
                    ->orWhere(function (EloquentBuilder $fallback) use ($occurredAt, $end): void {
                        $fallback->whereNull('ordered_at')
                            ->whereBetween('created_at', [$occurredAt, $end]);
                    });
            })
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            $summary['processed']++;
            $result = $this->attributeOrder(
                order: $order,
                tenantId: $tenantId,
                storeKey: $storeKey,
                windowDays: $window,
                profileIds: collect([$profileId])
            );

            if ($result['attributed']) {
                $summary['attributed']++;
                $summary['created'] += (int) $result['created'];
                $summary['updated'] += (int) $result['updated'];
                continue;
            }

            $summary['cleared'] += (int) $result['cleared'];
            $summary['skipped'] += (int) $result['skipped'];
        }

        return $summary;
    }

    /**
     * @return array{processed:int,attributed:int,created:int,updated:int,cleared:int,skipped:int}
     */
    public function syncForTenantStore(
        ?int $tenantId,
        ?string $storeKey,
        ?CarbonInterface $dateFrom = null,
        ?CarbonInterface $dateTo = null,
        ?int $windowDays = null
    ): array {
        $summary = [
            'processed' => 0,
            'attributed' => 0,
            'created' => 0,
            'updated' => 0,
            'cleared' => 0,
            'skipped' => 0,
        ];

        $tenantId = $this->positiveInt($tenantId);
        if ($tenantId === null) {
            $summary['skipped']++;

            return $summary;
        }

        $window = max(1, $windowDays ?? $this->defaultWindowDays());
        $normalizedStoreKey = $this->nullableString($storeKey);
        $from = $this->resolveDate($dateFrom) ?? CarbonImmutable::now()->subDays(30);
        $to = $this->resolveDate($dateTo) ?? CarbonImmutable::now();

        $ordersQuery = Order::query()
            ->forTenantId($tenantId)
            ->when(
                Schema::hasColumn('orders', 'shopify_store_key') && $normalizedStoreKey !== null,
                fn (EloquentBuilder $query): EloquentBuilder => $query->where('shopify_store_key', $normalizedStoreKey)
            )
            ->where(function (EloquentBuilder $query) use ($from, $to): void {
                $query->whereBetween('ordered_at', [$from, $to])
                    ->orWhere(function (EloquentBuilder $fallback) use ($from, $to): void {
                        $fallback->whereNull('ordered_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->orderBy('id');

        $ordersQuery->chunkById(200, function (Collection $orders) use (&$summary, $tenantId, $normalizedStoreKey, $window): void {
            $orderIds = $orders->pluck('id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->values();

            $profileLinksByOrder = $this->profileLinksByOrderIds($tenantId, $orderIds);

            foreach ($orders as $order) {
                $summary['processed']++;
                $profileIds = $profileLinksByOrder->get((int) $order->id, collect());
                $result = $this->attributeOrder(
                    order: $order,
                    tenantId: $tenantId,
                    storeKey: $normalizedStoreKey,
                    windowDays: $window,
                    profileIds: $profileIds
                );

                if ($result['attributed']) {
                    $summary['attributed']++;
                    $summary['created'] += (int) $result['created'];
                    $summary['updated'] += (int) $result['updated'];
                    continue;
                }

                $summary['cleared'] += (int) $result['cleared'];
                $summary['skipped'] += (int) $result['skipped'];
            }
        });

        return $summary;
    }

    /**
     * @param  Collection<int,int>  $profileIds
     * @return array{attributed:bool,created:int,updated:int,cleared:int,skipped:int}
     */
    protected function attributeOrder(
        Order $order,
        int $tenantId,
        ?string $storeKey,
        int $windowDays,
        Collection $profileIds
    ): array {
        $orderAt = $this->resolveDate($order->ordered_at) ?? $this->resolveDate($order->created_at);
        if ($orderAt === null || $profileIds->isEmpty()) {
            return [
                'attributed' => false,
                'created' => 0,
                'updated' => 0,
                'cleared' => $this->clearAttribution($tenantId, $storeKey, (int) $order->id),
                'skipped' => 1,
            ];
        }

        $click = $this->resolveLastClickEvent(
            tenantId: $tenantId,
            storeKey: $storeKey,
            profileIds: $profileIds,
            orderAt: $orderAt,
            windowDays: $windowDays
        );

        if (! $click instanceof MarketingMessageEngagementEvent) {
            return [
                'attributed' => false,
                'created' => 0,
                'updated' => 0,
                'cleared' => $this->clearAttribution($tenantId, $storeKey, (int) $order->id),
                'skipped' => 1,
            ];
        }

        $keys = [
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'order_id' => (int) $order->id,
            'attribution_model' => self::ATTRIBUTION_MODEL,
        ];

        $payload = [
            'marketing_profile_id' => $this->positiveInt($click->marketing_profile_id),
            'marketing_email_delivery_id' => $this->positiveInt($click->marketing_email_delivery_id),
            'marketing_message_engagement_event_id' => (int) $click->id,
            'channel' => $this->nullableString($click->channel) ?? 'email',
            'attribution_window_days' => $windowDays,
            'attributed_url' => $this->nullableString($click->url),
            'normalized_url' => $this->nullableString($click->normalized_url),
            'click_occurred_at' => $click->occurred_at,
            'order_occurred_at' => $orderAt,
            'revenue_cents' => $this->orderRevenueCents($order),
            'metadata' => [
                'attribution_rule' => 'last_click_within_window',
                'order_number' => $this->nullableString((string) ($order->order_number ?? null)),
            ],
        ];

        $record = MarketingMessageOrderAttribution::query()->where($keys)->first();
        if (! $record instanceof MarketingMessageOrderAttribution) {
            MarketingMessageOrderAttribution::query()->create([
                ...$keys,
                ...$payload,
            ]);

            return [
                'attributed' => true,
                'created' => 1,
                'updated' => 0,
                'cleared' => 0,
                'skipped' => 0,
            ];
        }

        $record->fill($payload)->save();

        return [
            'attributed' => true,
            'created' => 0,
            'updated' => 1,
            'cleared' => 0,
            'skipped' => 0,
        ];
    }

    /**
     * @param  Collection<int,int>  $profileIds
     */
    protected function resolveLastClickEvent(
        int $tenantId,
        ?string $storeKey,
        Collection $profileIds,
        CarbonInterface $orderAt,
        int $windowDays
    ): ?MarketingMessageEngagementEvent {
        $query = MarketingMessageEngagementEvent::query()
            ->forTenantId($tenantId)
            ->where('event_type', 'click')
            ->whereNotNull('marketing_email_delivery_id')
            ->whereIn('marketing_profile_id', $profileIds->all())
            ->whereNotNull('occurred_at')
            ->whereBetween('occurred_at', [$orderAt->copy()->subDays($windowDays), $orderAt]);

        if ($storeKey !== null) {
            $query->where('store_key', $storeKey);
        }

        return $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    protected function clearAttribution(int $tenantId, ?string $storeKey, int $orderId): int
    {
        return MarketingMessageOrderAttribution::query()
            ->where('tenant_id', $tenantId)
            ->where('store_key', $storeKey)
            ->where('order_id', $orderId)
            ->where('attribution_model', self::ATTRIBUTION_MODEL)
            ->delete();
    }

    /**
     * @return Collection<int,int>
     */
    protected function orderIdsForProfile(int $tenantId, int $profileId): Collection
    {
        $query = DB::table('marketing_profile_links')
            ->where('marketing_profile_id', $profileId)
            ->where('source_type', 'order');

        if (Schema::hasColumn('marketing_profile_links', 'tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->pluck('source_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int,int>  $orderIds
     * @return Collection<int,Collection<int,int>>
     */
    protected function profileLinksByOrderIds(int $tenantId, Collection $orderIds): Collection
    {
        if ($orderIds->isEmpty()) {
            return collect();
        }

        $query = DB::table('marketing_profile_links')
            ->whereIn('source_id', $orderIds->map(fn (int $id): string => (string) $id)->all())
            ->where('source_type', 'order');

        if (Schema::hasColumn('marketing_profile_links', 'tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->get(['source_id', 'marketing_profile_id'])
            ->groupBy(fn ($row): int => (int) ($row->source_id ?? 0))
            ->map(function (Collection $rows): Collection {
                return $rows
                    ->pluck('marketing_profile_id')
                    ->map(fn ($value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->values();
            });
    }

    protected function orderRevenueCents(Order $order): int
    {
        $total = (float) ($order->total_price ?? 0);

        return (int) round($total * 100);
    }

    protected function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function resolveDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return CarbonImmutable::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
