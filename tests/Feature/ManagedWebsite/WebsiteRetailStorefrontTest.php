<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSiteDomain;
use App\Models\User;
use App\Models\WebsiteCart;
use App\Models\WebsiteCollection;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProduct;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCatalogService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->tenant = Tenant::query()->create(['name' => 'Fictional apparel', 'slug' => 'fictional-retail']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true,
        'managed_website.public_render_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id], 'managed_website.commerce_enabled' => true]);
    $this->websites = app(ManagedWebsiteService::class);
    $this->site = $this->websites->createSite($this->tenant, $this->actor);
    $this->websites->applyTheme($this->site, 'hvac-service', $this->actor);
    $this->websites->publish($this->site->fresh(), $this->actor);
    $this->site = $this->site->fresh();
    $this->origin = 'https://'.$this->site->subdomain.'.theeverbranch.com';
    $this->product = app(WebsiteCatalogService::class)->saveProduct($this->site, null, [
        'title' => 'Linen shirt', 'handle' => 'linen-shirt', 'description' => 'A fictional cotton and linen shirt.', 'status' => 'active', 'product_type' => 'physical',
        'track_inventory' => true, 'media' => [], 'collection_ids' => [], 'variants' => [
            ['title' => 'Natural / Small', 'sku' => 'LINEN-S', 'price' => '25.00', 'inventory_quantity' => 0, 'is_available' => true],
            ['title' => 'Natural / Medium', 'sku' => 'LINEN-M', 'price' => '39.50', 'compare_at_price' => '49.50', 'inventory_quantity' => 2, 'is_available' => true],
            ['title' => 'Navy / Large', 'sku' => 'LINEN-L', 'price' => '45.00', 'inventory_quantity' => 3, 'is_available' => false],
        ],
    ], $this->actor);
    $this->variants = $this->product->variants;
});

afterEach(function (): void {
    Http::assertNothingSent();
});

test('both public designs select a sellable variant and support explicit unavailable options without client prices', function (string $theme): void {
    $this->websites->applyTheme($this->site->fresh(), $theme, $this->actor);
    $this->websites->publish($this->site->fresh(), $this->actor);
    $url = $this->origin.'/products/linen-shirt';
    $this->get($url)->assertOk()
        ->assertViewHas('selectedVariant', fn ($variant) => $variant['id'] === $this->variants[1]->id && $variant['price'] === '$39.50' && $variant['compare_price'] === '$49.50' && $variant['maximum'] === 2)
        ->assertSee('aria-describedby="retail-availability"', false)
        ->assertSee('data-variant-choice', false)->assertSee('View option');
    $this->get($url.'?variant='.$this->variants[0]->id)->assertOk()
        ->assertViewHas('selectedVariant', fn ($variant) => $variant['id'] === $this->variants[0]->id && ! $variant['available'] && $variant['price'] === '$25.00')
        ->assertSee('disabled data-variant-add', false);
    $this->get($url.'?variant='.$this->variants[2]->id)->assertOk()
        ->assertViewHas('selectedVariant', fn ($variant) => ! $variant['available']);
    $this->get($url.'?variant=999999')->assertNotFound();
    $this->getJson($url.'?variant[]=1')->assertUnprocessable();
})->with(['native' => 'hvac-service', 'Sawyer' => 'sawyer-naturals']);

