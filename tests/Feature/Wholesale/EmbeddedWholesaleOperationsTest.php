<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WholesaleEmailMessengerDraft;
use App\Models\WholesaleOrderClassification;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyStores;
use App\Services\Wholesale\WholesaleEmailMessengerService;
use App\Services\Wholesale\WholesaleOrderClassificationService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedWholesaleStore((int) $this->tenant->id);
});

test('embedded wholesale metrics and directories exclude retail orders server side', function (): void {
    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'wholesale',
        'source' => 'shopify_wholesale',
        'order_type' => 'wholesale',
        'customer_name' => 'Qualified Stockist',
        'customer_email' => 'buyer@stockist.example',
        'shopify_customer_id' => '101',
        'total_price' => 250,
        'refund_total' => 0,
        'ordered_at' => now()->subDays(10),
    ]);

    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'customer_name' => 'Private Retail Buyer',
        'customer_email' => 'private@example.com',
        'shopify_customer_id' => '202',
        'total_price' => 999,
        'ordered_at' => now()->subDays(5),
    ]);

    $query = wholesaleEmbeddedSignedQuery();

    $this->get(route('shopify.app.wholesale', $query))
        ->assertOk()
        ->assertSeeText('$250.00')
        ->assertSeeText('Qualified Stockist')
        ->assertDontSeeText('$999.00')
        ->assertDontSeeText('Private Retail Buyer');

    $this->get(route('shopify.app.wholesale.customers', $query))
        ->assertOk()
        ->assertSeeText('Qualified Stockist')
        ->assertDontSeeText('Private Retail Buyer')
        ->assertDontSee('private@example.com');

    $this->get(route('shopify.app.wholesale.orders', $query))
        ->assertOk()
        ->assertSeeText('Qualified Stockist')
        ->assertDontSeeText('Private Retail Buyer');
});

test('confirmed legacy wholesale orders appear while ambiguous legacy orders remain excluded', function (): void {
    $confirmed = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'source' => 'shopify_retail',
        'order_type' => 'wholesale',
        'customer_name' => 'Confirmed Legacy Shop',
        'total_price' => 400,
        'ordered_at' => now()->subMonth(),
    ]);
    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'source' => 'shopify_retail',
        'order_type' => 'wholesale',
        'customer_name' => 'Ambiguous Retail Record',
        'total_price' => 800,
        'ordered_at' => now()->subMonth(),
    ]);

    WholesaleOrderClassification::query()->create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $confirmed->id,
        'status' => 'confirmed',
        'classification_basis' => 'documented_wholesale_order',
        'evidence' => ['source' => 'operator_review'],
        'classified_at' => now(),
    ]);

    $response = $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Confirmed Legacy Shop')
        ->assertSeeText('$400.00')
        ->assertDontSeeText('Ambiguous Retail Record')
        ->assertDontSeeText('$800.00');
});

test('same tenant retail installation cannot see or open wholesale operations', function (): void {
    configureEmbeddedRetailStore((int) $this->tenant->id);

    $this->get(route('shopify.app', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Dashboard')
        ->assertDontSeeText('Wholesale Operations')
        ->assertDontSee('/shopify/app/wholesale', false);

    $response = $this->get(route('shopify.app.wholesale', retailEmbeddedSignedQuery()));

    $response->assertForbidden()
        ->assertSeeText('We could not verify this Shopify request')
        ->assertDontSeeText('What needs attention');
});

test('eligible non modern forestry tenant can open only its configured wholesale data', function (): void {
    $otherTenant = Tenant::query()->create([
        'name' => 'Sample Shopify Merchant',
        'slug' => 'sample-shopify-merchant',
    ]);
    configureEmbeddedWholesaleStore((int) $otherTenant->id);
    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'wholesale',
        'order_type' => 'wholesale',
        'customer_name' => 'Modern Forestry Private Buyer',
        'total_price' => 900,
    ]);
    Order::factory()->create([
        'tenant_id' => $otherTenant->id,
        'shopify_store_key' => 'wholesale',
        'order_type' => 'wholesale',
        'customer_name' => 'Sample Merchant Buyer',
        'total_price' => 125,
    ]);

    $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Sample Merchant Buyer')
        ->assertDontSeeText('Modern Forestry Private Buyer');
});

test('canonical module catalog scopes wholesale operations to an explicitly designated wholesale store', function (): void {
    expect(config('module_catalog.modules.wholesale_operations.required_shopify_store_role'))
        ->toBe('wholesale');
});

test('embedded mutation controls wait for a fresh shopify admin session token', function (): void {
    $query = wholesaleEmbeddedSignedQuery();

    $this->get(route('shopify.app.wholesale.prospects.discover', $query))
        ->assertOk()
        ->assertSee('data-wholesale-session-token', false)
        ->assertSee('data-wholesale-mutation-button', false)
        ->assertSee('Finishing Shopify admin verification', false)
        ->assertSee('getShopifySessionToken', false);
});

