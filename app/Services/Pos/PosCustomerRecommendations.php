<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\ShopifyStore;
use App\Services\Inventory\StockEventProduction;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\Marketing\CustomerPreferenceService;
use App\Services\PaymentsCore\Contract;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\CarbonImmutable;

/** Reorders only the assigned register's current catalog using explicitly linked original purchases. */
class PosCustomerRecommendations
{
    public function __construct(private PosAccess $access, private PosStore $store, private StockLedger $stock) {}

    public function forCustomer(string $register, int $profileId): array
    {
        if (config('pos_live.customer_recommendations_enabled') !== true) {
            throw new PosException('customer_recommendations_unavailable', 404);
        }
        $scope = $this->access->register($register, 'catalog');
        $tenant = $scope['tenant_id'];
        if (! app(TenantModuleAccessResolver::class)->canAccess($tenant, 'customers')) {
            throw new PosException('customer_access_required', 403);
        }
        $profile = app(PosCustomerDirectory::class)->profile($tenant, $profileId);
        $catalog = app(PosCatalog::class)->listing($scope);
        $snapshot = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $tenant, 100);
        if ((int) ($snapshot['customer_id'] ?? 0) !== (int) $profile->id) {
            throw new PosException('customer_selection_changed', 409);
        }
        $preferences = app(CustomerPreferenceService::class)->forStaff($profile, $tenant);
        if ((int) ($preferences['profile_id'] ?? 0) !== (int) $profile->id) {
            throw new PosException('customer_selection_changed', 409);
        }
        $avoided = $preferences['declared']['avoided_scents'] ?? [];
        $products = [];
        $scents = [];
        $excluded = 0;
        $missing = 0;
        $unmapped = 0;
        $eligible = 0;
        $channels = ['online' => 0, 'in_person' => 0, 'unknown' => 0];
        // The canonical snapshot already suppresses proven Pay/Shopify/Square mirrors.
        foreach (collect($snapshot['purchases'])->unique('id') as $purchase) {
            $date = is_string($purchase['occurred_at'] ?? null) ? strtotime($purchase['occurred_at']) : false;
            if (($purchase['confirmed'] ?? false) !== true || ($purchase['refunded_minor'] ?? null) !== 0
                || in_array($purchase['status'] ?? '', ['refunded', 'partially_refunded'], true) || $date === false || $date > now()->timestamp) {
                $excluded++;

                continue;
            }
            $eligible++;
            $channel = array_key_exists($purchase['channel'] ?? '', $channels) ? $purchase['channel'] : 'unknown';
            $channels[$channel]++;
            $items = $purchase['items'] ?? [];
            if (! $items || count($items) > 500) {
                $missing++;

                continue;
            }
            foreach ($items as $item) {
                $quantity = $item['quantity'] ?? null;
                if (! is_int($quantity) || $quantity < 1) {
                    $missing++;

                    continue;
                }
                $stock = $item['stock_item_id'] ?? null;
                if (is_string($stock) && preg_match('/^[a-f0-9]{32}$/D', $stock)) {
                    $this->observe($products, $stock, $purchase['id'], $quantity);
                }
                $scent = $item['scent'] ?? null;
                if (is_array($scent) && is_string($scent['key'] ?? null) && is_string($scent['name'] ?? null) && $scent['key'] !== '') {
                    $this->observe($scents, $scent['key'], $purchase['id'], $quantity);
                } else {
                    $unmapped += $quantity;
                }
            }
        }
        $owned = app(StockPosCommerce::class)->enabled($scope['register']);
        $identities = $this->catalogStockIdentities($scope, $catalog, $owned);
        $items = [];
        $availableScents = [];
        $missingCatalog = 0;
        foreach ($catalog as $index => $catalogItem) {
            $id = $catalogItem['item_id'];
            $stock = $identities[$id] ?? null;
            try {
                $scent = $stock ? app(StockEventProduction::class)->reviewedScentForItem($tenant, $stock) : null;
            } catch (\Throwable) {
                $scent = null;
            }
            $product = $products[$stock ?? ''] ?? ['purchases' => [], 'units' => 0];
            $scentHistory = $scents[$scent['key'] ?? ''] ?? ['purchases' => [], 'units' => 0];
            $productCount = count($product['purchases']);
            $scentCount = count($scentHistory['purchases']);
            // Scent history may span explicitly reviewed sizes; do not add the same purchase twice.
            $useScent = $scentCount > $productCount || ($scentCount === $productCount && $scentHistory['units'] >= $product['units'] && $scentCount > 0);
            $count = $useScent ? $scentCount : $productCount;
            $units = $useScent ? $scentHistory['units'] : $product['units'];
            $quantity = $catalogItem['available_quantity'] ?? null;
            $availability = $catalogItem['availability'] ?? ($quantity === null ? 'untracked' : ($quantity > 0 ? 'available' : 'sold_out'));
            $available = in_array($availability, ['available', 'untracked'], true) && ($quantity === null || (is_int($quantity) && $quantity > 0));
            $avoidsScent = $scent && in_array($scent['name'], $avoided, true);
            $recommended = $available && ! $avoidsScent && $count > 0;
            $reason = $avoidsScent ? 'customer_avoids_scent' : (! $available ? 'catalog_item_unavailable' : (! $stock ? 'catalog_identity_unmapped' : ($count ? ($useScent ? 'history_for_reviewed_scent' : 'history_for_exact_product') : 'no_linked_purchase_evidence')));
            $missingCatalog += $stock ? 0 : 1;
            $items[] = ['item_id' => $id, 'revision' => $catalogItem['revision'], 'catalog_index' => $index, 'rank' => 0, 'available_quantity' => $quantity, 'availability' => $availability,
                'recommended' => $recommended, 'basis' => $count > 0 ? ($useScent ? 'reviewed_scent' : 'explicit_stock_item') : null, 'reason' => $reason,
                'score' => ['purchase_count' => $count, 'units' => $units, 'product_purchase_count' => $productCount, 'product_units' => $product['units'],
                    'scent_purchase_count' => $scentCount, 'scent_units' => $scentHistory['units']], 'scent' => $scent];
            if ($available && ! $avoidsScent && $scent && $scentCount > 0) {
                $availableScents[$scent['key']] ??= ['key' => $scent['key'], 'name' => $scent['name'], 'purchase_count' => $scentCount, 'units' => $scentHistory['units'],
                    'available_item_ids' => [], 'catalog_index' => $index];
                $availableScents[$scent['key']]['available_item_ids'][] = $id;
            }
        }
        usort($items, fn ($a, $b) => ($b['recommended'] <=> $a['recommended'])
            ?: ($a['recommended'] ? (($b['score']['purchase_count'] <=> $a['score']['purchase_count']) ?: ($b['score']['units'] <=> $a['score']['units'])) : 0)
            ?: ($a['catalog_index'] <=> $b['catalog_index']));
        foreach ($items as $index => &$item) {
            $item['rank'] = $index + 1;
        }
        unset($item);
        $availableScents = array_values($availableScents);
        usort($availableScents, fn ($a, $b) => ($b['purchase_count'] <=> $a['purchase_count']) ?: ($b['units'] <=> $a['units']) ?: ($a['catalog_index'] <=> $b['catalog_index']));
        $issues = array_values(array_unique([...($snapshot['issues'] ?? []), ...($preferences['issues'] ?? []), 'unconnected_and_unlinked_history_unknown']));

