<?php

use App\Services\Billing\ShopPayWalletPreflight;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->credentials = ['shop_domain' => 'fictional-wallet.myshopify.com', 'shop_id' => '12345',
        'admin_access_token' => 'fictional-admin-secret', 'storefront_access_token' => 'fictional-storefront-secret'];
});

it('verifies the explicit merchant identity without requesting banking or customer data', function () {
    Http::fake(['*/admin/api/2026-10/graphql.json' => Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/12345', 'name' => 'Fictional Wallet', 'myshopifyDomain' => 'fictional-wallet.myshopify.com']]])]);
    $result = (new ShopPayWalletPreflight($this->credentials))->inspect();
    expect($result['api_identity_verified'])->toBeTrue()->and($result['checkout_ready'])->toBeFalse();
    Http::assertSent(fn ($request) => ! str_contains($request['query'], 'shopifyPaymentsAccount')
        && ! str_contains($request['query'], 'customers') && ! array_key_exists('variables', $request->data()));
});

it('uses the Wallet session operation and never submits a payment or exposes its bearer values', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/admin/api/')) {
            return Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/12345', 'myshopifyDomain' => 'fictional-wallet.myshopify.com']]]);
        }

        return Http::response(['data' => ['shopPayPaymentRequestSessionCreate' => ['shopPayPaymentRequestSession' => [
            'sourceIdentifier' => $request['variables']['source'], 'token' => 'fictional-session-secret',
            'checkoutUrl' => 'https://shop.app/checkout/12345/spe/fictional-session-secret/shoppay'], 'userErrors' => []]]]);
    });
    $result = (new ShopPayWalletPreflight($this->credentials))->probeSession();
    expect($result['session_created'])->toBeTrue()->and($result['payment_submitted'])->toBeFalse()
        ->and(json_encode($result))->not->toContain('fictional-session-secret')->not->toContain('checkoutUrl');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/2026-10/')
        && ! str_contains($request->url(), '/admin/api/') && $request['variables']['request']['total']['amount'] === '1.00'
        && ! isset($request['variables']['request']['paymentMethod']) && ! str_contains($request['query'], 'SessionSubmit'));
});

it('rejects unknown hosts before sending credentials', function ($host) {
    $this->credentials['shop_domain'] = $host;
    expect(fn () => new ShopPayWalletPreflight($this->credentials))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with(['https://fictional-wallet.myshopify.com', 'fictional-wallet.myshopify.com.attacker.test', 'user@fictional-wallet.myshopify.com', '127.0.0.1', 'fictional-wallet.myshopify.com:443']);

it('stops before session creation if the Admin token belongs to a different shop', function () {
    Http::fake(['*' => Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/99999', 'myshopifyDomain' => 'fictional-wallet.myshopify.com']]])]);
    expect(fn () => (new ShopPayWalletPreflight($this->credentials))->probeSession())->toThrow(RuntimeException::class, 'do not match');
    Http::assertSentCount(1);
});

it('does not relay provider errors containing secret request details', function () {
    Http::fake(['*' => Http::response(['errors' => [['message' => 'private-provider-context']]], 403)]);
    expect(fn () => (new ShopPayWalletPreflight($this->credentials))->inspect())
        ->toThrow(RuntimeException::class, 'Shopify rejected the Wallet admin check.');
});

it('rejects mismatched merchant URLs and invalid session responses', function ($changes) {
    Http::fake(function ($request) use ($changes) {
        if (str_contains($request->url(), '/admin/api/')) {
            return Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/12345', 'myshopifyDomain' => 'fictional-wallet.myshopify.com']]]);
        }
        $session = array_replace(['sourceIdentifier' => $request['variables']['source'], 'token' => 'fictional-token', 'checkoutUrl' => 'https://shop.app/checkout/12345/spe/fictional-token/shoppay'], $changes);

        return Http::response(['data' => ['shopPayPaymentRequestSessionCreate' => ['shopPayPaymentRequestSession' => $session, 'userErrors' => []]]]);
    });
    expect(fn () => (new ShopPayWalletPreflight($this->credentials))->probeSession())->toThrow(RuntimeException::class, 'valid Wallet session');
})->with([
    'unknown host' => [['checkoutUrl' => 'https://attacker.test/checkout/12345/session']],
    'other shop' => [['checkoutUrl' => 'https://shop.app/checkout/99999/session']],
    'userinfo' => [['checkoutUrl' => 'https://attacker@shop.app/checkout/12345/session']],
    'wrong source' => [['sourceIdentifier' => 'another-request']],
    'no token' => [['token' => '']],
]);

it('requires an owner-only credential file before any provider request', function () {
    $path = tempnam(sys_get_temp_dir(), 'wallet-test-');
    try {
        file_put_contents($path, json_encode($this->credentials));
        chmod($path, 0644);
        $this->artisan('payments:shop-pay-preflight', ['--credentials' => $path])->assertFailed();
        Http::assertNothingSent();
    } finally {
        unlink($path);
    }
});
