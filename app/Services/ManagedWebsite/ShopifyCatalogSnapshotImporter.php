<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteCatalogSourceMapping;
use App\Models\WebsiteCollection;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit local snapshot -> native drafts. No connections, HTTP, cursors or activation. */
class ShopifyCatalogSnapshotImporter
{
    private const PRODUCT_FIELDS = ['id', 'handle', 'title', 'description', 'descriptionHtml', 'productType', 'vendor', 'tags', 'status', 'updatedAt', 'createdAt', 'onlineStoreUrl', 'hasOnlyDefaultVariant', 'isGiftCard', 'requiresSellingPlan', 'sellingPlanGroupsCount', 'options', 'seo', 'images', 'collection_ids', 'typed_dimension_fields'];

    private const VARIANT_FIELDS = ['id', 'title', 'displayName', 'sku', 'barcode', 'price', 'compareAtPrice', 'availableForSale', 'inventoryQuantity', 'inventoryPolicy', 'taxable', 'updatedAt', 'selectedOptions', 'product_id', 'inventoryItem', 'media', 'typed_dimension_fields', 'requiresComponents', 'sellingPlanGroupsCount'];

    private const COLLECTION_FIELDS = ['id', 'handle', 'title', 'description', 'descriptionHtml', 'updatedAt', 'sortOrder', 'image'];

