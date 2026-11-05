<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\LandlordOperatorAction;
use App\Models\ShopifyStore;
use App\Models\StockistLocation;
use App\Models\StockistLocatorSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Stockists\StockistLocatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function stockistFixture(): array
{
    $tenant = Tenant::create(['name' => 'Fictional Candles', 'slug' => 'fictional-stockists']);
    configureEmbeddedRetailStore($tenant->id);
    $store = ShopifyStore::where('tenant_id', $tenant->id)->firstOrFail();
    $actor = User::factory()->create(['is_active' => true]);
    $actor->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);

    return [$store, $actor, app(StockistLocatorService::class)];
}

function stockistRow(array $overrides = []): array
{
    return array_merge(['name' => 'Fictional Shop', 'address' => '100 Fictional St, Easley, SC 29640', 'latitude' => 34.8, 'longitude' => -82.6, 'visible' => true, 'featured' => false, 'sort_order' => 0], $overrides);
}

it('imports an arbitrary reviewed current source idempotently without publishing or retaining vendor secrets', function () {
    [$store, $actor, $service] = stockistFixture();
    expect(StockistLocatorSetting::count())->toBe(0);
    $service->payload($store);
    expect(StockistLocatorSetting::count())->toBe(0);
    $source = ['locations' => [stockistRow(), stockistRow(['name' => 'Second Shop', 'address' => '200 Fictional St'])], 'api_key' => 'private-source-key'];
    $result = $service->import($store, $source, str_repeat('a', 64), $actor->id);
    $service->import($store, $source, str_repeat('a', 64), $actor->id);
    expect($result)->toBe(['source_count' => 2, 'visible_count' => 2])
        ->and(StockistLocation::count())->toBe(2)
        ->and(StockistLocation::where('published', true)->count())->toBe(0)
        ->and($service->settings($store)->status)->toBe('draft')
        ->and(json_encode($service->settings($store)->configuration))->not->toContain('private-source-key');
});

