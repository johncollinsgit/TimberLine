<?php

use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\Identity;
use App\Models\Marketplace\InventoryLevel;
use App\Models\Marketplace\LedgerEntry;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Membership;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\RateAcceptance;
use App\Models\Marketplace\StockReservation;
use App\Models\Marketplace\Store;
use App\Models\Tenant;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Marketplace\MarketplaceCatalog;
use App\Services\Marketplace\MarketplaceNativeCheckout;
use App\Services\Marketplace\MarketplaceRates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['marketplace.enabled' => true, 'marketplace.checkout_enabled' => true, 'marketplace.checkout_provider' => 'everbranch', 'marketplace.host' => 'marketsandmakers.test', 'marketplace.stripe_webhook_secret' => 'whsec_native_fixture', 'app.url' => 'https://app.theeverbranch.test', 'services.stripe.secret' => 'sk_test_native_fixture', 'services.stripe.account_id' => 'acct_platformfixture', 'commercial.billing_readiness.tenant_payments' => ['enabled' => true, 'tenant_slugs' => ['markets-and-makers']]]);
    Route::middleware('web')->group(base_path('routes/marketplace.php'));
    $routes = new Illuminate\Routing\RouteCollection;
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

function nativeMarketFixture(): array
{
    $tenant = Tenant::create(['name' => 'Markets and Makers', 'slug' => 'markets-and-makers']);
    $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Markets and Makers', 'host' => 'marketsandmakers.test', 'verified_at' => now()]);
    $account = TenantPaymentAccount::create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_account_id' => 'acct_marketsfixture', 'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true, 'metadata' => ['routing_mode' => 'direct']]);
    $identity = Identity::create(['email' => 'maker@native.example.test', 'active' => true]);
    $maker = Maker::create(['name' => 'Native Maker', 'slug' => 'native-maker', 'city' => 'Easley', 'story' => 'Test goods', 'status' => 'approved']);
    Membership::create(['maker_id' => $maker->id, 'identity_id' => $identity->id, 'role' => 'owner', 'active' => true]);
    $listing = Listing::create(['maker_id' => $maker->id, 'title' => 'Native ceramic mug', 'slug' => 'native-mug', 'category' => 'Ceramics', 'description' => 'Made here', 'price_cents' => 5000, 'shipping' => true, 'images' => ['https://example.test/mug.jpg'], 'status' => 'approved', 'revision' => 1, 'published_revision' => 1]);
    $rate = app(MarketplaceRates::class)->global();
    RateAcceptance::create(['maker_id' => $maker->id, 'identity_id' => $identity->id, 'rate_id' => $rate->id]);
    app(MarketplaceCatalog::class)->allocate($listing, 5, 'eb:shipping:'.$maker->id, (string) Str::uuid(), null, $identity);
    app(MarketplaceAccess::class)->settings()->update(['checkout_enabled' => true, 'support_email' => 'support@native.example.test', 'shipping_policy' => 'US shipping', 'return_policy' => 'Contact us', 'privacy_policy' => 'Private orders', 'readiness' => ['merchant_setup' => true, 'shipping' => true, 'tax' => true, 'guest_checkout' => true, 'policies' => true, 'promotion_bps' => 1000, 'shipping_per_maker_cents' => 600]]);

    return compact('tenant', 'store', 'account', 'identity', 'maker', 'listing', 'rate');
}

function nativeMarketFake(array $f, array &$refunds = [], string &$state = 'complete'): void
{
    Http::fake(function ($request) use ($f, &$refunds, &$state) {
        expect($request->header('Stripe-Account'))->toBe(['acct_marketsfixture']);
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/customers')) {
            return Http::response(['id' => 'cus_pickupfixture']);
        }
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/checkout/sessions')) {
            return Http::response(['id' => 'cs_test_nativefixture', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_nativefixture']);
        }
        $checkout = Checkout::first();
        $shipping = $checkout?->snapshot['shipping_cents'] ?? 600;
        $tax = $shipping === 0 ? 315 : 357;
        $total = 4500 + $shipping + $tax;
        if ($request->method() === 'GET' && str_contains($request->url(), '/checkout/sessions/cs_test_nativefixture/line_items')) {
            return Http::response(['has_more' => false, 'data' => [['id' => 'li_nativefixture', 'quantity' => 1, 'amount_total' => 4815, 'price' => ['unit_amount' => 4500, 'product' => ['metadata' => ['marketplace_listing_id' => (string) $f['listing']->id]]]]]]);
        }
        if ($request->method() === 'GET' && str_contains($request->url(), '/checkout/sessions/cs_test_nativefixture')) {
            return Http::response(['id' => 'cs_test_nativefixture', 'livemode' => false, 'currency' => 'usd', 'status' => $state, 'payment_status' => $state === 'expired' ? 'unpaid' : 'paid', 'automatic_tax' => ['status' => 'complete'], 'amount_subtotal' => 4500, 'amount_total' => $total, 'customer' => ['shipping' => ['address' => $checkout->snapshot['pickup']['tax_address'] ?? []]], 'total_details' => ['amount_tax' => $tax, 'amount_shipping' => $shipping], 'shipping_cost' => ['amount_tax' => 42], 'metadata' => ['marketplace_checkout' => $checkout->uuid, 'marketplace_nonce' => $checkout->snapshot['nonce']], 'customer_details' => ['email' => 'buyer@native.example.test', 'name' => 'Fictional buyer'], 'collected_information' => ['shipping_details' => ['name' => 'Fictional buyer', 'address' => ['line1' => '100 Example Street', 'city' => 'Easley', 'state' => 'SC', 'postal_code' => '29640', 'country' => 'US']]], 'payment_intent' => ['id' => 'pi_nativefixture', 'status' => 'succeeded', 'amount_received' => $total, 'currency' => 'usd', 'latest_charge' => ['balance_transaction' => ['fee' => 188]]]]);
        }
        if ($request->method() === 'GET' && str_contains($request->url(), '/v1/refunds')) {
            return Http::response(['has_more' => false, 'data' => $refunds]);
        }
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/refunds')) {
            $refunds[] = ['id' => 're_nativefixture', 'status' => 'succeeded', 'currency' => 'usd', 'payment_intent' => 'pi_nativefixture', 'amount' => $request['amount'], 'metadata' => $request['metadata']];

            return Http::response(end($refunds));
        }
        throw new RuntimeException('Unexpected native fixture request');
    });
}

function nativeMarketBegin($test, array $f): Checkout
{
    $test->post('https://marketsandmakers.test/cart/add', ['listing_id' => $f['listing']->id, 'quantity' => 1, 'group' => 'shipping'])->assertRedirect('/cart');
    $test->post('https://marketsandmakers.test/checkout', ['group' => 'shipping', 'uuid' => (string) Str::uuid()])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_nativefixture');

    return Checkout::first();
}

