<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteCatalogSourceMapping;
use App\Models\WebsiteCollection;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\ShopifyCatalogSnapshotImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

function fictionalShopifyCatalogSnapshot(): array
{
    $product = 'gid://shopify/Product/101';
    $collection = 'gid://shopify/Collection/301';
    $image = ['id' => 'gid://shopify/ProductImage/401', 'url' => 'https://cdn.shopify.com/s/files/1/fictional.jpg?v=1', 'altText' => 'Fictional product', 'width' => 100, 'height' => 100];
    $variant = ['id' => 'gid://shopify/ProductVariant/201', 'title' => 'Small', 'displayName' => 'Fictional candle - Small', 'sku' => 'FICTIONAL-S', 'barcode' => '00123', 'price' => '12.34', 'compareAtPrice' => '15.00', 'availableForSale' => true, 'inventoryQuantity' => 8, 'inventoryPolicy' => 'DENY', 'taxable' => true, 'updatedAt' => now()->toIso8601String(), 'selectedOptions' => [['name' => 'Size', 'value' => 'Small']], 'product_id' => $product, 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/501', 'sku' => 'FICTIONAL-S', 'tracked' => true, 'requiresShipping' => true, 'measurement' => ['weight' => ['value' => 1.01, 'unit' => 'POUNDS']]], 'media' => [['__typename' => 'MediaImage', 'id' => 'gid://shopify/MediaImage/601', 'image' => $image]], 'typed_dimension_fields' => [], 'requiresComponents' => false, 'sellingPlanGroupsCount' => ['count' => 0, 'precision' => 'EXACT']];
    $second = $variant;
    $second['id'] = 'gid://shopify/ProductVariant/202';
    $second['title'] = 'Large';
    $second['sku'] = 'FICTIONAL-L';
    $second['selectedOptions'] = [['name' => 'Size', 'value' => 'Large']];
    $second['inventoryItem']['measurement']['weight'] = ['value' => 500, 'unit' => 'GRAMS'];
    $counts = ['productsCount' => ['count' => 1, 'precision' => 'EXACT'], 'productVariantsCount' => ['count' => 2, 'precision' => 'EXACT']];

    return ['schema_version' => 1, 'source' => ['provider' => 'shopify', 'api_version' => '2026-01', 'shop_domain' => 'fictional-pilot.myshopify.com', 'shop_id' => 'gid://shopify/Shop/999', 'currency' => 'USD', 'capture_started_at' => now()->subMinute()->toIso8601String(), 'captured_at' => now()->toIso8601String(), 'transport' => 'shopify_cli_app_execute_query_only', 'credentials_included' => false],
        'completeness' => ['products' => true, 'variants' => true, 'product_images' => true, 'variant_media' => true, 'product_collection_links' => true, 'typed_dimension_metadata' => true, 'source_feature_flags' => true, 'collections' => true, 'counts_before' => $counts, 'counts_after' => $counts],
        'products' => [['id' => $product, 'handle' => 'fictional-candle', 'title' => 'Fictional candle', 'description' => 'A fictional product for automated tests.', 'descriptionHtml' => '<p>A fictional product.</p>', 'productType' => 'Candle', 'vendor' => 'Fictional Maker', 'tags' => ['fictional'], 'status' => 'ACTIVE', 'updatedAt' => now()->toIso8601String(), 'createdAt' => now()->subDay()->toIso8601String(), 'hasOnlyDefaultVariant' => false, 'isGiftCard' => false, 'requiresSellingPlan' => false, 'sellingPlanGroupsCount' => ['count' => 0, 'precision' => 'EXACT'], 'options' => [['id' => 'gid://shopify/ProductOption/701', 'name' => 'Size', 'position' => 1, 'optionValues' => [['id' => 'gid://shopify/ProductOptionValue/801', 'name' => 'Small'], ['id' => 'gid://shopify/ProductOptionValue/802', 'name' => 'Large']]]], 'seo' => ['title' => 'Fictional candle', 'description' => 'Test fixture only'], 'images' => [$image], 'collection_ids' => [$collection], 'typed_dimension_fields' => []]],
        'variants' => [$variant, $second], 'collections' => [['id' => $collection, 'handle' => 'fictional-gifts', 'title' => 'Fictional gifts', 'description' => 'Test collection.', 'descriptionHtml' => '<p>Test collection.</p>', 'updatedAt' => now()->toIso8601String(), 'sortOrder' => 'MANUAL', 'image' => $image]]];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    $this->tenant = Tenant::query()->create(['name' => 'Fictional pilot', 'slug' => 'fictional-pilot']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped']);
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.editor_tenant_ids', [$this->tenant->id]);
    config()->set('managed_website.publishing_enabled', false);
    config()->set('managed_website.commerce_enabled', false);
    config()->set('managed_website.commerce_shipping_enabled', false);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    $this->snapshot = fictionalShopifyCatalogSnapshot();
    $this->import = fn (bool $apply = false) => app(ShopifyCatalogSnapshotImporter::class)->import($this->site, $this->actor, $this->snapshot, 'fictional-pilot.myshopify.com', 'gid://shopify/Shop/999', $apply);
});

test('complete snapshot dry run performs no native writes and apply prepares complete drafts once', function (): void {
    $before = $this->site->fresh()->toArray();
    $preview = ($this->import)();
    expect($preview['create_products'])->toBe(1)->and($preview['applied'])->toBeFalse()->and(WebsiteProduct::count())->toBe(0)->and(WebsiteCatalogSourceMapping::count())->toBe(0);
    $result = ($this->import)(true);
    $product = WebsiteProduct::with('variants', 'collections')->firstOrFail();
    expect($result['applied'])->toBeTrue()->and($result['inventory_snapshot_only'])->toBeTrue()->and($result['live_stock_allocation_verified'])->toBeFalse()
        ->and($product->status)->toBe('draft')->and($product->media)->toBe([$this->snapshot['products'][0]['images'][0]['url']])
        ->and($product->variants)->toHaveCount(2)->and($product->variants[0]->price_cents)->toBe(1234)->and($product->variants[0]->compare_at_price_cents)->toBe(1500)
        ->and($product->variants[0]->shipping_weight_ounces)->toBe(17)->and($product->variants[1]->shipping_weight_ounces)->toBe(18)
        ->and($product->variants[0]->options['shopify_selected_options'])->toBe([['name' => 'Size', 'value' => 'Small']])
        ->and($product->variants[0]->options['shopify_media'])->toHaveCount(1)->and($product->collections)->toHaveCount(1)->and($product->collections[0]->status)->toBe('draft');
    $mapping = WebsiteCatalogSourceMapping::where('source_type', 'product')->firstOrFail();
    expect($mapping->provenance['resource']['descriptionHtml'])->toBe('<p>A fictional product.</p>')
        ->and(DB::table('website_catalog_source_mappings')->where('id', $mapping->id)->value('provenance'))->not->toContain('A fictional product')
        ->and($mapping->toArray())->not->toHaveKey('provenance');
    $ids = $product->variants->pluck('id')->all();
    $movements = WebsiteInventoryMovement::count();
    $replay = ($this->import)(true);
    expect($replay['unchanged_products'])->toBe(1)->and(WebsiteProduct::count())->toBe(1)->and(WebsiteCollection::count())->toBe(1)->and(WebsiteProductVariant::pluck('id')->all())->toBe($ids)->and(WebsiteCatalogSourceMapping::count())->toBe(4)->and(WebsiteInventoryMovement::count())->toBe($movements)->and($this->site->fresh()->toArray())->toBe($before);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('operator edits conflict and cannot overwrite measured weights or other native fields', function (): void {
    ($this->import)(true);
    $variant = WebsiteProductVariant::firstOrFail();
    $variant->update(['shipping_weight_ounces' => 99, 'shipping_length_inches' => 7]);
    $this->snapshot['variants'][0]['price'] = '99.99';
    $result = ($this->import)(true);
    expect($result['applied'])->toBeFalse()->and($result['conflicts'])->toBeGreaterThan(0)->and($variant->fresh()->shipping_weight_ounces)->toBe(99)->and($variant->fresh()->shipping_length_inches)->toBe(7)->and($variant->fresh()->price_cents)->toBe(1234);
});

test('missing zero weights stay missing and unreviewed typed dimensions stay encrypted rather than guessed', function (): void {
    $this->snapshot['variants'][0]['inventoryItem']['measurement']['weight'] = null;
    $this->snapshot['variants'][1]['inventoryItem']['measurement']['weight'] = ['value' => 0, 'unit' => 'OUNCES'];
    $this->snapshot['variants'][0]['typed_dimension_fields'] = [['namespace' => 'custom', 'key' => 'box_size', 'type' => 'dimension', 'value' => '{"value":4,"unit":"in"}']];
    $result = ($this->import)(true);
    expect(array_count_values(array_column($result['issues'], 'code'))['missing_weight'])->toBe(2)
        ->and(WebsiteProductVariant::whereNull('shipping_weight_ounces')->count())->toBe(2)->and(WebsiteProductVariant::whereNull('shipping_length_inches')->count())->toBe(2)
        ->and(WebsiteCatalogSourceMapping::where('source_gid', 'gid://shopify/ProductVariant/201')->first()->provenance['resource']['typed_dimension_fields'])->toHaveCount(1);
});

test('snapshot updates keep stable mapped variant identities through source reordering and SKU swaps', function (): void {
    ($this->import)(true);
    $ids = WebsiteCatalogSourceMapping::where('source_type', 'variant')->pluck('website_product_variant_id', 'source_gid')->all();
    $this->snapshot['variants'] = array_reverse($this->snapshot['variants']);
    $this->snapshot['variants'][0]['sku'] = 'FICTIONAL-S';
    $this->snapshot['variants'][1]['sku'] = 'FICTIONAL-L';
    $this->snapshot['products'][0]['title'] = 'Updated fictional candle';
    expect(($this->import)(true)['applied'])->toBeTrue()->and(WebsiteCatalogSourceMapping::where('source_type', 'variant')->pluck('website_product_variant_id', 'source_gid')->all())->toBe($ids)
        ->and(WebsiteProductVariant::find($ids['gid://shopify/ProductVariant/201'])->sku)->toBe('FICTIONAL-L');
});

test('gift cards selling plans and mixed physical products are explicit skips', function (string $scenario, string $code): void {
    match ($scenario) {
        'gift' => $this->snapshot['products'][0]['isGiftCard'] = true,
        'plans' => $this->snapshot['products'][0]['sellingPlanGroupsCount']['count'] = 1,
        'mixed' => $this->snapshot['variants'][1]['inventoryItem']['requiresShipping'] = false,
    };
    $result = ($this->import)(true);
    expect($result['skipped_products'])->toBe(1)->and(array_column($result['issues'], 'code'))->toContain($code)->and(WebsiteProduct::count())->toBe(0);
})->with([['gift', 'unsupported_gift_card'], ['plans', 'unsupported_selling_plan'], ['mixed', 'unsupported_nonphysical_or_mixed_inventory']]);

test('identity pagination counts duplicate GIDs unsafe images and missing parents are rejected', function (string $scenario): void {
    match ($scenario) {
        'identity' => $this->snapshot['source']['shop_id'] = 'gid://shopify/Shop/123',
        'currency' => $this->snapshot['source']['currency'] = 'CAD',
        'page' => $this->snapshot['completeness']['variant_media'] = false,
        'counts' => $this->snapshot['completeness']['counts_after']['productVariantsCount']['count'] = 3,
        'duplicate' => $this->snapshot['variants'][1]['id'] = $this->snapshot['variants'][0]['id'],
        'image' => $this->snapshot['products'][0]['images'][0]['url'] = 'https://cdn.shopify.com@evil.example/image.jpg',
        'parent' => $this->snapshot['variants'][1]['product_id'] = 'gid://shopify/Product/9999',
    };
    expect(fn () => ($this->import)(true))->toThrow(ValidationException::class);
    expect(WebsiteProduct::count())->toBe(0)->and(WebsiteCatalogSourceMapping::count())->toBe(0);
})->with(['identity', 'currency', 'page', 'counts', 'duplicate', 'image', 'parent']);

test('unmapped native collisions foreign mappings and missing authorization never claim native rows', function (): void {
    $native = WebsiteProduct::create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'handle' => 'fictional-candle', 'title' => 'Manual native product', 'product_type' => 'physical', 'status' => 'draft']);
    expect(($this->import)(true)['conflicts'])->toBe(1)->and($native->fresh()->title)->toBe('Manual native product')->and(WebsiteCatalogSourceMapping::count())->toBe(0);
    $native->delete();
    ($this->import)(true);
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other-pilot']);
    $foreign = WebsiteProduct::create(['tenant_id' => $other->id, 'tenant_site_id' => $this->site->id, 'handle' => 'foreign', 'title' => 'Foreign', 'product_type' => 'physical', 'status' => 'draft']);
    WebsiteCatalogSourceMapping::where('source_type', 'product')->update(['website_product_id' => $foreign->id]);
    expect(fn () => ($this->import)(true))->toThrow(ValidationException::class);
    expect($foreign->fresh()->title)->toBe('Foreign');
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    expect(fn () => ($this->import)(true))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('any existing native order blocks inventory reapplication', function (): void {
    ($this->import)(true);
    WebsiteOrder::create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'number' => 'TEST-ORDER', 'lookup_token' => str_repeat('x', 56)]);
    $this->snapshot['variants'][0]['inventoryQuantity'] = 100;
    $result = ($this->import)(true);
    expect($result['applied'])->toBeFalse()->and($result['native_activity_blocks_inventory_import'])->toBeTrue()->and(WebsiteProductVariant::first()->inventory_quantity)->toBe(8);
});

test('CLI defaults to dry run and emits an explicit local readiness CSV', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'fictional-shopify-');
    $report = $file.'.csv';
    file_put_contents($file, json_encode($this->snapshot, JSON_THROW_ON_ERROR));
    try {
        $this->artisan('website:import-shopify-catalog-snapshot', ['file' => $file, '--tenant' => $this->tenant->slug, '--site' => $this->site->id, '--actor' => $this->actor->id, '--expected-shop' => 'fictional-pilot.myshopify.com', '--expected-shop-id' => 'gid://shopify/Shop/999', '--report' => $report])->assertSuccessful();
        expect(WebsiteProduct::count())->toBe(0)->and(file_get_contents($report))->toContain('dimensions_require_measured_axis_review')->toContain('inventory_snapshot_only_requires_pilot_stock_allocation');
        expect(fileperms($report) & 0777)->toBe(0600);
    } finally {
        @unlink($file);
        @unlink($report);
    }
});

