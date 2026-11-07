<?php

namespace App\Services\Marketing;

use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\WebsiteOrderLine;
use App\Services\Inventory\StockEventProduction;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\Schema;

/** Deterministic summaries of explicitly linked merchant purchase records, never card data or AI inference. */
class CustomerBehaviorProfile
{
    public function build(int $tenant, array $purchases, bool $hasMore, array $sourceIssues, int $rankLimit = 10): array
    {
        $rankLimit = max(1, min(1000, $rankLimit));
        $products = [];
        $scents = [];
        $channels = ['online' => 0, 'in_person' => 0, 'unknown' => 0];
        $missing = 0;
        $refunds = 0;
        $unmapped = 0;
        $unknownProducts = 0;
        $stockScents = [];
        foreach ($purchases as &$purchase) {
            $purchase['items'] = $this->items($tenant, $purchase);
            if (! $purchase['confirmed']) {
                continue;
            }
            $channel = array_key_exists($purchase['channel'], $channels) ? $purchase['channel'] : 'unknown';
            $channels[$channel]++;
            // Do not invent which units a refund returned, or retain returned goods as a preference.
            if (($purchase['refunded_minor'] ?? null) !== 0 || in_array($purchase['status'], ['refunded', 'partially_refunded'], true)) {
                $refunds++;

                continue;
            }
            if (! $purchase['items'] || count($purchase['items']) > 500) {
                $missing++;

                continue;
            }
            foreach ($purchase['items'] as &$item) {
                if (isset($item['stock_item_id']) && ! ($item['scent_mapping_review_required'] ?? false)) {
                    $id = $item['stock_item_id'];
                    if (! array_key_exists($id, $stockScents)) {
                        $stockScents[$id] = $this->stockScent($tenant, $id);
                    }
                    $item['scent'] ??= $stockScents[$id];
                }
                $units = $item['quantity'];
                $key = $item['product_key'];
                if ($item['product_identity_known'] ?? false) {
                    $products[$key] ??= ['key' => $key, 'name' => $item['title'], 'units' => 0, 'purchase_ids' => []];
                    $products[$key]['units'] += $units;
                    $products[$key]['purchase_ids'][$purchase['id']] = true;
                } else {
                    $unknownProducts += $units;
                }
                if (! empty($item['scent'])) {
                    $scent = $item['scent'];
                    $scents[$scent['key']] ??= ['key' => $scent['key'], 'name' => $scent['name'], 'units' => 0, 'purchase_ids' => []];
                    $scents[$scent['key']]['units'] += $units;
                    $scents[$scent['key']]['purchase_ids'][$purchase['id']] = true;
                } else {
                    $unmapped += $units;
                }
            }
            unset($item);
        }
        unset($purchase);
        $rank = function (array $rows) use ($rankLimit): array {
            usort($rows, fn ($a, $b) => ($b['units'] <=> $a['units']) ?: strcmp($a['name'], $b['name']) ?: strcmp($a['key'], $b['key']));

            return array_map(fn ($row) => ['key' => $row['key'], 'name' => $row['name'], 'units' => $row['units'], 'purchase_count' => count($row['purchase_ids'])], array_slice($rows, 0, $rankLimit));
        };

        return ['purchases' => $purchases, 'profile' => ['contract_version' => 1, 'ranking_limit' => $rankLimit, 'channels' => $channels,
            'most_purchased_products' => $rank(array_values($products)), 'most_purchased_scents' => $rank(array_values($scents)),
            'purchase_window_count' => array_sum($channels), 'last_purchase_at' => collect($purchases)->where('confirmed', true)->pluck('occurred_at')->filter()->max(),
            'missing_item_purchase_count' => $missing, 'excluded_refunded_or_unknown_refund_purchases' => $refunds, 'units_without_reviewed_scent' => $unmapped, 'units_without_product_identity' => $unknownProducts,
            'partial' => $hasMore || (bool) $sourceIssues || $missing > 0 || $refunds > 0 || $unmapped > 0 || $unknownProducts > 0,
            'basis' => 'Recent explicitly linked confirmed purchases. Product rankings exclude purchases with refunds or unknown refund allocation. Scents use recorded order lines or reviewed inventory mappings; names and card details never infer scent identity. Missing channels and older unlinked purchases may be absent.']];
    }

