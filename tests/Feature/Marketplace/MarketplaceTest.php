<?php

use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\Connection;
use App\Models\Marketplace\Identity;
use App\Models\Marketplace\InventoryAllocation;
use App\Models\Marketplace\LedgerEntry;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\LoginLink;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Membership;
use App\Models\Marketplace\Operation;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\OrderLine;
use App\Models\Marketplace\PickupLocation;
use App\Models\Marketplace\RateAcceptance;
use App\Models\Marketplace\Statement;
use App\Models\Marketplace\WebhookEvent;
use App\Models\Order as LegacyOrder;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Marketplace\MarketplaceCatalog;
use App\Services\Marketplace\MarketplaceCheckout;
use App\Services\Marketplace\MarketplaceOrders;
use App\Services\Marketplace\MarketplaceRates;
use App\Services\Marketplace\MarketplaceShopify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['marketplace.enabled' => true, 'marketplace.checkout_enabled' => true, 'marketplace.checkout_provider' => 'shopify', 'marketplace.host' => 'marketsandmakers.test', 'marketplace.operator_user_ids' => [], 'app.url' => 'https://app.theeverbranch.test']);
    // Domain routes are registered from configuration at boot; exercise the real
    // route file with this test's independently configured marketplace host.
    Route::middleware('web')->group(base_path('routes/marketplace.php'));
    $routes = new \Illuminate\Routing\RouteCollection;
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'marketplace.public.')) {
            $routes->add($route);
        }
    }
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'marketplace.public.')) {
            $routes->add($route);
        }
    }
    Route::setRoutes($routes);
    $this->withoutVite();
    Http::preventStrayRequests();
});

function marketsFixture(bool $testMode = false): array
{
    $identity = Identity::create(['email' => Str::random(10).'@example.test', 'active' => true]);
    $maker = Maker::create(['slug' => 'maker-'.Str::lower(Str::random(8)), 'name' => 'Fictional Upstate Maker', 'city' => 'Easley', 'story' => 'Handmade test goods.', 'status' => 'approved']);
    Membership::create(['maker_id' => $maker->id, 'identity_id' => $identity->id, 'role' => 'owner', 'active' => true]);
    $connection = Connection::create(['uuid' => (string) Str::uuid(), 'shop_domain' => 'mm-fixture.myshopify.com', 'shop_gid' => 'gid://shopify/Shop/100', 'publication_gid' => 'gid://shopify/Publication/200', 'credentials' => ['admin_token' => 'fixture-admin', 'storefront_token' => 'fixture-storefront', 'webhook_secret' => 'fixture-signing-secret', 'checkout_host' => 'mm-fixture.myshopify.com'], 'test_mode' => $testMode, 'verified_at' => now(), 'created_by' => 1]);
    app(MarketplaceAccess::class)->settings()->forceFill(['connection_id' => $connection->id, 'checkout_enabled' => true, 'support_email' => 'support@example.test', 'shipping_policy' => 'Ships in three days.', 'return_policy' => 'Contact support.', 'privacy_policy' => 'Order data is used for fulfillment.', 'readiness' => ['merchant_setup' => true, 'shipping' => true, 'tax' => true, 'guest_checkout' => true, 'policies' => true, 'test_paid_shop_gid' => $connection->shop_gid, 'signed_test_secret_hash' => hash('sha256', 'fixture-signing-secret')]])->save();
    $listing = Listing::create(['maker_id' => $maker->id, 'slug' => 'ceramic-mug-'.Str::lower(Str::random(8)), 'title' => 'Fictional ceramic mug', 'category' => 'Ceramics', 'description' => 'A locally made mug.', 'images' => ['https://example.test/mug.jpg'], 'price_cents' => 5000, 'stock_quantity' => 10, 'shipping' => true, 'status' => 'approved', 'revision' => 1, 'published_revision' => 1, 'connection_id' => $connection->id, 'shopify_product_gid' => 'gid://shopify/Product/300', 'shopify_variant_gid' => 'gid://shopify/ProductVariant/400', 'shopify_inventory_gid' => 'gid://shopify/InventoryItem/500', 'shipping_location_gid' => 'gid://shopify/Location/600']);
    $rate = app(MarketplaceRates::class)->global();
    RateAcceptance::create(['maker_id' => $maker->id, 'rate_id' => $rate->id, 'identity_id' => $identity->id]);

    return compact('identity', 'maker', 'connection', 'listing', 'rate');
}

function marketsCheckout(array $fixture, int $quantity = 1, string $group = 'shipping'): Checkout
{
    $listing = $fixture['listing'];
    $rate = app(MarketplaceRates::class)->resolve($listing);

    return Checkout::create(['uuid' => (string) Str::uuid(), 'session_hash' => hash('sha256', 'guest-fixture'), 'connection_id' => $fixture['connection']->id, 'group_key' => $group, 'status' => 'ready', 'snapshot' => ['lines' => [['cart_key' => $listing->id.':'.$group, 'listing_id' => $listing->id, 'maker_id' => $listing->maker_id, 'quantity' => $quantity, 'title' => $listing->title, 'variant_gid' => $listing->shopify_variant_gid, 'location_gid' => $listing->shipping_location_gid, ...app(MarketplaceRates::class)->amounts($listing->price_cents, $rate)]], 'subsidy_limit_cents' => 0], 'expires_at' => now()->addDays(10)]);
}