test('a non-first variant cart selection keeps current server pricing and rejects unavailable stale or mismatched items', function (): void {
    $variant = $this->variants[1];
    $action = $this->origin.'/cart/items/'.$variant->id;
    $this->post($action, ['product_id' => $this->product->id, 'quantity' => 1, 'price_cents' => 1])->assertRedirect($this->origin.'/cart');
    $cart = WebsiteCart::query()->forTenant($this->tenant)->sole();
    expect($cart->items()->sole()->website_product_variant_id)->toBe($variant->id);
    $variant->update(['price_cents' => 4200]);
    expect(app(WebsiteCommerceService::class)->cartFor($this->site, $cart->token)->items->sole()->variant->price_cents)->toBe(4200);
    $this->postJson($action, ['product_id' => $this->product->id + 999, 'quantity' => 1])->assertNotFound();
    $this->postJson($action, ['product_id' => $this->product->id, 'quantity' => 2])->assertUnprocessable();
    $this->postJson($this->origin.'/cart/items/'.$this->variants[0]->id, ['quantity' => 1])->assertUnprocessable();
    $variant->update(['is_available' => false]);
    $this->postJson($action, ['quantity' => 1])->assertUnprocessable();
    expect($cart->items()->sole()->quantity)->toBe(1)->and(WebsiteOrder::query()->count())->toBe(0);
});

test('a foreign tenant or different product cannot supply the selected variant', function (): void {
    $foreign = Tenant::query()->create(['name' => 'Other shop', 'slug' => 'foreign-retail']);
    $product = WebsiteProduct::query()->create(['tenant_id' => $foreign->id, 'tenant_site_id' => $this->site->id, 'title' => 'Foreign', 'handle' => 'foreign', 'status' => 'active', 'product_type' => 'physical']);
    $variant = $product->variants()->create(['tenant_id' => $foreign->id, 'title' => 'Foreign option', 'price_cents' => 100, 'is_available' => true]);
    $this->get($this->origin.'/products/linen-shirt?variant='.$variant->id)->assertNotFound();
    $this->postJson($this->origin.'/cart/items/'.$variant->id, ['quantity' => 1])->assertNotFound();
    $local = WebsiteProduct::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'title' => 'Other product', 'handle' => 'other-product', 'status' => 'active', 'product_type' => 'physical']);
    $localVariant = $local->variants()->create(['tenant_id' => $this->tenant->id, 'title' => 'Other local option', 'price_cents' => 200, 'is_available' => true]);
    $this->get($this->origin.'/products/linen-shirt?variant='.$localVariant->id)->assertNotFound();
    $this->postJson($this->origin.'/cart/items/'.$localVariant->id, ['product_id' => $this->product->id, 'quantity' => 1])->assertNotFound();
    expect(WebsiteCart::query()->count())->toBe(0);
});

test('all one hundred supported variants remain individually selectable', function (): void {
    foreach (range(4, 100) as $number) {
        $last = $this->product->variants()->create(['tenant_id' => $this->tenant->id, 'title' => 'Option '.$number, 'sku' => 'OPTION-'.$number, 'price_cents' => $number * 100, 'inventory_quantity' => 1, 'is_available' => true]);
    }
    $this->get($this->origin.'/products/linen-shirt?variant='.$last->id)->assertOk()
        ->assertViewHas('variantChoices', fn ($choices) => $choices->count() === 100)
        ->assertViewHas('selectedVariant', fn ($variant) => $variant['id'] === $last->id && $variant['price'] === '$100.00');
});

