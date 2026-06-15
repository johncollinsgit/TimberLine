<?php

use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyWholesaleCustomerApprovalService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.wholesale.shop', 'wholesale-test.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-client');
    config()->set('services.shopify.stores.wholesale.client_secret', 'wholesale-secret');
    config()->set('services.shopify.allow_env_token_fallback', false);
});

function seedWholesaleShopifyStoreForApprovalSyncTests(): void
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

test('existing wholesale customers are updated and tagged without clobbering other tags', function (): void {
    seedWholesaleShopifyStoreForApprovalSyncTests();

    $requests = [];

    Http::fake(function (Request $request) use (&$requests) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');
        $variables = (array) data_get($payload, 'variables', []);
        $requests[] = compact('query', 'variables');

        if (str_contains($query, 'FindWholesaleCustomerByEmail')) {
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

        if (str_contains($query, 'UpsertWholesaleCustomer')) {
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

        if (str_contains($query, 'AddWholesaleCustomerTag')) {
            expect(data_get($variables, 'tags'))->toBe(['wholesale']);

            return Http::response([
                'data' => [
                    'tagsAdd' => [
                        'node' => [
                            'id' => 'gid://shopify/Customer/123',
                            'legacyResourceId' => '123',
                            'email' => 'ops-existing@example.com',
                            'firstName' => 'Ops',
                            'lastName' => 'Existing',
                            'phone' => null,
                            'tags' => ['vip', 'wholesale'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        throw new \RuntimeException('Unexpected Shopify request during approval sync test.');
    });

    $result = app(ShopifyWholesaleCustomerApprovalService::class)->syncByEmail('ops-existing@example.com', [
        'name' => 'Ops Existing',
    ]);

    expect($result['status'])->toBe('updated_tagged')
        ->and($result['customer_created'])->toBeFalse()
        ->and($result['customer_updated'])->toBeTrue()
        ->and($result['tag_added'])->toBeTrue()
        ->and($result['customer_tags'])->toContain('vip', 'wholesale')
        ->and($requests)->toHaveCount(3);
});

test('missing wholesale customers are created and tagged', function (): void {
    seedWholesaleShopifyStoreForApprovalSyncTests();

    $requests = [];

    Http::fake(function (Request $request) use (&$requests) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');
        $variables = (array) data_get($payload, 'variables', []);
        $requests[] = compact('query', 'variables');

        if (str_contains($query, 'FindWholesaleCustomerByEmail')) {
            return Http::response([
                'data' => [
                    'customers' => [
                        'edges' => [],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'UpsertWholesaleCustomer')) {
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

        if (str_contains($query, 'AddWholesaleCustomerTag')) {
            return Http::response([
                'data' => [
                    'tagsAdd' => [
                        'node' => [
                            'id' => 'gid://shopify/Customer/456',
                            'legacyResourceId' => '456',
                            'email' => 'ops-new@example.com',
                            'firstName' => 'Ops',
                            'lastName' => 'New',
                            'phone' => null,
                            'tags' => ['wholesale'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        throw new \RuntimeException('Unexpected Shopify request during approval sync test.');
    });

    $result = app(ShopifyWholesaleCustomerApprovalService::class)->syncByEmail('ops-new@example.com', [
        'name' => 'Ops New',
    ]);

    expect($result['status'])->toBe('created_tagged')
        ->and($result['customer_created'])->toBeTrue()
        ->and($result['customer_updated'])->toBeFalse()
        ->and($result['tag_added'])->toBeTrue()
        ->and($result['customer_tags'])->toContain('wholesale')
        ->and($requests)->toHaveCount(3);
});

test('shopify sync failures throw instead of silently granting access', function (): void {
    seedWholesaleShopifyStoreForApprovalSyncTests();

    Http::fake(function (Request $request) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');

        if (str_contains($query, 'FindWholesaleCustomerByEmail')) {
            return Http::response([
                'data' => [
                    'customers' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Customer/789',
                                    'legacyResourceId' => '789',
                                    'email' => 'ops-failure@example.com',
                                    'firstName' => 'Ops',
                                    'lastName' => 'Failure',
                                    'phone' => null,
                                    'tags' => ['vip'],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'UpsertWholesaleCustomer')) {
            return Http::response([
                'data' => [
                    'customerSet' => [
                        'customer' => null,
                        'userErrors' => [
                            [
                                'field' => ['email'],
                                'message' => 'Customer does not exist',
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        throw new \RuntimeException('tagsAdd should not be called when customerSet fails.');
    });

    expect(fn () => app(ShopifyWholesaleCustomerApprovalService::class)->syncByEmail('ops-failure@example.com', [
        'name' => 'Ops Failure',
    ]))->toThrow(RuntimeException::class, 'Shopify customerSet failed');
});
