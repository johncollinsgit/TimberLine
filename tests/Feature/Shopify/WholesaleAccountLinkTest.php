<?php

use App\Models\CustomerAccessRequest;
use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyWholesaleAccountLinkService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.shopify.stores.wholesale.shop' => 'wholesale-test.myshopify.com', 'services.shopify.stores.wholesale.client_id' => 'client', 'services.shopify.stores.wholesale.client_secret' => 'secret']);
    ShopifyStore::updateOrCreate(['store_key' => 'wholesale'], ['shop_domain' => 'wholesale-test.myshopify.com', 'access_token' => 'test-token', 'scopes' => 'read_customers,write_customers', 'installed_at' => now()]);
    $this->application = new CustomerAccessRequest(['metadata' => ['shopify_customer_gid' => 'gid://shopify/Customer/123']]);
});

test('classic disabled buyer receives a Shopify activation link', function () {
    Http::fakeSequence()->push(['data' => ['shop' => ['primaryDomain' => ['url' => 'https://wholesale.example.com'], 'customerAccountsV2' => ['customerAccountsVersion' => 'CLASSIC']], 'customer' => ['state' => 'DISABLED']]])
        ->push(['data' => ['customerGenerateAccountActivationUrl' => ['accountActivationUrl' => 'https://wholesale.example.com/account/activate/123/test', 'userErrors' => []]]]);
    expect(app(ShopifyWholesaleAccountLinkService::class)->forApplication($this->application))->toBe('https://wholesale.example.com/account/activate/123/test');
    Http::assertSentCount(2);
});

test('active buyers receive storefront login and never an internal password link', function () {
    Http::fakeSequence()->push(['data' => ['shop' => ['primaryDomain' => ['url' => 'https://wholesale.example.com'], 'customerAccountsV2' => ['customerAccountsVersion' => 'CLASSIC']], 'customer' => ['state' => 'ENABLED']]]);
    expect(app(ShopifyWholesaleAccountLinkService::class)->forApplication($this->application))->toBe('https://wholesale.example.com/account/login');
    Http::assertSentCount(1);
});

test('activation provider error stays retryable instead of mailing a broken link', function () {
    Http::fakeSequence()->push(['data' => ['shop' => ['primaryDomain' => ['url' => 'https://wholesale.example.com'], 'customerAccountsV2' => ['customerAccountsVersion' => 'CLASSIC']], 'customer' => ['state' => 'DISABLED']]])
        ->push(['data' => ['customerGenerateAccountActivationUrl' => ['accountActivationUrl' => null, 'userErrors' => [['message' => 'Unavailable']]]]]);
    expect(fn () => app(ShopifyWholesaleAccountLinkService::class)->forApplication($this->application))->toThrow(RuntimeException::class);
});