test('active collections and bounded product search combine without exposing drafts or foreign memberships', function (string $theme): void {
    $this->websites->applyTheme($this->site->fresh(), $theme, $this->actor);
    $this->websites->publish($this->site->fresh(), $this->actor);
    $collection = WebsiteCollection::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'title' => 'Summer linen', 'handle' => 'summer-linen', 'status' => 'active']);
    $collection->products()->attach($this->product, ['tenant_id' => $this->tenant->id, 'position' => 0]);
    $draft = WebsiteCollection::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'title' => 'Private collection', 'handle' => 'private-collection', 'status' => 'draft']);
    $this->get($this->origin.'/shop?collection=summer-linen&q=LINEN-M')->assertOk()->assertSee('Linen shirt')->assertSee('Summer linen')
        ->assertDontSee('Private collection')->assertViewHas('products', fn ($products) => $products->total() === 1 && $products->perPage() === 24);
    $this->get($this->origin.'/shop?collection=summer-linen&q=unmatched')->assertOk()->assertSee('No products match.')->assertViewHas('products', fn ($products) => $products->total() === 0);
    $this->get($this->origin.'/shop?collection='.$draft->handle)->assertNotFound();
    $foreignTenant = Tenant::query()->create(['name' => 'Foreign collections', 'slug' => 'foreign-collections']);
    $foreignCollection = WebsiteCollection::query()->create(['tenant_id' => $foreignTenant->id, 'tenant_site_id' => $this->site->id, 'title' => 'Foreign collection', 'handle' => 'foreign-collection', 'status' => 'active']);
    $this->get($this->origin.'/shop?collection='.$foreignCollection->handle)->assertNotFound();
    $this->getJson($this->origin.'/shop?q='.str_repeat('x', 101))->assertUnprocessable();
    $this->product->update(['status' => 'draft']);
    $this->get($this->origin.'/shop?collection=summer-linen')->assertOk()->assertDontSee('Linen shirt');
})->with(['native' => 'hvac-service', 'Sawyer' => 'sawyer-naturals']);

test('search treats wildcard characters literally and pagination retains the chosen collection', function (): void {
    $collection = WebsiteCollection::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'title' => 'Complete collection', 'handle' => 'complete', 'status' => 'active']);
    foreach (range(1, 26) as $number) {
        $product = WebsiteProduct::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'title' => 'Fixture item '.$number, 'handle' => 'fixture-'.$number, 'status' => 'active', 'product_type' => 'physical']);
        $collection->products()->attach($product, ['tenant_id' => $this->tenant->id, 'position' => $number]);
    }
    $this->get($this->origin.'/shop?q=%25')->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 0);
    $this->product->update(['title' => '100% linen_shirt!']);
    $this->get($this->origin.'/shop?q=%25')->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 1);
    $this->get($this->origin.'/shop?q=linen_shirt!')->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 1);
    $this->get($this->origin.'/shop?collection=complete&q=Fixture')->assertOk()->assertSee('aria-label="Product pages"', false)
        ->assertViewHas('products', fn ($products) => $products->total() === 26 && $products->count() === 24 && str_contains($products->nextPageUrl(), 'collection=complete'));
    $this->get($this->origin.'/shop?collection=complete&q=Fixture&page=2')->assertOk()->assertViewHas('products', fn ($products) => $products->count() === 2);
});

test('legacy Sawyer category URLs remain independent of native collection membership', function (): void {
    $this->websites->applyTheme($this->site, 'sawyer-naturals', $this->actor);
    $this->websites->publish($this->site->fresh(), $this->actor);
    $this->product->update(['service_details' => ['categories' => ['apparel'], 'category' => 'A separate native label']]);
    $this->get($this->origin.'/shop?category=apparel')->assertOk()->assertSee('Linen shirt')->assertSee('category=deodorant');
    $this->get($this->origin.'/shop?category=soaps')->assertOk()->assertDontSee('Linen shirt');
});

test('paused commerce shows choices and contact guidance without offering a cart write', function (): void {
    config()->set(['managed_website.commerce_enabled' => false, 'managed_website.commerce_preview_tenant_ids' => []]);
    $this->get($this->origin.'/products/linen-shirt')->assertOk()->assertSee('Choose an option')->assertSee('Contact us to order.')
        ->assertDontSee('data-variant-cart-form', false)->assertDontSee('>Cart</a>', false);
    $this->postJson($this->origin.'/cart/items/'.$this->variants[1]->id, ['quantity' => 1])->assertStatus(423);
    expect(WebsiteCart::query()->count())->toBe(0);
});