    public function import(TenantSite $site, User $actor, array $snapshot, string $expectedShop, string $expectedShopId, bool $apply = false): array
    {
        app(WebsiteCatalogService::class)->assertEditor($site, $actor);
        $shop = strtolower(trim($expectedShop));
        $this->validateSnapshot($snapshot, $shop, $expectedShopId);

        return DB::transaction(function () use ($site, $actor, $snapshot, $shop, $apply): array {
            $site = TenantSite::query()->forTenantId($site->tenant_id)->whereKey($site->id)->when($apply, fn ($query) => $query->lockForUpdate())->firstOrFail();
            // Recheck entitlement/actor and the exact target inside the site lock.
            app(WebsiteCatalogService::class)->assertEditor($site, $actor);
            $mappings = WebsiteCatalogSourceMapping::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('provider', 'shopify')->where('source_shop', $shop)->when($apply, fn ($query) => $query->lockForUpdate())->get()->keyBy(fn ($row) => $row->source_type.':'.$row->source_gid);
            $report = ['applied' => false, 'products' => count($snapshot['products']), 'variants' => count($snapshot['variants']), 'create_products' => 0, 'update_products' => 0, 'unchanged_products' => 0, 'skipped_products' => 0, 'conflicts' => 0, 'collections_created' => 0, 'issues' => [], 'inventory_snapshot_only' => true, 'live_stock_allocation_verified' => false, 'source_active_products' => count(array_filter($snapshot['products'], fn ($row) => ($row['status'] ?? '') === 'ACTIVE')), 'planned_source_active_products' => 0, 'planned_variant_rows' => 0, 'planned_missing_weight_rows' => 0, 'planned_unavailable_stock_review_rows' => 0, 'skipped_products_by_source_status' => []];
            $issue = static function (string $code, string $product = '', string $variant = '') use (&$report): void {
                $report['issues'][] = ['code' => $code, 'product_gid' => $product, 'variant_gid' => $variant];
            };
            $sourceIds = ['product' => array_column($snapshot['products'], 'id'), 'variant' => array_column($snapshot['variants'], 'id'), 'collection' => array_column($snapshot['collections'], 'id')];
            foreach ($mappings as $mapping) {
                if (data_get($mapping->provenance, 'source.shop_id') !== $snapshot['source']['shop_id']
                    || data_get($mapping->provenance, 'source.shop_domain') !== $shop) {
                    $this->invalid('mapping_source_shop_identity_changed');
                }
                $target = $this->target($mapping, $site, $apply);
                if (! $target || ! hash_equals($mapping->target_fingerprint, $this->targetFingerprint($target, $apply))) {
                    $report['conflicts']++;
                    $issue('operator_edit_or_missing_target', $mapping->source_type === 'product' ? $mapping->source_gid : '', $mapping->source_type === 'variant' ? $mapping->source_gid : '');
                }
                if (($mapping->source_type !== 'collection' || $snapshot['completeness']['collections']) && ! in_array($mapping->source_gid, $sourceIds[$mapping->source_type] ?? [], true)) {
                    $report['conflicts']++;
                    $issue('source_removal_requires_review', $mapping->source_type === 'product' ? $mapping->source_gid : '', $mapping->source_type === 'variant' ? $mapping->source_gid : '');
                }
            }
            $variants = collect($snapshot['variants'])->groupBy('product_id');
            $collections = [];
            if ($snapshot['completeness']['collections']) {
                foreach ($snapshot['collections'] as $source) {
                    $data = ['title' => $source['title'], 'handle' => $source['handle'], 'description' => $source['description'] ?? '', 'status' => 'draft', 'image_url' => isset($source['image']['url']) ? $this->image($source['image']['url']) : null];
                    Validator::make($data, ['title' => 'required|string|max:190', 'handle' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'description' => 'nullable|string|max:8000', 'image_url' => 'nullable|url:https|max:2048'])->validate();
                    $mapping = $mappings->get('collection:'.$source['id']);
                    $target = $mapping ? $this->target($mapping, $site, $apply) : null;
                    if (WebsiteCollection::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('handle', $data['handle'])->when($target, fn ($q) => $q->whereKeyNot($target->id))->exists()) {
                        $report['conflicts']++;
                        $issue('unmapped_collection_handle_collision');
                    }
                    $issue('collection_display_order_requires_review');
                    $collections[$source['id']] = compact('source', 'data', 'mapping', 'target');
                }
            } else {
                $issue('collections_unavailable_preserved_in_provenance');
            }
            $products = [];
            foreach ($snapshot['products'] as $source) {
                $rows = ($variants->get($source['id']) ?? collect())->all();
                $data = $this->product($source, $rows, $issue);
                if ($data === null) {
                    $report['skipped_products']++;
                    $status = in_array($source['status'] ?? '', ['ACTIVE', 'DRAFT', 'ARCHIVED'], true) ? $source['status'] : 'UNKNOWN';
                    $reason = $report['issues'][array_key_last($report['issues'])]['code'];
                    $report['skipped_products_by_source_status'][$status][$reason] = ($report['skipped_products_by_source_status'][$status][$reason] ?? 0) + 1;

                    continue;
                }
                $report['planned_source_active_products'] += ($source['status'] ?? '') === 'ACTIVE' ? 1 : 0;
                $report['planned_variant_rows'] += count($data['variants']);
                $report['planned_missing_weight_rows'] += count(array_filter($data['variants'], fn ($row) => $row['shipping_weight_ounces'] === null));
                $report['planned_unavailable_stock_review_rows'] += count(array_filter($rows, fn ($row) => $data['track_inventory'] && (! is_int($row['inventoryQuantity'] ?? null) || $row['inventoryQuantity'] < 0 || $row['inventoryQuantity'] > 1000000)));
                $mapping = $mappings->get('product:'.$source['id']);
                $target = $mapping ? $this->target($mapping, $site, $apply) : null;
                if (WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('handle', $data['handle'])->when($target, fn ($q) => $q->whereKeyNot($target->id))->exists()) {
                    $report['conflicts']++;
                    $issue('unmapped_product_handle_collision', $source['id']);
                }
                foreach ($rows as $i => $row) {
                    $variantMapping = $mappings->get('variant:'.$row['id']);
                    if ($variantMapping) {
                        $variantTarget = $this->target($variantMapping, $site, $apply);
                        if (! $target || ! $variantTarget || $variantTarget->website_product_id !== $target->id) {
                            $this->invalid('source_variant_target_scope_mismatch');
                        }
                        $data['variants'][$i]['id'] = $variantTarget->id;
                    }
                }
                if ($target && array_diff($target->variants()->pluck('id')->all(), array_filter(array_column($data['variants'], 'id')))) {
                    $report['conflicts']++;
                    $issue('unmapped_native_variant_requires_review', $source['id']);
                }
                $fingerprint = $this->fingerprint(['product' => Arr::only($source, self::PRODUCT_FIELDS), 'variants' => array_map(fn ($row) => Arr::only($row, self::VARIANT_FIELDS), $rows)]);
                $changed = ! $mapping || ! hash_equals($mapping->source_fingerprint, $fingerprint);
                $report[! $mapping ? 'create_products' : ($changed ? 'update_products' : 'unchanged_products')]++;
                $products[] = compact('source', 'rows', 'data', 'mapping', 'target', 'fingerprint', 'changed');
            }
            $issue('inventory_snapshot_only_requires_pilot_stock_allocation');
            $hasActivity = WebsiteOrder::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->exists()
                || WebsiteInventoryReservation::query()->forTenantId($site->tenant_id)->where('status', 'active')->exists();
            $report['native_activity_blocks_inventory_import'] = $hasActivity;
            if (! $apply || $report['conflicts'] > 0 || $hasActivity) {
                return $report;
            }
            foreach ($collections as $gid => &$collection) {
                if (! $collection['mapping'] || ! hash_equals($collection['mapping']->source_fingerprint, $this->fingerprint(Arr::only($collection['source'], self::COLLECTION_FIELDS)))) {
                    $collection['target'] ??= new WebsiteCollection(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id]);
                    $collection['target']->fill($collection['data'])->save();
                    $report['collections_created'] += $collection['mapping'] ? 0 : 1;
                    $this->record($site, $shop, 'collection', $collection['source'], $collection['target'], $this->fingerprint(Arr::only($collection['source'], self::COLLECTION_FIELDS)), $snapshot['source']);
                }
            }
            unset($collection);
            foreach ($products as $product) {
                if (! $product['changed']) {
                    continue;
                }
                $data = $product['data'];
                $data['collection_ids'] = $snapshot['completeness']['collections']
                    ? array_map(fn ($gid) => $collections[$gid]['target']->id, $product['source']['collection_ids'])
                    : ($product['target']?->collections()->pluck('website_collections.id')->all() ?? []);
                $target = app(WebsiteCatalogService::class)->saveProduct($site, $product['target'], $data, $actor);
                $targetVariants = $target->variants->values();
                foreach ($product['rows'] as $index => $sourceVariant) {
                    // saveProduct preserves existing IDs; resolve additions by source order,
                    // never by a title/SKU match. Its returned order is native ID order.
                    $variant = isset($data['variants'][$index]['id'])
                        ? $targetVariants->firstWhere('id', $data['variants'][$index]['id'])
                        : $targetVariants->first(fn ($v) => ! in_array($v->id, array_filter(array_column($data['variants'], 'id')), true));
                    if (! $variant) {
                        $this->invalid('native_variant_creation_failed');
                    }
                    $targetVariants = $targetVariants->reject(fn ($v) => $v->id === $variant->id)->values();
                    $variant->update(['options' => array_merge($variant->options ?? [], ['shopify_selected_options' => $sourceVariant['selectedOptions'], 'shopify_media' => $this->variantImages($sourceVariant)])]);
                    $this->record($site, $shop, 'variant', $sourceVariant, $variant, $this->fingerprint(Arr::only($sourceVariant, self::VARIANT_FIELDS)), $snapshot['source']);
                }
                $this->record($site, $shop, 'product', $product['source'], $target, $product['fingerprint'], $snapshot['source']);
            }
            // Collection membership and variant options are finalized first. Freeze all
            // managed targets afterwards so the next identical snapshot is a no-op.
            foreach (WebsiteCatalogSourceMapping::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('provider', 'shopify')->where('source_shop', $shop)->get() as $mapping) {
                $target = $this->target($mapping, $site, $apply);
                $fingerprint = $this->targetFingerprint($target);
                if (! hash_equals($mapping->target_fingerprint, $fingerprint)) {
                    $mapping->update(['target_fingerprint' => $fingerprint]);
                }
            }
            $report['applied'] = true;

            return $report;
        });
    }

    private function validateSnapshot(array $snapshot, string $shop, string $shopId): void
    {
        if (($snapshot['schema_version'] ?? null) !== 1 || ! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $shop)
            || ! preg_match('#^gid://shopify/Shop/[0-9]+$#D', $shopId)
            || data_get($snapshot, 'source.provider') !== 'shopify' || data_get($snapshot, 'source.shop_domain') !== $shop
            || data_get($snapshot, 'source.shop_id') !== $shopId || data_get($snapshot, 'source.currency') !== 'USD'
            || data_get($snapshot, 'source.api_version') !== '2026-01'
            || data_get($snapshot, 'source.transport') !== 'shopify_cli_app_execute_query_only'
            || data_get($snapshot, 'source.credentials_included') !== false) {
            $this->invalid('snapshot_source_identity_or_currency_invalid');
        }
        foreach (['products', 'variants', 'product_images', 'variant_media', 'product_collection_links', 'typed_dimension_metadata', 'source_feature_flags'] as $key) {
            if (data_get($snapshot, 'completeness.'.$key) !== true) {
                $this->invalid('snapshot_pagination_or_measurement_capture_incomplete');
            }
        }
        if (! is_bool(data_get($snapshot, 'completeness.collections'))) {
            $this->invalid('snapshot_collection_completeness_missing');
        }
        foreach (['products' => 'Product', 'variants' => 'ProductVariant', 'collections' => 'Collection'] as $resource => $type) {
            if (! isset($snapshot[$resource]) || ! is_array($snapshot[$resource]) || ! array_is_list($snapshot[$resource])) {
                $this->invalid('snapshot_resource_array_missing');
            }
            $ids = [];
            foreach ($snapshot[$resource] as $row) {
                if (! is_array($row) || ! is_string($row['id'] ?? null) || ! preg_match('#^gid://shopify/'.$type.'/[0-9]+$#D', $row['id']) || strlen($row['id']) > 190 || isset($ids[$row['id']])) {
                    $this->invalid('snapshot_duplicate_or_invalid_source_gid');
                }
                $ids[$row['id']] = true;
            }
        }
        foreach (['products' => 'productsCount', 'variants' => 'productVariantsCount'] as $resource => $key) {
            foreach (['counts_before', 'counts_after'] as $boundary) {
                if (data_get($snapshot, 'completeness.'.$boundary.'.'.$key.'.precision') !== 'EXACT'
                    || data_get($snapshot, 'completeness.'.$boundary.'.'.$key.'.count') !== count($snapshot[$resource])) {
                    $this->invalid('snapshot_source_counts_changed_or_incomplete');
                }
            }
        }
        foreach (['capture_started_at', 'captured_at'] as $field) {
            if (! is_string(data_get($snapshot, 'source.'.$field)) || strtotime(data_get($snapshot, 'source.'.$field)) === false) {
                $this->invalid('snapshot_capture_time_missing');
            }
        }
        if (strtotime($snapshot['source']['capture_started_at']) > strtotime($snapshot['source']['captured_at']) || strtotime($snapshot['source']['captured_at']) > time() + 300) {
            $this->invalid('snapshot_capture_time_invalid');
        }
        $productIds = array_column($snapshot['products'], 'id');
        $collectionIds = array_column($snapshot['collections'], 'id');
        foreach ($snapshot['variants'] as $variant) {
            foreach (['availableForSale', 'requiresComponents', 'inventoryItem.requiresShipping', 'inventoryItem.tracked'] as $flag) {
                if (! is_bool(data_get($variant, $flag))) {
                    $this->invalid('snapshot_variant_flags_invalid');
                }
            }
            if (! is_array($variant['selectedOptions'] ?? null) || ! is_array($variant['media'] ?? null) || ! is_array($variant['typed_dimension_fields'] ?? null) || ! is_array($variant['inventoryItem'] ?? null)) {
                $this->invalid('snapshot_variant_fields_incomplete');
            }
            Validator::make($variant, ['selectedOptions' => 'array|max:100', 'selectedOptions.*.name' => 'required|string|max:190', 'selectedOptions.*.value' => 'required|string|max:190'])->validate();
            if (! in_array($variant['product_id'] ?? '', $productIds, true)) {
                $this->invalid('snapshot_variant_parent_missing');
            }
        }
        foreach ($snapshot['products'] as $product) {
            if (! is_bool($product['isGiftCard'] ?? null) || ! is_bool($product['requiresSellingPlan'] ?? null) || ! in_array($product['status'] ?? '', ['ACTIVE', 'DRAFT', 'ARCHIVED'], true)) {
                $this->invalid('snapshot_product_flags_or_status_invalid');
            }
            if (! is_array($product['images'] ?? null) || ! is_array($product['options'] ?? null) || ! is_array($product['typed_dimension_fields'] ?? null) || ! is_array($product['tags'] ?? null)) {
                $this->invalid('snapshot_product_fields_incomplete');
            }
            if (! is_array($product['collection_ids'] ?? null) || ($snapshot['completeness']['collections'] && array_diff($product['collection_ids'], $collectionIds))) {
                $this->invalid('snapshot_collection_link_unresolved');
            }
        }
    }

    private function product(array $source, array $variants, callable $issue): ?array
    {
        $skip = function (string $code) use ($source, $issue): null {
            $issue($code, $source['id']);

            return null;
        };
        if (! is_bool($source['isGiftCard'] ?? null) || ! is_bool($source['requiresSellingPlan'] ?? null)) {
            return $skip('unsupported_feature_flags_unverified');
        }
        if (! is_array($source['sellingPlanGroupsCount'] ?? null) || data_get($source, 'sellingPlanGroupsCount.precision') !== 'EXACT' || ! is_int(data_get($source, 'sellingPlanGroupsCount.count')) || data_get($source, 'sellingPlanGroupsCount.count') < 0) {
            return $skip('unsupported_selling_plan_count_unverified');
        }
        if ($source['isGiftCard'] || $source['requiresSellingPlan'] || (int) data_get($source, 'sellingPlanGroupsCount.count', 0) > 0) {
            return $skip($source['isGiftCard'] ? 'unsupported_gift_card' : 'unsupported_selling_plan');
        }
        if (count($variants) < 1 || count($variants) > 100 || count($source['collection_ids']) > 100) {
            return $skip('native_catalog_bounds_require_review');
        }
        $shipping = array_unique(array_map(fn ($v) => data_get($v, 'inventoryItem.requiresShipping'), $variants), SORT_REGULAR);
        $tracked = array_unique(array_map(fn ($v) => data_get($v, 'inventoryItem.tracked'), $variants), SORT_REGULAR);
        if (count($shipping) !== 1 || $shipping[0] !== true || count($tracked) !== 1 || ! is_bool($tracked[0])) {
            return $skip('unsupported_nonphysical_or_mixed_inventory');
        }
        $allImages = array_map(fn ($image) => $this->image($image['url'] ?? null), $source['images'] ?? []);
        if (count($allImages) > 12) {
            $issue('gallery_display_truncated_requires_review', $source['id']);
        }
        $data = ['title' => $source['title'] ?? null, 'handle' => $source['handle'] ?? null, 'description' => $source['description'] ?? '', 'status' => 'draft', 'product_type' => 'physical', 'track_inventory' => $tracked[0],
            'media' => array_slice($allImages, 0, 12), 'collection_ids' => [], 'variants' => [],
            'image_alt' => data_get($source, 'images.0.altText'), 'vendor' => $source['vendor'] ?? '', 'category' => $source['productType'] ?? '', 'tags' => implode(', ', $source['tags'] ?? []), 'seo_title' => data_get($source, 'seo.title'), 'seo_description' => data_get($source, 'seo.description')];
        foreach ($variants as $variant) {
            if (! is_array($variant['sellingPlanGroupsCount'] ?? null) || data_get($variant, 'sellingPlanGroupsCount.precision') !== 'EXACT' || ! is_int(data_get($variant, 'sellingPlanGroupsCount.count')) || data_get($variant, 'sellingPlanGroupsCount.count') < 0) {
                return $skip('unsupported_selling_plan_count_unverified');
            }
            if (! is_bool($variant['requiresComponents'] ?? null) || $variant['requiresComponents'] || (int) data_get($variant, 'sellingPlanGroupsCount.count', 0) > 0) {
                return $skip('unsupported_bundle_or_bundle_flag_unverified');
            }
            $stockNeedsReview = $tracked[0] && (! is_int($variant['inventoryQuantity'] ?? null) || $variant['inventoryQuantity'] < 0 || $variant['inventoryQuantity'] > 1000000);
            if ($stockNeedsReview) {
                $issue('source_stock_requires_allocation_review', $source['id'], $variant['id']);
            }
            $weight = data_get($variant, 'inventoryItem.measurement.weight');
            $ounces = null;
            if ($weight !== null) {
                if (! is_array($weight) || ! is_numeric($weight['value'] ?? null) || ! is_finite((float) $weight['value']) || (float) $weight['value'] < 0) {
                    return $skip('source_weight_invalid');
                }
                $unit = match ($weight['unit'] ?? '') {
                    'OUNCES' => 'oz', 'POUNDS' => 'lb', 'GRAMS' => 'g', 'KILOGRAMS' => 'g', default => null
                };
                if (! $unit) {
                    return $skip('source_weight_unit_invalid');
                }
                if ((float) $weight['value'] > 0) {
                    $ounces = app(WebsiteShippingReadinessService::class)->normalizeWeight(['shipping_weight' => (float) $weight['value'] * (($weight['unit'] ?? '') === 'KILOGRAMS' ? 1000 : 1), 'shipping_weight_unit' => $unit])['shipping_weight_ounces'];
                }
            }
            if ($ounces === null) {
                $issue('missing_weight', $source['id'], $variant['id']);
            }
            $issue('dimensions_require_measured_axis_review', $source['id'], $variant['id']);
            if (($variant['taxable'] ?? null) !== true) {
                $issue('source_tax_policy_requires_review', $source['id'], $variant['id']);
            }
            if (($variant['inventoryPolicy'] ?? '') !== 'DENY') {
                $issue('source_overselling_policy_not_enabled', $source['id'], $variant['id']);
            }
            if (collect($variant['media'])->contains(fn ($media) => ($media['__typename'] ?? '') !== 'MediaImage')) {
                $issue('unsupported_non_image_media_preserved_only', $source['id'], $variant['id']);
            }
            $this->variantImages($variant);
            $data['variants'][] = ['title' => $variant['title'] ?? null, 'sku' => ($variant['sku'] ?? '') !== '' ? $variant['sku'] : null,
                'price' => $variant['price'] ?? null, 'compare_at_price' => $variant['compareAtPrice'] ?? null, 'inventory_quantity' => $tracked[0] ? ($stockNeedsReview ? 0 : $variant['inventoryQuantity']) : null,
                'is_available' => ! $stockNeedsReview && ($source['status'] ?? '') === 'ACTIVE' && ($variant['availableForSale'] ?? false), 'shipping_weight_ounces' => $ounces,
                'shipping_length_inches' => null, 'shipping_width_inches' => null, 'shipping_height_inches' => null];
        }
        // Use the native catalog's validation/price conversion on the eventual save.
        // Validate native bounds during dry-run too; incompatible products are reported.
        $rules = ['image_alt' => 'nullable|string|max:500', 'title' => 'required|string|max:190', 'handle' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'description' => 'nullable|string|max:8000', 'vendor' => 'nullable|string|max:190', 'category' => 'nullable|string|max:190', 'tags' => 'nullable|string|max:1000', 'seo_title' => 'nullable|string|max:190', 'seo_description' => 'nullable|string|max:320', 'variants.*.title' => 'required|string|max:190', 'variants.*.sku' => 'nullable|string|max:120|distinct', 'variants.*.price' => 'required|numeric|min:0|max:1000000|decimal:0,2', 'variants.*.compare_at_price' => 'nullable|numeric|min:0|max:1000000|decimal:0,2', 'variants.*.is_available' => 'required|boolean'];
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            foreach (array_keys($validator->failed()) as $field) {
                $issue('native_validation_'.str_replace('.', '_', preg_replace('/\.\d+/', '', $field)).'_requires_review', $source['id']);
            }

            return $skip('native_validation_requires_review');
        }

        return $data;
    }

    private function variantImages(array $variant): array
    {
        $images = [];
        foreach ($variant['media'] ?? [] as $media) {
            if (($media['__typename'] ?? '') === 'MediaImage' && isset($media['image']['url'])) {
                $images[] = ['url' => $this->image($media['image']['url']), 'alt' => $media['image']['altText'] ?? null];
            }
        }

        return $images;
    }

    private function image(mixed $url): string
    {
        if (! is_string($url) || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->invalid('unsafe_source_image_url');
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! ($host === 'cdn.shopify.com' || $host === 'shopifycdn.com' || str_ends_with($host, '.shopifycdn.com'))) {
            $this->invalid('unsafe_source_image_url');
        }

        return $url;
    }

    private function target(WebsiteCatalogSourceMapping $mapping, TenantSite $site, bool $lock = false): WebsiteProduct|WebsiteProductVariant|WebsiteCollection|null
    {
        if ($mapping->tenant_id !== (int) $site->tenant_id || $mapping->tenant_site_id !== (int) $site->id) {
            $this->invalid('mapping_scope_invalid');
        }
        $column = match ($mapping->source_type) {
            'product' => 'website_product_id', 'variant' => 'website_product_variant_id', 'collection' => 'website_collection_id', default => null
        };
        if (! $column) {
            $this->invalid('mapping_type_invalid');
        }
        foreach (['website_product_id', 'website_product_variant_id', 'website_collection_id'] as $key) {
            if ($key !== $column && $mapping->{$key} !== null) {
                $this->invalid('mapping_target_ambiguous');
            }
        }
        $class = match ($mapping->source_type) {
            'product' => WebsiteProduct::class, 'variant' => WebsiteProductVariant::class, 'collection' => WebsiteCollection::class
        };
        $target = $class::query()->forTenantId($site->tenant_id)->when($lock, fn ($query) => $query->lockForUpdate())->find($mapping->{$column});
        if ($target && ($target instanceof WebsiteProductVariant ? $target->product?->tenant_site_id !== $site->id || $target->product?->tenant_id !== $site->tenant_id : $target->tenant_site_id !== $site->id)) {
            $this->invalid('mapping_target_scope_invalid');
        }

        return $target;
    }

    private function record(TenantSite $site, string $shop, string $type, array $source, WebsiteProduct|WebsiteProductVariant|WebsiteCollection $target, string $sourceFingerprint, array $identity): void
    {
        $column = match ($type) {
            'product' => 'website_product_id', 'variant' => 'website_product_variant_id', 'collection' => 'website_collection_id'
        };
        $fields = match ($type) {
            'product' => self::PRODUCT_FIELDS, 'variant' => self::VARIANT_FIELDS, 'collection' => self::COLLECTION_FIELDS
        };
        WebsiteCatalogSourceMapping::query()->updateOrCreate(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id, 'provider' => 'shopify', 'source_shop' => $shop, 'source_type' => $type, 'source_gid' => $source['id']],
            [$column => $target->id, 'source_fingerprint' => $sourceFingerprint, 'target_fingerprint' => $this->targetFingerprint($target), 'provenance' => ['source' => Arr::only($identity, ['provider', 'api_version', 'shop_domain', 'shop_id', 'currency', 'capture_started_at', 'captured_at', 'transport']), 'resource' => Arr::only($source, $fields), 'inventory_snapshot_only' => true]]);
    }

    private function targetFingerprint(WebsiteProduct|WebsiteProductVariant|WebsiteCollection $target, bool $lock = false): string
    {
        // Locking reads see the latest committed operator state on MySQL, even
        // if an earlier consistent read established a repeatable-read snapshot.
        $target = $target->newQuery()->whereKey($target->id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        if ($target instanceof WebsiteProduct) {
            return $this->fingerprint(['attributes' => $target->only(['tenant_id', 'tenant_site_id', 'title', 'handle', 'description', 'product_type', 'status', 'track_inventory', 'media', 'service_details', 'seo']),
                'variants' => $target->variants()->when($lock, fn ($query) => $query->lockForUpdate())->orderBy('id')->get()->map(fn ($variant) => $this->variantState($variant))->all(),
                'collections' => $target->collections()->when($lock, fn ($query) => $query->lockForUpdate())->orderBy('website_collections.id')->pluck('website_collections.id')->all()]);
        }
        if ($target instanceof WebsiteProductVariant) {
            return $this->fingerprint($this->variantState($target));
        }

        return $this->fingerprint(['attributes' => $target->only(['tenant_id', 'tenant_site_id', 'title', 'handle', 'description', 'status', 'image_url', 'seo']), 'products' => $target->products()->when($lock, fn ($query) => $query->lockForUpdate())->orderBy('website_products.id')->pluck('website_products.id')->all()]);
    }

    private function variantState(WebsiteProductVariant $variant): array
    {
        return $variant->only(['id', 'tenant_id', 'website_product_id', 'title', 'sku', 'price_cents', 'compare_at_price_cents', 'wholesale_price_cents', 'inventory_quantity', 'is_available', 'shipping_weight_ounces', 'shipping_length_inches', 'shipping_width_inches', 'shipping_height_inches', 'options']);
    }

    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function invalid(string $code): never
    {
        throw ValidationException::withMessages(['snapshot' => $code]);
    }
}
