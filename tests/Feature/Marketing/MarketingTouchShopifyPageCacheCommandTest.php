<?php

use App\Models\ShopifyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.retail.shop', 'retail-cache-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-client-id');
    config()->set('services.shopify.stores.retail.client_secret', 'retail-client-secret');
    config()->set('services.shopify.allow_env_token_fallback', false);

    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-cache-test.myshopify.com',
        'access_token' => 'oauth-token',
        'scopes' => 'read_content,write_content',
        'installed_at' => now(),
    ]);
});

test('marketing touch shopify page cache command updates page via graphql', function (): void {
    $lookupCalls = 0;
    $updateCalls = 0;

    Http::fake(function (HttpRequest $request) use (&$lookupCalls, &$updateCalls) {
        expect($request->url())->toContain('/admin/api/2026-01/graphql.json');
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');

        if (str_contains($query, 'query TouchPageLookup')) {
            $lookupCalls++;

            return Http::response([
                'data' => [
                    'pages' => [
                        'nodes' => [
                            [
                                'id' => 'gid://shopify/OnlineStorePage/123',
                                'handle' => 'rewards',
                                'title' => 'Rewards',
                                'body' => '<p>Rewards body</p>',
                                'updatedAt' => '2026-04-07T20:50:00Z',
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'mutation TouchPage')) {
            $updateCalls++;

            expect(data_get($payload, 'variables.id'))->toBe('gid://shopify/OnlineStorePage/123');
            expect(data_get($payload, 'variables.page.title'))->toBe('Rewards');

            return Http::response([
                'data' => [
                    'pageUpdate' => [
                        'page' => [
                            'id' => 'gid://shopify/OnlineStorePage/123',
                            'updatedAt' => '2026-04-07T20:51:00Z',
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        return Http::response([
            'errors' => [
                ['message' => 'unexpected query'],
            ],
        ], 422);
    });

    $this->artisan('marketing:touch-shopify-page-cache retail --handle=rewards')
        ->expectsOutputToContain('store=retail')
        ->expectsOutputToContain('handle=rewards')
        ->expectsOutputToContain('Shopify page touched successfully.')
        ->assertExitCode(0);

    expect($lookupCalls)->toBe(1)
        ->and($updateCalls)->toBe(1);
});

test('marketing touch shopify page cache command dry run skips mutation', function (): void {
    $lookupCalls = 0;
    $updateCalls = 0;

    Http::fake(function (HttpRequest $request) use (&$lookupCalls, &$updateCalls) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');

        if (str_contains($query, 'query TouchPageLookup')) {
            $lookupCalls++;

            return Http::response([
                'data' => [
                    'pages' => [
                        'nodes' => [
                            [
                                'id' => 'gid://shopify/OnlineStorePage/123',
                                'handle' => 'rewards',
                                'title' => 'Rewards',
                                'body' => '<p>Rewards body</p>',
                                'updatedAt' => '2026-04-07T20:50:00Z',
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'mutation TouchPage')) {
            $updateCalls++;
        }

        return Http::response([
            'data' => [],
        ], 200);
    });

    $this->artisan('marketing:touch-shopify-page-cache retail --handle=rewards --dry-run')
        ->expectsOutputToContain('mode=dry-run')
        ->assertExitCode(0);

    expect($lookupCalls)->toBe(1)
        ->and($updateCalls)->toBe(0);
});