test('wholesale app navigation puts email messenger before suggestions', function (): void {
    $html = $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()))->assertOk()->getContent();
    preg_match_all('/<s-link\b([^>]*)>(.*?)<\/s-link>/s', $html, $matches, PREG_SET_ORDER);

    expect($matches)->toHaveCount(10)
        ->and($matches[0][1])->toContain('rel="home"')
        ->and(trim(strip_tags($matches[0][2])))->toBe('Overview');

    $visibleLabels = collect(array_slice($matches, 1))
        ->map(fn (array $match): string => trim(strip_tags($match[2])))
        ->values()
        ->all();
    expect($visibleLabels)->toBe(['Email Messenger', 'Suggestions', 'Customers', 'Custom Labels', 'Orders', 'Follow-Ups', 'Prospects', 'Discover', 'Applications']);
    foreach ($matches as $match) {
        expect($match[1])->toContain('href="/shopify/app/wholesale');
    }
});

test('wholesale header logo returns to wholesale overview with embedded context', function (): void {
    $signedQuery = wholesaleEmbeddedSignedQuery();
    $html = $this->get(route('shopify.app.wholesale.messaging', $signedQuery))->assertOk()->getContent();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $links = (new DOMXPath($document))->query('//a[contains(@class, "app-topbar-brand-link")]');
    expect($links->length)->toBe(1);
    $url = $links->item(0)->getAttribute('href');
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
    expect(parse_url($url, PHP_URL_PATH))->toBe('/shopify/app/wholesale')
        ->and($query['shop'] ?? null)->toBe($signedQuery['shop'])
        ->and($query['host'] ?? null)->not->toBeEmpty();
});

test('wholesale email messenger creates and persists the approved sixteen-block draft', function (): void {
    $response = $this->get(route('shopify.app.wholesale.messaging', wholesaleEmbeddedSignedQuery()));
    $response->assertOk()->assertSeeText('Email Messenger')->assertSee('Bring Modern Forestry to your store')->assertSee('cdn.shopify.com');
    expect(WholesaleEmailMessengerDraft::query()->where('tenant_id', $this->tenant->id)->firstOrFail()->sections)->toHaveCount(16);
});

