<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ShopifyStore;

/** Online orders allocate physical stock; only shipment and explicit returns move on-hand units. */
final class StockOnlineOrders
{
    public function __construct(private StockLedger $stock) {}

    public function ingest(int $tenant, string $storeKey, array $payload, bool $openingBaseline = false): array
    {
        $store = ShopifyStore::forTenantId($tenant)->where('store_key', $storeKey)->first();
        if (! $store || empty($payload['id']) || empty($payload['updated_at']) || ($payload['source_name'] ?? '') === 'everbranch_pos') {
            return ['managed' => false];
        }
        $binding = $this->stock->rows('bindings', $tenant)->where('channel', 'shopify')->where('channel_key', (string) $store->id)->where('active', true)->first();
        if (! $binding) {
            return ['managed' => false];
        }
        $settings = json_decode($binding->settings, true, 64, JSON_THROW_ON_ERROR);
        $cutover = strtotime($settings['cutover_at'] ?? '');
        $created = strtotime($payload['created_at'] ?? '');
        if (! $cutover || ! $created) {
            throw new StockException('stock_cutover_review_required');
        }
        $published = strtotime($payload['updated_at']);
        if (! $published || $published < $created) {
            throw new StockException('stock_order_timestamp_required');
        }
        $latest = $this->stock->rows('online_orders', $tenant)->where('store_id', $store->id)->where('order_id', (string) $payload['id'])->first();
        if ($latest && strtotime($latest->provider_updated_at) > $published) {
            return ['managed' => true, 'ignored_older' => true];
        }
        if (! $latest && $created < $cutover && ! $openingBaseline) {
            throw new StockException('stock_order_opening_baseline_required');
        }
        if (count($payload['line_items'] ?? []) >= 250 || count($payload['fulfillments'] ?? []) >= 100 || count($payload['refunds'] ?? []) >= 100) {
            throw new StockException('stock_order_completeness_review_required');
        }
        $map = $this->stock->rows('provider_items', $tenant)->where('store_id', $store->id)->get()->keyBy('variant_id');
        $shipped = [];
        $restocked = [];
        $seen = [];
        $cancelRestocked = [];
        $legacyRestocked = [];
        $physicalTimes = [];
        foreach ($payload['fulfillments'] ?? [] as $fulfillment) {
            if (empty($fulfillment['id']) || isset($seen['f'.$fulfillment['id']]) || in_array($fulfillment['status'] ?? '', ['cancelled', 'failure', 'error'], true)) {
                continue;
            }
            $seen['f'.$fulfillment['id']] = true;
            $occurred = strtotime($fulfillment['created_at'] ?? '');
            if (! $occurred) {
                throw new StockException('stock_fulfillment_time_required');
            }
            foreach ($fulfillment['line_items'] ?? [] as $line) {
                $id = (string) $line['id'];
                $shipped[$id] = ($shipped[$id] ?? 0) + max(0, (int) $line['quantity']);
                $physicalTimes[$id][] = $occurred;
                if (! empty($settings['location_id']) && 'gid://shopify/Location/'.($fulfillment['location_id'] ?? '') !== $settings['location_id']) {
                    throw new StockException('stock_fulfillment_location_review_required');
                }
            }
        }
        foreach ($payload['refunds'] ?? [] as $refund) {
            if (empty($refund['id']) || isset($seen['r'.$refund['id']])) {
                continue;
            }
            $seen['r'.$refund['id']] = true;
            foreach ($refund['refund_line_items'] ?? [] as $line) {
                if (in_array($line['restock_type'] ?? '', ['return', 'cancel', 'legacy_restock'], true)) {
                    $id = (string) ($line['line_item_id'] ?? $line['line_item']['id'] ?? '');
                    if ($line['restock_type'] === 'cancel') {
                        $cancelRestocked[$id] = ($cancelRestocked[$id] ?? 0) + max(0, (int) $line['quantity']);
                    } elseif ($line['restock_type'] === 'legacy_restock') {
                        $legacyRestocked[$id] = ($legacyRestocked[$id] ?? 0) + max(0, (int) $line['quantity']);
                    } else {
                        $restocked[$id] = ($restocked[$id] ?? 0) + max(0, (int) $line['quantity']);
                    }
                    $occurred = strtotime($refund['created_at'] ?? '');
                    if (! $occurred) {
                        throw new StockException('stock_return_time_required');
                    }
                    $physicalTimes[$id][] = $occurred;
                    if (! empty($settings['location_id']) && 'gid://shopify/Location/'.($line['location_id'] ?? '') !== $settings['location_id']) {
                        throw new StockException('stock_return_location_review_required');
                    }
                }
            }
        }
        $items = [];
        $baseline = [];
        $itemTimes = [];
        foreach ($payload['line_items'] ?? [] as $line) {
            $variant = 'gid://shopify/ProductVariant/'.($line['variant_id'] ?? '');
            if (! isset($map[$variant])) {
                throw new StockException('online_variant_mapping_required');
            }
            $item = $map[$variant]->item_id;
            if (! $this->stock->item($tenant, $item, true)->tracked) {
                continue;
            }
            $id = (string) $line['id'];
            $itemTimes[$item] = [...($itemTimes[$item] ?? []), ...($physicalTimes[$id] ?? [])];
            $sent = $shipped[$id] ?? 0;
            $ordered = max(0, (int) $line['quantity']);
            $explicitReturn = $restocked[$id] ?? 0;
            if ($sent > $ordered || $explicitReturn > $sent) {
                throw new StockException('stock_order_quantity_review_required');
            }
            $legacyReturn = min($sent - $explicitReturn, $legacyRestocked[$id] ?? 0);
            $return = $explicitReturn + $legacyReturn;
            $cancel = ($cancelRestocked[$id] ?? 0) + ($legacyRestocked[$id] ?? 0) - $legacyReturn;
            if ($cancel > $ordered - $sent) {
                throw new StockException('stock_order_quantity_review_required');
            }
            $remaining = $ordered - $sent - $cancel;
            if (! empty($payload['cancelled_at']) && $remaining > 0) {
                throw new StockException('stock_cancellation_restock_evidence_required');
            }
            $row = $items[$item] ?? ['reserved' => 0, 'dispatched' => 0, 'restocked' => 0, 'ordered' => 0, 'cancel_restocked' => 0];
            $row['reserved'] += $remaining;
            $row['dispatched'] += $sent;
            $row['restocked'] += $return;
            $row['ordered'] += $ordered;
            $row['cancel_restocked'] += $cancel;
            $items[$item] = $row;
            $baseline[$item] = [...$row, 'reserved' => 0];
        }
        ksort($items);
        $order = (string) $payload['id'];
        $request = ($openingBaseline ? 'shopify-opening:' : 'shopify:').$store->id.':'.$order.':'.$published.':'.hash('sha256', json_encode($items));

        return $this->stock->operation($tenant, $request, 'online_order', 0, [$binding->pool_id, $items, $openingBaseline], function ($key) use ($tenant, $store, $binding, $order, $published, $items, $baseline, $openingBaseline, $restocked, $payload, $itemTimes): array {
            $old = $this->stock->rows('online_orders', $tenant)->where('store_id', $store->id)->where('order_id', $order)->first();
            if ($old && strtotime($old->provider_updated_at) > $published) {
                return ['managed' => true, 'ignored_older' => true];
            }
            $prior = $old ? json_decode($old->lines, true, 64, JSON_THROW_ON_ERROR) : ($openingBaseline ? $baseline : []);
            if ($old && strtotime($old->provider_updated_at) === $published) {
                if ($prior !== $items) {
                    throw new StockException('stock_order_evidence_conflict');
                }

                return ['managed' => true, 'duplicate' => true];
            }
            if ($old && $old->pool_id !== $binding->pool_id) {
                throw new StockException('stock_order_pool_changed');
            }
            foreach (array_unique([...array_keys($prior), ...array_keys($items)]) as $item) {
                $a = $prior[$item] ?? ['reserved' => 0, 'dispatched' => 0, 'restocked' => 0, 'ordered' => 0, 'cancel_restocked' => 0];
                if (! isset($items[$item]) && $a['reserved'] > 0) {
                    throw new StockException('stock_order_removed_allocation_review_required');
                }
                $b = $items[$item] ?? $a;
                $items[$item] = $b;
                if ($b['ordered'] < $a['ordered'] || $b['cancel_restocked'] < $a['cancel_restocked']) {
                    throw new StockException('stock_order_allocation_review_required');
                }
                if ($b['dispatched'] < $a['dispatched'] || $b['restocked'] < $a['restocked']) {
                    throw new StockException('stock_order_history_regressed');
                }
                $onHandChange = -($b['dispatched'] - $a['dispatched']) + ($b['restocked'] - $a['restocked']);
                $reservedChange = $b['reserved'] - $a['reserved'];
                $balance = $this->stock->balance($tenant, $binding->pool_id, $item, true);
                if (! $openingBaseline && $onHandChange !== 0 && ! empty($itemTimes[$item]) && min($itemTimes[$item]) < strtotime($balance->counted_at ?? '')) {
                    throw new StockException('stock_movement_predates_physical_count_review_required');
                }
                if ($balance->reserved + $reservedChange > $balance->on_hand + $onHandChange) {
                    app(\App\Services\Pos\StockPosCommerce::class)->preemptUnstarted($tenant, $binding->pool_id, $item);
                }
                $this->stock->externalChange($tenant, $binding->pool_id, $item, $onHandChange, $reservedChange, $key, 'shopify:'.$store->id.':'.$order);
                $delta = -($b['dispatched'] - $a['dispatched']) + ($b['restocked'] - $a['restocked']) - ($b['reserved'] - $a['reserved']);
                $mapping = $this->stock->rows('provider_items', $tenant)->where('store_id', $store->id)->where('item_id', $item)->lockForUpdate()->first();
                if ($mapping && $mapping->acknowledged_quantity !== null && $delta !== 0 && ! ($openingBaseline && ! $old)) {
                    $uncertain = $this->stock->rows('publications', $tenant)->where('binding_id', $binding->id)->where('item_id', $item)->where('state', 'dispatching')->exists();
                    if ($uncertain) {
                        $this->stock->rows('provider_items', $tenant)->where('id', $mapping->id)->update(['pending_online_delta' => $mapping->pending_online_delta + $delta]);
                    } else {
                        $this->stock->rows('provider_items', $tenant)->where('id', $mapping->id)->update(['acknowledged_quantity' => $mapping->acknowledged_quantity + $delta]);
                        $this->stock->rows('publications', $tenant)->where('binding_id', $binding->id)->where('item_id', $item)
                            ->where('state', 'review')->where('error_code', 'provider_quantity_changed_reconcile_online_orders')->update(['state' => 'superseded', 'updated_at' => now()]);
                    }
                }
            }
            $this->stock->rows('online_orders', $tenant)->updateOrInsert(['tenant_id' => $tenant, 'store_id' => $store->id, 'order_id' => $order],
                ['pool_id' => $binding->pool_id, 'state' => ! empty($payload['cancelled_at']) ? 'cancelled' : (($payload['fulfillment_status'] ?? '') === 'fulfilled' ? 'fulfilled' : 'allocated'),
                    'provider_updated_at' => gmdate('Y-m-d H:i:s', $published), 'lines' => json_encode($items, JSON_THROW_ON_ERROR),
                    'restocks' => json_encode(array_keys($restocked)), 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);

            return ['managed' => true, 'order_id' => $order];
        });
    }
}
