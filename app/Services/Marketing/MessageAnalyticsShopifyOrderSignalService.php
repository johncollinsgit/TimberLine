<?php

namespace App\Services\Marketing;

use App\Models\Order;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyStores;

class MessageAnalyticsShopifyOrderSignalService
{
    public function __construct(
        protected MarketingAttributionSourceMetaBuilder $attributionSourceMetaBuilder
    ) {
    }

    /**
     * @param  iterable<int,Order>  $orders
     * @return array<int,array<string,mixed>>
     */
    public function refreshForOrders(iterable $orders): array
    {
        $collection = collect($orders)
            ->filter(fn ($order): bool => $order instanceof Order)
            ->keyBy(fn (Order $order): int => (int) $order->id);

        if ($collection->isEmpty()) {
            return [];
        }

        $metaByOrderId = $collection
            ->map(fn (Order $order): array => $this->storedMeta($order))
            ->all();

        $ordersByStore = [];

        foreach ($collection as $order) {
            $storeKey = $this->storeKeyForOrder($order);
            $shopifyOrderId = $this->positiveInt($order->shopify_order_id);

            if ($storeKey === null || $shopifyOrderId === null) {
                continue;
            }

            $ordersByStore[$storeKey][$shopifyOrderId] = (int) $order->id;
        }

        foreach ($ordersByStore as $storeKey => $orderIdMap) {
            $store = ShopifyStores::find($storeKey);
            if (! is_array($store)) {
                continue;
            }

            $client = new ShopifyClient(
                (string) ($store['shop'] ?? ''),
                (string) ($store['token'] ?? ''),
                (string) ($store['api_version'] ?? '2026-01')
            );

            foreach (array_chunk(array_keys($orderIdMap), 50) as $shopifyOrderIds) {
                try {
                    $payload = $client->get('orders.json', [
                        'status' => 'any',
                        'ids' => implode(',', $shopifyOrderIds),
                        'fields' => implode(',', [
                            'id',
                            'landing_site',
                            'referring_site',
                            'source_name',
                            'source_identifier',
                            'source_url',
                            'checkout_token',
                            'cart_token',
                            'landing_page',
                            'fbclid',
                            'fbc',
                            'fbp',
                            'created_at',
                            'client_details',
                            'note_attributes',
                            'tags',
                        ]),
                    ]);
                } catch (\Throwable) {
                    continue;
                }

                foreach ((array) ($payload['orders'] ?? []) as $remoteOrder) {
                    if (! is_array($remoteOrder)) {
                        continue;
                    }

                    $shopifyOrderId = $this->positiveInt($remoteOrder['id'] ?? null);
                    if ($shopifyOrderId === null) {
                        continue;
                    }

                    $localOrderId = $orderIdMap[$shopifyOrderId] ?? null;
                    if (! is_int($localOrderId)) {
                        continue;
                    }

                    /** @var Order|null $order */
                    $order = $collection->get($localOrderId);
                    if (! $order instanceof Order) {
                        continue;
                    }

                    $existingMeta = $metaByOrderId[$localOrderId] ?? $this->storedMeta($order);
                    $remoteMeta = $this->attributionSourceMetaBuilder->fromShopifyOrderPayload($remoteOrder, $storeKey);
                    $mergedMeta = $remoteMeta === []
                        ? $existingMeta
                        : $this->attributionSourceMetaBuilder->mergeSourceMeta($existingMeta, $remoteMeta);

                    $metaByOrderId[$localOrderId] = $mergedMeta;

                    if ($mergedMeta !== $existingMeta) {
                        $order->forceFill([
                            'attribution_meta' => $mergedMeta,
                        ])->saveQuietly();
                    }
                }
            }
        }

        return $metaByOrderId;
    }

    protected function storeKeyForOrder(Order $order): ?string
    {
        $storeKey = trim((string) ($order->shopify_store_key ?: $order->shopify_store ?: ''));

        return $storeKey !== '' ? $storeKey : null;
    }

    /**
     * @return array<string,mixed>
     */
    protected function storedMeta(Order $order): array
    {
        return is_array($order->attribution_meta ?? null) ? $order->attribution_meta : [];
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }
}