test('the dedicated Everbranch merchant workspace owns management without granting maker workspace access', function (): void {
    $f = nativeMarketFixture();
    $owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($owner)->get('https://app.theeverbranch.test/marketplace/operations')->assertForbidden();
    $owner->tenants()->attach($f['tenant']->id, ['role' => 'owner', 'membership_active' => true]);
    config(['tenant_payments.setup_enabled' => true, 'tenant_payments.tenant_slugs' => ['markets-and-makers']]);
    \App\Models\TenantModuleEntitlement::create(['tenant_id' => $f['tenant']->id, 'module_key' => 'markets_and_makers', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included']);
    $this->get('https://app.theeverbranch.test/marketplace/operations?tab=payments')->assertOk()->assertSee('Your Everbranch merchant store.')->assertSee('Set up this store');
    $this->withSession(['marketplace_identity' => $f['identity']->id])->get('https://marketsandmakers.test/maker/'.$f['maker']->id)->assertOk()->assertSee('Your products');
});

test('native checkout reserves only allocated stock and pays the dedicated merchant without Shopify catalog requests', function (): void {
    $f = nativeMarketFixture();
    $refunds = [];
    $state = 'complete';
    nativeMarketFake($f, $refunds, $state);
    $checkout = nativeMarketBegin($this, $f);
    expect($checkout->provider)->toBe('stripe')->and($checkout->connection_id)->toBeNull()->and(StockReservation::count())->toBe(1)->and(InventoryLevel::sum('quantity'))->toBe(4);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['line_items'][0]['price_data']['unit_amount'] === 4500 && ! isset($r['payment_intent_data']['transfer_data']));
    expect(Order::count())->toBe(0);
    $order = app(MarketplaceNativeCheckout::class)->reconcile($checkout);
    app(MarketplaceNativeCheckout::class)->reconcile($checkout->fresh());
    expect(Order::count())->toBe(1)->and($order->merchandise_cents)->toBe(5000)->and($order->discount_cents)->toBe(500)->and($order->lines()->first()->maker_unit_cents)->toBe(4250)->and($order->processing_cost_cents)->toBe(188)->and(StockReservation::first()->committed_at)->not->toBeNull()->and(InventoryLevel::sum('quantity'))->toBe(4)->and(LedgerEntry::whereNotNull('eligible_at')->count())->toBe(0);
});

test('confirmed checkout expiry releases native allocations once without returning them to the source channel', function (): void {
    $f = nativeMarketFixture();
    $refunds = [];
    $state = 'complete';
    nativeMarketFake($f, $refunds, $state);
    $checkout = nativeMarketBegin($this, $f);
    $state = 'expired';
    app(MarketplaceNativeCheckout::class)->reconcile($checkout);
    app(MarketplaceNativeCheckout::class)->reconcile($checkout->fresh());
    expect($checkout->fresh()->status)->toBe('expired')->and(InventoryLevel::sum('quantity'))->toBe(5)->and(Order::count())->toBe(0);
});

test('native refunds include verified tax and reverse original supplier terms without automatic restocking', function (): void {
    $f = nativeMarketFixture();
    $refunds = [];
    $state = 'complete';
    nativeMarketFake($f, $refunds, $state);
    $checkout = nativeMarketBegin($this, $f);
    $service = app(MarketplaceNativeCheckout::class);
    $order = $service->reconcile($checkout);
    $line = $order->lines()->first();
    $preview = $service->refundPreview($line, 1, 600);
    expect($preview['amount_cents'])->toBe(5457);
    $uuid = (string) Str::uuid();
    $service->refund($line, 1, 600, 5457, $uuid, 1);
    $service->refund($line, 1, 600, 5457, $uuid, 1);
    expect(count($refunds))->toBe(1)->and($line->fresh()->refunded_quantity)->toBe(1)->and(LedgerEntry::where('kind', 'refund')->sum('amount_cents'))->toBe(-4250)->and(InventoryLevel::sum('quantity'))->toBe(4)->and($order->fresh()->shipping_refunds['re_nativefixture']['status'])->toBe('review');
});

test('signed native events reject other merchant accounts and browser returns cannot record payment', function (): void {
    $f = nativeMarketFixture();
    $refunds = [];
    $state = 'complete';
    nativeMarketFake($f, $refunds, $state);
    $checkout = nativeMarketBegin($this, $f);
    $this->get('https://marketsandmakers.test/checkout/'.$checkout->uuid.'?success=1')->assertOk();
    expect(Order::count())->toBe(0);
    $payload = ['id' => 'evt_nativefixture', 'type' => 'checkout.session.completed', 'account' => 'acct_wrongmerchant', 'livemode' => false, 'data' => ['object' => ['id' => 'cs_test_nativefixture', 'metadata' => ['marketplace_checkout' => $checkout->uuid, 'marketplace_nonce' => $checkout->snapshot['nonce']]]]];
    $raw = json_encode($payload);
    $time = (string) time();
    $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, 'whsec_native_fixture');
    $this->call('POST', 'https://app.theeverbranch.test/webhooks/marketplace/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $raw)->assertStatus(202);
    expect(Order::count())->toBe(0);
});

function nativeWalletFixture(): array
{
    $f = nativeMarketFixture();
    $connection = \App\Models\Marketplace\Connection::create(['uuid' => (string) Str::uuid(), 'shop_domain' => 'markets-wallet.myshopify.com', 'shop_gid' => 'gid://shopify/Shop/12345', 'publication_gid' => 'wallet', 'created_by' => 1, 'test_mode' => true, 'verified_at' => now(), 'credentials' => ['wallet' => true, 'client_id' => (string) Str::uuid(), 'admin_token' => 'wallet_admin_fixture', 'storefront_token' => 'wallet_storefront_fixture', 'webhook_secret' => 'wallet_signing_fixture']]);
    $f['store']->update(['settings' => ['wallet_connection_id' => $connection->id, 'wallet_origin_verified' => true]]);
    config(['marketplace.shop_pay_enabled' => true]);

    return [...$f, 'connection' => $connection];
}
function nativeWalletBegin($test, array $f): Checkout
{
    $test->post('https://marketsandmakers.test/cart/add', ['listing_id' => $f['listing']->id, 'quantity' => 1, 'group' => 'shipping'])->assertRedirect();
    $test->post('https://marketsandmakers.test/checkout', ['group' => 'shipping', 'uuid' => (string) Str::uuid(), 'payment_method' => 'shop_pay'])->assertRedirect();

    return Checkout::firstOrFail();
}
function nativeWalletContact(): array
{
    return ['address1' => '100 Example Street', 'city' => 'Easley', 'provinceCode' => 'SC', 'postalCode' => '29640', 'countryCode' => 'US', 'firstName' => 'Fictional', 'lastName' => 'Buyer'];
}
function nativeWalletProvider(Checkout $checkout): array
{
    return ['id' => 'gid://shopify/Order/800', 'name' => 'MM-WALLET', 'sourceIdentifier' => $checkout->uuid, 'currencyCode' => 'USD', 'test' => true, 'displayFinancialStatus' => 'PAID', 'taxesIncluded' => false, 'email' => 'buyer@wallet.example.test', 'shippingAddress' => ['name' => 'Fictional Buyer', 'address1' => '100 Example Street', 'city' => 'Easley', 'provinceCode' => 'SC', 'zip' => '29640', 'countryCodeV2' => 'US'], 'totalPriceSet' => ['shopMoney' => ['amount' => '54.57', 'currencyCode' => 'USD']], 'totalTaxSet' => ['shopMoney' => ['amount' => '3.57']], 'shippingLines' => ['nodes' => [['discountedPriceSet' => ['shopMoney' => ['amount' => '6.00']]]], 'pageInfo' => ['hasNextPage' => false]], 'transactions' => [['status' => 'SUCCESS', 'kind' => 'SALE', 'amountSet' => ['shopMoney' => ['amount' => '54.57']]]], 'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/801', 'sku' => 'MM-'.$checkout->snapshot['lines'][0]['listing_id'], 'quantity' => 1, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '50.00']], 'discountedTotalSet' => ['shopMoney' => ['amount' => '45.00']]]], 'pageInfo' => ['hasNextPage' => false]], 'fulfillmentOrders' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]], 'fulfillments' => [], 'refunds' => []];
}
function nativeWalletFake(?array &$provider = null, bool $unknownSubmit = false): void
{
    Http::fake(function ($r) use (&$provider, $unknownSubmit) {
        $checkout = Checkout::firstOrFail();
        if (str_contains($r->url(), 'api.stripe.com')) {
            expect($r->header('Stripe-Account'))->toBe(['acct_marketsfixture']);
            if (str_ends_with($r->url(), '/tax/calculations')) {
                return Http::response(['id' => 'taxcalc_walletfixture', 'amount_total' => 5457, 'tax_amount_inclusive' => 0, 'tax_amount_exclusive' => 357, 'expires_at' => now()->addHour()->timestamp]);
            }
            if (str_contains($r->url(), '/tax/transactions/')) {
                return Http::response(['id' => str_ends_with($r->url(), 'create_reversal') ? 'tax_refund_fixture' : 'tax_sale_fixture']);
            }
        }
        expect(parse_url($r->url(), PHP_URL_HOST))->toBe('markets-wallet.myshopify.com');
        if (str_contains($r['query'], 'MarketplaceWalletSession')) {
            expect($r->header('X-Shopify-Storefront-Access-Token'))->toBe(['wallet_storefront_fixture']);

            return Http::response(['data' => ['shopPayPaymentRequestSessionCreate' => ['shopPayPaymentRequestSession' => ['token' => 'wallet_session_fixture', 'sourceIdentifier' => $checkout->uuid, 'checkoutUrl' => 'https://shop.app/checkout/12345/walletfixture'], 'userErrors' => []]]]);
        }
        if (str_contains($r['query'], 'MarketplaceWalletSubmit')) {
            expect($r['variables']['input']['total']['amount'])->toBe('54.57')->and($r['variables']['input']['lineItems'][0]['finalItemPrice']['amount'])->toBe('45.00');
            if ($unknownSubmit) {
                throw new \Illuminate\Http\Client\ConnectionException('Fictional network uncertainty');
            }

            return Http::response(['data' => ['shopPayPaymentRequestSessionSubmit' => ['paymentRequestReceipt' => ['token' => 'receipt_fixture', 'processingStatusType' => 'PROCESSING'], 'userErrors' => []]]]);
        }
        if (str_contains($r['query'], 'MarketplaceWalletReceipts')) {
            return Http::response(['data' => ['shopPayPaymentRequestReceipts' => ['nodes' => [['token' => 'receipt_fixture', 'sourceIdentifier' => $checkout->uuid, 'processingStatus' => ['state' => 'COMPLETED'], 'order' => ['id' => 'gid://shopify/Order/800']]], 'pageInfo' => ['hasNextPage' => false]]]]);
        }
        if (str_contains($r['query'], 'query MarketplaceOrder')) {
            return Http::response(['data' => ['order' => $provider ?? nativeWalletProvider($checkout)]]);
        }
        throw new RuntimeException('Unexpected Wallet fixture request');
    });
}