it('rejects invalid or duplicate imports before changing records and retains separate branch addresses', function () {
    [$store, $actor, $service] = stockistFixture();
    expect(fn () => $service->import($store, ['locations' => [stockistRow(), stockistRow()]], str_repeat('a', 64), $actor->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(StockistLocation::count())->toBe(0);
    expect(fn () => $service->save($store, null, stockistRow(['latitude' => 91]), $service->revision($store), $actor->id))->toThrow(ValidationException::class);
    $service->import($store, ['locations' => [stockistRow(), stockistRow(['address' => 'Different branch'])]], str_repeat('b', 64), $actor->id);
    expect(StockistLocation::count())->toBe(2);
});

it('isolates drafts, rejects stale writes and atomically publishes and restores a previous snapshot', function () {
    [$store, $actor, $service] = stockistFixture();
    $service->import($store, ['locations' => [stockistRow()]], str_repeat('a', 64), $actor->id);
    $service->configureKey($store, 'AIza'.str_repeat('x', 35), $service->revision($store), $actor->id);
    expect(json_encode($service->payload($store)))->not->toContain('AIza')
        ->and(json_encode($service->settings($store)->configuration))->not->toContain('AIza');
    $service->publish($store, $service->revision($store), $actor->id);
    $id = StockistLocation::first()->id;
    $stale = $service->revision($store);
    $service->save($store, $id, stockistRow(['name' => 'Edited draft']), $stale, $actor->id);
    expect(data_get($service->settings($store)->configuration, 'published.locations.0.name'))->toBe('Fictional Shop');
    expect(fn () => $service->save($store, $id, stockistRow(), $stale, $actor->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $service->publish($store, $service->revision($store), $actor->id);
    expect(data_get($service->settings($store)->configuration, 'published.locations.0.name'))->toBe('Edited draft');
    $service->publish($store, $service->revision($store), $actor->id, true);
    expect(data_get($service->settings($store)->configuration, 'published.locations.0.name'))->toBe('Fictional Shop')
        ->and($service->draft($store)[0]['name'])->toBe('Edited draft')
        ->and(LandlordOperatorAction::where('tenant_id', $store->tenant_id)->where('action_type', 'stockist.publication_rolled_back')->count())->toBe(1);
});

it('requires a verified signed storefront request and serves only the requested store publication', function () {
    [$store, $actor, $service] = stockistFixture();
    $this->withoutVite();
    $service->import($store, ['locations' => [stockistRow()]], str_repeat('a', 64), $actor->id);
    $service->configureKey($store, 'AIza'.str_repeat('x', 35), $service->revision($store), $actor->id);
    $service->publish($store, $service->revision($store), $actor->id);
    $this->get('/shopify/marketing/v1/stockists?shop='.$store->shop_domain)->assertUnauthorized();
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stockist-test-secret');
    $params = ['shop' => $store->shop_domain, 'timestamp' => (string) time(), 'path_prefix' => '/apps/forestry'];
    ksort($params);
    $canonical = implode('', array_map(fn ($key, $value) => $key.'='.$value, array_keys($params), $params));
    $params['signature'] = hash_hmac('sha256', $canonical, 'stockist-test-secret');
    $this->get('/shopify/marketing/v1/stockists?'.http_build_query($params))->assertOk()->assertSeeText('Fictional Shop')->assertSee('Get directions');
    $params['shop'] = 'foreign.myshopify.com';
    unset($params['signature']);
    ksort($params);
    $params['signature'] = hash_hmac('sha256', implode('', array_map(fn ($key, $value) => $key.'='.$value, array_keys($params), $params)), 'stockist-test-secret');
    $this->get('/shopify/marketing/v1/stockists?'.http_build_query($params))->assertNotFound();
});

it('limits preview links to the signed unchanged draft and rejects raw HTML', function () {
    [$store, $actor, $service] = stockistFixture();
    $this->withoutVite();
    $service->import($store, ['locations' => [stockistRow()]], str_repeat('a', 64), $actor->id);
    $url = URL::temporarySignedRoute('stockists.preview', now()->addMinutes(5), ['store' => $store->id, 'revision' => $service->revision($store)]);
    $this->get($url)->assertOk()->assertSeeText('Private draft preview');
    $this->get('/stockists/preview/'.$store->id)->assertForbidden();
    expect(fn () => $service->save($store, null, stockistRow(['name' => '<script>bad</script>']), $service->revision($store), $actor->id))->toThrow(ValidationException::class);
    $service->save($store, StockistLocation::first()->id, stockistRow(['name' => 'Draft changed']), $service->revision($store), $actor->id);
    $this->get($url)->assertStatus(409);
});

it('requires owner membership and rejects another tenant or wholesale store for admin operations', function () {
    [$store, $actor, $service] = stockistFixture();
    $headers = ['Authorization' => 'Bearer '.retailShopifySessionToken(['email' => $actor->email])];
    $this->actingAs($actor)->getJson('/shopify/app/api/stockists', $headers)->assertOk();
    $other = User::factory()->create(['is_active' => true]);
    $this->actingAs($other)->postJson('/shopify/app/api/stockists/locations', stockistRow(['revision' => $service->revision($store)]), $headers)->assertForbidden();
    $other->tenants()->attach($store->tenant_id, ['role' => 'employee', 'membership_active' => true]);
    $this->getJson('/shopify/app/api/stockists', $headers)->assertForbidden();
    configureEmbeddedWholesaleStore($store->tenant_id);
    $this->actingAs($actor)->getJson('/shopify/app/api/stockists', ['Authorization' => 'Bearer '.wholesaleShopifySessionToken(['email' => $actor->email])])->assertForbidden();
});

it('scopes encrypted storefront preview grants to one unchanged tenant store and expiration', function () {
    [$store, $actor, $service] = stockistFixture();
    $service->import($store, ['locations' => [stockistRow()]], str_repeat('a', 64), $actor->id);
    $token = $service->previewToken($store);
    expect($service->previewRows($store, $token))->toHaveCount(1);
    expect(fn () => $service->previewRows($store, $token.'changed'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->travel(16)->minutes();
    expect(fn () => $service->previewRows($store, $token))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
