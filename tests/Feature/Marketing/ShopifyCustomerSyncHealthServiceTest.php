<?php

use App\Jobs\ProvisionShopifyCustomerForMarketingProfile;
use App\Models\CustomerExternalProfile;
use App\Models\IntegrationHealthEvent;
use App\Models\ShopifyStore;
use App\Services\Marketing\ShopifyCustomerSyncHealthService;
use App\Services\Shopify\ShopifyWebhookSubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.allow_env_token_fallback', false);
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-client');
    config()->set('services.shopify.stores.retail.client_secret', 'retail-secret');
    config()->set('services.shopify.stores.wholesale.shop', 'wholesale-test.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-client');
    config()->set('services.shopify.stores.wholesale.client_secret', 'wholesale-secret');
    config()->set('services.shopify.active_store_keys', 'retail,wholesale');
    config()->set('services.shopify.required_store_keys', 'retail');
    config()->set('shopify_webhooks.required_topics', [
        'customers/create' => 'shopify.webhooks.customers.create',
        'customers/update' => 'shopify.webhooks.customers.update',
    ]);
});

test('health report marks store healthy when webhooks, auth, and ingestion signals are present', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);

    CustomerExternalProfile::query()->create([
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '1001',
        'raw_metafields' => [
            'shopify_customer_webhook' => [
                'topic' => 'customers/create',
                'received_at' => now()->subHour()->toIso8601String(),
            ],
        ],
        'synced_at' => now(),
    ]);

    bindShopifyWebhookVerifier([
        'retail' => [
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
            'drift_count' => 0,
            'topics' => [],
        ],
    ]);

    $report = app(ShopifyCustomerSyncHealthService::class)->report(refreshWebhooks: true, lookbackHours: 72);
    $store = collect($report['stores'])->firstWhere('store_key', 'retail');

    expect($store)->not->toBeNull()
        ->and($store['status'])->toBe('healthy')
        ->and($store['webhook']['status'])->toBe('ok')
        ->and($store['auth']['status'])->toBe('healthy')
        ->and($store['last_customer_webhook_ingested_at'])->not->toBeNull();
});

test('health report marks store warning when required webhook topics are missing', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);

    bindShopifyWebhookVerifier([
        'retail' => [
            'status' => 'drift',
            'required_count' => 2,
            'counts' => [
                'ok' => 1,
                'missing' => 1,
                'mismatch' => 0,
                'created' => 0,
                'repaired' => 0,
                'failed' => 0,
                'duplicates' => 0,
            ],
            'drift_count' => 1,
            'topics' => [],
        ],
    ]);

    $report = app(ShopifyCustomerSyncHealthService::class)->report(refreshWebhooks: true, lookbackHours: 72);
    $store = collect($report['stores'])->firstWhere('store_key', 'retail');

    expect($store)->not->toBeNull()
        ->and($store['status'])->toBe('warning')
        ->and($store['webhook']['status'])->toBe('drift')
        ->and((int) $store['webhook']['missing_count'])->toBe(1);
});

test('health report includes recent provisioning failures from failed jobs', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);

    bindShopifyWebhookVerifier([
        'retail' => [
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
            'drift_count' => 0,
            'topics' => [],
        ],
    ]);

    insertFailedJob(
        queuePayloadForJob(
            new ProvisionShopifyCustomerForMarketingProfile(77, 'retail', 5, 'test'),
            ProvisionShopifyCustomerForMarketingProfile::class
        ),
        'RuntimeException: provisioning failed'
    );

    $report = app(ShopifyCustomerSyncHealthService::class)->report(refreshWebhooks: true, lookbackHours: 72);
    $store = collect($report['stores'])->firstWhere('store_key', 'retail');

    expect($store)->not->toBeNull()
        ->and((int) $store['recent_provisioning_failures'])->toBe(1)
        ->and($store['status'])->toBe('warning');
});

