<?php

use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyWholesaleApplicationCustomerService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.wholesale.shop', 'wholesale-test.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-client');
    config()->set('services.shopify.stores.wholesale.client_secret', 'wholesale-secret');
    config()->set('services.shopify.allow_env_token_fallback', false);
});

function seedWholesaleShopifyStoreForApplicationSyncTests(): void
{
    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'tenant_id' => null,
            'shop_domain' => 'wholesale-test.myshopify.com',
            'access_token' => 'wholesale-token',
            'scopes' => 'read_customers,write_customers',
            'installed_at' => now(),
        ]
    );
}

test('existing wholesale application customers are updated without adding tags', function (): void {
    seedWholesaleShopifyStoreForApplicationSyncTests();

    $requests = [];

    Http::fake(function (Request $request) use (&$requests) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');
        $variables = (array) data_get($payload, 'variables', []);
        $requests[] = compact('query', 'variables');

        if (str_contains($query, 'FindWholesaleApplicationCustomerByEmail')) {
            return Http::response([
                'data' => [
                    'customers' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Customer/123',
                                    'legacyResourceId' => '123',
                                    'email' => 'ops-existing@example.com',
                                    'firstName' => 'Ops',
                                    'lastName' => 'Existing',
                                    'phone' => null,
                                    'tags' => ['vip'],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'UpsertWholesaleApplicationCustomer')) {
            expect(data_get($variables, 'identifier.email'))->toBe('ops-existing@example.com')
                ->and(data_get($variables, 'input.tags'))->toBeNull();

            return Http::response([
                'data' => [
                    'customerSet' => [
                        'customer' => [
                            'id' => 'gid://shopify/Customer/123',
                            'legacyResourceId' => '123',
                            'email' => 'ops-existing@example.com',
                            'firstName' => 'Ops',
                            'lastName' => 'Existing',
                            'phone' => null,
                            'tags' => ['vip'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        throw new \RuntimeException('Unexpected Shopify request during wholesale application sync test.');
    });

    $result = app(ShopifyWholesaleApplicationCustomerService::class)->syncByEmail('ops-existing@example.com', [
        'name' => 'Ops Existing',
    ]);

    expect($result['status'])->toBe('updated_synced')
        ->and($result['customer_created'])->toBeFalse()
        ->and($result['customer_updated'])->toBeTrue()
        ->and($result['customer_tags'])->toContain('vip')
        ->and($requests)->toHaveCount(2);
});

test('missing wholesale application customers are created without wholesale tags', function (): void {
    seedWholesaleShopifyStoreForApplicationSyncTests();

    $requests = [];

    Http::fake(function (Request $request) use (&$requests) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');
        $variables = (array) data_get($payload, 'variables', []);
        $requests[] = compact('query', 'variables');

        if (str_contains($query, 'FindWholesaleApplicationCustomerByEmail')) {
            return Http::response([
                'data' => [
                    'customers' => [
                        'edges' => [],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'UpsertWholesaleApplicationCustomer')) {
            expect(data_get($variables, 'identifier.email'))->toBe('ops-new@example.com')
                ->and(data_get($variables, 'input.tags'))->toBeNull();

            return Http::response([
                'data' => [
                    'customerSet' => [
                        'customer' => [
                            'id' => 'gid://shopify/Customer/456',
                            'legacyResourceId' => '456',
                            'email' => 'ops-new@example.com',
                            'firstName' => 'Ops',
                            'lastName' => 'New',
                            'phone' => null,
                            'tags' => [],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        throw new \RuntimeException('Unexpected Shopify request during wholesale application sync test.');
    });

    $result = app(ShopifyWholesaleApplicationCustomerService::class)->syncByEmail('ops-new@example.com', [
        'name' => 'Ops New',
    ]);

    expect($result['status'])->toBe('created_synced')
        ->and($result['customer_created'])->toBeTrue()
        ->and($result['customer_updated'])->toBeFalse()
        ->and($result['customer_tags'])->toBeEmpty()
        ->and($requests)->toHaveCount(2);
});