function marketsProvider(Checkout $checkout, int $discount = 0): array
{
    $gross = collect($checkout->snapshot['lines'])->sum(fn ($line) => $line['price_cents'] * $line['quantity']);
    $shipping = $checkout->group_key === 'shipping' ? 600 : 0;
    $total = $gross - $discount + $shipping;
    $lines = [];
    $fos = [];
    foreach ($checkout->snapshot['lines'] as $index => $line) {
        $gid = 'gid://shopify/LineItem/'.(700 + $index);
        $lines[] = ['id' => $gid, 'title' => $line['title'], 'quantity' => $line['quantity'], 'variant' => ['id' => $line['variant_gid']], 'customAttributes' => [['key' => '_mm_line', 'value' => $line['cart_key']]], 'originalUnitPriceSet' => ['shopMoney' => ['amount' => MarketplaceShopify::decimal($line['price_cents'])]], 'discountedTotalSet' => ['shopMoney' => ['amount' => MarketplaceShopify::decimal($line['price_cents'] * $line['quantity'] - ($index === 0 ? $discount : 0))]]];
        $fos[] = ['id' => 'gid://shopify/FulfillmentOrder/'.(800 + $index), 'status' => 'OPEN', 'assignedLocation' => ['location' => ['id' => $line['location_gid']]], 'deliveryMethod' => ['methodType' => $checkout->group_key === 'shipping' ? 'SHIPPING' : 'PICK_UP'], 'lineItems' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrderLineItem/'.(850 + $index), 'remainingQuantity' => $line['quantity'], 'lineItem' => ['id' => $gid]]], 'pageInfo' => ['hasNextPage' => false]]];
    }

    return ['id' => 'gid://shopify/Order/900', 'name' => '#MM1001', 'test' => $checkout->connection->test_mode, 'email' => 'buyer@example.test', 'currencyCode' => 'USD', 'displayFinancialStatus' => 'PAID', 'taxesIncluded' => false, 'customAttributes' => [['key' => '_mm_checkout', 'value' => $checkout->uuid]], 'shippingAddress' => ['name' => 'Fictional Buyer', 'address1' => '100 Test Street', 'city' => 'Easley', 'provinceCode' => 'SC', 'zip' => '29640', 'countryCodeV2' => 'US'], 'totalPriceSet' => ['shopMoney' => ['amount' => MarketplaceShopify::decimal($total), 'currencyCode' => 'USD']], 'totalTaxSet' => ['shopMoney' => ['amount' => '0.00']], 'shippingLines' => ['nodes' => $shipping ? [['discountedPriceSet' => ['shopMoney' => ['amount' => '6.00']]]] : [], 'pageInfo' => ['hasNextPage' => false]], 'transactions' => [['id' => 'gid://shopify/OrderTransaction/950', 'status' => 'SUCCESS', 'kind' => 'SALE', 'gateway' => 'shopify_payments', 'amountSet' => ['shopMoney' => ['amount' => MarketplaceShopify::decimal($total)]]]], 'lineItems' => ['nodes' => $lines, 'pageInfo' => ['hasNextPage' => false]], 'fulfillmentOrders' => ['nodes' => $fos, 'pageInfo' => ['hasNextPage' => false]], 'fulfillments' => [], 'refunds' => []];
}

function marketsFulfilled(array $provider): array
{
    $provider['fulfillments'] = [['id' => 'gid://shopify/Fulfillment/1000', 'status' => 'SUCCESS', 'trackingInfo' => [['number' => 'TEST123', 'url' => 'https://example.test/tracking']], 'fulfillmentLineItems' => ['nodes' => array_map(fn ($line) => ['quantity' => $line['quantity'], 'lineItem' => ['id' => $line['id']]], $provider['lineItems']['nodes']), 'pageInfo' => ['hasNextPage' => false]]]];

    return $provider;
}

function marketsRefund(array $provider, int $quantity = 1): array
{
    $provider['displayFinancialStatus'] = 'PARTIALLY_REFUNDED';
    $provider['refunds'] = [['id' => 'gid://shopify/Refund/1100', 'totalRefundedSet' => ['shopMoney' => ['amount' => MarketplaceShopify::decimal(5000 * $quantity)]], 'refundShippingLines' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]], 'refundLineItems' => ['nodes' => [['quantity' => $quantity, 'lineItem' => ['id' => $provider['lineItems']['nodes'][0]['id']]]], 'pageInfo' => ['hasNextPage' => false]], 'transactions' => ['nodes' => [['status' => 'SUCCESS', 'kind' => 'REFUND', 'amountSet' => ['shopMoney' => ['amount' => MarketplaceShopify::decimal(5000 * $quantity)]]]], 'pageInfo' => ['hasNextPage' => false]]]];

    return $provider;
}