test('wholesale email messenger uses the configured embedded app client id for Shopify Admin session tokens', function (): void {
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-admin-client-id');
    config()->set('services.shopify.stores.wholesale.embedded_client_id', 'wholesale-embedded-client-id');
    config()->set('services.shopify.stores.wholesale.embedded_client_secret', 'wholesale-embedded-client-secret');

    $this->get(route('shopify.app.wholesale.messaging', wholesaleEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSee('<meta name="shopify-api-key" content="wholesale-embedded-client-id">', false);
});

test('wholesale email messenger saves with a server-issued page context when Safari has no embedded session cookie', function (): void {
    $draft = app(WholesaleEmailMessengerService::class)->draft((int) $this->tenant->id, 'wholesale');
    $store = ShopifyStores::find('wholesale', true);
    $contextToken = app(ShopifyEmbeddedAppContext::class)->issueContextToken([
        'store' => $store,
        'shop_domain' => $store['shop'],
        'host' => 'wholesale-admin-host-token',
    ]);

    $this->postJson(route('shopify.app.api.wholesale.messaging.save'), [
        'subject' => $draft['subject'],
        'sections' => $draft['sections'],
        'personalization' => $draft['personalization'],
        'revision' => $draft['revision'],
        'context_token' => $contextToken,
    ])->assertOk()->assertJsonPath('ok', true);
});

test('wholesale email messenger repairs only the legacy placeholder candle assets in an existing draft', function (): void {
    $service = app(WholesaleEmailMessengerService::class);
    $service->draft($this->tenant->id, 'wholesale');
    $draft = WholesaleEmailMessengerDraft::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $sections = $draft->sections;
    $sections[0]['imageUrl'] = 'https://theforestrystudio.com/cdn/shop/files/modern-forestry-wholesale-hero.jpg';
    $sections[10]['products'][0] = [
        'title' => 'Merchant-written product title',
        'imageUrl' => 'https://theforestrystudio.com/cdn/shop/files/cedar-smoke-candle.jpg',
        'href' => 'https://theforestrystudio.com/products/cedar-smoke-candle',
        'buttonLabel' => 'Merchant-written link label',
    ];
    $draft->forceFill(['sections' => $sections])->save();

    $payload = $service->draft($this->tenant->id, 'wholesale');

    expect(data_get($payload, 'sections.0.imageUrl'))->toStartWith('https://cdn.shopify.com/')
        ->and(data_get($payload, 'sections.10.products.0.href'))->toBe('https://theforestrystudio.com/products/forest-spice')
        ->and(data_get($payload, 'sections.10.products.0.imageUrl'))->toStartWith('https://cdn.shopify.com/')
        ->and(data_get($payload, 'sections.10.products.0.title'))->toBe('Merchant-written product title')
        ->and(data_get($payload, 'sections.10.products.0.buttonLabel'))->toBe('Merchant-written link label');
});

test('retired wholesale hero and entry URLs repair once without replacing merchant edits', function (): void {
    $service = app(WholesaleEmailMessengerService::class);
    $service->draft($this->tenant->id, 'wholesale');
    $draft = WholesaleEmailMessengerDraft::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $expected = $draft->sections;
    $expected[0]['alt'] = 'Merchant-written image description';
    $expected[3]['imageUrl'] = 'https://assets.example.test/custom-craft.jpg';
    $expected[11]['label'] = 'Merchant-written application label';
    $legacy = $expected;
    $legacy[0]['imageUrl'] = 'https://cdn.shopify.com/s/files/1/2081/2479/files/SaleImage-darktheme_5f5f655b-77a9-49f5-b699-037f71dee79e.png?v=1762446943';
    $legacy[0]['href'] = 'https://theforestrystudio.com/collections/wholesale';
    $legacy[11]['href'] = 'https://theforestrystudio.com/pages/wholesale';
    $revision = $draft->revision;
    $draft->forceFill(['sections' => $legacy])->save();

    $repaired = $service->draft($this->tenant->id, 'wholesale');
    expect($repaired['sections'])->toBe($expected)
        ->and($repaired['revision'])->toBe($revision + 1);
    $unchanged = $service->draft($this->tenant->id, 'wholesale');
    expect($unchanged['sections'])->toBe($expected)
        ->and($unchanged['revision'])->toBe($repaired['revision']);
});

test('wholesale URL repair leaves custom merchant artwork and destinations unchanged', function (): void {
    $service = app(WholesaleEmailMessengerService::class);
    $service->draft($this->tenant->id, 'wholesale');
    $draft = WholesaleEmailMessengerDraft::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $sections = $draft->sections;
    $sections[0]['imageUrl'] = 'https://assets.example.test/merchant-hero.png';
    $sections[0]['href'] = 'https://example.test/merchant-collection';
    $sections[11]['href'] = 'https://example.test/merchant-application';
    $revision = $draft->revision;
    $draft->forceFill(['sections' => $sections])->save();

    $payload = $service->draft($this->tenant->id, 'wholesale');
    expect($payload['sections'])->toBe($sections)
        ->and($payload['revision'])->toBe($revision);
});

test('email photo selection resolves only from the verified store without changing a draft', function (string $routeName): void {
    $store = ShopifyStores::find('wholesale', true);
    $token = app(ShopifyEmbeddedAppContext::class)->issueContextToken(['store' => $store, 'shop_domain' => $store['shop'], 'host' => 'photo-picker-test']);
    Http::fake(fn () => Http::response(['data' => ['node' => ['id' => 'gid://shopify/MediaImage/123', 'alt' => 'Selected candle photo', 'image' => ['url' => 'https://cdn.shopify.com/fixture-photo.png']]]], 200));

    $this->postJson(route($routeName), [
        'id' => 'gid://shopify/MediaImage/123', 'context_token' => $token,
        'store_key' => 'retail', 'url' => 'https://untrusted.example/ignored.png',
    ])->assertOk()->assertJsonPath('data.url', 'https://cdn.shopify.com/fixture-photo.png');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://'.$store['shop'].'/admin/api/')
        && $request->hasHeader('X-Shopify-Access-Token', $store['token'])
        && $request['variables']['id'] === 'gid://shopify/MediaImage/123');
    expect(WholesaleEmailMessengerDraft::query()->count())->toBe(0);
})->with(['wholesale' => ['shopify.app.api.wholesale.messaging.photo'], 'birthday' => ['shopify.app.api.rewards.birthdays.email.photo']]);

test('email photo selection rejects missing authorization and invalid resource IDs before Shopify is called', function (): void {
    Http::fake();
    $this->postJson(route('shopify.app.api.wholesale.messaging.photo'), ['id' => 'gid://shopify/MediaImage/123'])->assertUnauthorized();
    $store = ShopifyStores::find('wholesale', true);
    $token = app(ShopifyEmbeddedAppContext::class)->issueContextToken(['store' => $store, 'shop_domain' => $store['shop'], 'host' => 'photo-picker-test']);
    $this->postJson(route('shopify.app.api.wholesale.messaging.photo'), ['id' => 'https://untrusted.example/photo.png', 'context_token' => $token])->assertUnprocessable();
    Http::assertNothingSent();
});

test('email photo selection rejects a photo missing from the current store', function (): void {
    Http::fake(fn () => Http::response(['data' => ['node' => null]], 200));
    $store = ShopifyStores::find('wholesale', true);
    $token = app(ShopifyEmbeddedAppContext::class)->issueContextToken(['store' => $store, 'shop_domain' => $store['shop'], 'host' => 'photo-picker-test']);
    $this->postJson(route('shopify.app.api.wholesale.messaging.photo'), ['id' => 'gid://shopify/MediaImage/999', 'context_token' => $token])->assertUnprocessable()->assertJsonMissingPath('data.url');
});

test('email photo selection rejects an unmapped store before Shopify is called', function (): void {
    configureEmbeddedWholesaleStore();
    Http::fake();
    $store = ShopifyStores::find('wholesale', true);
    $token = app(ShopifyEmbeddedAppContext::class)->issueContextToken(['store' => $store, 'shop_domain' => $store['shop'], 'host' => 'photo-picker-test']);
    $this->postJson(route('shopify.app.api.wholesale.messaging.photo'), ['id' => 'gid://shopify/MediaImage/123', 'context_token' => $token])->assertForbidden();
    Http::assertNothingSent();
});

test('wholesale pages fail closed when the authenticated store has no tenant mapping', function (): void {
    configureEmbeddedWholesaleStore();

    $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()))
        ->assertForbidden()
        ->assertSeeText('We could not verify this Shopify request')
        ->assertDontSeeText('What needs attention');
});