test('Shop Pay is merchant gated uses server priced tax quotes and protects the shopper session', function (): void {
    $f = nativeWalletFixture();
    nativeWalletFake();
    $checkout = nativeWalletBegin($this, $f);
    $endpoint = 'https://marketsandmakers.test/checkout/'.$checkout->uuid.'/shop-pay';
    $this->get($endpoint)->assertOk()->assertSee('Shop Pay')->assertDontSee('wallet_admin_fixture')->assertDontSee('wallet_storefront_fixture');
    $this->postJson($endpoint, ['event' => 'session'])->assertOk();
    $this->postJson($endpoint, ['event' => 'quote', 'contact' => nativeWalletContact(), 'total' => '0.01'])->assertOk()->assertJsonPath('payment_request.total.amount', '54.57');
    $hash = $checkout->fresh()->provider_cart['quote']['hash'];
    $this->postJson($endpoint, ['event' => 'submit', 'payment_method_token' => 'one_use_method_fixture', 'quote_hash' => str_repeat('x', 64), 'request_uuid' => (string) Str::uuid()])->assertConflict();
    $this->postJson($endpoint, ['event' => 'submit', 'payment_method_token' => 'one_use_method_fixture', 'quote_hash' => $hash, 'request_uuid' => (string) Str::uuid(), 'total' => '0.01'])->assertOk();
    expect($checkout->fresh()->status)->toBe('wallet_submitted')->and(Order::count())->toBe(0)->and(InventoryLevel::sum('quantity'))->toBe(4);
    $this->post($endpoint.'/cancel')->assertConflict();
    $checkout->update(['session_hash' => hash('sha256', 'another shopper')]);
    $this->get($endpoint)->assertNotFound();
    config(['marketplace.shop_pay_enabled' => false]);
    expect(app(\App\Services\Marketplace\MarketplaceWallet::class)->ready())->toBeFalse();
});

test('ambiguous Wallet submission remains durable and cannot charge or restore stock twice', function (): void {
    $f = nativeWalletFixture();
    nativeWalletFake(unknownSubmit: true);
    $checkout = nativeWalletBegin($this, $f);
    $wallet = app(\App\Services\Marketplace\MarketplaceWallet::class);
    $wallet->session($checkout);
    $checkout->refresh();
    $quote = $wallet->quote($checkout, nativeWalletContact());
    $uuid = (string) Str::uuid();
    expect(fn () => $wallet->submit($checkout->fresh(), 'one_use_method_fixture', $quote['quote_hash'], $uuid))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($checkout->fresh()->status)->toBe('wallet_unknown')->and(\App\Models\Marketplace\Operation::where('uuid', $uuid)->value('status'))->toBe('unknown');
    expect(fn () => $wallet->submit($checkout->fresh(), 'one_use_method_fixture', $quote['quote_hash'], $uuid))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $wallet->cancel($checkout->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertSentCount(2);
    expect(InventoryLevel::sum('quantity'))->toBe(4)->and(Order::count())->toBe(0);
});

test('canonical Wallet receipts settle native stock once and post tax before supplier earnings', function (): void {
    $f = nativeWalletFixture();
    nativeWalletFake();
    $checkout = nativeWalletBegin($this, $f);
    $wallet = app(\App\Services\Marketplace\MarketplaceWallet::class);
    $wallet->session($checkout);
    $checkout->refresh();
    $quote = $wallet->quote($checkout, nativeWalletContact());
    $wallet->submit($checkout->fresh(), 'one_use_method_fixture', $quote['quote_hash'], (string) Str::uuid());
    config(['marketplace.checkout_enabled' => false, 'marketplace.shop_pay_enabled' => false]);
    $order = $wallet->reconcile($checkout->fresh());
    $wallet->reconcile($checkout->fresh());
    expect(Order::count())->toBe(1)->and($order->provider)->toBe('shop_pay')->and($order->review_required)->toBeFalse()->and($order->discount_cents)->toBe(500)->and($order->lines()->first()->maker_unit_cents)->toBe(4250)->and(StockReservation::first()->committed_at)->not->toBeNull()->and(InventoryLevel::sum('quantity'))->toBe(4)->and(\App\Models\Marketplace\Operation::where('kind', 'wallet_tax_sale')->count())->toBe(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'create_from_calculation') && $r['calculation'] === 'taxcalc_walletfixture');
});