test('only the independently configured marketplace host serves the storefront and isolates app routes', function (): void {
    marketsFixture();
    $this->get('https://marketsandmakers.test/')->assertOk()->assertSee('Good things.')->assertSee('Fictional ceramic mug');
    $this->get('https://marketsandmakers.test/login')->assertNotFound();
    $this->get('https://marketsandmakers.test/marketplace/operations')->assertNotFound();
    $this->get('https://unrelated.invalid/products/ceramic-mug')->assertNotFound();
    config(['marketplace.enabled' => false]);
    $this->get('https://marketsandmakers.test/')->assertNotFound();
});

test('guest cart groups shipping and pickup while checkout remains disabled by default', function (): void {
    $f = marketsFixture();
    $pickup = PickupLocation::create(['name' => 'Fictional Saturday Market', 'address' => 'Test location', 'instructions' => 'Saturday morning', 'shopify_location_gid' => 'gid://shopify/Location/600', 'active' => true]);
    $f['listing']->update(['pickup_location_ids' => [$pickup->id]]);
    $this->post('https://marketsandmakers.test/cart/add', ['listing_id' => $f['listing']->id, 'quantity' => 1, 'group' => 'shipping'])->assertRedirect('/cart');
    $this->post('https://marketsandmakers.test/cart/add', ['listing_id' => $f['listing']->id, 'quantity' => 1, 'group' => 'pickup:'.$pickup->id])->assertRedirect('/cart');
    config(['marketplace.checkout_enabled' => false]);
    $this->get('https://marketsandmakers.test/cart')->assertOk()->assertSee('2 checkout groups')->assertSee('Fictional Saturday Market')->assertSee('Checkout opens soon');
    Http::assertNothingSent();
});

test('a guest can begin an idempotent server-priced Shopify cart without an account', function (): void {
    $f = marketsFixture();
    Http::fake(function ($request) use ($f) {
        if (str_contains($request['query'], 'MarketplaceInventory')) {
            return Http::response(['data' => ['inventoryItem' => ['inventoryLevels' => ['nodes' => [['location' => ['id' => $f['listing']->shipping_location_gid], 'quantities' => [['name' => 'available', 'quantity' => 10]]]], 'pageInfo' => ['hasNextPage' => false]]]]]);
        }

        return Http::response(['data' => ['cartCreate' => ['cart' => ['id' => 'gid://shopify/Cart/fixture?key=private', 'checkoutUrl' => 'https://mm-fixture.myshopify.com/checkouts/fixture', 'cost' => ['subtotalAmount' => ['amount' => '100.00', 'currencyCode' => 'USD']], 'lines' => ['nodes' => [['quantity' => 2, 'merchandise' => ['id' => $f['listing']->shopify_variant_gid]]]]], 'userErrors' => []]]]);
    });
    $this->post('https://marketsandmakers.test/cart/add', ['listing_id' => $f['listing']->id, 'quantity' => 2, 'group' => 'shipping']);
    $uuid = (string) Str::uuid();
    $this->post('https://marketsandmakers.test/checkout', ['group' => 'shipping', 'uuid' => $uuid, 'price_cents' => 1])->assertRedirect('https://mm-fixture.myshopify.com/checkouts/fixture');
    $this->post('https://marketsandmakers.test/checkout', ['group' => 'shipping', 'uuid' => $uuid])->assertRedirect('https://mm-fixture.myshopify.com/checkouts/fixture');
    expect(Checkout::count())->toBe(1)->and(Checkout::first()->snapshot['lines'][0]['maker_cents'])->toBe(4250)->and(Order::count())->toBe(0);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request['query'], 'MarketplaceCart') && $request['variables']['input']['lines'][0]['quantity'] === 2 && ! isset($request['variables']['input']['lines'][0]['price']));
});

test('rate precedence and acceptance preserve immutable old checkout earnings', function (): void {
    $f = marketsFixture();
    $rates = app(MarketplaceRates::class);
    $checkout = marketsCheckout($f);
    $makerRate = $rates->revise('maker:'.$f['maker']->id, 'percentage', 1000, 'Founding maker rate', 1);
    expect($rates->resolve($f['listing'])->id)->toBe($makerRate->id)->and($rates->accepted($f['listing']))->toBeFalse();
    $rates->accept($f['maker'], $f['identity'], $makerRate);
    expect($rates->amounts(5000, $makerRate)['maker_cents'])->toBe(4500);
    $productRate = $rates->revise('listing:'.$f['listing']->id, 'fixed', 300, 'Three dollar marketplace fee per unit', 1);
    $rates->accept($f['maker'], $f['identity'], $productRate);
    expect($rates->resolve($f['listing'])->id)->toBe($productRate->id)->and($rates->amounts(5000, $productRate)['maker_cents'])->toBe(4700)->and($checkout->fresh()->snapshot['lines'][0]['maker_cents'])->toBe(4250);
});

