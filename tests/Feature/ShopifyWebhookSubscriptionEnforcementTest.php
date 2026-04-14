<?php

use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyWebhookSubscriptionService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as ConsoleCommand;

beforeEach(function (): void {
    config()->set('app.url', 'https://backstage.test');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.landlord.primary_host', 'app.grovebud.com');
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.allow_env_token_fallback', false);
    config()->set('services.shopify.active_store_keys', 'retail,wholesale');
    config()->set('services.shopify.required_store_keys', 'retail');
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-client');
    config()->set('services.shopify.stores.retail.client_secret', 'retail-secret');
    config()->set('services.shopify.stores.wholesale.shop', 'wholesale-test.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-client');
    config()->set('services.shopify.stores.wholesale.client_secret', 'wholesale-secret');
    config()->set('services.shopify.scopes', 'read_orders,read_products,read_customers,write_customers,read_webhooks,write_webhooks');

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'shop_domain' => 'retail-test.myshopify.com',
            'access_token' => 'retail-token',
            'scopes' => 'read_orders,read_products,read_customers,write_customers,read_webhooks,write_webhooks',
            'installed_at' => now(),
        ]
    );
});

test('required webhook callbacks use canonical landlord host even when app url is stale', function (): void {
    config()->set('app.url', 'https://app.forestrybackstage.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.landlord.primary_host', 'app.grovebud.com');

    $callbacks = app(ShopifyWebhookSubscriptionService::class)->requiredTopicsWithCallbacks();

    expect($callbacks)->not->toBeEmpty();

    foreach ($callbacks as $topic => $callback) {
        expect((string) $topic)->not->toBe('')
            ->and(parse_url((string) $callback, PHP_URL_HOST))->toBe('app.grovebud.com')
            ->and(parse_url((string) $callback, PHP_URL_SCHEME))->toBe('https');
    }
});

test('oauth callback triggers required webhook registration for connected store', function (): void {
    $state = 'oauth-state-retail-123';
    Cache::store('file')->put('shopify_oauth_state_retail', $state, now()->addMinutes(10));

    Http::fake(function (HttpRequest $request) {
        $url = $request->url();

        if (str_ends_with($url, '/admin/oauth/access_token')) {
            return Http::response([
                'access_token' => 'new-token',
                'scope' => 'read_orders,read_products,read_customers,write_customers,read_webhooks,write_webhooks',
            ], 200);
        }

        if (str_ends_with($url, '/admin/api/2026-01/graphql.json')) {
            return Http::response([
                'data' => [
                    'currentAppInstallation' => [
                        'accessScopes' => [
                            ['handle' => 'read_orders'],
                            ['handle' => 'read_products'],
                            ['handle' => 'read_customers'],
                            ['handle' => 'write_customers'],
                            ['handle' => 'read_webhooks'],
                            ['handle' => 'write_webhooks'],
                        ],
                    ],
                ],
            ], 200);
        }

        if (str_contains($url, '/admin/api/2026-01/webhooks.json?limit=250')) {
            return Http::response(['webhooks' => []], 200);
        }

        if (str_ends_with($url, '/admin/api/2026-01/webhooks.json') && $request->method() === 'POST') {
            $topic = (string) data_get($request->data(), 'webhook.topic');
            $address = (string) data_get($request->data(), 'webhook.address');
            return Http::response([
                'webhook' => [
                    'id' => random_int(1000, 9999),
                    'topic' => $topic,
                    'address' => $address,
                    'format' => 'json',
                ],
            ], 201);
        }

        return Http::response([], 404);
    });

    $query = shopifyOauthCallbackQuery([
        'shop' => 'retail-test.myshopify.com',
        'code' => 'oauth-code',
        'state' => $state,
        'timestamp' => (string) time(),
    ], 'retail-secret');

    $response = $this->get(route('shopify.callback', array_merge(['store' => 'retail'], $query)));
    $response->assertRedirect(route('dashboard'));

    Http::assertSent(fn (HttpRequest $request): bool =>
        $request->method() === 'POST'
        && str_ends_with($request->url(), '/admin/api/2026-01/webhooks.json')
        && (string) data_get($request->data(), 'webhook.topic') === 'customers/create'
    );

    Http::assertSent(fn (HttpRequest $request): bool =>
        $request->method() === 'POST'
        && str_ends_with($request->url(), '/admin/api/2026-01/webhooks.json')
        && (string) data_get($request->data(), 'webhook.topic') === 'customers/update'
    );
});

