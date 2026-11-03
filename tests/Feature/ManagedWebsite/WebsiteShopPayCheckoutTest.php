<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteShopPayConnection;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\ShopPayWalletConnectionService;
use App\Services\ManagedWebsite\WebsiteCheckoutService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsitePaymentService;
use App\Services\ManagedWebsite\WebsiteShopPayCheckoutService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WebsiteStripeFixture;

beforeEach(function () {
    $this->withoutVite();
    config()->set('logging.default', 'null');
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional Wallet Retailer', 'slug' => 'wallet-test-retailer']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    TenantPaymentAccount::create(['tenant_id' => $this->tenant->id, 'provider_account_id' => 'acct_walletfictional', 'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'platform_fee_bps' => 0]);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id],
        'managed_website.commerce_enabled' => true, 'managed_website.public_render_enabled' => true, 'services.stripe.secret' => 'sk_test_fictional',
        'managed_website.stripe_webhook_secret' => 'whsec_fictional', 'managed_website.shop_pay_wallet_enabled' => true,
        'managed_website.shop_pay_wallet_tenant_ids' => [$this->tenant->id],
        'commercial.billing_readiness.tenant_payments' => ['enabled' => true, 'tenant_slugs' => [$this->tenant->slug],
            'connect_webhooks_verified' => true, 'automatic_tax_enabled' => true, 'tax_decision_confirmed' => true]]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    WebsiteStripeFixture::origin($this->site);
    $this->connection = WebsiteShopPayConnection::create(['tenant_id' => $this->tenant->id, 'shop_id' => 12345,
        'shop_domain' => 'fictional-wallet.myshopify.com', 'client_id' => 'fictional-client', 'environment' => 'test', 'api_version' => '2026-10',
        'admin_access_token' => 'fictional-admin-secret', 'storefront_access_token' => 'fictional-storefront-secret', 'webhook_secret' => 'fictional-webhook-secret',
        'enabled' => true, 'connection_status' => 'connected', 'last_verified_at' => now(),
        'verified_origin' => app(ShopPayWalletConnectionService::class)->origin($this->site), 'setup' => ['dedicated_store_confirmed' => true]]);
    $commerce = app(WebsiteCommerceService::class);
    $product = $commerce->saveProduct($this->site, ['title' => 'Native retail kit', 'product_type' => 'physical', 'status' => 'active', 'price' => '20.00', 'track_inventory' => true, 'inventory_quantity' => 2, 'is_available' => true]);
    $this->variant = $product->variants->sole();
    $this->cart = $commerce->addToCart($commerce->cartFor($this->site, null), $this->variant, 1);
    $this->buyer = ['name' => 'Fictional Buyer', 'email' => 'fictional@example.test', 'fulfillment_method' => 'pickup'];
    $this->wallet = app(WebsiteShopPayCheckoutService::class);
    $this->checkout = app(WebsiteCheckoutService::class);
    $this->begin = function () {
        $result = $this->checkout->begin($this->cart, $this->buyer, 'https://fictional.test/success', 'https://fictional.test/cart', 'shop_pay');
        $this->payment = $result['order']->payments()->sole();

        return $result;
    };
    $this->providerState = 'pending';
    $this->loseSubmission = false;
    $this->submitCalls = 0;
    $this->remoteChanges = [];
    $this->refundCents = 0;
    $this->taxReversalDown = false;
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/tax/calculations')) {
            expect($request->header('Stripe-Account'))->toBe(['acct_walletfictional']);

            return Http::response(['id' => 'taxcalc_fictional', 'currency' => 'usd', 'amount_total' => 2175,
                'tax_amount_exclusive' => 175, 'tax_amount_inclusive' => 0, 'expires_at' => now()->addHour()->timestamp]);
        }
        if (str_contains($request->url(), '/tax/transactions/')) {
            if ($this->taxReversalDown && str_contains($request->url(), 'create_reversal')) {
                throw new ConnectionException('Fictional tax reversal response unavailable');
            }

            return Http::response(['id' => 'tax_fictional'.($request['flat_amount'] ?? ''), 'reference' => $request['reference']]);
        }
        $query = $request['query'] ?? '';
        $attempt = isset($this->payment) ? $this->wallet->attempt($this->payment) : null;
        if (str_contains($query, 'WebsiteWalletSession')) {
            return Http::response(['data' => ['shopPayPaymentRequestSessionCreate' => ['shopPayPaymentRequestSession' => [
                'token' => 'fictional-session', 'sourceIdentifier' => $request['variables']['source'],
                'checkoutUrl' => 'https://shop.app/checkout/12345/spe/fictional/shoppay'], 'userErrors' => []]]]);
        }
        if (str_contains($query, 'WebsiteWalletSubmit')) {
            $this->submitCalls++;
            expect($attempt->status)->toBe('submitting')->and($attempt->submitted_at)->not->toBeNull()
                ->and($attempt->checkout_state['submitted_input'])->toBe($request['variables']['input']);
            if ($this->loseSubmission) {
                throw new ConnectionException('Fictional response lost after receipt creation');
            }

            return Http::response(['data' => ['shopPayPaymentRequestSessionSubmit' => ['paymentRequestReceipt' => ['token' => 'fictional-receipt', 'processingStatusType' => 'ready'], 'userErrors' => []]]]);
        }
        if (str_contains($query, 'WebsiteWalletReceipts')) {
            return Http::response(['data' => ['shopPayPaymentRequestReceipts' => ['nodes' => $this->providerState === 'empty' ? [] : [[
                'token' => 'fictional-receipt', 'sourceIdentifier' => $attempt->source_identifier,
                'processingStatus' => ['state' => $this->providerState], 'order' => $this->providerState === 'completed' ? ['id' => 'gid://shopify/Order/123'] : null]], 'pageInfo' => ['hasNextPage' => false]]]]);
        }
        if (str_contains($query, 'WebsiteWalletOrder')) {
            $money = fn ($cents) => ['amount' => number_format($cents / 100, 2, '.', ''), 'currencyCode' => 'USD'];
            $order = WebsiteOrder::findOrFail($this->payment->website_order_id);
            $tx = ['status' => 'SUCCESS', 'kind' => 'REFUND', 'amountSet' => ['shopMoney' => $money($this->refundCents)]];
            $remote = ['id' => 'gid://shopify/Order/123', 'name' => '#W123', 'test' => true, 'email' => 'payer@example.test', 'currencyCode' => 'USD',
                'displayFinancialStatus' => $this->refundCents ? 'PARTIALLY_REFUNDED' : 'PAID', 'taxesIncluded' => false, 'sourceIdentifier' => $attempt->source_identifier,
                'totalPriceSet' => ['shopMoney' => $money(2175)], 'totalTaxSet' => ['shopMoney' => $money(175)], 'totalRefundedSet' => ['shopMoney' => $money($this->refundCents)],
                'shippingLines' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
                'transactions' => [['id' => 'gid://shopify/OrderTransaction/1', 'status' => 'SUCCESS', 'kind' => 'SALE', 'amountSet' => ['shopMoney' => $money(2175)]]],
                'lineItems' => ['nodes' => [['quantity' => 1, 'sku' => 'WEB-'.$order->lines()->sole()->id, 'originalUnitPriceSet' => ['shopMoney' => $money(2000)], 'discountedTotalSet' => ['shopMoney' => $money(2000)]]], 'pageInfo' => ['hasNextPage' => false]],
                'refunds' => $this->refundCents ? [['id' => 'gid://shopify/Refund/9', 'totalRefundedSet' => ['shopMoney' => $money($this->refundCents)], 'transactions' => ['nodes' => [$tx], 'pageInfo' => ['hasNextPage' => false]]]] : []];
            if ($this->refundCents) {
                $remote['transactions'][] = $tx;
            }

            return Http::response(['data' => ['order' => array_replace_recursive($remote, $this->remoteChanges)]]);
        }
        throw new RuntimeException('Unexpected fictional transport: '.$request->url());
    });
    $this->submitWallet = function () {
        ($this->begin)();
        $this->wallet->session($this->payment);
        $quote = $this->wallet->quote($this->payment, null);
        $this->wallet->submit($this->payment, 'fictional-payment-method', $quote['quote_hash']);
    };
});