test('fees cannot exceed the item and money calculations avoid floating point rounding', function (): void {
    $f = marketsFixture();
    expect(MarketplaceShopify::cents('10.01'))->toBe(1001)->and(MarketplaceShopify::decimal(1001))->toBe('10.01')->and(app(MarketplaceRates::class)->amounts(101, $f['rate'])['fee_cents'])->toBe(15);
    expect(fn () => MarketplaceShopify::cents('1.001'))->toThrow(\Illuminate\Validation\ValidationException::class);
    $rate = $f['rate']->replicate()->fill(['kind' => 'fixed', 'value' => 5001]);
    expect(fn () => app(MarketplaceRates::class)->amounts(5000, $rate))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('material product changes return to approval and cannot cross maker boundaries', function (): void {
    $f = marketsFixture();
    $other = marketsFixture();
    $this->withSession(['marketplace_identity' => $other['identity']->id])->post('https://marketsandmakers.test/maker/'.$f['maker']->id.'/listings', ['title' => 'Hijacked'])->assertForbidden();
    $f['listing']->update(['shopify_product_gid' => null]);
    app(MarketplaceCatalog::class)->save($f['maker'], ['title' => 'Changed mug', 'category' => 'Ceramics', 'description' => 'Updated story', 'images' => ['https://example.test/mug.jpg'], 'price_cents' => 5100, 'shipping' => true], $f['listing']);
    expect($f['listing']->fresh()->status)->toBe('pending')->and($f['listing']->fresh()->revision)->toBe(2);
});

test('standalone makers can apply and use a portal without Everbranch subscriptions', function (): void {
    $identity = Identity::create(['email' => 'standalone@example.test', 'active' => true]);
    $this->withSession(['marketplace_identity' => $identity->id])->post('https://marketsandmakers.test/sell', ['name' => 'Fictional textile maker', 'city' => 'Easley', 'story' => 'I weave useful goods.'])->assertRedirect();
    $maker = Maker::first();
    expect($maker->tenant_id)->toBeNull()->and($maker->status)->toBe('pending');
    $this->get('https://marketsandmakers.test/maker/'.$maker->id)->assertOk()->assertSee('Your products')->assertSee('Accepted terms');
});

test('email link GETs do not consume sign-ins and verified identities see only their own orders', function (): void {
    $f = marketsFixture();
    $order = app(MarketplaceOrders::class)->sync($f['connection'], marketsProvider(marketsCheckout($f)));
    $token = str_repeat('a', 64);
    $link = LoginLink::create(['email' => 'buyer@example.test', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(15)]);
    $this->get('https://marketsandmakers.test/account/verify/'.$token)->assertOk()->assertSee('One more click.');
    expect($link->fresh()->consumed_at)->toBeNull();
    $this->post('https://marketsandmakers.test/account/verify/'.$token)->assertRedirect('/account');
    $this->get('https://marketsandmakers.test/account/orders/'.$order->id)->assertOk()->assertSee('#MM1001');
    $this->post('https://marketsandmakers.test/account/verify/'.$token)->assertNotFound();
    $this->withSession(['marketplace_identity' => $f['identity']->id])->get('https://marketsandmakers.test/account/orders/'.$order->id)->assertNotFound();
});

test('native stock allocations are scoped atomic idempotent and exclude reserved units', function (): void {
    $f = marketsFixture();
    $tenant = Tenant::create(['name' => 'Native maker', 'slug' => 'native-maker']);
    $actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $actor->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
    $f['maker']->update(['tenant_id' => $tenant->id]);
    $site = TenantSite::create(['tenant_id' => $tenant->id, 'subdomain' => $tenant->slug, 'status' => 'draft', 'settings' => []]);
    $product = WebsiteProduct::create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'handle' => 'mug', 'title' => 'Mug', 'product_type' => 'physical', 'track_inventory' => true]);
    $variant = WebsiteProductVariant::create(['tenant_id' => $tenant->id, 'website_product_id' => $product->id, 'title' => 'Default', 'price_cents' => 5000, 'inventory_quantity' => 10]);
    $f['listing']->update(['source_variant_id' => $variant->id]);
    $catalog = app(MarketplaceCatalog::class);
    $uuid = (string) Str::uuid();
    $catalog->allocate($f['listing'], 3, $f['listing']->shipping_location_gid, $uuid, $actor, null);
    $catalog->allocate($f['listing'], 3, $f['listing']->shipping_location_gid, $uuid, $actor, null);
    expect($variant->fresh()->inventory_quantity)->toBe(7)->and(InventoryAllocation::count())->toBe(1);
    expect(fn () => $catalog->allocate($f['listing'], 8, $f['listing']->shipping_location_gid, (string) Str::uuid(), $actor, null))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    expect(fn () => $catalog->allocate($f['listing'], 1, $f['listing']->shipping_location_gid, (string) Str::uuid(), $other, null))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($variant->fresh()->inventory_quantity)->toBe(7);
});

