<?php

use App\Models\IntegrationHealthEvent;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Marketing\IntegrationHealthEventRecorder;

test('records webhook drift event with structured metadata', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);

    $event = app(IntegrationHealthEventRecorder::class)->record([
        'provider' => 'shopify',
        'event_type' => 'webhook_subscription_missing',
        'severity' => 'warning',
        'status' => 'open',
        'store_key' => 'retail',
        'context' => [
            'topic' => 'customers/create',
            'callback' => 'https://backstage.test/webhooks/shopify/customers/create',
        ],
    ]);

    expect($event->event_type)->toBe('webhook_subscription_missing')
        ->and($event->severity)->toBe('warning')
        ->and($event->status)->toBe('open')
        ->and($event->store_key)->toBe('retail')
        ->and(data_get($event->context, 'topic'))->toBe('customers/create');
});

test('records provisioning failure event', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);

    $event = app(IntegrationHealthEventRecorder::class)->record([
        'provider' => 'shopify',
        'event_type' => 'customer_provisioning_failed',
        'severity' => 'error',
        'status' => 'open',
        'store_key' => 'retail',
        'context' => ['error_message' => 'customerCreate failed'],
    ]);

    expect($event->event_type)->toBe('customer_provisioning_failed')
        ->and($event->severity)->toBe('error')
        ->and($event->status)->toBe('open');
});

test('dedupes repeated same-condition events into one open record', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);

    $recorder = app(IntegrationHealthEventRecorder::class);

    $recorder->record([
        'provider' => 'shopify',
        'event_type' => 'customer_webhook_ingestion_failed',
        'severity' => 'error',
        'status' => 'open',
        'store_key' => 'retail',
        'context' => [
            'topic' => 'customers/create',
            'error_message' => 'timeout',
        ],
        'dedupe_key' => 'retail-webhook-fail-customers-create',
    ]);

    $recorder->record([
        'provider' => 'shopify',
        'event_type' => 'customer_webhook_ingestion_failed',
        'severity' => 'error',
        'status' => 'open',
        'store_key' => 'retail',
        'context' => [
            'topic' => 'customers/create',
            'error_message' => 'timeout again',
        ],
        'dedupe_key' => 'retail-webhook-fail-customers-create',
    ]);

    expect(IntegrationHealthEvent::query()->count())->toBe(1);

    $event = IntegrationHealthEvent::query()->first();
    expect((int) data_get($event?->context, '_occurrences'))->toBe(2);
});

test('resolves an open event when condition is repaired', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);

    app(IntegrationHealthEventRecorder::class)->record([
        'provider' => 'shopify',
        'event_type' => 'webhook_subscription_mismatch',
        'severity' => 'warning',
        'status' => 'open',
        'store_key' => 'retail',
        'context' => ['topic' => 'customers/create'],
        'dedupe_key' => 'retail-mismatch-create',
    ]);

    $updated = app(IntegrationHealthEventRecorder::class)->resolve([
        'provider' => 'shopify',
        'store_key' => 'retail',
        'event_type' => 'webhook_subscription_mismatch',
        'dedupe_key' => 'retail-mismatch-create',
    ]);

    expect($updated)->toBe(1);

    $event = IntegrationHealthEvent::query()->first();
    expect($event?->status)->toBe('resolved')
        ->and($event?->resolved_at)->not->toBeNull();
});

test('resolves tenant and store attribution from store key', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $store = ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);

    $event = app(IntegrationHealthEventRecorder::class)->record([
        'provider' => 'shopify',
        'event_type' => 'tenant_context_unresolved',
        'severity' => 'warning',
        'status' => 'open',
        'store_key' => 'retail',
        'context' => ['reason' => 'tenant_context_unresolved'],
    ]);

    expect((int) $event->tenant_id)->toBe((int) $tenant->id)
        ->and((int) $event->shopify_store_id)->toBe((int) $store->id);
});