test('the included quote-only address denies new retail writes without clearing an existing cart', function (): void {
    $cart = app(WebsiteCommerceService::class)->cartFor($this->site, null);
    app(WebsiteCommerceService::class)->addToCart($cart, $this->variants[1]->load('product'), 1);
    $theme = $this->site->publishedSiteVersion;
    $theme->update(['settings' => [...$theme->settings, 'domain_choice' => 'everbranch_subdomain']]);
    $this->get($this->origin.'/shop')->assertOk()->assertDontSee('Linen shirt')->assertDontSee('>Cart</a>', false);
    $this->get($this->origin.'/products/linen-shirt')->assertNotFound();
    $this->postJson($this->origin.'/cart/items/'.$this->variants[1]->id, ['quantity' => 1])->assertNotFound();
    $this->postJson($this->origin.'/checkout/website', [])->assertNotFound();
    expect($cart->items()->sole()->quantity)->toBe(1)->and(WebsiteOrder::query()->count())->toBe(0);
});

test('an approved direct custom host keeps browsing without offering prohibited cart writes', function (): void {
    $hostname = 'shop.direct-fictional.example';
    config()->set(['managed_website.custom_domains_enabled' => true, 'managed_website.custom_domain_activation_enabled' => true,
        'managed_website.custom_domain_tenant_ids' => [$this->tenant->id]]);
    TenantSiteDomain::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'hostname' => $hostname, 'status' => 'active', 'is_primary' => true, 'verification_token' => 'fictional-proof', 'verified_at' => now(), 'activated_at' => now()]);
    $this->get('https://'.$hostname.'/products/linen-shirt?variant='.$this->variants[1]->id)->assertOk()->assertViewHas('cartEnabled', false)
        ->assertSee('Contact us to order.')->assertDontSee('data-variant-cart-form', false)->assertDontSee('>Account</a>', false);
    $this->post('https://'.$hostname.'/cart/items/'.$this->variants[1]->id, ['product_id' => $this->product->id, 'quantity' => 1])->assertNotFound();
    expect(WebsiteCart::query()->count())->toBe(0);
});

test('signed custom-domain relay permits browsing but exposes no shopper controls and rejects commerce writes', function (): void {
    $hostname = 'www.fictional-retail.example';
    config()->set(['managed_website.custom_domains_enabled' => true, 'managed_website.custom_domain_activation_enabled' => true,
        'managed_website.custom_domain_tenant_ids' => [$this->tenant->id], 'managed_website.relay.enabled' => true, 'managed_website.relay.secret' => str_repeat('fictional-relay-key-', 3)]);
    TenantSiteDomain::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'hostname' => $hostname, 'status' => 'active', 'is_primary' => true, 'verification_token' => 'fictional-proof', 'verified_at' => now(), 'activated_at' => now()]);
    $headers = static function (string $method, string $uri) use ($hostname): array {
        $stamp = now()->timestamp;
        $message = implode("\n", ['v1', (string) $stamp, $method, $hostname, $uri, hash('sha256', '')]);

        return ['HTTP_X_EVERBRANCH_WEBSITE_HOST' => $hostname, 'HTTP_X_EVERBRANCH_WEBSITE_TIMESTAMP' => (string) $stamp, 'HTTP_X_EVERBRANCH_WEBSITE_SIGNATURE' => hash_hmac('sha256', $message, config('managed_website.relay.secret'))];
    };
    $uri = '/products/linen-shirt?variant='.$this->variants[1]->id;
    $this->call('GET', 'https://app.theeverbranch.com'.$uri, [], [], [], $headers('GET', $uri))->assertOk()->assertSee('Choose an option')->assertSee('Contact us to order.')
        ->assertDontSee('data-variant-cart-form', false)->assertDontSee('>Account</a>', false);
    foreach (['/cart/items/'.$this->variants[1]->id, '/checkout/website'] as $path) {
        $this->call('POST', 'https://app.theeverbranch.com'.$path, [], [], [], $headers('POST', $path))->assertNotFound();
    }
    $this->product->update(['product_type' => 'quote']);
    $uri = '/products/linen-shirt';
    $this->call('GET', 'https://app.theeverbranch.com'.$uri, [], [], [], $headers('GET', $uri))->assertOk()->assertSee('To request a quote,')
        ->assertSee('href="/contact"', false)->assertDontSee('method="POST"', false);
    expect(WebsiteCart::query()->count())->toBe(0)->and(WebsiteOrder::query()->count())->toBe(0);
});