test('verify command detects missing required customer webhooks', function (): void {
    Http::fake(function (HttpRequest $request) {
        if (str_contains($request->url(), '/admin/api/2026-01/webhooks.json?limit=250')) {
            return Http::response(['webhooks' => requiredWebhookRows(excludeTopics: ['customers/create', 'customers/update'])], 200);
        }

        return Http::response([], 404);
    });

    $this->artisan('shopify:webhooks:verify retail')
        ->expectsOutputToContain('status=drift')
        ->expectsOutputToContain('topic=customers/create state=missing')
        ->expectsOutputToContain('topic=customers/update state=missing')
        ->assertExitCode(ConsoleCommand::FAILURE);
});

test('repair mode creates missing required customer webhooks', function (): void {
    Http::fake(function (HttpRequest $request) {
        if (str_contains($request->url(), '/admin/api/2026-01/webhooks.json?limit=250')) {
            return Http::response(['webhooks' => requiredWebhookRows(excludeTopics: ['customers/create', 'customers/update'])], 200);
        }

        if (str_ends_with($request->url(), '/admin/api/2026-01/webhooks.json') && $request->method() === 'POST') {
            return Http::response([
                'webhook' => [
                    'id' => random_int(1000, 9999),
                    'topic' => (string) data_get($request->data(), 'webhook.topic'),
                    'address' => (string) data_get($request->data(), 'webhook.address'),
                    'format' => 'json',
                ],
            ], 201);
        }

        return Http::response([], 404);
    });

    $this->artisan('shopify:webhooks:verify retail --repair')
        ->expectsOutputToContain('status=repaired')
        ->expectsOutputToContain('topic=customers/create state=created')
        ->expectsOutputToContain('topic=customers/update state=created')
        ->assertExitCode(ConsoleCommand::SUCCESS);

    Http::assertSent(fn (HttpRequest $request): bool =>
        $request->method() === 'POST'
        && str_ends_with($request->url(), '/admin/api/2026-01/webhooks.json')
        && (string) data_get($request->data(), 'webhook.topic') === 'customers/create'
    );

    Http::assertSent(fn (HttpRequest $request): bool =>
        $request->method() === 'POST'
        && str_ends_with($request->url(), '/admin/api/2026-01/webhooks.json')
        && (string) data_get($request->data(), 'webhook.topic') === 'customers/update'
    );
});