test('Wallet merchant order or quote mismatches cannot create paid orders', function (): void {
    $f = nativeWalletFixture();
    $provider = null;
    nativeWalletFake($provider);
    $checkout = nativeWalletBegin($this, $f);
    $wallet = app(\App\Services\Marketplace\MarketplaceWallet::class);
    $wallet->session($checkout);
    $quote = $wallet->quote($checkout->fresh(), nativeWalletContact());
    $wallet->submit($checkout->fresh(), 'one_use_method_fixture', $quote['quote_hash'], (string) Str::uuid());
    $provider = nativeWalletProvider($checkout);
    $provider['sourceIdentifier'] = (string) Str::uuid();
    expect(fn () => $wallet->reconcile($checkout->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $provider = nativeWalletProvider($checkout);
    $provider['totalPriceSet']['shopMoney']['amount'] = '54.58';
    expect(fn () => $wallet->reconcile($checkout->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $provider = nativeWalletProvider($checkout);
    $provider['lineItems']['nodes'][0]['sku'] = 'MM-999';
    expect(fn () => $wallet->reconcile($checkout->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Order::count())->toBe(0)->and(StockReservation::first()->committed_at)->toBeNull();
});

test('unsubmitted Wallet cancellation safely restores allocated availability only once', function (): void {
    $f = nativeWalletFixture();
    $checkout = nativeWalletBegin($this, $f);
    $wallet = app(\App\Services\Marketplace\MarketplaceWallet::class);
    $wallet->cancel($checkout);
    expect(InventoryLevel::sum('quantity'))->toBe(5)->and($checkout->fresh()->status)->toBe('cancelled');
    expect(fn () => $wallet->cancel($checkout))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('native pickup freezes the physical tax location and holds late payment after market changes', function (): void {
    $f = nativeMarketFixture();
    $address = ['line1' => '100 Example Market Street', 'city' => 'Easley', 'state' => 'SC', 'postal_code' => '29640', 'country' => 'US'];
    $pickup = \App\Models\Marketplace\PickupLocation::create(['name' => 'Market pickup', 'address' => '100 Example Market Street, Easley, SC', 'instructions' => 'Saturday 9–1 at the collection tent.', 'tax_address' => $address, 'shopify_location_gid' => 'eb:pickup:fixture', 'active' => true]);
    $f['listing']->update(['shipping' => false, 'pickup_location_ids' => [$pickup->id]]);
    app(MarketplaceCatalog::class)->allocate($f['listing']->fresh(), 2, 'eb:pickup:'.$pickup->id, (string) Str::uuid(), null, $f['identity']);
    nativeMarketFake($f);
    $this->post('https://marketsandmakers.test/cart/add', ['listing_id' => $f['listing']->id, 'quantity' => 1, 'group' => 'pickup:'.$pickup->id])->assertRedirect();
    $this->post('https://marketsandmakers.test/checkout', ['group' => 'pickup:'.$pickup->id, 'uuid' => (string) Str::uuid()])->assertRedirect();
    $checkout = Checkout::firstOrFail();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/customers') && $r['shipping']['address'] === $address);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/checkout/sessions') && $r['customer'] === 'cus_pickupfixture' && ! isset($r['shipping_address_collection']) && ! isset($r['customer_update']));
    $pickup->update(['active' => false]);
    $order = app(MarketplaceNativeCheckout::class)->reconcile($checkout);
    expect($order->review_required)->toBeTrue()->and($order->pickupDetails()['instructions'])->toBe('Saturday 9–1 at the collection tent.')->and($checkout->fresh()->status)->toBe('paid');
});

test('free shipping requires approved marketplace funding and snapshots maker shipping credits', function (): void {
    $f = nativeMarketFixture();
    $settings = app(MarketplaceAccess::class)->settings();
    $settings->update(['readiness' => [...$settings->readiness, 'free_shipping_promotion' => true]]);
    $this->post('https://marketsandmakers.test/cart/add', ['listing_id' => $f['listing']->id, 'quantity' => 1, 'group' => 'shipping']);
    $this->post('https://marketsandmakers.test/checkout', ['group' => 'shipping', 'uuid' => (string) Str::uuid()])->assertStatus(422);
    expect(Checkout::count())->toBe(0)->and(InventoryLevel::sum('quantity'))->toBe(5);
    $settings->update(['readiness' => [...$settings->readiness, 'free_shipping_credit_per_maker_cents' => 600, 'free_shipping_approved_by' => 1, 'promotion_subsidy_limit_cents' => 350]]);
    nativeMarketFake($f);
    $this->post('https://marketsandmakers.test/checkout', ['group' => 'shipping', 'uuid' => (string) Str::uuid()])->assertRedirect();
    $checkout = Checkout::firstOrFail();
    expect($checkout->snapshot['shipping_cents'])->toBe(0)->and($checkout->snapshot['shipping_subsidy_cents'])->toBe(600);
    $order = app(MarketplaceNativeCheckout::class)->reconcile($checkout);
    expect($order->shipping_cents)->toBe(0)->and($order->shipping_subsidy_cents)->toBe(600)->and($order->lines()->first()->shipping_credit_cents)->toBe(600);
});

test('signed Wallet callbacks work with checkout off and refunded supplier and tax entries are idempotent', function (): void {
    $f = nativeWalletFixture();
    $provider = null;
    nativeWalletFake($provider);
    $checkout = nativeWalletBegin($this, $f);
    $wallet = app(\App\Services\Marketplace\MarketplaceWallet::class);
    $wallet->session($checkout);
    $quote = $wallet->quote($checkout->fresh(), nativeWalletContact());
    $wallet->submit($checkout->fresh(), 'one_use_method_fixture', $quote['quote_hash'], (string) Str::uuid());
    config(['marketplace.checkout_enabled' => false, 'marketplace.shop_pay_enabled' => false]);
    $raw = json_encode(['id' => '800']);
    $event = (string) Str::uuid();
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $f['connection']->shop_domain, 'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $raw, 'wallet_signing_fixture', true)), 'HTTP_X_SHOPIFY_WEBHOOK_ID' => $event, 'HTTP_X_SHOPIFY_TOPIC' => 'orders/create'];
    $endpoint = 'https://app.theeverbranch.test/webhooks/marketplace/shopify/'.$f['connection']->uuid;
    $this->call('POST', $endpoint, [], [], [], $headers, $raw)->assertOk();
    $this->call('POST', $endpoint, [], [], [], $headers, $raw)->assertOk();
    expect(Order::count())->toBe(1)->and($f['store']->fresh()->settings['wallet_test_shop_gid'])->toBe($f['connection']->shop_gid);
    $provider = nativeWalletProvider($checkout);
    $provider['displayFinancialStatus'] = 'PARTIALLY_REFUNDED';
    $provider['refunds'] = [['id' => 'gid://shopify/Refund/850', 'totalRefundedSet' => ['shopMoney' => ['amount' => '48.15']], 'refundLineItems' => ['nodes' => [['quantity' => 1, 'lineItem' => ['id' => 'gid://shopify/LineItem/801']]], 'pageInfo' => ['hasNextPage' => false]], 'refundShippingLines' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]], 'transactions' => ['nodes' => [['kind' => 'REFUND', 'status' => 'SUCCESS', 'amountSet' => ['shopMoney' => ['amount' => '48.15']]]], 'pageInfo' => ['hasNextPage' => false]]]];
    $wallet->reconcile($checkout->fresh());
    $wallet->reconcile($checkout->fresh());
    expect(Order::first()->lines()->first()->refunded_quantity)->toBe(1)->and(LedgerEntry::where('kind', 'refund')->sum('amount_cents'))->toBe(-4250)->and(\App\Models\Marketplace\Operation::where('kind', 'wallet_tax_refund')->count())->toBe(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'create_reversal') && $r['flat_amount'] === -4815);
});

test('merchant preparation grants an audited internal module usable in existing workspace modes', function (): void {
    $owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->artisan('marketplace:prepare-store', ['--owner' => $owner->id])->assertSuccessful();
    $this->artisan('marketplace:prepare-store', ['--owner' => $owner->id])->assertSuccessful();
    $store = Store::firstOrFail();
    expect(Store::count())->toBe(1)->and(app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($store->tenant_id, 'markets_and_makers'))->toBeTrue()->and(\App\Models\LandlordOperatorAction::where('action_type', 'marketplace.module_granted')->count())->toBe(1);
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    expect(fn () => $this->artisan('marketplace:prepare-store', ['--owner' => $stranger->id])->run())->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('split refunds return every original tax cent and reject stale shipping allocations', function (): void {
    $f = nativeMarketFixture();
    nativeMarketFake($f);
    $checkout = nativeMarketBegin($this, $f);
    $order = app(MarketplaceNativeCheckout::class)->reconcile($checkout);
    $line = $order->lines()->first();
    $line->update(['quantity' => 3]);
    $checkout->refresh();
    $cart = $checkout->provider_cart;
    $cart['settled_lines'][$line->listing_id]['amount_total'] = 15001;
    $cart['shipping_tax_cents'] = 43;
    $checkout->update(['provider_cart' => $cart]);
    // Canonical reads are covered above. This exercises the original paid snapshot's cumulative allocation.
    $service = Mockery::mock(MarketplaceNativeCheckout::class)->makePartial();
    $service->shouldReceive('reconcile')->andReturn($order);
    $amounts = [];
    for ($i = 0; $i < 3; $i++) {
        $line->update(['refunded_quantity' => $i]);
        $order->update(['shipping_refunds' => $i ? ['previous' => ['amount_cents' => $i * 200]] : []]);
        $amounts[] = $service->refundPreview($line->fresh(), 1, 200)['amount_cents'];
    }
    expect(array_sum($amounts))->toBe(15644)->and($amounts)->toBe([5214, 5214, 5216]);
});

function nativePayPalFixture(): array
{
    $f = nativeMarketFixture();
    config(['marketplace.paypal_enabled' => true, 'marketplace.paypal_webhook_id' => 'WEBHOOKFIXTURE01', 'marketplace.paypal_webhook_verified' => true, 'tenant_payments.setup_enabled' => true, 'tenant_payments.tenant_slugs' => ['markets-and-makers'], 'tenant_payments.paypal.enabled' => true, 'tenant_payments.paypal.environment' => 'sandbox', 'tenant_payments.paypal.client_id' => 'paypal_client_fixture', 'tenant_payments.paypal.client_secret' => 'paypal_secret_fixture', 'tenant_payments.paypal.partner_id' => 'PARTNERFIXTURE01', 'tenant_payments.paypal.bn_code' => 'BN_FIXTURE']);
    \App\Models\TenantModuleEntitlement::create(['tenant_id' => $f['tenant']->id, 'module_key' => 'markets_and_makers', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included']);
    $connection = \App\Models\IntegrationConnection::create(['tenant_id' => $f['tenant']->id, 'provider' => 'tenant_paypal', 'external_account_id' => hash('sha256', 'sandbox|paypal_client_fixture|PARTNERFIXTURE01'), 'status' => 'connected', 'external_account_secret' => json_encode(['merchant_id' => 'MERCHANTFIXTURE01', 'tracking_id' => 'tracking_fixture']), 'last_synced_at' => now(), 'metadata' => ['purpose' => 'payment_processing', 'environment' => 'sandbox', 'permissions_verified' => true, 'payments_receivable' => true, 'email_confirmed' => true]]);

    return [...$f, 'paypal_connection' => $connection];
}
function nativePayPalFake(array &$state): void
{
    $state += ['creates' => 0, 'captures' => 0, 'refund_calls' => 0, 'refunds' => [], 'provider' => null, 'mode' => 'normal', 'verified' => true];
    Http::fake(function ($r) use (&$state) {
        $checkout = Checkout::first();
        if (str_contains($r->url(), 'api.stripe.com')) {
            expect($r->header('Stripe-Account'))->toBe(['acct_marketsfixture']);
            if (str_ends_with($r->url(), '/tax/calculations')) {
                $lines = array_map(fn ($l) => ['reference' => 'MM-'.$l['listing_id'], 'amount' => $l['net_unit_cents'] * $l['quantity'], 'quantity' => $l['quantity'], 'amount_tax' => intdiv($l['net_unit_cents'] * $l['quantity'] * 7 + 50, 100)], $checkout->snapshot['lines']);
                $shippingTax = intdiv($checkout->snapshot['shipping_cents'] * 7 + 50, 100);
                $tax = collect($lines)->sum('amount_tax') + $shippingTax;
                $total = collect($lines)->sum('amount') + $checkout->snapshot['shipping_cents'] + $tax;

                return Http::response(['id' => 'taxcalc_paypalfixture', 'amount_total' => $total, 'tax_amount_exclusive' => $tax, 'tax_amount_inclusive' => 0, 'expires_at' => now()->addHour()->timestamp, 'line_items' => ['has_more' => false, 'data' => $lines], 'shipping_cost' => ['amount_tax' => $shippingTax]]);
            }
            if ($state['mode'] === 'tax_failure' && str_contains($r->url(), '/tax/transactions/')) {
                return Http::response(['error' => ['message' => 'Fictional tax posting failure']], 500);
            }
            if (str_contains($r->url(), '/tax/transactions/')) {
                return Http::response(['id' => str_ends_with($r->url(), 'create_reversal') ? 'tax_paypalreversal' : 'tax_paypalsale']);
            }
        }
        expect(parse_url($r->url(), PHP_URL_HOST))->toBe('api-m.sandbox.paypal.com');
        if (str_ends_with($r->url(), '/v1/oauth2/token')) {
            return Http::response(['access_token' => 'paypal_oauth_fixture']);
        }
        if (str_contains($r->url(), '/merchant-integrations')) {
            return Http::response(['merchant_id' => 'MERCHANTFIXTURE01', 'tracking_id' => 'tracking_fixture', 'payments_receivable' => true, 'primary_email_confirmed' => true, 'oauth_integrations' => [['oauth_third_party' => [['partner_client_id' => 'paypal_client_fixture', 'scopes' => ['https://uri.paypal.com/services/payments/payment/authcapture', 'https://uri.paypal.com/services/payments/refund']]]]]]);
        }
        if (str_contains($r->url(), 'verify-webhook-signature')) {
            return Http::response(['verification_status' => $state['verified'] ? 'SUCCESS' : 'FAILURE']);
        }
        $jwt = $r->header('PayPal-Auth-Assertion')[0] ?? '';
        $parts = explode('.', $jwt);
        expect(json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true)['payer_id'] ?? null)->toBe('MERCHANTFIXTURE01');
        if ($r->method() === 'POST' && str_ends_with($r->url(), '/v2/checkout/orders')) {
            $state['creates']++;
            $state['provider'] = ['id' => 'ORDERFIXTURE01', 'status' => 'CREATED', 'intent' => 'CAPTURE', 'purchase_units' => $r['purchase_units'], 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDERFIXTURE01']]];

            return Http::response($state['provider']);
        }
        if ($r->method() === 'POST' && str_ends_with($r->url(), '/capture')) {
            $state['captures']++;
            $state['provider']['status'] = 'COMPLETED';
            $state['provider']['purchase_units'][0]['payments']['captures'] = [['id' => 'CAPTUREFIXTURE01', 'status' => $state['mode'] === 'pending' ? 'PENDING' : ($state['mode'] === 'declined' ? 'DECLINED' : 'COMPLETED'), 'final_capture' => true, 'amount' => ['currency_code' => 'USD', 'value' => \App\Services\Marketplace\MarketplaceShopify::decimal($checkout->provider_cart['tax_quote']['total_cents'])], 'seller_receivable_breakdown' => ['paypal_fee' => ['currency_code' => 'USD', 'value' => '1.90']]]];
            if ($state['mode'] === 'capture_lost') {
                throw new \Illuminate\Http\Client\ConnectionException('Fictional lost capture response');
            }

            return Http::response($state['provider']);
        }
        if ($r->method() === 'GET' && str_ends_with($r->url(), '/v2/checkout/orders/ORDERFIXTURE01')) {
            return Http::response($state['provider']);
        }
        if ($r->method() === 'POST' && str_ends_with($r->url(), '/refund')) {
            $state['refund_calls']++;
            $id = 'REFUNDFIXTURE'.str_pad((string) $state['refund_calls'], 2, '0', STR_PAD_LEFT);
            $data = ['id' => $id, 'status' => 'COMPLETED', 'amount' => $r['amount'], 'invoice_id' => $r['invoice_id'], 'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTUREFIXTURE01']]];
            $state['refunds'][$id] = $data;
            $state['provider']['purchase_units'][0]['payments']['refunds'][] = ['id' => $id];
            $state['provider']['purchase_units'][0]['payments']['captures'][0]['status'] = 'PARTIALLY_REFUNDED';
            if ($state['mode'] === 'refund_lost') {
                throw new \Illuminate\Http\Client\ConnectionException('Fictional lost refund response');
            }

            return Http::response($data);
        }
        if ($r->method() === 'GET' && str_contains($r->url(), '/v2/payments/refunds/')) {
            return Http::response($state['refunds'][basename(parse_url($r->url(), PHP_URL_PATH))]);
        }
        throw new RuntimeException('Unexpected PayPal fixture request');
    });
}
function nativePayPalBegin($test, array $f): Checkout
{
    $test->post('https://marketsandmakers.test/cart/add', ['listing_id' => $f['listing']->id, 'quantity' => 1, 'group' => 'shipping'])->assertRedirect();
    $test->post('https://marketsandmakers.test/checkout', ['group' => 'shipping', 'uuid' => (string) Str::uuid(), 'payment_method' => 'paypal'])->assertRedirect();

    return Checkout::firstOrFail();
}
function nativePayPalCreate($test, Checkout $checkout): void
{
    $test->post('https://marketsandmakers.test/checkout/'.$checkout->uuid.'/paypal', ['action' => 'create', 'name' => 'Fictional Buyer', 'email' => 'buyer@paypal.example.test', 'address' => ['line1' => '100 Example Street', 'city' => 'Easley', 'state' => 'SC', 'postal_code' => '29640', 'country' => 'US'], 'total' => '0.01'])->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDERFIXTURE01');
}
test('native PayPal is unavailable without its independent merchant gates and buyer returns never mark paid', function (): void {
    $f = nativePayPalFixture();
    config(['marketplace.paypal_enabled' => false]);
    $this->post('https://marketsandmakers.test/cart/add', ['listing_id' => $f['listing']->id, 'quantity' => 1, 'group' => 'shipping']);
    $this->get('https://marketsandmakers.test/cart')->assertOk()->assertDontSee('Continue with PayPal');
    $this->post('https://marketsandmakers.test/checkout', ['group' => 'shipping', 'uuid' => (string) Str::uuid(), 'payment_method' => 'paypal'])->assertStatus(423);
    expect(Checkout::count())->toBe(0);
    Http::assertNothingSent();
    $this->withSession(['marketplace_cart' => []]);
    config(['marketplace.paypal_enabled' => true]);
    $state = [];
    nativePayPalFake($state);
    $checkout = nativePayPalBegin($this, $f);
    nativePayPalCreate($this, $checkout);
    $this->get('https://marketsandmakers.test/checkout/'.$checkout->uuid.'/paypal?token=OTHERORDER01&success=1')->assertOk();
    expect(Order::count())->toBe(0)->and($state['captures'])->toBe(0)->and($state['creates'])->toBe(1);
    $checkout->update(['session_hash' => hash('sha256', 'different browser')]);
    $this->get('https://marketsandmakers.test/checkout/'.$checkout->uuid.'/paypal')->assertNotFound();
});
test('PayPal approval captures only server-priced merchant orders and preserves original supplier earnings', function (): void {
    $f = nativePayPalFixture();
    $state = [];
    nativePayPalFake($state);
    $checkout = nativePayPalBegin($this, $f);
    nativePayPalCreate($this, $checkout);
    $checkout->refresh();
    expect($state['provider']['purchase_units'][0]['amount']['value'])->toBe('54.57')->and($state['provider']['purchase_units'][0]['items'][0]['unit_amount']['value'])->toBe('45.00');
    $state['provider']['status'] = 'APPROVED';
    $service = app(\App\Services\Marketplace\MarketplacePayPal::class);
    $order = $service->capture($checkout);
    $service->capture($checkout->fresh());
    expect($state['captures'])->toBe(1)->and(Order::count())->toBe(1)->and($order->provider)->toBe('paypal')->and($order->review_required)->toBeFalse()->and($order->processing_cost_cents)->toBe(190)->and($order->lines()->first()->maker_unit_cents)->toBe(4250)->and(InventoryLevel::sum('quantity'))->toBe(4)->and(StockReservation::first()->committed_at)->not->toBeNull();
});
test('PayPal merchant delivery or price changes fail before capture', function (): void {
    $f = nativePayPalFixture();
    $state = [];
    nativePayPalFake($state);
    $checkout = nativePayPalBegin($this, $f);
    nativePayPalCreate($this, $checkout);
    $checkout->refresh();
    $state['provider']['status'] = 'APPROVED';
    $original = $state['provider'];
    $service = app(\App\Services\Marketplace\MarketplacePayPal::class);
    $state['provider']['purchase_units'][0]['payee']['merchant_id'] = 'FOREIGNMERCHANT';
    expect(fn () => $service->capture($checkout))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $state['provider'] = $original;
    $state['provider']['purchase_units'][0]['shipping']['address']['postal_code'] = '10001';
    expect(fn () => $service->capture($checkout))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $state['provider'] = $original;
    $state['provider']['purchase_units'][0]['amount']['value'] = '0.01';
    expect(fn () => $service->capture($checkout))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($state['captures'])->toBe(0)->and(Order::count())->toBe(0);
});
test('an uncertain PayPal capture cannot be replaced or cancelled and canonical recovery settles once', function (): void {
    $f = nativePayPalFixture();
    $state = ['mode' => 'capture_lost'];
    nativePayPalFake($state);
    $checkout = nativePayPalBegin($this, $f);
    nativePayPalCreate($this, $checkout);
    $checkout->refresh();
    $state['provider']['status'] = 'APPROVED';
    $service = app(\App\Services\Marketplace\MarketplacePayPal::class);
    expect(fn () => $service->capture($checkout))->toThrow(\Illuminate\Http\Client\ConnectionException::class);
    expect($checkout->fresh()->status)->toBe('paypal_capture_unknown')->and(Order::count())->toBe(0);
    expect(fn () => $service->cancel($checkout->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config(['marketplace.paypal_enabled' => false, 'marketplace.checkout_enabled' => false]);
    $service->reconcile($checkout->fresh());
    $service->reconcile($checkout->fresh());
    expect($state['captures'])->toBe(1)->and(Order::count())->toBe(1)->and(StockReservation::first()->committed_at)->not->toBeNull();
});
test('original PayPal refunds reconcile lost responses with tax and supplier reversals without replay', function (): void {
    $f = nativePayPalFixture();
    $state = [];
    nativePayPalFake($state);
    $checkout = nativePayPalBegin($this, $f);
    nativePayPalCreate($this, $checkout);
    $checkout->refresh();
    $state['provider']['status'] = 'APPROVED';
    $service = app(\App\Services\Marketplace\MarketplacePayPal::class);
    $order = $service->capture($checkout);
    $line = $order->lines()->first();
    $uuid = (string) Str::uuid();
    $state['mode'] = 'refund_lost';
    expect(app(MarketplaceNativeCheckout::class)->refundPreview($line, 1, 600)['amount_cents'])->toBe(5457);
    expect(fn () => $service->refund($line, 1, 600, 5457, $uuid, 1))->toThrow(\Illuminate\Http\Client\ConnectionException::class);
    expect($order->fresh()->review_required)->toBeTrue()->and(\App\Models\Marketplace\Operation::where('uuid', $uuid)->value('status'))->toBe('unknown');
    $service->reconcile($checkout->fresh());
    $service->refund($line->fresh(), 1, 600, 5457, $uuid, 1);
    expect($state['refund_calls'])->toBe(1)->and($line->fresh()->refunded_quantity)->toBe(1)->and(LedgerEntry::where('kind', 'refund')->sum('amount_cents'))->toBe(-4250)->and($order->fresh()->shipping_refunds['REFUNDFIXTURE01']['amount_cents'])->toBe(600)->and(InventoryLevel::sum('quantity'))->toBe(4);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'create_reversal') && $r['flat_amount'] === -5457);
});
test('PayPal verified callbacks deduplicate while collection is off and reject invalid verification', function (): void {
    $f = nativePayPalFixture();
    $state = [];
    nativePayPalFake($state);
    $checkout = nativePayPalBegin($this, $f);
    nativePayPalCreate($this, $checkout);
    $checkout->refresh();
    $state['provider']['status'] = 'APPROVED';
    app(\App\Services\Marketplace\MarketplacePayPal::class)->capture($checkout);
    config(['marketplace.paypal_enabled' => false, 'marketplace.checkout_enabled' => false]);
    $data = ['id' => 'WH-PAYPALFIXTURE01', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAPTUREFIXTURE01', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDERFIXTURE01']]]];
    $headers = [];
    foreach (['AUTH-ALGO', 'CERT-URL', 'TRANSMISSION-ID', 'TRANSMISSION-SIG', 'TRANSMISSION-TIME'] as $key) {
        $headers['PAYPAL-'.$key] = 'fixture';
    }
    $state['verified'] = false;
    $this->withHeaders($headers)->postJson('https://app.theeverbranch.test/webhooks/marketplace/paypal', $data)->assertUnauthorized();
    $state['verified'] = true;
    $this->withHeaders($headers)->postJson('https://app.theeverbranch.test/webhooks/marketplace/paypal', $data)->assertOk();
    $this->withHeaders($headers)->postJson('https://app.theeverbranch.test/webhooks/marketplace/paypal', $data)->assertOk();
    expect(\App\Models\Marketplace\PaymentEvent::where('provider', 'paypal')->count())->toBe(1)->and(Order::count())->toBe(1)->and($f['store']->fresh()->settings['paypal_test_store_id'])->toBe($f['store']->id);
});
test('unsubmitted PayPal cancellation restores only marketplace allocations once', function (): void {
    $f = nativePayPalFixture();
    $checkout = nativePayPalBegin($this, $f);
    $service = app(\App\Services\Marketplace\MarketplacePayPal::class);
    $service->cancel($checkout);
    expect(InventoryLevel::sum('quantity'))->toBe(5);
    expect(fn () => $service->cancel($checkout->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('pending PayPal captures keep stock held and definitive declines release it once', function (): void {
    $f = nativePayPalFixture();
    $state = ['mode' => 'pending'];
    nativePayPalFake($state);
    $checkout = nativePayPalBegin($this, $f);
    nativePayPalCreate($this, $checkout);
    $checkout->refresh();
    $state['provider']['status'] = 'APPROVED';
    $service = app(\App\Services\Marketplace\MarketplacePayPal::class);
    expect($service->capture($checkout))->toBeNull()->and(Order::count())->toBe(0)->and(InventoryLevel::sum('quantity'))->toBe(4);
    expect(fn () => $service->cancel($checkout->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $state['provider']['purchase_units'][0]['payments']['captures'][0]['status'] = 'DENIED';
    $service->reconcile($checkout->fresh());
    $service->reconcile($checkout->fresh());
    expect($checkout->fresh()->status)->toBe('paypal_declined')->and(InventoryLevel::sum('quantity'))->toBe(5)->and(Order::count())->toBe(0);
});
test('captured PayPal funds survive a tax posting failure with supplier earnings held', function (): void {
    $f = nativePayPalFixture();
    $state = ['mode' => 'tax_failure'];
    nativePayPalFake($state);
    $checkout = nativePayPalBegin($this, $f);
    nativePayPalCreate($this, $checkout);
    $checkout->refresh();
    $state['provider']['status'] = 'APPROVED';
    $service = app(\App\Services\Marketplace\MarketplacePayPal::class);
    expect(fn () => $service->capture($checkout))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($checkout->fresh()->status)->toBe('paid')->and(Order::first()->review_required)->toBeTrue()->and(LedgerEntry::whereNotNull('eligible_at')->count())->toBe(0)->and(InventoryLevel::sum('quantity'))->toBe(4);
    expect(fn () => $service->reconcile($checkout->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($state['captures'])->toBe(1)->and(\App\Models\Marketplace\Operation::where('kind', 'paypal_tax_sale')->count())->toBe(1);
});

test('multiple maker PayPal shipping has one charge and balanced maker obligations', function (): void {
    $f = nativePayPalFixture();
    $maker = Maker::create(['name' => 'Second PayPal maker', 'slug' => 'second-paypal-maker', 'city' => 'Greer', 'story' => 'Fictional second maker', 'status' => 'approved']);
    Membership::create(['maker_id' => $maker->id, 'identity_id' => $f['identity']->id, 'role' => 'owner', 'active' => true]);
    RateAcceptance::create(['maker_id' => $maker->id, 'identity_id' => $f['identity']->id, 'rate_id' => $f['rate']->id]);
    $listing = $f['listing']->replicate();
    $listing->fill(['maker_id' => $maker->id, 'slug' => 'second-paypal-mug', 'title' => 'Second maker mug', 'stock_quantity' => 0])->save();
    app(MarketplaceCatalog::class)->allocate($listing, 3, 'eb:shipping:'.$maker->id, (string) Str::uuid(), null, $f['identity']);
    $this->post('https://marketsandmakers.test/cart/add', ['listing_id' => $listing->id, 'quantity' => 1, 'group' => 'shipping']);
    $state = [];
    nativePayPalFake($state);
    $checkout = nativePayPalBegin($this, $f);
    nativePayPalCreate($this, $checkout);
    $checkout->refresh();
    $state['provider']['status'] = 'APPROVED';
    $order = app(\App\Services\Marketplace\MarketplacePayPal::class)->capture($checkout);
    expect($order->lines()->count())->toBe(2)->and($state['captures'])->toBe(1)->and($order->shipping_cents)->toBe(1200)->and($order->lines()->sum('shipping_credit_cents'))->toBe(1200)->and(LedgerEntry::where('kind', 'merchandise')->sum('amount_cents'))->toBe(8500)->and($order->total_cents)->toBe(10914);
});
test('PayPal pickup uses the frozen market address for tax instead of the buyers billing location', function (): void {
    $f = nativePayPalFixture();
    $address = ['line1' => '200 Example Market Street', 'city' => 'Easley', 'state' => 'SC', 'postal_code' => '29640', 'country' => 'US'];
    $pickup = \App\Models\Marketplace\PickupLocation::create(['name' => 'PayPal pickup market', 'address' => '200 Example Market Street, Easley', 'instructions' => 'Saturday collection tent', 'tax_address' => $address, 'shopify_location_gid' => 'eb:fixture', 'active' => true]);
    $f['listing']->update(['shipping' => false, 'pickup_location_ids' => [$pickup->id]]);
    app(MarketplaceCatalog::class)->allocate($f['listing']->fresh(), 2, 'eb:pickup:'.$pickup->id, (string) Str::uuid(), null, $f['identity']);
    $state = [];
    nativePayPalFake($state);
    $this->post('https://marketsandmakers.test/cart/add', ['listing_id' => $f['listing']->id, 'quantity' => 1, 'group' => 'pickup:'.$pickup->id]);
    $this->post('https://marketsandmakers.test/checkout', ['group' => 'pickup:'.$pickup->id, 'uuid' => (string) Str::uuid(), 'payment_method' => 'paypal'])->assertRedirect();
    $checkout = Checkout::firstOrFail();
    nativePayPalCreate($this, $checkout);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tax/calculations') && $r['customer_details']['address'] === $address);
    $checkout->refresh();
    $state['provider']['status'] = 'APPROVED';
    $order = app(\App\Services\Marketplace\MarketplacePayPal::class)->capture($checkout);
    expect($order->total_cents)->toBe(4815)->and($order->shipping_cents)->toBe(0)->and($order->pickupDetails()['name'])->toBe($pickup->name);
});
test('unknown original-provider refunds block bank payment even if a review flag was cleared', function (): void {
    $f = nativePayPalFixture();
    $state = [];
    nativePayPalFake($state);
    $checkout = nativePayPalBegin($this, $f);
    nativePayPalCreate($this, $checkout);
    $checkout->refresh();
    $state['provider']['status'] = 'APPROVED';
    $order = app(\App\Services\Marketplace\MarketplacePayPal::class)->capture($checkout);
    $order->update(['test_mode' => false]);
    $line = $order->lines()->first();
    $line->update(['fulfilled_at' => now(), 'fulfillment_status' => 'fulfilled']);
    $orders = app(\App\Services\Marketplace\MarketplaceOrders::class);
    $orders->releaseEarnings($order->fresh());
    $statement = $orders->statement($f['maker']->id, 1);
    $orders->approveStatement($statement, 1);
    \App\Models\Marketplace\Operation::create(['uuid' => (string) Str::uuid(), 'connection_id' => null, 'kind' => 'paypal_refund', 'subject_id' => $line->id, 'status' => 'unknown', 'payload' => ['merchant' => $checkout->snapshot['paypal']]]);
    expect(fn () => $orders->recordPayment($statement->fresh(), 1, 'FICTIONAL-BANK-REFERENCE', $statement->amount_cents))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($statement->fresh()->status)->toBe('approved');
});

test('native maker connections recognize active tenant owners and reject ordinary members', function (): void {
    $f = nativeMarketFixture();
    $owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $owner->tenants()->attach($f['tenant']->id, ['role' => 'tenant_owner', 'membership_active' => true]);
    expect(app(MarketplaceAccess::class)->assertWorkspaceManager($owner, $f['tenant']->id)->id)->toBe($f['tenant']->id);
    $owner->tenants()->updateExistingPivot($f['tenant']->id, ['role' => 'member']);
    expect(fn () => app(MarketplaceAccess::class)->assertWorkspaceManager($owner, $f['tenant']->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