test('custom-host quote products link to a published contact page instead of a prohibited POST', function (string $theme): void {
    $this->websites->applyTheme($this->site->fresh(), $theme, $this->actor);
    $this->websites->publish($this->site->fresh(), $this->actor);
    $this->product->update(['product_type' => 'quote']);
    $hostname = 'quotes.fictional.example';
    config()->set(['managed_website.custom_domains_enabled' => true, 'managed_website.custom_domain_activation_enabled' => true, 'managed_website.custom_domain_tenant_ids' => [$this->tenant->id]]);
    TenantSiteDomain::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'hostname' => $hostname, 'status' => 'active', 'is_primary' => true, 'verification_token' => 'fictional-proof', 'verified_at' => now(), 'activated_at' => now()]);
    $this->get('https://'.$hostname.'/products/linen-shirt')->assertOk()->assertSee('To request a quote,')->assertSee('href="/contact"', false)
        ->assertDontSee('method="POST"', false);
    $this->get('https://'.$hostname.'/contact')->assertOk()->assertSee('action="/website/forms/', false);
    $this->postJson('https://'.$hostname.'/products/linen-shirt/quote', [])->assertNotFound();
    $this->site->pages()->where('slug', 'contact')->update(['published_version_id' => null]);
    $this->site->update(['settings' => [...$this->site->settings, 'business_contact' => ['email' => 'draft-only@example.test']]]);
    $response = $this->get('https://'.$hostname.'/products/linen-shirt')->assertOk()->assertSee('contact the business directly.')->assertViewHas('contactUrl', null);
    expect(preg_match('/<p class="retail-shopping-note">(.*?)<\/p>/s', $response->getContent(), $paragraph))->toBe(1);
    expect($paragraph[1])->not->toContain('<a'); // Preserve unrelated legacy footer links.
    $themeVersion = $this->site->fresh()->publishedSiteVersion;
    $themeVersion->update(['settings' => [...$themeVersion->settings, 'business_contact' => ['email' => 'published-contact@example.test']]]);
    // Reload the public snapshot after this direct fictional fixture seed.
    \Illuminate\Support\Facades\Cache::forget('managed-website:public:'.$this->site->id.':'.sha1('/'));
    $this->get('https://'.$hostname.'/products/linen-shirt')->assertOk()->assertViewHas('contactUrl', 'mailto:published-contact@example.test')
        ->assertSee('href="mailto:published-contact@example.test"', false)->assertDontSee('draft-only@example.test');
})->with(['native' => 'hvac-service', 'Sawyer' => 'sawyer-naturals']);

test('included-address quote requests keep their original allowed submission', function (): void {
    $this->product->update(['product_type' => 'quote']);
    $theme = $this->site->publishedSiteVersion;
    $theme->update(['settings' => [...$theme->settings, 'domain_choice' => 'everbranch_subdomain']]);
    $this->get($this->origin.'/products/linen-shirt')->assertOk()->assertSee('method="POST"', false)->assertSee('/products/linen-shirt/quote');
    $this->post($this->origin.'/products/linen-shirt/quote', ['name' => 'Fictional buyer', 'email' => 'fictional@example.test', 'phone' => '5550101234', 'service_address' => 'Fictional address', 'service_needed' => 'Fictional request', 'message' => 'A fictional quote request.', 'website' => ''])->assertRedirect();
    expect(\App\Models\FormSubmission::query()->where('tenant_id', $this->tenant->id)->where('source', 'managed_website_quote')->count())->toBe(1)
        ->and(WebsiteOrder::query()->count())->toBe(0);
});