test('health report marks store failing when auth token is missing', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => '',
        'installed_at' => now(),
    ]);

    bindShopifyWebhookVerifier([
        'retail' => [
            'status' => 'failed',
            'error' => 'store_credentials_missing',
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
            'drift_count' => 0,
            'topics' => [],
        ],
    ]);

    $report = app(ShopifyCustomerSyncHealthService::class)->report(refreshWebhooks: true, lookbackHours: 72);
    $store = collect($report['stores'])->firstWhere('store_key', 'retail');

    expect($store)->not->toBeNull()
        ->and($store['auth']['status'])->toBe('failing')
        ->and($store['status'])->toBe('failing');
});

test('health report marks store unknown when no ingestion data exists yet', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);

    bindShopifyWebhookVerifier([
        'retail' => [
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
            'drift_count' => 0,
            'topics' => [],
        ],
    ]);

    $report = app(ShopifyCustomerSyncHealthService::class)->report(refreshWebhooks: true, lookbackHours: 72);
    $store = collect($report['stores'])->firstWhere('store_key', 'retail');

    expect($store)->not->toBeNull()
        ->and($store['status'])->toBe('unknown')
        ->and($store['last_customer_webhook_ingested_at'])->toBeNull();
});

test('health report uses persisted warning events to mark store warning', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);

    bindShopifyWebhookVerifier([
        'retail' => [
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
            'drift_count' => 0,
            'topics' => [],
        ],
    ]);

    IntegrationHealthEvent::query()->create([
        'store_key' => 'retail',
        'provider' => 'shopify',
        'event_type' => 'tenant_context_unresolved',
        'severity' => 'warning',
        'status' => 'open',
        'occurred_at' => now()->subMinutes(10),
        'context' => ['reason' => 'tenant_context_unresolved'],
    ]);

    $report = app(ShopifyCustomerSyncHealthService::class)->report(refreshWebhooks: true, lookbackHours: 72);
    $store = collect($report['stores'])->firstWhere('store_key', 'retail');

    expect($store)->not->toBeNull()
        ->and($store['status'])->toBe('warning')
        ->and((int) $store['open_warning_events'])->toBeGreaterThan(0);
});

test('health report uses persisted error events to mark store failing', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);

    bindShopifyWebhookVerifier([
        'retail' => [
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
            'drift_count' => 0,
            'topics' => [],
        ],
    ]);

    IntegrationHealthEvent::query()->create([
        'store_key' => 'retail',
        'provider' => 'shopify',
        'event_type' => 'customer_webhook_ingestion_failed',
        'severity' => 'error',
        'status' => 'open',
        'occurred_at' => now()->subMinutes(5),
        'context' => ['error_message' => 'Webhook sync failed'],
    ]);

    $report = app(ShopifyCustomerSyncHealthService::class)->report(refreshWebhooks: true, lookbackHours: 72);
    $store = collect($report['stores'])->firstWhere('store_key', 'retail');

    expect($store)->not->toBeNull()
        ->and($store['status'])->toBe('failing')
        ->and((int) $store['open_error_events'])->toBeGreaterThan(0)
        ->and((int) $store['recent_webhook_ingestion_failures'])->toBe(1);
});

test('launch gate passes when required retail is healthy and optional wholesale is failing', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);
    ShopifyStore::query()->create([
        'store_key' => 'wholesale',
        'shop_domain' => 'wholesale-test.myshopify.com',
        'access_token' => '',
        'installed_at' => now(),
    ]);

    CustomerExternalProfile::query()->create([
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '2001',
        'raw_metafields' => [
            'shopify_customer_webhook' => [
                'topic' => 'customers/update',
                'received_at' => now()->subMinutes(30)->toIso8601String(),
            ],
        ],
        'synced_at' => now(),
    ]);

    bindShopifyWebhookVerifier([
        'retail' => [
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
            'drift_count' => 0,
            'topics' => [],
        ],
        'wholesale' => [
            'status' => 'failed',
            'error' => 'store_credentials_missing',
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
            'drift_count' => 0,
            'topics' => [],
        ],
    ]);

    $report = app(ShopifyCustomerSyncHealthService::class)->report(refreshWebhooks: true, lookbackHours: 72);
    $retail = collect($report['stores'])->firstWhere('store_key', 'retail');
    $wholesale = collect($report['stores'])->firstWhere('store_key', 'wholesale');
    $launchGate = (array) ($report['launch_gate'] ?? []);

    expect($retail)->not->toBeNull()
        ->and($retail['is_required'])->toBeTrue()
        ->and($retail['status'])->toBe('healthy');

    expect($wholesale)->not->toBeNull()
        ->and($wholesale['is_required'])->toBeFalse()
        ->and($wholesale['status'])->toBe('failing');

    expect($launchGate['status'] ?? null)->toBe('healthy')
        ->and((int) ($launchGate['required_healthy'] ?? -1))->toBe(1)
        ->and((int) ($launchGate['required_failing'] ?? -1))->toBe(0)
        ->and((int) (($report['totals']['optional_failing'] ?? -1)))->toBe(1);
});