test('wholesale pages fail closed when the wholesale store is incorrectly mapped to collins electric', function (): void {
    $collinsElectric = Tenant::query()->create([
        'name' => 'Collins Electric',
        'slug' => 'collins-electric',
    ]);
    ShopifyStore::query()->where('store_key', 'wholesale')->update(['tenant_id' => $collinsElectric->id]);

    $this->get(route('shopify.app.wholesale', wholesaleEmbeddedSignedQuery()))
        ->assertForbidden()
        ->assertSeeText('We could not verify this Shopify request')
        ->assertDontSeeText('What needs attention');
});

test('manual wholesale overrides require evidence and are audited', function (): void {
    $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $actor->tenants()->attach($this->tenant->id, ['role' => 'admin']);
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'order_type' => 'retail',
        'customer_name' => 'Documented Wholesale Buyer',
    ]);

    app(WholesaleOrderClassificationService::class)->classify(
        $this->tenant->id,
        $order->id,
        'manual_override',
        $actor,
        'Operator verified wholesale invoice',
        ['invoice_reference' => 'INV-100']
    );

    expect(WholesaleOrderClassification::query()->forAllTenants()->where('order_id', $order->id)->value('status'))->toBe('manual_override');
    $this->assertDatabaseHas('landlord_operator_actions', [
        'tenant_id' => $this->tenant->id,
        'action_type' => 'wholesale.order_classification.changed',
        'target_id' => (string) $order->id,
    ]);
});

test('customer detail product and scent metrics use qualified wholesale lines only', function (): void {
    $wholesale = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'wholesale',
        'source' => 'shopify_wholesale',
        'order_type' => 'wholesale',
        'shopify_customer_id' => '501',
        'customer_name' => 'Line Item Stockist',
        'total_price' => 120,
        'ordered_at' => now()->subDays(20),
    ]);
    OrderLine::query()->create(['order_id' => $wholesale->id, 'raw_title' => 'Forest Candle', 'scent_name' => 'Pine', 'size_code' => '8oz', 'quantity' => 12, 'line_total' => 120]);
    $retail = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_customer_id' => '999',
        'customer_name' => 'Retail Buyer',
        'total_price' => 999,
        'ordered_at' => now()->subDays(10),
    ]);
    OrderLine::query()->create(['order_id' => $retail->id, 'raw_title' => 'Retail Secret Product', 'scent_name' => 'Retail Secret Scent', 'size_code' => '8oz', 'quantity' => 99, 'line_total' => 999]);
    $accountKey = app(\App\Services\Wholesale\WholesaleOperationsService::class)->customers($this->tenant->id)->first()['public_key'];

    $this->get(route('shopify.app.wholesale.customers.show', array_merge(['accountKey' => $accountKey], wholesaleEmbeddedSignedQuery())))
        ->assertOk()
        ->assertSeeText('Forest Candle')
        ->assertSeeText('Pine')
        ->assertSeeText('12 units')
        ->assertDontSeeText('Retail Secret Product')
        ->assertDontSeeText('Retail Secret Scent');
});