test('source variant additions preserve old IDs and source removals require explicit review', function (): void {
    ($this->import)(true);
    $oldIds = WebsiteProductVariant::pluck('id')->all();
    $new = $this->snapshot['variants'][0];
    $new['id'] = 'gid://shopify/ProductVariant/203';
    $new['sku'] = 'FICTIONAL-NEW';
    $new['title'] = 'New size';
    array_unshift($this->snapshot['variants'], $new);
    foreach (['counts_before', 'counts_after'] as $key) {
        $this->snapshot['completeness'][$key]['productVariantsCount']['count'] = 3;
    }
    expect(($this->import)(true)['applied'])->toBeTrue()->and(WebsiteProductVariant::count())->toBe(3)->and(WebsiteProductVariant::whereIn('id', $oldIds)->count())->toBe(2)
        ->and(WebsiteCatalogSourceMapping::where('source_gid', $new['id'])->first()->website_product_variant_id)->not->toBeIn($oldIds);
    array_pop($this->snapshot['variants']);
    foreach (['counts_before', 'counts_after'] as $key) {
        $this->snapshot['completeness'][$key]['productVariantsCount']['count'] = 2;
    }
    $result = ($this->import)(true);
    expect($result['applied'])->toBeFalse()->and(array_column($result['issues'], 'code'))->toContain('source_removal_requires_review')->and(WebsiteProductVariant::count())->toBe(3);
});

