<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Tenant;
use App\Models\WholesaleSuggestion;
use App\Services\Wholesale\WholesaleCustomerOutreachDraft;
use App\Services\Wholesale\WholesaleOutreachDigest;
use App\Services\Wholesale\WholesaleOutreachProductCatalog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Fictional Candles', 'slug' => 'modern-forestry']);
    configureEmbeddedWholesaleStore((int) $this->tenant->id);
    configureWholesaleReportingFixture();
    $this->order = Order::factory()->create([
        'tenant_id' => $this->tenant->id, 'shopify_store_key' => 'wholesale', 'shopify_store' => 'wholesale',
        'source' => 'shopify_wholesale', 'order_type' => 'wholesale', 'shopify_customer_id' => 'fictional-buyer',
        'customer_name' => 'Robin Example', 'shipping_company' => 'Example Shop', 'customer_email' => 'buyer@example.com',
        'total_price' => 300, 'refund_total' => 0, 'ordered_at' => now()->subDays(160),
    ]);
    foreach ([[11, 'Forest Candle', '8 oz', 12], [11, 'Forest Candle', '16 oz', 6], [12, 'Cedar Candle', '8 oz', 8]] as [$id, $title, $variant, $quantity]) {
        OrderLine::query()->create(['order_id' => $this->order->id, 'shopify_product_id' => $id, 'raw_title' => $title, 'raw_variant' => $variant, 'quantity' => $quantity]);
    }
    Http::fake(['s2vscq-rf.myshopify.com/*' => function ($request) {
        expect($request['query'])->toBe(WholesaleOutreachProductCatalog::QUERY);

        return Http::response(['data' => ['shop' => ['primaryDomain' => ['url' => 'https://wholesale.example.com']], 'nodes' => [
            ['id' => 'gid://shopify/Product/11', 'title' => 'Wholesale Forest Candle', 'onlineStoreUrl' => 'https://wholesale.example.com/products/forest', 'featuredMedia' => ['preview' => ['image' => ['url' => 'https://cdn.shopify.com/forest.png', 'altText' => 'Forest Candle']]]],
            ['id' => 'gid://shopify/Product/12', 'title' => 'Wholesale Cedar Candle', 'onlineStoreUrl' => 'https://wholesale.example.com/products/cedar', 'featuredMedia' => ['preview' => ['image' => ['url' => 'https://cdn.shopify.com/cedar.png', 'altText' => null]]]],
        ]]]);
    }]);
});

test('branded drafts match current product IDs and group variants without exposing internal ranking', function (): void {
    Mail::fake();
    $row = app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'][0];
    $draft = app(WholesaleCustomerOutreachDraft::class)->forCustomer($this->tenant, $row['account_key']);
    expect($draft['greeting'])->toBe('Hi Robin,')
        ->and($draft['products'])->toHaveCount(2)
        ->and($draft['products'][0]['title'])->toBe('Forest Candle')
        ->and($draft['products'][0]['previous_order'])->toBe('12 × 8 oz · 6 × 16 oz')
        ->and($draft['products'][0]['url'])->toBe('https://wholesale.example.com/products/forest');
    $html = view('emails.wholesale.customer-outreach', compact('draft'))->render();
    expect($html)->toContain('modern-forestry-logo-white.png', 'https://cdn.shopify.com/forest.png', 'https://wholesale.example.com/products/cedar', 'Hi Robin,', $row['order_number'])
        ->not->toContain('at_risk', 'usual reorder window', '1452 days', '@if', '@endif', 'Everbranch');
    $page = $this->get(route('shopify.app.wholesale.customers.outreach', [...wholesaleEmbeddedSignedQuery(), 'accountKey' => $row['account_key']]))
        ->assertOk()->assertHeader('Cache-Control')
        ->assertSeeText('1. Copy formatted email')->assertSeeText('2. Open Gmail')->assertSeeText('Robin')
        ->assertSee('data-outreach-message-input', false);
    expect($page->headers->hasCacheControlDirective('no-store'))->toBeTrue()
        ->and($page->headers->hasCacheControlDirective('private'))->toBeTrue();
    if ($fixture = getenv('WHOLESALE_OUTREACH_BROWSER_FIXTURE')) {
        file_put_contents($fixture, $page->getContent());
    }
    expect(Order::query()->count())->toBe(1)
        ->and(\App\Models\MarketingEmailDelivery::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

test('old digest links cannot revive a dismissed account or reveal another tenants recipient', function (): void {
    $row = app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'][0];
    WholesaleSuggestion::query()->create([
        'tenant_id' => $this->tenant->id, 'public_id' => (string) Str::uuid(), 'target_type' => 'customer',
        'target_key' => $row['account_key'], 'suggestion_type' => 'customer_due_for_reorder', 'title' => 'Reviewed',
        'recommended_action' => 'Review', 'reason' => 'Qualified timing', 'last_evaluated_at' => now(),
        'evidence_fingerprint' => Str::random(64), 'status' => 'dismissed',
        'supporting_evidence' => ['last_wholesale_order_id' => $this->order->shopify_order_id, 'last_wholesale_order_at' => $this->order->ordered_at->toIso8601String()],
    ]);
    $this->get(route('shopify.app.wholesale.customers.outreach', [...wholesaleEmbeddedSignedQuery(), 'accountKey' => $row['account_key']]))
        ->assertOk()->assertSeeText('Outreach needs another look')->assertDontSeeText('buyer@example.com')->assertDontSee('data-outreach-copy', false);
    expect(fn () => app(WholesaleCustomerOutreachDraft::class)->forCustomer($this->tenant, str_repeat('f', 64)))->toThrow(DomainException::class);
    Http::assertNothingSent();
});

test('catalog lookups never substitute a similarly named product or foreign product link', function (): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['s2vscq-rf.myshopify.com/*' => Http::response(['data' => [
        'shop' => ['primaryDomain' => ['url' => 'https://wholesale.example.com']],
        'nodes' => [null,
            ['id' => 'gid://shopify/Product/11', 'title' => 'Forest', 'onlineStoreUrl' => 'https://other-store.example/products/forest'],
            ['id' => 'gid://shopify/Product/12', 'title' => 'Deleted', 'onlineStoreUrl' => null],
            ['id' => 'gid://shopify/Product/99', 'title' => 'Forest', 'onlineStoreUrl' => 'https://wholesale.example.com/products/forest'],
        ],
    ]])]);
    $result = app(WholesaleOutreachProductCatalog::class)->products((int) $this->tenant->id, [11, 12]);
    expect($result['products'])->toBe([]);
});

test('catalog cache and authority stay within the current tenant and store', function (): void {
    $catalog = app(WholesaleOutreachProductCatalog::class);
    expect($catalog->products((int) $this->tenant->id, [11])['products'])->toHaveCount(1);
    $other = Tenant::query()->create(['name' => 'Other tenant', 'slug' => 'other-tenant']);
    configureEmbeddedWholesaleStore((int) $other->id);
    expect(fn () => $catalog->products((int) $this->tenant->id, [11]))->toThrow(DomainException::class);
});

test('missing catalog media leaves an honest branded draft without guessing an image', function (): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['s2vscq-rf.myshopify.com/*' => Http::response(['data' => ['shop' => ['primaryDomain' => ['url' => 'https://wholesale.example.com']], 'nodes' => []]])]);
    $row = app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'][0];
    $draft = app(WholesaleCustomerOutreachDraft::class)->forCustomer($this->tenant, $row['account_key']);
    expect($draft['products'])->toBe([]);
    $html = view('emails.wholesale.customer-outreach', compact('draft'))->render();
    expect($html)->toContain('modern-forestry-logo-white.png', 'Forest Candle', 'https://wholesale.example.com/')
        ->not->toContain('cdn.shopify.com/forest.png');
});