test('repair mode corrects wrong callback URL for required topic', function (): void {
    $rows = requiredWebhookRows();
    foreach ($rows as &$row) {
        if (($row['topic'] ?? '') === 'customers/create') {
            $row['address'] = 'https://wrong.example/webhooks/shopify/customers/create';
        }
    }
    unset($row);

    Http::fake(function (HttpRequest $request) use ($rows) {
        if (str_contains($request->url(), '/admin/api/2026-01/webhooks.json?limit=250')) {
            return Http::response(['webhooks' => $rows], 200);
        }

        if (
            $request->method() === 'PUT'
            && preg_match('#/admin/api/2026-01/webhooks/\d+\.json$#', $request->url()) === 1
        ) {
            return Http::response([
                'webhook' => [
                    'id' => (int) preg_replace('/\D+/', '', basename((string) $request->url())),
                    'topic' => (string) data_get($request->data(), 'webhook.topic'),
                    'address' => (string) data_get($request->data(), 'webhook.address'),
                    'format' => 'json',
                ],
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->artisan('shopify:webhooks:verify retail --repair')
        ->expectsOutputToContain('topic=customers/create state=repaired')
        ->assertExitCode(ConsoleCommand::SUCCESS);

    Http::assertSent(fn (HttpRequest $request): bool =>
        $request->method() === 'PUT'
        && str_contains($request->url(), '/admin/api/2026-01/webhooks/')
        && (string) data_get($request->data(), 'webhook.topic') === 'customers/create'
        && str_contains((string) data_get($request->data(), 'webhook.address'), '/webhooks/shopify/customers/create')
    );
});

test('verify leaves existing correct registrations unchanged', function (): void {
    Http::fake(function (HttpRequest $request) {
        if (str_contains($request->url(), '/admin/api/2026-01/webhooks.json?limit=250')) {
            return Http::response(['webhooks' => requiredWebhookRows()], 200);
        }

        return Http::response([], 404);
    });

    $this->artisan('shopify:webhooks:verify retail')
        ->expectsOutputToContain('status=ok')
        ->assertExitCode(ConsoleCommand::SUCCESS);

    Http::assertNotSent(fn (HttpRequest $request): bool =>
        in_array($request->method(), ['POST', 'PUT'], true)
        && str_contains($request->url(), '/admin/api/2026-01/webhooks')
    );
});

test('verify command handles shopify API failure safely and logs details', function (): void {
    Log::spy();

    Http::fake(function (HttpRequest $request) {
        if (str_contains($request->url(), '/admin/api/2026-01/webhooks.json?limit=250')) {
            return Http::response(['errors' => 'unauthorized'], 401);
        }

        return Http::response([], 404);
    });

    $this->artisan('shopify:webhooks:verify retail')
        ->expectsOutputToContain('status=failed')
        ->assertExitCode(ConsoleCommand::FAILURE);

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'shopify webhook verification failed while listing subscriptions'
                && (string) ($context['store_key'] ?? '') === 'retail'
                && str_contains((string) ($context['error'] ?? ''), '401');
        })
        ->atLeast()
        ->once();
});

test('verify command treats optional wholesale failures as non-blocking in all-store mode', function (): void {
    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'shop_domain' => 'wholesale-test.myshopify.com',
            'access_token' => 'wholesale-token',
            'scopes' => 'read_orders,read_products,read_webhooks,write_webhooks',
            'installed_at' => now(),
        ]
    );

    $mock = Mockery::mock(ShopifyWebhookSubscriptionService::class);
    $mock->shouldReceive('verifyStore')
        ->twice()
        ->andReturnUsing(function (array $store, bool $repair): array {
            expect($repair)->toBeFalse();
            $storeKey = (string) ($store['key'] ?? '');

            if ($storeKey === 'wholesale') {
                return [
                    'store_key' => 'wholesale',
                    'status' => 'failed',
                    'required_count' => 2,
                    'counts' => [
                        'ok' => 0,
                        'missing' => 0,
                        'mismatch' => 0,
                        'created' => 0,
                        'repaired' => 0,
                        'failed' => 1,
                        'duplicates' => 0,
                    ],
                    'topics' => [],
                ];
            }

            return [
                'store_key' => 'retail',
                'status' => 'ok',
                'required_count' => 2,
                'counts' => [
                    'ok' => 2,
                    'missing' => 0,
                    'mismatch' => 0,
                    'created' => 0,
                    'repaired' => 0,
                    'failed' => 0,
                    'duplicates' => 0,
                ],
                'topics' => [],
            ];
        });
    app()->instance(ShopifyWebhookSubscriptionService::class, $mock);

    $this->artisan('shopify:webhooks:verify')
        ->expectsOutputToContain('store=retail')
        ->expectsOutputToContain('store_role=required')
        ->expectsOutputToContain('store=wholesale')
        ->expectsOutputToContain('store_role=optional')
        ->expectsOutputToContain('summary_required_failed_stores=0')
        ->expectsOutputToContain('summary_optional_failed_stores=1')
        ->expectsOutputToContain('Optional Shopify stores have webhook/auth drift. Launch gating remains based on required stores only.')
        ->assertExitCode(ConsoleCommand::SUCCESS);
});

test('verify command required-only mode checks only required stores', function (): void {
    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'shop_domain' => 'wholesale-test.myshopify.com',
            'access_token' => 'wholesale-token',
            'scopes' => 'read_orders,read_products,read_webhooks,write_webhooks',
            'installed_at' => now(),
        ]
    );

    $mock = Mockery::mock(ShopifyWebhookSubscriptionService::class);
    $mock->shouldReceive('verifyStore')
        ->once()
        ->withArgs(function (array $store, bool $repair): bool {
            return ($store['key'] ?? null) === 'retail' && $repair === false;
        })
        ->andReturn([
            'store_key' => 'retail',
            'status' => 'ok',
            'required_count' => 2,
            'counts' => [
                'ok' => 2,
                'missing' => 0,
                'mismatch' => 0,
                'created' => 0,
                'repaired' => 0,
                'failed' => 0,
                'duplicates' => 0,
            ],
            'topics' => [],
        ]);
    app()->instance(ShopifyWebhookSubscriptionService::class, $mock);

    $this->artisan('shopify:webhooks:verify --required-only')
        ->expectsOutputToContain('summary_stores=1')
        ->expectsOutputToContain('summary_required_stores=1')
        ->expectsOutputToContain('summary_optional_stores=0')
        ->assertExitCode(ConsoleCommand::SUCCESS);
});