test('launch gate fails when required retail auth is unhealthy', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => '',
        'installed_at' => now(),
    ]);
    ShopifyStore::query()->create([
        'store_key' => 'wholesale',
        'shop_domain' => 'wholesale-test.myshopify.com',
        'access_token' => 'wholesale-token',
        'installed_at' => now(),
    ]);

    CustomerExternalProfile::query()->create([
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'wholesale',
        'external_customer_id' => '2002',
        'raw_metafields' => [
            'shopify_customer_webhook' => [
                'topic' => 'customers/create',
                'received_at' => now()->subMinutes(20)->toIso8601String(),
            ],
        ],
        'synced_at' => now(),
    ]);

    bindShopifyWebhookVerifier([
        'retail' => [
            'status' => 'failed',
            'error' => 'store_credentials_missing',
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
            'drift_count' => 0,
            'topics' => [],
        ],
        'wholesale' => [
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
            'drift_count' => 0,
            'topics' => [],
        ],
    ]);

    $report = app(ShopifyCustomerSyncHealthService::class)->report(refreshWebhooks: true, lookbackHours: 72);
    $retail = collect($report['stores'])->firstWhere('store_key', 'retail');
    $launchGate = (array) ($report['launch_gate'] ?? []);

    expect($retail)->not->toBeNull()
        ->and($retail['is_required'])->toBeTrue()
        ->and($retail['status'])->toBe('failing');

    expect($launchGate['status'] ?? null)->toBe('failing')
        ->and((int) ($launchGate['required_failing'] ?? 0))->toBeGreaterThan(0);
});

/**
 * @param  array<string,array<string,mixed>>  $resultsByStoreKey
 */
function bindShopifyWebhookVerifier(array $resultsByStoreKey): void
{
    $mock = Mockery::mock(ShopifyWebhookSubscriptionService::class);

    $mock->shouldReceive('requiredTopicsWithCallbacks')
        ->andReturn([
            'customers/create' => 'https://backstage.test/webhooks/shopify/customers/create',
            'customers/update' => 'https://backstage.test/webhooks/shopify/customers/update',
        ]);

    $mock->shouldReceive('verifyStore')
        ->andReturnUsing(function (array $store, bool $repair) use ($resultsByStoreKey): array {
            expect($repair)->toBeFalse();

            $storeKey = strtolower(trim((string) ($store['key'] ?? 'unknown')));
            $result = $resultsByStoreKey[$storeKey] ?? [
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
                'drift_count' => 0,
                'topics' => [],
            ];

            return array_merge([
                'store_key' => $storeKey,
                'shop' => (string) ($store['shop'] ?? ''),
            ], $result);
        });

    app()->instance(ShopifyWebhookSubscriptionService::class, $mock);
}

function insertFailedJob(string $payload, string $exception): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => $payload,
        'exception' => $exception,
        'failed_at' => now(),
    ]);
}

function queuePayloadForJob(object $job, string $commandName): string
{
    return json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => $commandName,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'data' => [
            'commandName' => $commandName,
            'command' => serialize($job),
        ],
    ], JSON_THROW_ON_ERROR);
}
