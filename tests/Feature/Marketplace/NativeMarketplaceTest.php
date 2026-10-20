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