test('customer fields are escaped and retail sessions cannot open wholesale drafts', function (): void {
    $this->order->update(['customer_name' => '<script>bad</script>', 'shipping_company' => '<img src=x onerror=bad>']);
    $row = app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'][0];
    $draft = app(WholesaleCustomerOutreachDraft::class)->forCustomer($this->tenant, $row['account_key']);
    expect(view('emails.wholesale.customer-outreach', compact('draft'))->render())->not->toContain('<script>bad</script>', '<img src=x');
    configureEmbeddedRetailStore((int) $this->tenant->id);
    $this->get(route('shopify.app.wholesale.customers.outreach', [...retailEmbeddedSignedQuery(), 'accountKey' => $row['account_key']]))
        ->assertForbidden()->assertDontSeeText('buyer@example.com');
});

test('unsafe catalog images are omitted while a verified product link remains usable', function (): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['s2vscq-rf.myshopify.com/*' => Http::response(['data' => [
        'shop' => ['primaryDomain' => ['url' => 'https://wholesale.example.com']],
        'nodes' => [['id' => 'gid://shopify/Product/11', 'title' => 'Forest Candle', 'onlineStoreUrl' => 'https://wholesale.example.com/products/forest', 'featuredMedia' => ['preview' => ['image' => ['url' => 'https://unrelated.example/customer-photo.png']]]]],
    ]])]);
    $product = app(WholesaleOutreachProductCatalog::class)->products((int) $this->tenant->id, [11])['products'][11];
    expect($product['image_url'])->toBeNull()->and($product['url'])->toBe('https://wholesale.example.com/products/forest');
});

test('catalog failure is visible without changing reporting or creating a customer send', function (): void {
    \Illuminate\Support\Facades\Log::spy();
    Mail::fake();
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(fn () => throw new RuntimeException('Fictional catalog unavailable'));
    $row = app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'][0];
    $draft = app(WholesaleCustomerOutreachDraft::class)->forCustomer($this->tenant, $row['account_key']);
    expect($draft['catalog_issue'])->toBeTrue()->and($draft['products'])->toBe([])
        ->and(app(\App\Services\Wholesale\WholesaleOperationsService::class)->reportingStatus((int) $this->tenant->id)['complete'])->toBeTrue();
    $this->get(route('shopify.app.wholesale.customers.outreach', [...wholesaleEmbeddedSignedQuery(), 'accountKey' => $row['account_key']]))
        ->assertOk()->assertSeeText('Product photos couldn’t load');
    Mail::assertNothingSent();
});
