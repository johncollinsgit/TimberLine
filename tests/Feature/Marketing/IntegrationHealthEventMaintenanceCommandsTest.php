<?php

use App\Models\IntegrationHealthEvent;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use Symfony\Component\Console\Command\Command as ConsoleCommand;

beforeEach(function (): void {
    config()->set('marketing.integration_health.resolved_retention_days', 30);

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'retail-token',
        'installed_at' => now(),
    ]);
});

test('prune removes old resolved events', function (): void {
    IntegrationHealthEvent::query()->create([
        'provider' => 'shopify',
        'event_type' => 'webhook_subscription_mismatch',
        'severity' => 'warning',
        'status' => 'resolved',
        'store_key' => 'retail',
        'occurred_at' => now()->subDays(50),
        'resolved_at' => now()->subDays(40),
    ]);

    IntegrationHealthEvent::query()->create([
        'provider' => 'shopify',
        'event_type' => 'customer_provisioning_failed',
        'severity' => 'error',
        'status' => 'resolved',
        'store_key' => 'retail',
        'occurred_at' => now()->subDays(20),
        'resolved_at' => now()->subDays(10),
    ]);

    $this->artisan('integration-health:prune')
        ->expectsOutputToContain('matched=1')
        ->expectsOutputToContain('pruned=1')
        ->assertExitCode(ConsoleCommand::SUCCESS);

    expect(IntegrationHealthEvent::query()->count())->toBe(1)
        ->and(IntegrationHealthEvent::query()->value('event_type'))->toBe('customer_provisioning_failed');
});

test('prune does not remove open events', function (): void {
    IntegrationHealthEvent::query()->create([
        'provider' => 'shopify',
        'event_type' => 'tenant_context_unresolved',
        'severity' => 'warning',
        'status' => 'open',
        'store_key' => 'retail',
        'occurred_at' => now()->subDays(80),
    ]);

    $this->artisan('integration-health:prune')
        ->expectsOutputToContain('matched=0')
        ->expectsOutputToContain('pruned=0')
        ->assertExitCode(ConsoleCommand::SUCCESS);

    expect(IntegrationHealthEvent::query()->count())->toBe(1)
        ->and(IntegrationHealthEvent::query()->value('status'))->toBe('open');
});

test('prune dry run does not delete anything', function (): void {
    IntegrationHealthEvent::query()->create([
        'provider' => 'shopify',
        'event_type' => 'webhook_verification_failed',
        'severity' => 'error',
        'status' => 'resolved',
        'store_key' => 'retail',
        'occurred_at' => now()->subDays(120),
        'resolved_at' => now()->subDays(90),
    ]);

    $this->artisan('integration-health:prune --dry-run')
        ->expectsOutputToContain('mode=dry-run')
        ->expectsOutputToContain('matched=1')
        ->expectsOutputToContain('pruned=0')
        ->assertExitCode(ConsoleCommand::SUCCESS);

    expect(IntegrationHealthEvent::query()->count())->toBe(1);
});

test('list open returns only open events', function (): void {
    IntegrationHealthEvent::query()->create([
        'provider' => 'shopify',
        'event_type' => 'customer_webhook_ingestion_failed',
        'severity' => 'error',
        'status' => 'open',
        'store_key' => 'retail',
        'occurred_at' => now()->subMinutes(5),
    ]);

    IntegrationHealthEvent::query()->create([
        'provider' => 'shopify',
        'event_type' => 'webhook_subscription_missing',
        'severity' => 'warning',
        'status' => 'resolved',
        'store_key' => 'retail',
        'occurred_at' => now()->subDays(2),
        'resolved_at' => now()->subDay(),
    ]);

    $this->artisan('integration-health:list-open')
        ->expectsOutputToContain('customer_webhook_ingestion_failed')
        ->expectsOutputToContain('total=1')
        ->doesntExpectOutputToContain('webhook_subscription_missing')
        ->assertExitCode(ConsoleCommand::SUCCESS);
});

test('list open supports provider store and severity filters', function (): void {
    IntegrationHealthEvent::query()->create([
        'provider' => 'shopify',
        'event_type' => 'webhook_subscription_mismatch',
        'severity' => 'warning',
        'status' => 'open',
        'store_key' => 'retail',
        'occurred_at' => now()->subMinutes(20),
    ]);

    IntegrationHealthEvent::query()->create([
        'provider' => 'shopify',
        'event_type' => 'customer_provisioning_failed',
        'severity' => 'error',
        'status' => 'open',
        'store_key' => 'retail',
        'occurred_at' => now()->subMinutes(10),
    ]);

    IntegrationHealthEvent::query()->create([
        'provider' => 'square',
        'event_type' => 'customer_provisioning_failed',
        'severity' => 'error',
        'status' => 'open',
        'store_key' => 'retail',
        'occurred_at' => now()->subMinutes(9),
    ]);

    $this->artisan('integration-health:list-open --provider=shopify --store=retail --severity=error')
        ->expectsOutputToContain('customer_provisioning_failed')
        ->expectsOutputToContain('total=1')
        ->doesntExpectOutputToContain('webhook_subscription_mismatch')
        ->assertExitCode(ConsoleCommand::SUCCESS);
});
