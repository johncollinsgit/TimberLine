<?php

use App\Models\Marketplace\Identity;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Membership;
use App\Models\Marketplace\VideoCatalogSource;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\Marketplace\MakerVideoCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['marketplace.enabled' => true, 'marketplace.checkout_provider' => 'shopify', 'marketplace.host' => 'watch.example.test',
        'marketplace.video_shopify_client_id' => 'catalog-client', 'marketplace.video_shopify_client_secret' => 'fixture-secret']);
    Route::middleware('web')->group(base_path('routes/marketplace.php'));
    Route::getRoutes()->refreshNameLookups();
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->maker = Maker::create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry', 'story' => 'Candlemakers', 'city' => 'Easley', 'status' => 'approved']);
    DB::table('marketplace_video_invitations')->insert(['maker_id' => $this->maker->id]);
    $this->identity = Identity::create(['email' => 'maker@example.test', 'active' => true]);
    Membership::create(['identity_id' => $this->identity->id, 'maker_id' => $this->maker->id, 'role' => 'owner', 'active' => true]);
    $this->withSession(['marketplace_identity' => $this->identity->id]);
    $this->url = 'https://watch.example.test/maker/'.$this->maker->id.'/video-catalog';
});

it('authorizes an exact shop, selects only published variants and disables the link when stock or connection changes', function (): void {
    $shop = 'candles-fixture.myshopify.com';
    $inStock = true;
    $published = true;
    Http::fake(function ($request) use ($shop, &$inStock, &$published) {
        if (str_ends_with($request->url(), '/admin/oauth/access_token')) {
            expect($request['expiring'])->toBe(1);

            return Http::response(['access_token' => 'fixture-token', 'refresh_token' => 'fixture-refresh', 'expires_in' => 3600, 'scope' => 'read_products']);
        }
        expect($request->url())->toBe('https://'.$shop.'/admin/api/2026-10/graphql.json')
            ->and($request->header('X-Shopify-Access-Token'))->toBe(['fixture-token'])
            ->and($request['variables']['query'])->toContain('published_status:published');

        return Http::response(['data' => ['productVariants' => ['nodes' => $published ? [['legacyResourceId' => '871', 'title' => '8 oz',
            'availableForSale' => $inStock, 'product' => ['title' => 'Thru Hike', 'status' => 'ACTIVE', 'onlineStoreUrl' => 'https://candles.test/products/thru-hike']]] : [],
            'pageInfo' => ['hasNextPage' => false]]]]);
    });
    $this->post($this->url.'/shopify', ['shop' => $shop, 'consent' => 1])->assertRedirectContains('https://'.$shop.'/admin/oauth/authorize?');
    $state = session('mm_catalog_oauth.state');
    $callback = 'https://watch.example.test/integrations/video-catalog/shopify/callback';
    $query = ['code' => 'one-use', 'shop' => $shop, 'state' => $state];
    $this->get($callback.'?'.http_build_query($query + ['hmac' => 'wrong']))->assertForbidden();
    $query['hmac'] = hash_hmac('sha256', http_build_query($query, '', '&', PHP_QUERY_RFC3986), 'fixture-secret');
    $this->get($callback.'?'.http_build_query($query))->assertRedirect();
    $this->get($callback.'?'.http_build_query($query))->assertForbidden();
    $source = VideoCatalogSource::sole();
    expect(DB::table($source->getTable())->value('credentials'))->not->toContain('fixture-token');
    $this->get($this->url.'?source='.$source->id)->assertOk()->assertSee('Thru Hike');
    $catalog = app(MakerVideoCatalog::class);
    $key = 'shopify:'.$source->id.':871';
    expect($catalog->resolve($this->maker, $key))->toBeNull();
    $this->post($this->url.'/'.$source->id.'/variants', ['variant' => '871', 'action' => 'add'])->assertRedirect();
    $card = $catalog->resolve($this->maker, $key);
    expect($card['href'])->toBe('https://'.$shop.'/products/thru-hike?variant=871')->and($card['available'])->toBeTrue()->and($card['lane'])->toBe('maker_store');
    $other = Maker::create(['name' => 'Other', 'slug' => 'other', 'story' => 'Other', 'city' => 'Other', 'status' => 'approved']);
    expect($catalog->resolve($other, $key))->toBeNull();
    $this->delete('https://watch.example.test/maker/'.$other->id.'/video-catalog/'.$source->id)->assertForbidden();
    $inStock = false;
    Cache::flush();
    expect($catalog->resolve($this->maker, $key)['href'])->toBeNull();
    $published = false;
    Cache::flush();
    expect($catalog->resolve($this->maker, $key))->toBeNull();
    $this->delete($this->url.'/'.$source->id)->assertRedirect();
    expect($catalog->resolve($this->maker, $key))->toBeNull();
});

it('resolves only selected Everbranch variants in the linked workspace and published site', function (): void {
    $tenant = Tenant::create(['name' => 'Candle studio', 'slug' => 'candle-studio']);
    $this->maker->update(['tenant_id' => $tenant->id]);
    $site = TenantSite::create(['tenant_id' => $tenant->id, 'subdomain' => $tenant->slug, 'status' => 'published', 'public_enabled' => true]);
    $product = WebsiteProduct::create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'handle' => 'candle', 'title' => 'Candle',
        'status' => 'active', 'product_type' => 'physical', 'track_inventory' => true]);
    $variant = WebsiteProductVariant::create(['tenant_id' => $tenant->id, 'website_product_id' => $product->id, 'title' => '16 oz', 'price_cents' => 3000, 'inventory_quantity' => 2, 'is_available' => true]);
    $source = VideoCatalogSource::create(['maker_id' => $this->maker->id, 'provider' => 'everbranch', 'reference' => (string) $site->id,
        'selections' => [$variant->id => ['label' => 'Candle', 'variant' => '16 oz']]]);
    $this->mock(ManagedWebsiteService::class, function ($mock) use ($site) {
        $mock->shouldReceive('publicPage')->andReturn(['site' => $site]);
        $mock->shouldReceive('publicHostAllowed')->andReturnTrue();
    });
    $this->mock(WebsiteCommerceService::class)->shouldReceive('checkoutReadiness')->andReturn(['ready' => true]);
    $catalog = app(MakerVideoCatalog::class);
    $key = 'everbranch:'.$site->id.':'.$variant->id;
    expect($catalog->resolve($this->maker, $key)['href'])->toEndWith('/products/candle?variant='.$variant->id);
    $variant->update(['inventory_quantity' => 0]);
    expect($catalog->resolve($this->maker, $key)['available'])->toBeFalse();
    $tenant2 = Tenant::create(['name' => 'Second', 'slug' => 'second']);
    $this->maker->update(['tenant_id' => $tenant2->id]);
    expect($catalog->resolve($this->maker, $key))->toBeNull();
    $this->maker->update(['tenant_id' => $tenant->id]);
    $product->update(['status' => 'draft']);
    expect($catalog->resolve($this->maker, $key))->toBeNull();
    $source->delete();
    expect($catalog->options($this->maker))->toBe([]);
});
