<?php

namespace App\Services\Marketing;

use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class StorefrontOrderLinkageService
{
    public function __construct(
        protected MarketingAttributionSourceMetaBuilder $attributionSourceMetaBuilder
    ) {
    }

    /**
     * @param  array<string,mixed>  $orderPayload
     * @param  array{tenant_id?:?int,store_key?:?string}  $options
     * @return array{
     *   status:string,
     *   linked:bool,
     *   method:?string,
     *   confidence:?float,
     *   purchase_event_id:?int,
     *   matched_event_id:?int
     * }
     */
    public function linkOrder(Order $order, array $orderPayload = [], array $options = []): array
    {
        $storeKey = $this->nullableString($options['store_key'] ?? null)
            ?? $this->nullableString($order->shopify_store_key ?? null)
            ?? $this->nullableString($order->shopify_store ?? null);
        $tenantId = $this->positiveInt($options['tenant_id'] ?? null)
            ?? $this->positiveInt($order->tenant_id ?? null);
        $orderedAt = $this->resolveDate($order->ordered_at) ?? $this->resolveDate($order->created_at) ?? now()->toImmutable();

        $existingMeta = is_array($order->attribution_meta ?? null) ? $order->attribution_meta : [];
        $signals = $this->linkSignals($orderPayload, $existingMeta);

        $matched = $this->bestMatchingEvent(
            tenantId: $tenantId,
            storeKey: $storeKey,
            orderAt: $orderedAt,
            signals: $signals
        );

        $linkMethod = $matched['method'] ?? $this->fallbackLinkMethod($signals);
        $linkConfidence = $matched['confidence'] ?? $this->fallbackConfidence($signals);
        $matchedEvent = $matched['event'] ?? null;

        $purchaseEvent = $this->recordPurchaseEvent(
            order: $order,
            tenantId: $tenantId,
            storeKey: $storeKey,
            orderedAt: $orderedAt,
            signals: $signals,
            matchedEvent: $matchedEvent,
            linkMethod: $linkMethod,
            linkConfidence: $linkConfidence
        );

        $enrichedMeta = $this->attributionSourceMetaBuilder->mergeSourceMeta(
            $existingMeta,
            $this->attributionSourceMetaBuilder->fromMeta($this->attributionMetaSignals($signals, $matchedEvent), 'storefront_purchase_linkage')
        );
        $enrichedMeta['storefront_link'] = [
            'linked' => $matchedEvent instanceof MarketingStorefrontEvent,
            'method' => $linkMethod,
            'confidence' => $linkConfidence,
            'matched_event_id' => $matchedEvent?->id,
            'purchase_event_id' => $purchaseEvent?->id,
            'linked_at' => now()->toIso8601String(),
            'matched_event_type' => $matchedEvent ? (string) $matchedEvent->event_type : null,
            'matched_event_occurred_at' => $matchedEvent?->occurred_at?->toIso8601String(),
        ];

        $orderPatch = [
            'attribution_meta' => $enrichedMeta,
        ];

        if (Schema::hasColumn('orders', 'storefront_checkout_token')) {
            $orderPatch['storefront_checkout_token'] = $signals['checkout_token'] ?? null;
        }
        if (Schema::hasColumn('orders', 'storefront_cart_token')) {
            $orderPatch['storefront_cart_token'] = $signals['cart_token'] ?? null;
        }
        if (Schema::hasColumn('orders', 'storefront_session_key')) {
            $orderPatch['storefront_session_key'] = $signals['session_key'] ?? null;
        }
        if (Schema::hasColumn('orders', 'storefront_client_id')) {
            $orderPatch['storefront_client_id'] = $signals['client_id'] ?? null;
        }
        if (Schema::hasColumn('orders', 'storefront_message_delivery_id')) {
            $orderPatch['storefront_message_delivery_id'] = $this->positiveInt($signals['mf_delivery_id'] ?? null);
        }
        if (Schema::hasColumn('orders', 'storefront_linked_event_id')) {
            $orderPatch['storefront_linked_event_id'] = $purchaseEvent?->id;
        }
        if (Schema::hasColumn('orders', 'storefront_link_confidence')) {
            $orderPatch['storefront_link_confidence'] = $linkConfidence;
        }
        if (Schema::hasColumn('orders', 'storefront_link_method')) {
            $orderPatch['storefront_link_method'] = $linkMethod;
        }
        if (Schema::hasColumn('orders', 'storefront_linked_at')) {
            $orderPatch['storefront_linked_at'] = now();
        }

        $order->forceFill($orderPatch)->saveQuietly();

        return [
            'status' => 'ok',
            'linked' => $matchedEvent instanceof MarketingStorefrontEvent,
            'method' => $linkMethod,
            'confidence' => $linkConfidence,
            'purchase_event_id' => $purchaseEvent?->id,
            'matched_event_id' => $matchedEvent?->id,
        ];
    }

    /**
     * @param  array<string,mixed>  $orderPayload
     * @param  array<string,mixed>  $existingMeta
     * @return array<string,string>
     */
    protected function linkSignals(array $orderPayload, array $existingMeta): array
    {
        $noteSignals = $this->signalsFromNoteAttributes((array) ($orderPayload['note_attributes'] ?? []));
        $querySignals = array_merge(
            $this->signalsFromUrl($this->nullableString($orderPayload['landing_site'] ?? null)),
            $this->signalsFromUrl($this->nullableString($orderPayload['landing_page'] ?? null)),
            $this->signalsFromUrl($this->nullableString($orderPayload['source_url'] ?? null)),
            $this->signalsFromUrl($this->nullableString($existingMeta['landing_site'] ?? null)),
            $this->signalsFromUrl($this->nullableString($existingMeta['landing_page'] ?? null)),
            $this->signalsFromUrl($this->nullableString($existingMeta['source_url'] ?? null)),
        );

        $resolved = [];
        foreach ([
            'checkout_token',
            'cart_token',
            'session_key',
            'session_id',
            'client_id',
            'mf_delivery_id',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'fbclid',
            'fbc',
            'fbp',
        ] as $field) {
            $value = $this->firstNonEmpty(
                $orderPayload[$field] ?? null,
                $noteSignals[$field] ?? null,
                $existingMeta[$field] ?? null,
                $querySignals[$field] ?? null
            );

            if ($value !== null) {
                $resolved[$field] = $value;
            }
        }

        if (! array_key_exists('session_key', $resolved) && array_key_exists('session_id', $resolved)) {
            $resolved['session_key'] = $resolved['session_id'];
        }
        if (! array_key_exists('session_id', $resolved) && array_key_exists('session_key', $resolved)) {
            $resolved['session_id'] = $resolved['session_key'];
        }

        return $resolved;
    }

    /**
     * @param  array<string,string>  $signals
     * @return array{event:?MarketingStorefrontEvent,confidence:?float,method:?string}
     */
    protected function bestMatchingEvent(?int $tenantId, ?string $storeKey, CarbonImmutable $orderAt, array $signals): array
    {
        if ($tenantId === null || $storeKey === null || ! Schema::hasTable('marketing_storefront_events')) {
            return ['event' => null, 'confidence' => null, 'method' => null];
        }

        $events = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'shopify_storefront_funnel')
            ->whereBetween('occurred_at', [$orderAt->subDays(14), $orderAt->addDay()])
            ->where('meta->store_key', $storeKey)
            ->orderByDesc('occurred_at')
            ->limit(800)
            ->get(['id', 'event_type', 'occurred_at', 'meta', 'marketing_profile_id']);

        $best = ['event' => null, 'confidence' => null, 'method' => null, 'score' => 0.0];

        foreach ($events as $event) {
            $score = $this->scoreEventMatch($signals, $event, $orderAt);
            if ($score['score'] <= (float) $best['score']) {
                continue;
            }

            $best = [
                'event' => $event,
                'confidence' => $score['score'],
                'method' => $score['method'],
                'score' => $score['score'],
            ];
        }

        if (! $best['event'] instanceof MarketingStorefrontEvent) {
            return ['event' => null, 'confidence' => null, 'method' => null];
        }

        return [
            'event' => $best['event'],
            'confidence' => round((float) $best['confidence'], 2),
            'method' => $best['method'],
        ];
    }

    /**
     * @param  array<string,string>  $signals
     * @return array{score:float,method:?string}
     */
    protected function scoreEventMatch(array $signals, MarketingStorefrontEvent $event, CarbonImmutable $orderAt): array
    {
        $meta = is_array($event->meta ?? null) ? $event->meta : [];
        $eventCheckout = $this->nullableString($meta['checkout_token'] ?? null);
        $eventCart = $this->nullableString($meta['cart_token'] ?? null);
        $eventSession = $this->nullableString($meta['session_key'] ?? null) ?? $this->nullableString($meta['session_id'] ?? null);
        $eventClient = $this->nullableString($meta['client_id'] ?? null);
        $eventDeliveryId = $this->nullableString($meta['mf_delivery_id'] ?? null);

        $score = 0.0;
        $method = null;

        if (($signals['checkout_token'] ?? null) !== null && $eventCheckout !== null && hash_equals($signals['checkout_token'], $eventCheckout)) {
            $score = 1.0;
            $method = 'checkout_token_exact';
        } elseif (($signals['cart_token'] ?? null) !== null && $eventCart !== null && hash_equals($signals['cart_token'], $eventCart)) {
            $score = 0.92;
            $method = 'cart_token_exact';
        } elseif (($signals['session_key'] ?? null) !== null && $eventSession !== null && hash_equals($signals['session_key'], $eventSession)) {
            $score = 0.82;
            $method = 'session_key_exact';
        } elseif (($signals['client_id'] ?? null) !== null && $eventClient !== null && hash_equals($signals['client_id'], $eventClient)) {
            $score = 0.72;
            $method = 'client_id_exact';
        } elseif (($signals['mf_delivery_id'] ?? null) !== null && $eventDeliveryId !== null && hash_equals($signals['mf_delivery_id'], $eventDeliveryId)) {
            $score = 0.68;
            $method = 'message_delivery_exact';
        }

        if ($score === 0.0) {
            return ['score' => 0.0, 'method' => null];
        }

        if (in_array((string) $event->event_type, ['checkout_completed', 'purchase'], true)) {
            $score += 0.03;
        }

        $eventAt = $this->resolveDate($event->occurred_at);
        if ($eventAt instanceof CarbonImmutable) {
            $minutes = abs($eventAt->diffInMinutes($orderAt));
            if ($minutes <= 120) {
                $score += 0.02;
            }
        }

        return [
            'score' => min(1.0, $score),
            'method' => $method,
        ];
    }

    /**
     * @param  array<string,string>  $signals
     */
    protected function recordPurchaseEvent(
        Order $order,
        ?int $tenantId,
        ?string $storeKey,
        CarbonImmutable $orderedAt,
        array $signals,
        ?MarketingStorefrontEvent $matchedEvent,
        ?string $linkMethod,
        ?float $linkConfidence
    ): ?MarketingStorefrontEvent {
        if (! Schema::hasTable('marketing_storefront_events')) {
            return null;
        }

        $sourceId = $storeKey !== null
            ? $storeKey . ':' . ($this->nullableString($order->shopify_order_id ?? null) ?? ('order-' . (string) $order->id))
            : ('order:' . (string) $order->id);

        $purchaseMeta = array_filter([
            'store_key' => $storeKey,
            'shopify_order_id' => $this->nullableString($order->shopify_order_id ?? null),
            'order_id' => (int) $order->id,
            'order_number' => $this->nullableString($order->order_number ?? null),
            'checkout_token' => $signals['checkout_token'] ?? null,
            'cart_token' => $signals['cart_token'] ?? null,
            'session_key' => $signals['session_key'] ?? null,
            'session_id' => $signals['session_id'] ?? null,
            'client_id' => $signals['client_id'] ?? null,
            'mf_delivery_id' => $this->positiveInt($signals['mf_delivery_id'] ?? null),
            'utm_source' => $signals['utm_source'] ?? null,
            'utm_medium' => $signals['utm_medium'] ?? null,
            'utm_campaign' => $signals['utm_campaign'] ?? null,
            'utm_content' => $signals['utm_content'] ?? null,
            'utm_term' => $signals['utm_term'] ?? null,
            'fbclid' => $signals['fbclid'] ?? null,
            'fbc' => $signals['fbc'] ?? null,
            'fbp' => $signals['fbp'] ?? null,
            'linked_storefront_event_id' => $matchedEvent?->id,
            'link_method' => $linkMethod,
            'link_confidence' => $linkConfidence,
            'tracker' => 'order_ingest',
        ], static fn ($value) => $value !== null && $value !== '');

        $event = MarketingStorefrontEvent::query()
            ->where('source_type', 'shopify_storefront_purchase')
            ->where('source_id', $sourceId)
            ->where('event_type', 'purchase')
            ->first();

        if ($event instanceof MarketingStorefrontEvent) {
            $event->forceFill([
                'tenant_id' => $tenantId,
                'marketing_profile_id' => $matchedEvent?->marketing_profile_id,
                'status' => 'ok',
                'source_surface' => 'shopify_storefront',
                'endpoint' => 'shopify_order_ingest',
                'signature_mode' => 'internal_ingest',
                'meta' => $purchaseMeta,
                'occurred_at' => $orderedAt,
                'resolution_status' => 'resolved',
            ])->save();

            return $event;
        }

        return MarketingStorefrontEvent::query()->create([
            'tenant_id' => $tenantId,
            'event_type' => 'purchase',
            'status' => 'ok',
            'source_surface' => 'shopify_storefront',
            'endpoint' => 'shopify_order_ingest',
            'request_key' => null,
            'signature_mode' => 'internal_ingest',
            'marketing_profile_id' => $matchedEvent?->marketing_profile_id,
            'source_type' => 'shopify_storefront_purchase',
            'source_id' => $sourceId,
            'meta' => $purchaseMeta,
            'occurred_at' => $orderedAt,
            'resolution_status' => 'resolved',
        ]);
    }

    /**
     * @param  array<string,string>  $signals
     * @return array<string,mixed>
     */
    protected function attributionMetaSignals(array $signals, ?MarketingStorefrontEvent $matchedEvent): array
    {
        $eventMeta = is_array($matchedEvent?->meta ?? null) ? $matchedEvent->meta : [];

        $payload = [];
        foreach ([
            'checkout_token',
            'cart_token',
            'session_key',
            'session_id',
            'client_id',
            'mf_delivery_id',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'fbclid',
            'fbc',
            'fbp',
        ] as $field) {
            $value = $this->firstNonEmpty($signals[$field] ?? null, $eventMeta[$field] ?? null);
            if ($value !== null) {
                $payload[$field] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param  array<int,array<string,mixed>>  $noteAttributes
     * @return array<string,string>
     */
    protected function signalsFromNoteAttributes(array $noteAttributes): array
    {
        $signals = [];

        foreach ($noteAttributes as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = strtolower(trim((string) ($row['name'] ?? '')));
            $value = $this->nullableString($row['value'] ?? null);
            if ($name === '' || $value === null) {
                continue;
            }

            $normalized = str_replace(['-', ' '], '_', $name);

            $field = match (true) {
                in_array($normalized, ['checkout_token', 'cart_token', 'session_key', 'session_id', 'client_id', 'fbclid', 'fbc', 'fbp', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'], true) => $normalized,
                str_contains($normalized, 'mf_delivery_id') => 'mf_delivery_id',
                default => null,
            };

            if ($field !== null) {
                $signals[$field] = $value;
            }
        }

        return $signals;
    }

    /**
     * @return array<string,string>
     */
    protected function signalsFromUrl(?string $url): array
    {
        $url = $this->nullableString($url);
        if ($url === null) {
            return [];
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['query'])) {
            return [];
        }

        parse_str((string) $parts['query'], $query);
        if (! is_array($query)) {
            return [];
        }

        $signals = [];
        foreach ([
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'fbclid',
            'fbc',
            'fbp',
            'checkout_token',
            'cart_token',
            'session_key',
            'session_id',
            'client_id',
            'mf_delivery_id',
        ] as $key) {
            $value = $this->nullableString($query[$key] ?? null);
            if ($value !== null) {
                $signals[$key] = $value;
            }
        }

        return $signals;
    }

    protected function fallbackLinkMethod(array $signals): string
    {
        if (array_key_exists('checkout_token', $signals)) {
            return 'order_checkout_token_only';
        }

        if (array_key_exists('cart_token', $signals)) {
            return 'order_cart_token_only';
        }

        return 'order_payload_only';
    }

    protected function fallbackConfidence(array $signals): float
    {
        if (array_key_exists('checkout_token', $signals)) {
            return 0.55;
        }

        if (array_key_exists('cart_token', $signals) || array_key_exists('session_key', $signals)) {
            return 0.45;
        }

        return 0.20;
    }

    protected function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $resolved = $this->nullableString($value);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }

    protected function resolveDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }
}
