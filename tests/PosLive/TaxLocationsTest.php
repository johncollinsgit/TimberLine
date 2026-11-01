<?php

use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['pos_live.enabled' => true, 'pos_live.runtime_id' => 'fixture_tax_runtime', 'pos_live.tax_location_management_enabled' => false]);
    $this->tenant = Tenant::create(['name' => 'Tax fixture', 'slug' => 'tax-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $this->user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->user->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    $this->store = app(PosStore::class);
    $this->stock = app(StockLedger::class);
    $this->register = bin2hex(random_bytes(16));
    $this->pool = bin2hex(random_bytes(16));
    $this->item = bin2hex(random_bytes(16));
    $shop = ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'tax-'.$this->tenant->id,
        'shop_domain' => 'tax-'.$this->tenant->id.'.myshopify.com', 'access_token' => 'fictional']);
    $this->stock->rows('pools', $this->tenant->id)->insert(['id' => $this->pool, 'tenant_id' => $this->tenant->id,
        'name' => 'Show', 'kind' => 'show', 'event_key' => 'event:123', 'active' => true, 'reviewed_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->stock->rows('bindings', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'channel' => 'pos',
        'channel_key' => 'fixture_tax_runtime:'.$this->register, 'pool_id' => $this->pool, 'active' => true, 'publish_enabled' => false,
        'reviewed_by' => $this->user->id, 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $this->stock->rows('items', $this->tenant->id)->insert(['id' => $this->item, 'tenant_id' => $this->tenant->id,
        'sku' => 'FIXTURE', 'title' => 'Fictional candle', 'product_key' => 'fixture', 'revision' => str_repeat('d', 64),
        'unit_amount_minor' => 1000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->stock->rows('provider_items', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'store_id' => $shop->id,
        'item_id' => $this->item, 'variant_id' => 'gid://shopify/ProductVariant/123', 'inventory_item_id' => 'gid://shopify/InventoryItem/123', 'location_id' => 'gid://shopify/Location/123',
        'unit_amount_minor' => 1000, 'active' => true, 'revision' => str_repeat('d', 64), 'created_at' => now(), 'updated_at' => now()]);
    $this->store->rows('connections', $this->tenant->id)->insert($this->store->values($this->tenant->id, ['id' => str_repeat('d', 32),
        'merchant_id' => 'fixture', 'provider_location_id' => 'fixture', 'environment' => 'sandbox', 'credentials' => $this->store->encrypt([]), 'active' => true]));
    $this->store->rows('registers', $this->tenant->id)->insert($this->store->values($this->tenant->id, ['id' => $this->register,
        'name' => 'Fixture', 'location_id' => 'fixture', 'location_name' => 'Fixture', 'connection_id' => str_repeat('d', 32),
        'catalog_source' => 'shopify', 'active' => true, 'configuration' => $this->store->encrypt(['commerce_adapter' => 'everbranch_inventory_v1',
            'inventory_pool_id' => $this->pool, 'tax_valid_until' => now()->addDay()->toIso8601String(),
            'shopify' => ['store_id' => $shop->id, 'shop_id' => 'gid://shopify/Shop/123', 'tax_address' => ['city' => 'Old']]])]));
    $this->store->rows('assignments', $this->tenant->id)->insert($this->store->values($this->tenant->id, ['register_id' => $this->register,
        'user_id' => $this->user->id, 'device_id' => 'fixture_device_1234', 'role' => 'admin', 'active' => true]));
    $this->stock->rows('event_links', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'event_id' => 123,
        'provider' => 'manual', 'external_id' => 'event-fixture', 'metadata' => json_encode(['name' => 'Fictional show',
            'address' => '123 Example St', 'city' => 'Columbus', 'state' => 'GA', 'postal_code' => '31901', 'end_on' => now()->addDays(30)->format('Y-m-d')]),
        'reviewed_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->base = '/api/mobile/v1/workspaces/'.$this->tenant->slug.'/pos/registers/'.$this->register.'/tax-locations';
    $token = $this->user->createToken('fixture', ['mobile:read', 'mobile:write', 'payments:read', 'payments:write'], now()->addDay())->plainTextToken;
    $this->withToken($token)->withHeader('X-Pos-Device', 'fixture_device_1234');
});

it('previews a reviewed show address and publishes only after an explicit current review', function (): void {
    Http::fake(['*.myshopify.com/*' => Http::response(['data' => ['draftOrderCalculate' => ['userErrors' => [], 'calculatedDraftOrder' => [
        'taxesIncluded' => false, 'totalPriceSet' => ['shopMoney' => ['amount' => '10.90', 'currencyCode' => 'USD']],
        'totalTaxSet' => ['shopMoney' => ['amount' => '0.90', 'currencyCode' => 'USD']],
    ]]]])]);
    $options = $this->getJson($this->base)->assertOk()->json();
    expect($options['enabled'])->toBeFalse()->and($options['locations'])->toHaveCount(1);
    $location = $options['locations'][0];
    $preview = $this->postJson($this->base.'/preview', ['key' => $location['key']])->assertOk()->json();
    expect($preview['example']['tax_minor'])->toBe(90)->and($preview['location']['address']['city'])->toBe('Columbus');
    $input = ['key' => $location['key'], 'version' => $location['version'], 'previous_version' => $options['current_version'],
        'valid_until' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'), 'confirmed' => true];
    $this->postJson($this->base.'/publish', $input)->assertStatus(503);
    config(['pos_live.tax_location_management_enabled' => true]);
    $this->postJson($this->base.'/publish', [...$input, 'confirmed' => false])->assertStatus(422);
    $this->postJson($this->base.'/publish', [...$input, 'valid_until' => now()->addDays(60)->utc()->format('Y-m-d\TH:i:s\Z')])->assertStatus(422);
    $this->postJson($this->base.'/publish', $input)->assertOk()->assertJsonPath('location.address.city', 'Columbus');
    $this->postJson($this->base.'/publish', $input)->assertStatus(409);
    $saved = $this->store->decrypt($this->store->rows('registers', $this->tenant->id)->where('id', $this->register)->first()->configuration);
    expect($saved['shopify']['tax_address']['zip'])->toBe('31901')->and($saved['tax_location_review']['actor_id'])->toBe($this->user->id);
    Http::assertSentCount(4); // preview and the three reviewed publication attempts
    $scope = ['tenant_id' => $this->tenant->id, 'actor_id' => $this->user->id,
        'register' => $this->store->rows('registers', $this->tenant->id)->where('id', $this->register)->first()];
    app(\App\Services\Pos\PosTaxLocations::class)->assertCurrentReview($scope);
    $this->stock->rows('event_links', $this->tenant->id)->update(['metadata' => json_encode(['name' => 'Fictional show',
        'address' => '456 Other St', 'city' => 'Columbus', 'state' => 'GA', 'postal_code' => '31901', 'end_on' => now()->addDays(30)->format('Y-m-d')])]);
    expect(fn () => app(\App\Services\Pos\PosTaxLocations::class)->assertCurrentReview($scope))->toThrow(\App\Services\Pos\PosException::class, 'tax_location_changed');
});

it('rejects invalid event addresses, stale location evidence and unassigned or non-admin users', function (): void {
    $options = $this->getJson($this->base)->assertOk()->json();
    $location = $options['locations'][0];
    config(['pos_live.tax_location_management_enabled' => true]);
    $this->stock->rows('event_links', $this->tenant->id)->update(['metadata' => json_encode(['name' => 'Bad', 'address' => '', 'city' => 'x'])]);
    $this->postJson($this->base.'/preview', ['key' => $location['key']])->assertNotFound();
    $this->postJson($this->base.'/publish', ['key' => $location['key'], 'version' => $location['version'],
        'previous_version' => $options['current_version'], 'valid_until' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'), 'confirmed' => true])->assertNotFound();
    $this->user->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    $this->getJson($this->base)->assertForbidden();
    $this->withHeader('X-Pos-Device', 'wrong_device')->getJson($this->base)->assertNotFound();
});

it('offers active locations only from the register’s configured Shopify shop', function (): void {
    $this->stock->rows('pools', $this->tenant->id)->where('id', $this->pool)->update(['kind' => 'shared', 'event_key' => null]);
    $location = ['id' => 'gid://shopify/Location/12', 'name' => 'Shop', 'isActive' => true,
        'address' => ['address1' => '1 Main St', 'city' => 'Columbus', 'provinceCode' => 'OH', 'countryCode' => 'US', 'zip' => '43215']];
    $response = fn ($shop) => ['data' => ['shop' => ['id' => $shop], 'locations' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        'nodes' => [$location, [...$location, 'id' => 'gid://shopify/Location/13', 'isActive' => false]]]]];
    Http::fake(['*.myshopify.com/*' => Http::sequence()->push($response('gid://shopify/Shop/123'))->push($response('gid://shopify/Shop/999'))]);
    $this->getJson($this->base)->assertOk()->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.key', 'shopify:12');
    $this->getJson($this->base)->assertStatus(503);
});
