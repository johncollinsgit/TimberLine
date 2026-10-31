<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ShopifyStore;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Str;

/** Reviewed physical baseline. Provider quantities are comparison evidence, never added stock. */
final class StockOnlineOpening
{
    public function __construct(private StockLedger $stock, private StockShopifyApi $api, private StockOnlineOrders $orders) {}

    public function review(int $tenant, int $actor, array $input, bool $apply): array
    {
        $store = ShopifyStore::forTenantId($tenant)->find($input['store_id'] ?? 0);
        if (! $store || ! Str::isUuid($input['request_id'] ?? '') || ($input['physical_counts_reviewed'] ?? false) !== true
            || ! preg_match('/^gid:\/\/shopify\/Location\/\d+$/D', $input['location_id'] ?? '')
            || ! preg_match('/^gid:\/\/shopify\/Shop\/\d+$/D', $input['shop_id'] ?? '')
            || ! is_array($input['expected_versions'] ?? null) || ! is_array($input['opening_order_ids'] ?? null)
            || count($input['opening_order_ids']) > 500 || count(array_unique($input['opening_order_ids'])) !== count($input['opening_order_ids'])) {
            throw new StockException('stock_opening_review_required');
        }
        $pool = $this->stock->pool($tenant, $input['pool_id']);
        if ($pool->kind === 'show') {
            throw new StockException('stock_online_pool_review_required');
        }
        foreach (['read_inventory', 'write_inventory', 'read_orders'] as $scope) {
            if (! in_array($scope, preg_split('/[\s,]+/', (string) $store->scopes), true)) {
                throw new StockException('stock_write_permission_required');
            }
        }
        $request = 'online-opening:'.$input['request_id'];
        if ($this->stock->rows('operations', $tenant)->where('request_hash', hash('sha256', $request))->exists()) {
            $result = $this->stock->operation($tenant, $request, 'online_opening', $actor, $input, fn () => throw new StockException('stock_opening_retry_required'));

            return [...$result, 'applied' => true];
        }
        $existing = $this->stock->rows('bindings', $tenant)->where('channel', 'shopify')->where('channel_key', (string) $store->id)->first();
        if ($existing && (json_decode($existing->settings, true)['opening_reviewed_at'] ?? null)) {
            throw new StockException('stock_opening_already_applied');
        }
        $mappings = $this->stock->rows('provider_items', $tenant)->where('store_id', $store->id)->where('active', true)->get()->keyBy('variant_id');
        $mappingHash = $this->mappingHash($tenant, $store->id);
        if ($mappings->isEmpty()) {
            throw new StockException('stock_catalog_setup_required');
        }
        $orders = [];
        foreach ($input['opening_order_ids'] as $id) {
            if (! is_string($id)) {
                throw new StockException('stock_order_unconfirmed');
            }
            $orders[] = $this->api->order($tenant, $store->id, $id);
        }
        // Read quantities AFTER order evidence. Any newer commitment must prevent an opening increase.
        $cursor = null;
        $seen = [];
        $levels = [];
        do {
            $data = $this->api->request($tenant, $store->id, StockShopifyApi::CATALOG, ['cursor' => $cursor, 'location' => $input['location_id']]);
            if (($data['shop']['id'] ?? '') !== $input['shop_id'] || ($data['shop']['currencyCode'] ?? '') !== 'USD') {
                throw new StockException('stock_store_identity_changed');
            }
            foreach ($data['productVariants']['nodes'] ?? [] as $v) {
                if (($v['product']['status'] ?? '') !== 'ACTIVE' || ($v['product']['isGiftCard'] ?? true)) {
                    continue;
                }
                $mapping = $mappings[$v['id']] ?? null;
                if (! $mapping || $mapping->inventory_item_id !== ($v['inventoryItem']['id'] ?? null) || $mapping->location_id !== $input['location_id']
                    || ($v['inventoryItem']['tracked'] ?? false) !== true || ($v['inventoryPolicy'] ?? '') !== 'DENY'
                    || ($v['requiresComponents'] ?? true) || (json_decode($this->stock->item($tenant, $mapping->item_id)->metadata ?? '{}', true)['requires_components'] ?? false)) {
                    throw new StockException('stock_online_product_contract_review_required');
                }
                $quantities = array_column($v['inventoryItem']['inventoryLevel']['quantities'] ?? [], 'quantity', 'name');
                if (! is_int($quantities['available'] ?? null) || ! is_int($quantities['committed'] ?? null)) {
                    throw new StockException('stock_publication_baseline_required');
                }
                $levels[$mapping->item_id] = $quantities;
            }
            $page = $data['productVariants']['pageInfo'] ?? [];
            if (! is_bool($page['hasNextPage'] ?? null)) {
                throw new StockException('stock_catalog_page_unconfirmed');
            }
            $cursor = $page['hasNextPage'] ? ($page['endCursor'] ?? '') : null;
            if ($cursor !== null && (! is_string($cursor) || $cursor === '' || isset($seen[$cursor]))) {
                throw new StockException('stock_catalog_cursor_invalid');
            }
            if ($cursor) {
                $seen[$cursor] = true;
            }
        } while ($cursor !== null);
        if (count($levels) !== $mappings->count() || count($levels) !== count($input['expected_versions'])) {
            throw new StockException('stock_opening_mapping_review_required');
        }
        $cutover = now()->toIso8601String();
        $this->stock->db->beginTransaction();
        try {
            $result = $this->stock->operation($tenant, 'online-opening:'.$input['request_id'], 'online_opening', $actor, $input, function () use ($tenant, $actor, $input, $store, $levels, $orders, $cutover, $mappingHash): array {
                if (! hash_equals($mappingHash, $this->mappingHash($tenant, $store->id))) {
                    throw new StockException('stock_opening_mapping_review_required');
                }
                $this->stock->bind($tenant, 'online-opening-binding:'.$input['request_id'], $actor, 'shopify', (string) $store->id, $input['pool_id'],
                    ['cutover_at' => $cutover, 'location_id' => $input['location_id']], false);
                foreach ($levels as $item => $level) {
                    $b = $this->stock->balance($tenant, $input['pool_id'], $item);
                    if (! $b->counted_at || ! is_int($input['expected_versions'][$item] ?? null) || $input['expected_versions'][$item] !== (int) $b->version) {
                        throw new StockException('stock_count_changed_by_activity');
                    }
                }
                foreach ($orders as $order) {
                    $this->orders->ingest($tenant, $store->store_key, $order, true);
                }
                foreach ($levels as $item => $level) {
                    $reserved = 0;
                    foreach ($this->stock->rows('online_orders', $tenant)->where('store_id', $store->id)->get() as $order) {
                        $reserved += json_decode($order->lines, true)[$item]['reserved'] ?? 0;
                    }
                    if ($level['committed'] !== $reserved) {
                        throw new StockException('stock_online_commitments_unreconciled');
                    }
                    $this->stock->rows('provider_items', $tenant)->where('store_id', $store->id)->where('item_id', $item)->update([
                        'acknowledged_quantity' => $level['available'], 'acknowledged_version' => 0, 'pending_online_delta' => 0]);
                }
                $binding = $this->stock->rows('bindings', $tenant)->where('channel', 'shopify')->where('channel_key', (string) $store->id)->firstOrFail();
                $this->stock->rows('bindings', $tenant)->where('id', $binding->id)->update(['publish_enabled' => true,
                    'settings' => json_encode(['cutover_at' => $cutover, 'location_id' => $input['location_id'], 'opening_reviewed_at' => $cutover, 'evidence_hash' => Contract::hash([$levels, $orders])], JSON_THROW_ON_ERROR)]);
                // Queue the current projection, including zero-stock items, without inventing a movement.
                foreach ($levels as $item => $level) {
                    $b = $this->stock->balance($tenant, $input['pool_id'], $item);
                    $this->stock->rows('publications', $tenant)->insertOrIgnore(['tenant_id' => $tenant, 'binding_id' => $binding->id, 'pool_id' => $input['pool_id'], 'item_id' => $item,
                        'version' => $b->version, 'quantity' => $b->on_hand - $b->reserved, 'idempotency_key' => (string) Str::uuid(), 'state' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
                }

                return ['reviewed_items' => count($levels), 'opening_orders' => count($orders), 'pool_id' => $input['pool_id'], 'physical_quantities_imported' => false];
            });
            if ($apply) {
                $this->stock->db->commit();
            } else {
                $this->stock->db->rollBack();
            }

            return [...$result, 'applied' => $apply];
        } catch (\Throwable $error) {
            $this->stock->db->rollBack();
            throw $error;
        }
    }

    private function mappingHash(int $tenant, int $store): string
    {
        return Contract::hash($this->stock->rows('provider_items', $tenant)->where('store_id', $store)->where('active', true)
            ->orderBy('variant_id')->get()->map(fn ($row) => (array) $row)->all());
    }
}