test('mapping source shop identity cannot be rebound by a later snapshot', function (): void {
    ($this->import)(true);
    $mapping = WebsiteCatalogSourceMapping::where('source_type', 'product')->firstOrFail();
    $provenance = $mapping->provenance;
    $provenance['source']['shop_id'] = 'gid://shopify/Shop/1000';
    $mapping->update(['provenance' => $provenance]);
    expect(fn () => ($this->import)(true))->toThrow(ValidationException::class);
    expect(WebsiteProduct::count())->toBe(1);
});

test('unavailable optional collections preserve existing memberships and provenance', function (): void {
    ($this->import)(true);
    $this->snapshot['completeness']['collections'] = false;
    $this->snapshot['collections'] = [];
    $this->snapshot['products'][0]['description'] = 'Updated safe plain description.';
    $result = ($this->import)(true);
    expect($result['applied'])->toBeTrue()->and(WebsiteProduct::first()->collections)->toHaveCount(1)->and(array_column($result['issues'], 'code'))->toContain('collections_unavailable_preserved_in_provenance');
});

test('negative and unknown tracked stock project to unavailable zero while exact source stock and weights survive', function (): void {
    $this->snapshot['variants'][0]['inventoryQuantity'] = -37;
    $this->snapshot['variants'][1]['inventoryQuantity'] = null;
    $result = ($this->import)(true);
    expect($result['applied'])->toBeTrue()->and($result['planned_unavailable_stock_review_rows'])->toBe(2)
        ->and(WebsiteProduct::first()->status)->toBe('draft')->and(WebsiteProductVariant::where('inventory_quantity', 0)->where('is_available', false)->count())->toBe(2)
        ->and(WebsiteProductVariant::orderBy('id')->pluck('shipping_weight_ounces')->all())->toBe([17, 18])
        ->and(WebsiteCatalogSourceMapping::where('source_gid', 'gid://shopify/ProductVariant/201')->first()->provenance['resource']['inventoryQuantity'])->toBe(-37)
        ->and(WebsiteCatalogSourceMapping::where('source_gid', 'gid://shopify/ProductVariant/202')->first()->provenance['resource']['inventoryQuantity'])->toBeNull()
        ->and(WebsiteInventoryMovement::count())->toBe(0)->and(WebsiteOrder::count())->toBe(0)->and(\App\Models\WebsiteInventoryReservation::count())->toBe(0);
    expect(array_count_values(array_column($result['issues'], 'code'))['source_stock_requires_allocation_review'])->toBe(2);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('oversized source gallery keeps twelve safe display images and freezes the complete gallery encrypted', function (): void {
    $image = $this->snapshot['products'][0]['images'][0];
    $this->snapshot['products'][0]['images'] = [];
    foreach (range(1, 15) as $index) {
        $row = $image;
        $row['id'] = 'gid://shopify/ProductImage/'.(400 + $index);
        $row['url'] = 'https://cdn.shopify.com/s/files/1/fictional-'.$index.'.jpg';
        $this->snapshot['products'][0]['images'][] = $row;
    }
    $result = ($this->import)(true);
    expect($result['skipped_products'])->toBe(0)->and(WebsiteProduct::first()->media)->toHaveCount(12)
        ->and(WebsiteCatalogSourceMapping::where('source_type', 'product')->first()->provenance['resource']['images'])->toHaveCount(15)
        ->and(array_column($result['issues'], 'code'))->toContain('gallery_display_truncated_requires_review');
});

test('source boolean flags cannot be replaced with truthy strings or numbers', function (string $field): void {
    data_set($this->snapshot['variants'][1], $field, 'false');
    expect(fn () => ($this->import)(true))->toThrow(ValidationException::class);
    expect(WebsiteProduct::count())->toBe(0)->and(WebsiteCatalogSourceMapping::count())->toBe(0);
})->with(['availableForSale', 'inventoryItem.requiresShipping', 'inventoryItem.tracked', 'requiresComponents']);

test('selling plan counts require explicit nonnegative integers rather than precision alone', function (): void {
    unset($this->snapshot['products'][0]['sellingPlanGroupsCount']['count']);
    $result = ($this->import)(true);
    expect($result['skipped_products'])->toBe(1)->and(array_column($result['issues'], 'code'))->toContain('unsupported_selling_plan_count_unverified')->and(WebsiteProduct::count())->toBe(0);
});

test('CLI refuses an existing report path before an explicitly requested apply', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'fictional-snapshot-');
    $report = tempnam(sys_get_temp_dir(), 'existing-readiness-');
    file_put_contents($file, json_encode($this->snapshot, JSON_THROW_ON_ERROR));
    file_put_contents($report, 'Existing report must remain intact.');
    try {
        $this->artisan('website:import-shopify-catalog-snapshot', ['file' => $file, '--tenant' => $this->tenant->slug, '--site' => $this->site->id, '--actor' => $this->actor->id, '--expected-shop' => 'fictional-pilot.myshopify.com', '--expected-shop-id' => 'gid://shopify/Shop/999', '--apply' => true, '--report' => $report])->assertFailed();
        expect(WebsiteProduct::count())->toBe(0)->and(WebsiteCatalogSourceMapping::count())->toBe(0)->and(file_get_contents($report))->toBe('Existing report must remain intact.');
    } finally {
        @unlink($file);
        @unlink($report);
    }
});

test('CLI checksum identifies the bytes actually validated even when the source path is replaced later', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'fictional-snapshot-');
    $bytes = json_encode($this->snapshot, JSON_THROW_ON_ERROR);
    file_put_contents($file, $bytes);
    $checksum = hash('sha256', $bytes);
    $replaced = false;
    DB::listen(function ($query) use ($file, &$replaced): void {
        if (! $replaced && str_contains($query->sql, 'website_catalog_source_mappings')) {
            $replaced = true;
            file_put_contents($file, 'A later export replaced this path.');
        }
    });
    try {
        $this->artisan('website:import-shopify-catalog-snapshot', ['file' => $file, '--tenant' => $this->tenant->slug, '--site' => $this->site->id, '--actor' => $this->actor->id, '--expected-shop' => 'fictional-pilot.myshopify.com', '--expected-shop-id' => 'gid://shopify/Shop/999'])->expectsOutputToContain('source SHA256 '.$checksum)->assertSuccessful();
        expect($replaced)->toBeTrue()->and(hash_file('sha256', $file))->not->toBe($checksum)->and(WebsiteProduct::count())->toBe(0);
    } finally {
        @unlink($file);
    }
});
