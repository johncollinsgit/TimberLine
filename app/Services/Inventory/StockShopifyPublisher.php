<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ShopifyStore;
use Illuminate\Support\Facades\Cache;

/** Set projections only against an acknowledged provider quantity; never overwrite an unseen order. */
final class StockShopifyPublisher
{
    public function __construct(private StockLedger $stock, private StockShopifyApi $api) {}

    public function drain(int $tenant, int $limit = 30): array
    {
        if (config('pos_live.inventory_publication_enabled') !== true) {
            return ['published' => 0, 'disabled' => true];
        }
        $published = 0;
        $review = 0;

        return Cache::lock('stock-publish-'.$tenant, 180)->block(5, function () use ($tenant, $limit, &$published, &$review): array {
            foreach ($this->stock->rows('publications', $tenant)->whereIn('state', ['pending', 'dispatching'])->orderBy('id')->limit(min(100, max(1, $limit)))->get() as $effect) {
                $effect = $this->stock->rows('publications', $tenant)->where('id', $effect->id)->first();
                if (! $effect || ! in_array($effect->state, ['pending', 'dispatching'], true)) {
                    continue;
                }
                $binding = $this->stock->rows('bindings', $tenant)->where('id', $effect->binding_id)->where('active', true)->where('publish_enabled', true)->first();
                if (! $binding) {
                    continue;
                }
                $storeId = (int) $binding->channel_key;
                // A delayed/unmapped online event must stop publication from restoring its sold units.
                if ($this->stock->rows('online_inbox', $tenant)->where('store_id', $storeId)->where('state', '!=', 'completed')->exists()) {
                    continue;
                }
                $mappings = $this->stock->rows('provider_items', $tenant)->where('store_id', $storeId)->where('item_id', $effect->item_id)->where('active', true)->limit(2)->get();
                if ($mappings->count() !== 1) {
                    $this->review($tenant, $effect->id, 'stock_mapping_required');
                    $review++;

                    continue;
                }
                $mapping = $mappings->first();
                if ($mapping->inventory_policy !== 'DENY') {
                    $this->review($tenant, $effect->id, 'stock_no_overselling_policy_required');
                    $review++;

                    continue;
                }
                // A failed predecessor or unreviewed baseline must never be skipped.
                if ($this->stock->rows('publications', $tenant)->where('binding_id', $binding->id)->where('item_id', $effect->item_id)
                    ->where('id', '<', $effect->id)->whereNotIn('state', ['completed', 'superseded'])->exists()) {
                    continue;
                }
                $settings = json_decode($binding->settings, true);
                if (empty($settings['opening_reviewed_at']) || $mapping->acknowledged_quantity === null) {
                    $this->review($tenant, $effect->id, 'stock_publication_baseline_required');
                    $review++;

                    continue;
                }
                $store = ShopifyStore::forTenantId($tenant)->find($storeId);
                if (! $store || ! in_array('write_inventory', preg_split('/[\s,]+/', (string) $store->scopes), true)) {
                    $this->review($tenant, $effect->id, 'stock_write_permission_required');
                    $review++;

                    continue;
                }
                if ($effect->state === 'pending') {
                    if ((int) $effect->quantity > (int) $mapping->acknowledged_quantity) {
                        // Missing commitments must never be overwritten by an availability increase.
                        $level = $this->api->request($tenant, $storeId, StockShopifyApi::LEVEL, ['item' => $mapping->inventory_item_id, 'location' => $mapping->location_id])['inventoryItem'] ?? [];
                        $quantities = array_column($level['inventoryLevel']['quantities'] ?? [], 'quantity', 'name');
                        $committed = 0;
                        foreach ($this->stock->rows('online_orders', $tenant)->where('store_id', $storeId)->get() as $order) {
                            $committed += json_decode($order->lines, true)[$effect->item_id]['reserved'] ?? 0;
                        }
                        if (($level['tracked'] ?? false) !== true || ($quantities['committed'] ?? null) !== $committed || ($quantities['available'] ?? null) !== (int) $mapping->acknowledged_quantity) {
                            $this->review($tenant, $effect->id, 'stock_online_commitments_unreconciled');
                            $review++;

                            continue;
                        }
                    }
                    // Undispatched old snapshots must never restore stock sold by a newer online event.
                    $claimed = $this->stock->db->transaction(function () use ($tenant, $effect, $mapping, $binding): bool {
                        $this->stock->rows('mutexes', $tenant)->lockForUpdate()->firstOrFail();
                        $latest = $this->stock->rows('publications', $tenant)->where('binding_id', $binding->id)->where('item_id', $effect->item_id)->where('state', 'pending')->max('id');
                        if ((int) $latest !== (int) $effect->id) {
                            $this->stock->rows('publications', $tenant)->where('id', $effect->id)->update(['state' => 'superseded', 'updated_at' => now()]);

                            return false;
                        }
                        $current = $this->stock->rows('provider_items', $tenant)->where('id', $mapping->id)->lockForUpdate()->firstOrFail();
                        $this->stock->rows('publications', $tenant)->where('id', $effect->id)->update(['state' => 'dispatching',
                            'expected_quantity' => $current->acknowledged_quantity, 'claimed_at' => now(), 'attempts' => $effect->attempts + 1]);

                        return true;
                    });
                    if (! $claimed) {
                        continue;
                    }
                    $effect = $this->stock->rows('publications', $tenant)->where('id', $effect->id)->firstOrFail();
                }
                if (strtotime($effect->claimed_at) < time() - 3600) {
                    $this->review($tenant, $effect->id, 'stock_unknown_publication_review');
                    $review++;

                    continue;
                }
                $variables = ['key' => $effect->idempotency_key, 'input' => ['name' => 'available', 'reason' => 'correction',
                    'referenceDocumentUri' => 'everbranch://stock/'.$tenant.'/'.$effect->id,
                    'quantities' => [['inventoryItemId' => $mapping->inventory_item_id, 'locationId' => $mapping->location_id,
                        'quantity' => (int) $effect->quantity, 'changeFromQuantity' => (int) $effect->expected_quantity]]]];
                try {
                    $result = $this->api->request($tenant, $storeId, StockShopifyApi::PUBLISH, $variables)['inventorySetQuantities'] ?? [];
                    if (! empty($result['userErrors'])) {
                        $compare = collect($result['userErrors'])->contains(fn ($e) => preg_match('/(COMPARE|CHANGE_FROM).*QUANTITY|QUANTITY.*(STALE|MISMATCH)/', (string) ($e['code'] ?? '')));
                        $this->stock->db->transaction(function () use ($tenant, $effect, $mapping, $compare): void {
                            $this->stock->rows('mutexes', $tenant)->lockForUpdate()->firstOrFail();
                            if ($this->stock->rows('publications', $tenant)->where('id', $effect->id)->value('state') !== 'dispatching') {
                                return;
                            }
                            $current = $this->stock->rows('provider_items', $tenant)->where('id', $mapping->id)->lockForUpdate()->firstOrFail();
                            if ($compare && (int) $current->pending_online_delta !== 0) {
                                $this->stock->rows('provider_items', $tenant)->where('id', $mapping->id)->update([
                                    'acknowledged_quantity' => $current->acknowledged_quantity + $current->pending_online_delta, 'pending_online_delta' => 0]);
                                $this->stock->rows('publications', $tenant)->where('id', $effect->id)->update(['state' => 'superseded', 'error_code' => null, 'updated_at' => now()]);
                            } else {
                                $this->review($tenant, $effect->id, $compare ? 'provider_quantity_changed_reconcile_online_orders' : 'provider_inventory_rejected');
                            }
                        });
                        $review++;

                        continue;
                    }
                    if (empty($result['inventoryAdjustmentGroup'])) {
                        throw new StockException('stock_publication_unconfirmed');
                    }
                    $this->stock->db->transaction(function () use ($tenant, $effect, $mapping): void {
                        $this->stock->rows('mutexes', $tenant)->lockForUpdate()->firstOrFail();
                        if ($this->stock->rows('publications', $tenant)->where('id', $effect->id)->value('state') !== 'dispatching') {
                            return;
                        }
                        $current = $this->stock->rows('provider_items', $tenant)->where('id', $mapping->id)->lockForUpdate()->firstOrFail();
                        $this->stock->rows('provider_items', $tenant)->where('id', $mapping->id)->update(['acknowledged_quantity' => $effect->quantity + $current->pending_online_delta, 'pending_online_delta' => 0,
                            'acknowledged_version' => $effect->version, 'updated_at' => now()]);
                        $this->stock->rows('publications', $tenant)->where('id', $effect->id)->update(['state' => 'completed',
                            'provider_reference' => 'everbranch://stock/'.$tenant.'/'.$effect->id, 'error_code' => null, 'updated_at' => now()]);
                    });
                    $published++;
                } catch (StockException) {
                    // Retain dispatching + original idempotency key after an uncertain response.
                    $this->stock->rows('publications', $tenant)->where('id', $effect->id)->where('state', 'dispatching')->update(['error_code' => 'stock_publication_unconfirmed', 'updated_at' => now()]);
                    break;
                }
            }

            return ['published' => $published, 'review' => $review];
        });
    }

    private function review(int $tenant, int $id, string $reason): void
    {
        $this->stock->rows('publications', $tenant)->where('id', $id)->whereNotIn('state', ['completed', 'superseded'])->update(['state' => 'review', 'error_code' => $reason, 'updated_at' => now()]);
    }
}