test('verify command fails when required retail store is unresolved even if optional wholesale is healthy', function (): void {
    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'shop_domain' => 'retail-test.myshopify.com',
            'access_token' => '',
            'scopes' => 'read_orders,read_products,read_webhooks,write_webhooks',
            'installed_at' => now(),
        ]
    );
    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'shop_domain' => 'wholesale-test.myshopify.com',
            'access_token' => 'wholesale-token',
            'scopes' => 'read_orders,read_products,read_webhooks,write_webhooks',
            'installed_at' => now(),
        ]
    );

    $mock = Mockery::mock(ShopifyWebhookSubscriptionService::class);
    $mock->shouldReceive('verifyStore')
        ->once()
        ->withArgs(function (array $store, bool $repair): bool {
            return ($store['key'] ?? null) === 'wholesale' && $repair === false;
        })
        ->andReturn([
            'store_key' => 'wholesale',
            'status' => 'ok',
            'required_count' => 2,
            'counts' => [
                'ok' => 2,
                'missing' => 0,
                'mismatch' => 0,
                'created' => 0,
                'repaired' => 0,
                'failed' => 0,
                'duplicates' => 0,
            ],
            'topics' => [],
        ]);
    app()->instance(ShopifyWebhookSubscriptionService::class, $mock);

    $this->artisan('shopify:webhooks:verify')
        ->expectsOutputToContain('required_store_issue=retail store token missing. Run /shopify/reinstall/retail.')
        ->expectsOutputToContain('summary_required_resolution_issues=1')
        ->assertExitCode(ConsoleCommand::FAILURE);
});

/**
 * @param  array<string,string>  $query
 * @return array<string,string>
 */
function shopifyOauthCallbackQuery(array $query, string $secret): array
{
    $payload = $query;
    ksort($payload);

    $payload['hmac'] = hash_hmac('sha256', http_build_query($payload, '', '&', PHP_QUERY_RFC3986), $secret);

    return $payload;
}

/**
 * @param  array<int,string>  $excludeTopics
 * @return array<int,array{id:int,topic:string,address:string,format:string}>
 */
function requiredWebhookRows(array $excludeTopics = []): array
{
    $service = app(ShopifyWebhookSubscriptionService::class);
    $required = $service->requiredTopicsWithCallbacks();

    $rows = [];
    $id = 8000;
    foreach ($required as $topic => $callback) {
        if (in_array($topic, $excludeTopics, true)) {
            continue;
        }

        $id++;
        $rows[] = [
            'id' => $id,
            'topic' => $topic,
            'address' => $callback,
            'format' => 'json',
        ];
    }

    return $rows;
}