test('paid order reconciliation is idempotent preserves maker earnings through promotions and never uses legacy orders', function (): void {
    $f = marketsFixture();
    $checkout = marketsCheckout($f);
    $provider = marketsProvider($checkout, 500);
    $before = LegacyOrder::count();
    $orders = app(MarketplaceOrders::class);
    $order = $orders->sync($f['connection'], $provider);
    $orders->sync($f['connection'], $provider);
    expect(Order::count())->toBe(1)->and(OrderLine::count())->toBe(1)->and(LedgerEntry::where('kind', 'merchandise')->sum('amount_cents'))->toBe(4250)->and($order->review_required)->toBeFalse()->and($checkout->fresh()->status)->toBe('paid')->and(LegacyOrder::count())->toBe($before);
    expect(DB::table('marketplace_orders')->first()->customer)->not->toContain('buyer@example.test');
});

test('uncaptured payments mismatched merchant test mode and provider item changes cannot authorize supplier fulfillment', function (): void {
    $f = marketsFixture();
    $checkout = marketsCheckout($f);
    $provider = marketsProvider($checkout);
    $provider['transactions'][0]['status'] = 'PENDING';
    expect(fn () => app(MarketplaceOrders::class)->sync($f['connection'], $provider))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $provider = marketsProvider($checkout);
    $provider['lineItems']['nodes'][0]['variant']['id'] = 'gid://shopify/ProductVariant/999';
    $order = app(MarketplaceOrders::class)->sync($f['connection'], $provider);
    expect($order->review_required)->toBeTrue()->and(OrderLine::count())->toBe(0)->and(LedgerEntry::count())->toBe(0);
});

test('shipping and pickup changes or excessive promotions require review', function (): void {
    $f = marketsFixture();
    $checkout = marketsCheckout($f);
    $provider = marketsProvider($checkout, 1000);
    $provider['fulfillmentOrders']['nodes'][0]['deliveryMethod']['methodType'] = 'PICK_UP';
    $order = app(MarketplaceOrders::class)->sync($f['connection'], $provider);
    expect($order->review_required)->toBeTrue()->and($order->review_reason)->toContain('delivery changed', 'promotion exceeds');
});

