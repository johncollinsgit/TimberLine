<?php

use App\Services\Marketing\CustomerMergeException;
use App\Services\Shopify\ShopifyCustomerMergeApi;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

test('Shopify customer merge preview uses official preview query and exposes Shopify consent ownership', function (): void {
    Http::fake([
        '*' => Http::response(['data' => ['customerMergePreview' => [
            'resultingCustomerId' => 'gid://shopify/Customer/2',
            'customerMergeErrors' => [],
            'blockingFields' => ['note' => null, 'tags' => []],
        ]]], 200),
    ]);
    $result = app(ShopifyCustomerMergeApi::class)->preview([
        'shop' => 'example.myshopify.com', 'token' => 'token', 'api_version' => '2026-01',
        'scopes' => 'read_customer_merge,write_customer_merge',
    ], 'gid://shopify/Customer/1', 'gid://shopify/Customer/2', ['customerIdOfEmailToKeep' => 'gid://shopify/Customer/2']);

    expect($result['resultingCustomerId'])->toBe('gid://shopify/Customer/2')
        ->and($result['consent_result']['controlled_by'])->toBe('shopify');
    Http::assertSent(fn ($request): bool => str_contains((string) $request['query'], 'customerMergePreview')
        && $request['variables']['overrideFields']->customerIdOfEmailToKeep === 'gid://shopify/Customer/2');
});

test('Shopify customer merge refuses execution until the store is reauthorized', function (): void {
    expect(fn () => app(ShopifyCustomerMergeApi::class)->merge([
        'shop' => 'example.myshopify.com', 'token' => 'old-token', 'scopes' => 'read_customers,write_customers',
    ], 'gid://shopify/Customer/1', 'gid://shopify/Customer/2'))
        ->toThrow(CustomerMergeException::class, 'Retail must be reauthorized with write_customer_merge');
    Http::assertNothingSent();
});