    private function items(int $tenant, array $purchase): array
    {
        $source = $purchase['source'];
        $items = [];
        if ($source === 'pos_sale') {
            $namespace = $purchase['catalog_namespace'] ?? 'pos:unknown';
            foreach ($purchase['items'] ?? [] as $i => $line) {
                // A bundle contributes its selected components, not an extra candle for its wrapper.
                $lines = ! empty($line['components']) ? $line['components'] : [$line];
                foreach ($lines as $j => $part) {
                    $id = $part['item_id'] ?? $part['stock_item_id'] ?? null;
                    $items[] = ['product_key' => $id ? $namespace.':'.$id : 'pos_line:'.$purchase['source_id'].':'.$i.':'.$j,
                        'item_id' => $id, 'stock_item_id' => $namespace === 'stock' ? $id : null, 'product_identity_known' => $id !== null && $namespace !== 'pos:unknown', 'title' => $part['title'] ?? 'Recorded item', 'quantity' => $part['quantity'] ?? 0];
                }
            }
        } elseif ($source === 'order') {
            $order = Order::forTenantId($tenant)->find($purchase['source_id']);
            if ($order) {
                $store = ShopifyStore::forTenantId($tenant)->where('store_key', $order->shopify_store_key)->value('id');
                foreach ($order->lines()->with('scentSplits.scent')->orderBy('id')->limit(501)->get() as $line) {
                    $stock = $store && $line->shopify_variant_id && Schema::hasTable('commerce_stock_provider_items')
                        ? app(StockLedger::class)->rows('provider_items', $tenant)->where('store_id', $store)->where('variant_id', 'gid://shopify/ProductVariant/'.$line->shopify_variant_id)->value('item_id') : null;
                    $quantity = $line->quantity > 0 ? $line->quantity : ($line->ordered_qty ?? 0);
                    $key = $stock ? 'stock:'.$stock : ($line->shopify_product_id ? 'shopify:'.$order->shopify_store_key.':'.$line->shopify_product_id : ($line->shopify_variant_id ? 'shopify_variant:'.$order->shopify_store_key.':'.$line->shopify_variant_id : 'order_line:'.$line->id));
                    $item = ['product_key' => $key, 'product_identity_known' => (bool) ($stock || $line->shopify_product_id || $line->shopify_variant_id),
                        'stock_item_id' => $stock, 'title' => $line->raw_title ?: $line->scent_name ?: 'Recorded item', 'quantity' => $quantity,
                        'scent' => $line->scent_id && trim((string) $line->scent_name) !== '' ? ['key' => 'scent:'.$line->scent_id, 'name' => $line->scent_name] : null];
                    if ($line->scentSplits->isNotEmpty()) {
                        $splits = $line->scentSplits;
                        $valid = $splits->sum('quantity') === $quantity && $splits->every(fn ($split) => $split->quantity > 0 && $split->scent_id && trim((string) ($split->raw_scent_name ?: $split->scent?->name)) !== '');
                        if ($valid) {
                            foreach ($splits as $split) {
                                $items[] = array_replace($item, ['quantity' => (int) $split->quantity, 'scent' => ['key' => 'scent:'.$split->scent_id, 'name' => $split->raw_scent_name ?: $split->scent->name]]);
                            }

                            continue;
                        }
                        $item['scent'] = null;
                        $item['scent_mapping_review_required'] = true;
                    }
                    $items[] = $item;
                }
            }
        } elseif ($source === 'website_order') {
            foreach (WebsiteOrderLine::forTenantId($tenant)->where('website_order_id', $purchase['source_id'])->orderBy('id')->limit(501)->get() as $line) {
                $items[] = ['product_key' => 'website_variant:'.($line->website_product_variant_id ?: 'line:'.$line->id), 'product_identity_known' => (bool) $line->website_product_variant_id, 'title' => $line->title ?? $line->snapshot['title'] ?? 'Recorded item', 'quantity' => $line->quantity,
                    'scent' => ! empty($line->snapshot['scent_key']) && ! empty($line->snapshot['scent_name']) ? ['key' => 'website_scent:'.$line->snapshot['scent_key'], 'name' => $line->snapshot['scent_name']] : null];
            }
        }

        return array_values(array_filter($items, fn ($item) => is_int($item['quantity']) && $item['quantity'] > 0));
    }

    private function stockScent(int $tenant, string $item): ?array
    {
        if (! Schema::hasTable('commerce_stock_production_links')) {
            return null;
        }
        try {
            return app(StockEventProduction::class)->reviewedScentForItem($tenant, $item);
        } catch (\Throwable) {
            return null;
        }
    }
}