it('journals an encrypted original merchant and reserves native stock without a provider call', function () {
    $result = ($this->begin)();
    $attempt = $this->wallet->attempt($this->payment);
    expect($result['url'])->toContain($attempt->uuid)->and($this->payment->provider)->toBe('shop_pay')
        ->and((int) WebsiteInventoryReservation::where('status', 'active')->sum('quantity'))->toBe(1)
        ->and($attempt->toArray())->not->toHaveKey('checkout_state')
        ->and(DB::table('website_shop_pay_attempts')->value('checkout_state'))->not->toContain('fictional-admin-secret');
    Http::assertNothingSent();
    expect(fn () => $this->checkout->begin($this->cart, $this->buyer, 'https://fictional.test/success', 'https://fictional.test/cart'))->toThrow(HttpException::class);
    expect(fn () => app(WebsiteCommerceService::class)->setCartQuantity($this->cart, $this->cart->items()->sole()->id, 2))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('keeps a lost submission reserved and never sends another charge on retry or an empty status', function () {
    $this->loseSubmission = true;
    expect($this->submitWallet)->toThrow(RuntimeException::class);
    expect($this->wallet->attempt($this->payment)->status)->toBe('unknown');
    expect(fn () => $this->wallet->submit($this->payment, 'fictional-payment-method', str_repeat('a', 64)))->toThrow(HttpException::class);
    $this->providerState = 'empty';
    $this->wallet->reconcile($this->payment);
    expect(fn () => $this->wallet->cancel($this->payment))->toThrow(HttpException::class);
    expect((int) WebsiteInventoryReservation::where('status', 'active')->sum('quantity'))->toBe(1)->and($this->payment->fresh()->status)->toBe('processing');
    expect($this->submitCalls)->toBe(1);
});

it('verifies capture, commits stock once and recovers with the original account after settings are disabled', function () {
    ($this->submitWallet)();
    $this->connection->update(['enabled' => false, 'admin_access_token' => 'rotated-fictional-admin']);
    config()->set('managed_website.shop_pay_wallet_enabled', false);
    $this->providerState = 'completed';
    $this->wallet->reconcile($this->payment);
    $this->wallet->reconcile($this->payment);
    expect($this->payment->fresh()->status)->toBe('paid')->and($this->variant->fresh()->inventory_quantity)->toBe(1)
        ->and(WebsiteInventoryMovement::where('reason', 'website_sale')->count())->toBe(1)
        ->and($this->cart->fresh()->status)->toBe('converted');
    Http::assertSent(fn ($request) => str_contains($request['query'] ?? '', 'WebsiteWalletReceipts') && $request->header('X-Shopify-Access-Token') === ['fictional-admin-secret']);
    app(WebsitePaymentService::class)->assertFulfillable(WebsiteOrder::findOrFail($this->payment->website_order_id));
});

it('refuses mismatched paid evidence without committing inventory', function ($change) {
    ($this->submitWallet)();
    $this->providerState = 'completed';
    $this->remoteChanges = $change;
    expect(fn () => $this->wallet->reconcile($this->payment))->toThrow(HttpException::class);
    expect($this->payment->fresh()->status)->toBe('processing')->and($this->variant->fresh()->inventory_quantity)->toBe(2)
        ->and(WebsiteInventoryMovement::where('reason', 'website_sale')->count())->toBe(0);
})->with([
    'wrong mode' => [['test' => false]], 'wrong source' => [['sourceIdentifier' => 'another-order']],
    'wrong amount' => [['totalPriceSet' => ['shopMoney' => ['amount' => '1.00']]]],
    'uncaptured' => [['transactions' => [['status' => 'PENDING']]]],
    'incomplete items' => [['lineItems' => ['pageInfo' => ['hasNextPage' => true]]]],
    'different items' => [['lineItems' => ['nodes' => [['sku' => 'OTHER']]]]],
]);

it('only releases an unsubmitted checkout or an explicitly failed original receipt', function () {
    ($this->begin)();
    $this->wallet->cancel($this->payment);
    expect(WebsiteInventoryReservation::where('status', 'active')->count())->toBe(0);
    ($this->submitWallet)();
    $this->providerState = 'failed';
    $this->wallet->reconcile($this->payment);
    expect($this->payment->fresh()->status)->toBe('failed')->and(WebsiteInventoryReservation::where('status', 'active')->count())->toBe(0);
});

it('records original-store refunds and tax reversals once without restocking', function () {
    ($this->submitWallet)();
    $this->providerState = 'completed';
    $this->wallet->reconcile($this->payment);
    $this->refundCents = 1000;
    $this->wallet->reconcile($this->payment);
    $this->wallet->reconcile($this->payment);
    expect(WebsiteOrder::findOrFail($this->payment->website_order_id)->refunded_cents)->toBe(1000)
        ->and($this->payment->fresh()->status)->toBe('partially_refunded')->and($this->variant->fresh()->inventory_quantity)->toBe(1);
    expect(collect(Http::recorded())->filter(fn ($entry) => str_contains($entry[0]->url(), 'create_reversal'))->count())->toBe(1);
});

it('releases expired unsubmitted holds without contacting a payment provider', function () {
    ($this->begin)();
    $metadata = $this->payment->metadata;
    $metadata['expires_at'] = time() - 1;
    $this->payment->update(['metadata' => $metadata]);
    $this->wallet->reconcile($this->payment);
    expect($this->payment->fresh()->status)->toBe('cancelled')->and(WebsiteInventoryReservation::where('status', 'active')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps fully refunded Wallet payments in scheduled recovery until their tax reversal is confirmed', function () {
    ($this->submitWallet)();
    $this->providerState = 'completed';
    $this->wallet->reconcile($this->payment);
    $this->refundCents = 2175;
    $this->taxReversalDown = true;
    expect(fn () => $this->wallet->reconcile($this->payment))->toThrow(ConnectionException::class);
    expect($this->payment->fresh()->status)->toBe('refunded')->and(data_get($this->payment->fresh()->metadata, 'wallet_recovery_complete'))->not->toBeTrue();
    $this->taxReversalDown = false;
    $this->artisan('website:reconcile-payments')->assertSuccessful();
    expect(data_get($this->payment->fresh()->metadata, 'wallet_recovery_complete'))->toBeTrue();
});

it('offers the setup wizard outside payment rollout while rejecting an unrelated tenant member', function () {
    config()->set('tenant_payments.setup_enabled', false);
    $url = '/payments/shop-pay?tenant='.$this->tenant->slug;
    $this->actingAs($this->actor)->get($url)->assertOk()->assertSeeText('Choose how your store checks out')
        ->assertSeeText('dedicated Shop Pay Wallet store')->assertDontSee('fictional-admin-secret')->assertHeader('Cache-Control');
    $other = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $otherTenant = Tenant::create(['name' => 'Other business', 'slug' => 'unrelated-business']);
    $other->tenants()->attach($otherTenant, ['role' => 'owner', 'membership_active' => true]);
    $this->actingAs($other)->get($url)->assertForbidden();
    Http::assertNothingSent();
});

it('does not flash submitted API secrets when settings validation fails', function () {
    $this->actingAs($this->actor)->from('/payments/shop-pay?tenant='.$this->tenant->slug)
        ->post('/payments/shop-pay?tenant='.$this->tenant->slug, ['shop_domain' => 'fictional-wallet.myshopify.com', 'shop_id' => 12345,
            'environment' => 'test', 'dedicated_store_confirmed' => 1, 'admin_access_token' => 'new-fictional-admin',
            'storefront_access_token' => 'new-fictional-storefront', 'webhook_secret' => 'new-fictional-signing'])
        ->assertSessionHasErrors('client_id');
    expect(session('_old_input.admin_access_token'))->toBeNull()->and(session('_old_input.storefront_access_token'))->toBeNull()
        ->and(session('_old_input.webhook_secret'))->toBeNull()->and($this->connection->fresh()->admin_access_token)->toBe('fictional-admin-secret');
    Http::assertNothingSent();
});

it('requires the original merchant signature before using a webhook as a reconciliation hint', function () {
    ($this->submitWallet)();
    $payload = json_encode(['source_identifier' => $this->wallet->attempt($this->payment)->source_identifier]);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'fictional-wallet.myshopify.com', 'HTTP_X_SHOPIFY_TOPIC' => 'orders/paid'];
    $this->call('POST', '/webhooks/website-shop-pay', [], [], [], $headers + ['HTTP_X_SHOPIFY_HMAC_SHA256' => 'forged'], $payload)->assertUnauthorized();
    $this->call('POST', '/webhooks/website-shop-pay', [], [], [], $headers + ['HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $payload, 'fictional-webhook-secret', true))], $payload)->assertOk();
    expect($this->payment->fresh()->status)->toBe('processing');
    expect(collect(Http::recorded())->filter(fn ($entry) => str_contains($entry[0]['query'] ?? '', 'WebsiteWalletReceipts'))->count())->toBe(1);
});
