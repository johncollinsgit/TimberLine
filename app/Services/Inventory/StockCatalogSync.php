<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Services\PaymentsCore\Contract;
use App\Services\Pos\ShopifyPosCommerce;

final class StockCatalogSync
{
    public function __construct(private StockLedger $stock, private StockShopifyApi $api) {}

    public function import(int $tenant, int $store, string $location, string $shopId, int $actor): array
    {
        $generation = bin2hex(random_bytes(16));
        $cursor = null;
        $imported = 0;
        $seenCursors = [];
        $this->stock->operation($tenant, 'catalog:'.$generation.':start', 'catalog_start', $actor, [$store], function () use ($tenant, $store, $generation): array {
            $this->stock->rows('catalog_runs', $tenant)->updateOrInsert(['tenant_id' => $tenant, 'store_id' => $store],
                ['generation' => $generation, 'started_at' => now(), 'completed_at' => null]);

            return ['started' => true];
        });
        do {
            $data = $this->api->request($tenant, $store, StockShopifyApi::CATALOG, ['cursor' => $cursor, 'location' => $location]);
            if (($data['shop']['id'] ?? '') !== $shopId || ($data['shop']['currencyCode'] ?? '') !== 'USD') {
                throw new StockException('stock_store_identity_changed');
            }
            $page = $data['productVariants'] ?? [];
            $imported += $this->stock->operation($tenant, 'catalog:'.$generation.':'.($cursor ?? 'first'), 'catalog', $actor, [$store, $location, $page], function () use ($tenant, $store, $location, $page, $generation): array {
                $count = 0;
                $this->assertCurrent($tenant, $store, $generation);
                foreach ($page['nodes'] ?? [] as $v) {
                    if (($v['product']['status'] ?? '') !== 'ACTIVE' || ($v['product']['isGiftCard'] ?? true) || empty($v['inventoryItem']['id'])) {
                        continue;
                    }
                    $mapping = $this->stock->rows('provider_items', $tenant)->where('store_id', $store)->where('variant_id', $v['id'])->first();
                    // A new external variant gets its own canonical identity. No SKU/name merging.
                    $id = $mapping?->item_id ?? substr(hash('sha256', $tenant.':shopify:'.$store.':'.$v['id']), 0, 32);
                    $price = ShopifyPosCommerce::minor($v['price']);
                    $old = $this->stock->rows('items', $tenant)->where('id', $id)->first();
                    $values = ['tenant_id' => $tenant, 'sku' => $v['sku'] ?? '', 'title' => $v['product']['title'],
                        'product_key' => $store.':'.$v['product']['id'], 'variant_label' => $v['title'] ?? '',
                        'image_url' => $v['product']['featuredMedia']['preview']['image']['url'] ?? null, 'unit_amount_minor' => $price,
                        'currency' => 'USD', 'tracked' => $old ? (bool) $old->tracked : true, 'active' => true,
                        'revision' => Contract::hash([$v['id'], $v['updatedAt'], $price]),
                        'metadata' => json_encode(['taxable' => $v['taxable'] ?? true, 'source' => 'shopify', 'store_id' => $store,
                            'provider_tracks_inventory' => $v['inventoryItem']['tracked'], 'requires_components' => ($v['requiresComponents'] ?? true) || preg_match('/\bbundle\b/i', $v['product']['title']) === 1 || (json_decode($old?->metadata ?? '{}', true)['requires_components'] ?? false)], JSON_THROW_ON_ERROR), 'updated_at' => now()];
                    if (! $old) {
                        $values['created_at'] = now();
                    }
                    $this->stock->rows('items', $tenant)->updateOrInsert(['id' => $id, 'tenant_id' => $tenant], $values);
                    $this->stock->rows('provider_items', $tenant)->updateOrInsert(['tenant_id' => $tenant, 'store_id' => $store, 'variant_id' => $v['id']],
                        ['item_id' => $id, 'inventory_item_id' => $v['inventoryItem']['id'], 'location_id' => $location,
                            'unit_amount_minor' => $price, 'revision' => $values['revision'],
                            'inventory_policy' => $v['inventoryPolicy'] ?? null,
                            'active' => true, 'catalog_generation' => $generation, 'created_at' => $mapping?->created_at ?? now(), 'updated_at' => now()]);
                    $count++;
                }

                return ['imported' => $count];
            })['imported'];
            if (! isset($page['pageInfo']['hasNextPage']) || ! is_bool($page['pageInfo']['hasNextPage'])) {
                throw new StockException('stock_catalog_page_unconfirmed');
            }
            $next = $page['pageInfo']['endCursor'] ?? null;
            if ($page['pageInfo']['hasNextPage'] && (! is_string($next) || $next === '' || isset($seenCursors[$next]))) {
                throw new StockException('stock_catalog_cursor_invalid');
            }
            if ($next) {
                $seenCursors[$next] = true;
            }
            $cursor = $page['pageInfo']['hasNextPage'] ? $next : null;
        } while ($cursor !== null);
        $this->stock->operation($tenant, 'catalog:'.$generation.':complete', 'catalog_complete', $actor, [$store], function () use ($tenant, $store, $generation): array {
            $this->assertCurrent($tenant, $store, $generation);
            $stale = $this->stock->rows('provider_items', $tenant)->where('store_id', $store)
                ->where(fn ($q) => $q->whereNull('catalog_generation')->orWhere('catalog_generation', '!=', $generation))->get();
            foreach ($stale as $mapping) {
                $this->stock->rows('provider_items', $tenant)->where('id', $mapping->id)->update(['active' => false]);
                if (! $this->stock->rows('provider_items', $tenant)->where('item_id', $mapping->item_id)->where('active', true)->exists()) {
                    $this->stock->rows('items', $tenant)->where('id', $mapping->item_id)->update(['active' => false, 'updated_at' => now()]);
                }
            }
            $this->stock->rows('catalog_runs', $tenant)->where('store_id', $store)->where('generation', $generation)->update(['completed_at' => now()]);

            return ['complete' => true];
        });

        return ['imported_variants' => $imported, 'generation' => $generation, 'physical_stock_imported' => false];
    }

    private function assertCurrent(int $tenant, int $store, string $generation): void
    {
        if (! $this->stock->rows('catalog_runs', $tenant)->where('store_id', $store)->where('generation', $generation)->exists()) {
            throw new StockException('stock_catalog_run_superseded');
        }
    }
}