test('test orders never become eligible supplier payments even after fulfillment', function (): void {
    $f = marketsFixture(true);
    $order = app(MarketplaceOrders::class)->sync($f['connection'], marketsFulfilled(marketsProvider(marketsCheckout($f))));
    expect($order->test_mode)->toBeTrue()->and(LedgerEntry::whereNotNull('eligible_at')->count())->toBe(0);
    expect(fn () => app(MarketplaceOrders::class)->statement($f['maker']->id, 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('fulfilled supplier earnings can be reviewed and recorded paid only once with the actual bank reference', function (): void {
    $f = marketsFixture();
    $service = app(MarketplaceOrders::class);
    $service->sync($f['connection'], marketsFulfilled(marketsProvider(marketsCheckout($f))));
    $statement = $service->statement($f['maker']->id, 1);
    expect($statement->amount_cents)->toBe(4850)->and($statement->status)->toBe('review');
    $service->approveStatement($statement, 1);
    $service->recordPayment($statement, 1, 'BANK-REFERENCE-1001', 4850);
    $service->recordPayment($statement, 1, 'BANK-REFERENCE-1001', 4850);
    expect($statement->fresh()->status)->toBe('paid')->and(Statement::count())->toBe(1);
    expect(fn () => $service->recordPayment($statement, 1, 'CHANGED', 4850))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('partial refunds reverse the original supplier agreement once and block an approved unpaid batch', function (): void {
    $f = marketsFixture();
    $checkout = marketsCheckout($f, 2);
    $service = app(MarketplaceOrders::class);
    $provider = marketsFulfilled(marketsProvider($checkout));
    $service->sync($f['connection'], $provider);
    $statement = $service->statement($f['maker']->id, 1);
    $service->approveStatement($statement, 1);
    $refund = marketsRefund($provider);
    $service->sync($f['connection'], $refund);
    $service->sync($f['connection'], $refund);
    expect(OrderLine::first()->refunded_quantity)->toBe(1)->and(LedgerEntry::where('kind', 'refund')->sum('amount_cents'))->toBe(-4250)->and($statement->fresh()->status)->toBe('review');
    expect(fn () => $service->recordPayment($statement, 1, 'BANK', $statement->amount_cents))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('signed webhooks bind the merchant deduplicate event IDs and continue reconciliation with checkout disabled', function (): void {
    $f = marketsFixture(true);
    $provider = marketsProvider(marketsCheckout($f));
    Http::fake(['*' => Http::response(['data' => ['order' => $provider]])]);
    config(['marketplace.checkout_enabled' => false]);
    $raw = '{"id":900}';
    $id = (string) Str::uuid();
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_WEBHOOK_ID' => $id, 'HTTP_X_SHOPIFY_TOPIC' => 'orders/paid', 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $f['connection']->shop_domain, 'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $raw, 'fixture-signing-secret', true))];
    $url = 'https://app.theeverbranch.test/webhooks/marketplace/shopify/'.$f['connection']->uuid;
    $this->call('POST', $url, [], [], [], $headers, $raw)->assertOk();
    $this->call('POST', $url, [], [], [], $headers, $raw)->assertOk();
    expect(WebhookEvent::count())->toBe(1)->and(WebhookEvent::first()->status)->toBe('processed')->and(Order::count())->toBe(1);
    Http::assertSentCount(1);
    $headers['HTTP_X_SHOPIFY_SHOP_DOMAIN'] = 'other-store.myshopify.com';
    $this->call('POST', $url, [], [], [], $headers, $raw)->assertUnauthorized();
});

test('uncertain provider mutations are durable and never sent a second time', function (): void {
    $f = marketsFixture();
    $shopify = app(MarketplaceShopify::class);
    $uuid = (string) Str::uuid();
    $called = 0;
    $call = function () use (&$called) {
        $called++;
        throw new RuntimeException('connection lost');
    };
    expect(fn () => $shopify->operation($f['connection'], $uuid, 'refund', 1, ['amount' => 5000], $call))->toThrow(RuntimeException::class);
    expect(fn () => $shopify->operation($f['connection'], $uuid, 'refund', 1, ['amount' => 5000], $call))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($called)->toBe(1)->and(Operation::first()->status)->toBe('unknown');
});

test('only explicitly appointed active operators can access financial and credential settings', function (): void {
    marketsFixture();
    $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($user)->get('https://app.theeverbranch.test/marketplace/operations')->assertForbidden();
    config(['marketplace.operator_user_ids' => [$user->id]]);
    foreach (['overview', 'makers', 'listings', 'orders', 'rates', 'payments', 'statements', 'activity'] as $tab) {
        $this->get('https://app.theeverbranch.test/marketplace/operations?tab='.$tab)->assertOk();
    }
    $this->get('https://app.theeverbranch.test/marketplace/operations?tab=payments')->assertDontSee('fixture-admin')->assertDontSee('fixture-signing-secret');
});

test('partial cancellation before shipping cannot debit earnings that were never eligible', function (): void {
    $f = marketsFixture();
    $checkout = marketsCheckout($f, 2);
    $service = app(MarketplaceOrders::class);
    $provider = marketsProvider($checkout);
    $service->sync($f['connection'], $provider);
    $service->sync($f['connection'], marketsRefund($provider));
    expect(LedgerEntry::whereNotNull('eligible_at')->count())->toBe(0);
    $provider = marketsRefund(marketsFulfilled($provider));
    $service->sync($f['connection'], $provider);
    expect(LedgerEntry::whereNotNull('eligible_at')->sum('amount_cents'))->toBe(4850);
});

test('full cancellation before fulfillment nets out the original supplier obligation', function (): void {
    $f = marketsFixture();
    $service = app(MarketplaceOrders::class);
    $provider = marketsRefund(marketsProvider(marketsCheckout($f)));
    $service->sync($f['connection'], $provider);
    expect(LedgerEntry::where('kind', 'merchandise')->sum('amount_cents'))->toBe(4250)
        ->and(LedgerEntry::where('kind', 'refund')->sum('amount_cents'))->toBe(-4250)
        ->and(LedgerEntry::whereNotNull('eligible_at')->sum('amount_cents'))->toBe(0)
        ->and(LedgerEntry::where('kind', 'shipping')->whereNotNull('eligible_at')->count())->toBe(0);
});

test('multiple makers share a shipping order but shipping credits require an explicit balanced allocation', function (): void {
    $a = marketsFixture();
    $b = marketsFixture();
    $b['listing']->update(['connection_id' => $a['connection']->id, 'shopify_variant_gid' => 'gid://shopify/ProductVariant/401']);
    $checkout = marketsCheckout($a);
    $second = marketsCheckout($b)->snapshot['lines'][0];
    $snapshot = $checkout->snapshot;
    $snapshot['lines'][] = $second;
    $checkout->update(['snapshot' => $snapshot]);
    $service = app(MarketplaceOrders::class);
    $order = $service->sync($a['connection'], marketsFulfilled(marketsProvider($checkout)));
    expect($order->lines()->count())->toBe(2)->and($order->shipping_allocated_at)->toBeNull()->and(LedgerEntry::whereNotNull('eligible_at')->count())->toBe(0);
    $lines = $order->lines()->get();
    expect(fn () => $service->allocateShipping($order, [$lines[0]->id => 600, $lines[1]->id => 600], 0, 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $service->allocateShipping($order, [$lines[0]->id => 200, $lines[1]->id => 400], 0, 1);
    expect(LedgerEntry::where('maker_id', $a['maker']->id)->whereNotNull('eligible_at')->sum('amount_cents'))->toBe(4450)
        ->and(LedgerEntry::where('maker_id', $b['maker']->id)->whereNotNull('eligible_at')->sum('amount_cents'))->toBe(4650);
});

test('shipping refunds reopen bank review and cannot reverse maker credits twice', function (): void {
    $f = marketsFixture();
    $service = app(MarketplaceOrders::class);
    $provider = marketsFulfilled(marketsProvider(marketsCheckout($f)));
    $order = $service->sync($f['connection'], $provider);
    $statement = $service->statement($f['maker']->id, 1);
    $service->approveStatement($statement, 1);
    $provider = marketsRefund($provider);
    $provider['refunds'][0]['refundShippingLines']['nodes'] = [['subtotalAmountSet' => ['shopMoney' => ['amount' => '6.00']]]];
    $provider['refunds'][0]['totalRefundedSet']['shopMoney']['amount'] = '56.00';
    $provider['refunds'][0]['transactions']['nodes'][0]['amountSet']['shopMoney']['amount'] = '56.00';
    $order = $service->sync($f['connection'], $provider);
    expect($statement->fresh()->status)->toBe('review')->and($order->shipping_refunds['gid://shopify/Refund/1100']['status'])->toBe('review');
    expect(fn () => $service->approveStatement($statement, 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $line = $order->lines()->first();
    $service->allocateShippingRefund($order, 'gid://shopify/Refund/1100', [$line->id => 600], 0, 1);
    expect(LedgerEntry::where('kind', 'shipping_refund')->sum('amount_cents'))->toBe(-600);
    expect(fn () => $service->allocateShippingRefund($order, 'gid://shopify/Refund/1100', [$line->id => 600], 0, 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('refunds after bank payment carry forward without rewriting the historical statement', function (): void {
    $f = marketsFixture();
    $service = app(MarketplaceOrders::class);
    $provider = marketsFulfilled(marketsProvider(marketsCheckout($f)));
    $service->sync($f['connection'], $provider);
    $statement = $service->statement($f['maker']->id, 1);
    $service->approveStatement($statement, 1);
    $service->recordPayment($statement, 1, 'HISTORICAL-BANK', 4850);
    $service->sync($f['connection'], marketsRefund($provider));
    expect($statement->fresh()->status)->toBe('paid')->and($statement->fresh()->amount_cents)->toBe(4850)
        ->and(LedgerEntry::whereNull('statement_id')->whereNotNull('eligible_at')->sum('amount_cents'))->toBe(-4250);
    $adjustment = $service->statement($f['maker']->id, 1);
    expect($adjustment->amount_cents)->toBe(-4250)->and($adjustment->status)->toBe('review');
});

test('expired links and inactive maker memberships cannot grant portal access', function (): void {
    $f = marketsFixture();
    LoginLink::create(['email' => $f['identity']->email, 'token_hash' => hash('sha256', 'expired'), 'expires_at' => now()->subMinute()]);
    $this->post('https://marketsandmakers.test/account/verify/expired')->assertNotFound();
    Membership::where('maker_id', $f['maker']->id)->update(['active' => false]);
    $this->withSession(['marketplace_identity' => $f['identity']->id])->get('https://marketsandmakers.test/maker/'.$f['maker']->id)->assertForbidden();
});

test('browser success parameters cannot create or mark an order paid', function (): void {
    $f = marketsFixture();
    $checkout = marketsCheckout($f);
    $this->withSession(['marketplace_cart_owner' => 'guest-fixture'])->get('https://marketsandmakers.test/checkout/'.$checkout->uuid.'?paid=1&status=success')->assertOk()->assertSee('Checking your order.');
    expect($checkout->fresh()->status)->toBe('ready')->and(Order::count())->toBe(0);
});

test('native catalog reads require current active owner or administrator membership', function (): void {
    $f = marketsFixture();
    $tenant = Tenant::create(['name' => 'Fictional connected maker', 'slug' => 'connected-native']);
    $f['maker']->update(['tenant_id' => $tenant->id]);
    $actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $actor->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($actor)->get('https://app.theeverbranch.test/marketplace/workspaces/'.$f['maker']->id)->assertNotFound();
    $actor->tenants()->updateExistingPivot($tenant->id, ['role' => 'admin', 'membership_active' => false]);
    $this->get('https://app.theeverbranch.test/marketplace/workspaces/'.$f['maker']->id)->assertNotFound();
    $actor->tenants()->updateExistingPivot($tenant->id, ['membership_active' => true]);
    $this->get('https://app.theeverbranch.test/marketplace/workspaces/'.$f['maker']->id)->assertOk()->assertSee('Your connected catalog.');
});

test('signed event ID collisions with different contents cannot overwrite stored evidence', function (): void {
    $f = marketsFixture();
    $id = (string) Str::uuid();
    $raw = '{"id":900}';
    WebhookEvent::create(['connection_id' => $f['connection']->id, 'event_id' => $id, 'topic' => 'orders/paid', 'payload_hash' => hash('sha256', 'different'), 'payload' => ['retained' => true], 'status' => 'processed']);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_WEBHOOK_ID' => $id, 'HTTP_X_SHOPIFY_TOPIC' => 'orders/paid', 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $f['connection']->shop_domain, 'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $raw, 'fixture-signing-secret', true))];
    $this->call('POST', 'https://app.theeverbranch.test/webhooks/marketplace/shopify/'.$f['connection']->uuid, [], [], [], $headers, $raw)->assertConflict();
    expect(WebhookEvent::first()->payload)->toBe(['retained' => true]);
    Http::assertNothingSent();
});

test('a refund request uses the original merchant and original provider suggested transactions', function (): void {
    $f = marketsFixture();
    $checkout = marketsCheckout($f);
    $provider = marketsProvider($checkout);
    $order = app(MarketplaceOrders::class)->sync($f['connection'], $provider);
    $other = marketsFixture();
    app(MarketplaceAccess::class)->settings()->update(['connection_id' => $other['connection']->id]);
    Http::fake(function ($request) use ($provider) {
        if (str_contains($request['query'], 'MarketplaceRefundPreview')) {
            return Http::response(['data' => ['order' => ['suggestedRefund' => ['amountSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']], 'suggestedTransactions' => [['gateway' => 'shopify_payments', 'parentTransaction' => ['id' => 'gid://shopify/OrderTransaction/950'], 'amountSet' => ['shopMoney' => ['amount' => '50.00']]]]]]]]);
        }
        if (str_contains($request['query'], 'mutation MarketplaceRefund')) {
            return Http::response(['data' => ['refundCreate' => ['refund' => ['id' => 'gid://shopify/Refund/1100'], 'userErrors' => []]]]);
        }

        return Http::response(['data' => ['order' => marketsRefund($provider)]]);
    });
    app(\App\Services\Marketplace\MarketplaceFulfillment::class)->refund($order->lines()->first(), 1, 0, 5000, (string) Str::uuid(), 1);
    expect(OrderLine::first()->refunded_quantity)->toBe(1)->and(Operation::where('kind', 'refund')->first()->connection_id)->toBe($f['connection']->id);
    Http::assertSent(fn ($request) => str_contains($request['query'], 'mutation MarketplaceRefund') && $request['variables']['input']['transactions'][0]['parentId'] === 'gid://shopify/OrderTransaction/950' && $request['variables']['input']['transactions'][0]['amount'] === '50.00');
});

test('a long lived cart cannot replay an older paid group after its cleanup', function (): void {
    $f = marketsFixture();
    $checkout = marketsCheckout($f);
    $checkout->update(['status' => 'paid']);
    $session = app('session')->driver();
    $session->put('marketplace_cart_owner', 'guest-fixture');
    $session->put('marketplace_cleared_checkouts', range($checkout->id, $checkout->id + 249));
    $key = $checkout->snapshot['lines'][0]['cart_key'];
    $session->put('marketplace_cart', [$key => ['listing_id' => $f['listing']->id, 'group' => 'shipping', 'quantity' => 1]]);
    $request = Illuminate\Http\Request::create('/cart');
    $request->setLaravelSession($session);
    $service = app(MarketplaceCheckout::class);
    $service->cleanPaidGroups($request);
    $service->cleanPaidGroups($request);
    expect($session->get('marketplace_cart')[$key]['quantity'])->toBe(1);
});

test('inventory contention retries only a definitively rejected CAS with a fresh quantity', function (): void {
    $f = marketsFixture();
    $allocation = app(MarketplaceCatalog::class)->allocate($f['listing'], 2, $f['listing']->shipping_location_gid, (string) Str::uuid(), null, $f['identity']);
    $remote = 6;
    $writes = 0;
    Http::fake(function ($request) use (&$remote, &$writes, $f) {
        if (str_contains($request['query'], 'query MarketplaceInventory')) {
            return Http::response(['data' => ['inventoryItem' => ['id' => $f['listing']->shopify_inventory_gid, 'inventoryLevels' => ['nodes' => [['location' => ['id' => $f['listing']->shipping_location_gid], 'quantities' => [['name' => 'available', 'quantity' => $remote]]]], 'pageInfo' => ['hasNextPage' => false]]]]]);
        }
        if (str_contains($request['query'], 'mutation MarketplaceStock')) {
            $writes++;
            if ($writes === 1) {
                $remote = 4; // Two units sold before the first compare-and-swap.

                return Http::response(['data' => ['inventorySetQuantities' => ['userErrors' => [['code' => 'CHANGE_FROM_QUANTITY_STALE', 'message' => 'Quantity changed.']]]]]);
            }
            expect($request['variables']['input']['quantities'][0]['changeFromQuantity'])->toBe(4);
            $remote = $request['variables']['input']['quantities'][0]['quantity'];

            return Http::response(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()], 'userErrors' => []]]]);
        }
        throw new RuntimeException('Unexpected fixture request');
    });
    $service = app(MarketplaceShopify::class);
    expect(fn () => $service->syncInventory($f['listing']))->toThrow(Illuminate\Validation\ValidationException::class);
    expect($allocation->fresh()->applied_at)->toBeNull();
    $service->syncInventory($f['listing']);
    $service->syncInventory($f['listing']);
    expect($writes)->toBe(2)->and($remote)->toBe(6)->and($allocation->fresh()->applied_at)->not->toBeNull()
        ->and(Operation::where('kind', 'stock_allocation')->pluck('status')->all())->toBe(['rejected', 'completed']);
});