        return ['contract_version' => 1, 'tenant_id' => $tenant, 'register_id' => $register, 'customer_id' => (int) $profile->id, 'requested_customer_id' => $profileId,
            'ordered_item_ids' => array_column($items, 'item_id'), 'items' => $items, 'recommended_scents' => $availableScents,
            'catalog_fingerprint' => Contract::hash($catalog), 'as_of' => CarbonImmutable::now('UTC')->toIso8601String(),
            'coverage' => ['complete' => false, 'scope' => 'recent_explicitly_linked_original_purchases', 'purchase_limit' => 100,
                'preference_version' => $preferences['version'],
                'has_more_purchases' => (bool) $snapshot['has_more'], 'observed_eligible_purchase_count' => $eligible, 'channels' => $channels,
                'excluded_unconfirmed_refunded_or_undated_purchase_count' => $excluded, 'missing_item_purchase_count' => $missing,
                'units_without_reviewed_scent' => $unmapped, 'catalog_items_without_identity_mapping' => $missingCatalog, 'issues' => $issues],
            'basis' => 'Distinct confirmed purchases without refunds rank first, purchased units second, original register catalog order breaks ties. Reviewed scent identity may connect sizes. Explicitly avoided scents are not promoted; purchase observations do not declare favorites. Unobserved and unavailable items retain their relative catalog order. Availability and prices remain catalog-authoritative; history is incomplete and does not authorize a basket, discount, reward or payment.'];
    }

    private function observe(array &$stats, string $key, string $purchase, int $units): void
    {
        $stats[$key] ??= ['purchases' => [], 'units' => 0];
        $stats[$key]['purchases'][$purchase] = true;
        $stats[$key]['units'] += $units;
    }

    private function catalogStockIdentities(array $scope, array $catalog, bool $owned): array
    {
        $ids = array_column($catalog, 'item_id');
        if ($owned) {
            return $this->stock->rows('items', $scope['tenant_id'])->where('active', true)->whereIn('id', $ids)->pluck('id', 'id')->all();
        }
        if ($scope['register']->catalog_source !== 'shopify') {
            return [];
        }
        $settings = $this->store->decrypt($scope['register']->configuration)['shopify'] ?? [];
        $store = ShopifyStore::forTenantId($scope['tenant_id'])->where('shop_domain', $settings['shop_domain'] ?? '')->find($settings['store_id'] ?? 0);
        if (! $store) {
            return [];
        }

        $mappings = $this->stock->rows('provider_items', $scope['tenant_id'])->where('store_id', $store->id)->where('active', true)->whereIn('variant_id', $ids)->pluck('item_id', 'variant_id');
        $ownedIds = $this->stock->rows('items', $scope['tenant_id'])->where('active', true)->whereIn('id', $mappings->values())->pluck('id')->all();

        return $mappings->filter(fn ($id) => in_array($id, $ownedIds, true))->all();
    }
}
