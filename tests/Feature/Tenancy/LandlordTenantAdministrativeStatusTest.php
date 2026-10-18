<?php

use App\Models\IntegrationHealthEvent;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;

beforeEach(function (): void {
    $host = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    config()->set('tenancy.landlord.primary_host', $host);
    config()->set('tenancy.landlord.hosts', [$host]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

test('workspace administrative status stays active independently of setup and integration health', function (
    bool $hasProfile,
    bool $hasMember,
    string $operatingMode,
    bool $hasHealthEvent,
    string $expectedHealth
): void {
    $operator = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $tenant = Tenant::query()->create(['name' => 'Example Field Workspace', 'slug' => 'example-field']);

    if ($hasProfile) {
        TenantAccessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'plan_key' => 'starter',
            'operating_mode' => $operatingMode,
            'source' => 'manual',
            'metadata' => [],
        ]);
    }

    if ($hasMember) {
        $member = User::factory()->create(['role' => 'member', 'is_active' => true]);
        $tenant->users()->attach($member->id, ['role' => 'member', 'membership_active' => true]);
    }

    if ($hasHealthEvent) {
        IntegrationHealthEvent::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'quickbooks',
            'event_type' => 'scheduled_sync_failed',
            'severity' => 'error',
            'status' => 'open',
            'dedupe_key' => 'example-sync-failure',
            'occurred_at' => now()->subMonths(3),
        ]);
    }

    $this->actingAs($operator)
        ->get(route('landlord.tenants.index'))
        ->assertOk()
        ->assertViewHas('tenants', fn ($rows): bool => collect($rows)->contains(
            fn (array $row): bool => $row['id'] === $tenant->id
                && $row['tenant_status'] === 'active'
                && $row['tenant_status_label'] === 'Active'
                && $row['status'] === $expectedHealth
                && $row['open_integration_health_events_count'] === (int) $hasHealthEvent
        ));

    $this->get(route('landlord.tenants.show', ['tenant' => $tenant->id, 'tab' => 'settings']))
        ->assertOk()
        ->assertViewHas('tenantStatus', 'active')
        ->assertViewHas('summary', fn (array $summary): bool => $summary['status'] === $expectedHealth
            && $summary['tenant_status'] === 'active');

    expect(data_get($tenant->fresh()->accessProfile?->metadata, 'admin.status'))->toBeNull();
    if ($hasHealthEvent) {
        expect(IntegrationHealthEvent::query()->where('tenant_id', $tenant->id)->value('status'))->toBe('open');
    }
})->with([
    'healthy field workspace' => [true, true, 'direct', false, 'healthy'],
    'historical QuickBooks failures' => [true, true, 'direct', true, 'attention_needed'],
    'Shopify connection pending' => [true, true, 'shopify', false, 'shopify_connection_pending'],
    'members pending' => [true, false, 'direct', false, 'users_pending'],
    'access profile missing' => [false, true, 'direct', false, 'access_profile_missing'],
]);

test('explicit landlord administrative status is preserved on the directory and settings page', function (string $status): void {
    $operator = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $tenant = Tenant::query()->create(['name' => 'Example Managed Workspace', 'slug' => 'example-managed']);
    $member = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $tenant->users()->attach($member->id, ['role' => 'member', 'membership_active' => true]);
    $metadata = ['admin' => ['status' => $status], 'unrelated' => 'preserved'];
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'manual',
        'metadata' => $metadata,
    ]);

    $this->actingAs($operator)
        ->get(route('landlord.tenants.index'))
        ->assertOk()
        ->assertViewHas('tenants', fn ($rows): bool => collect($rows)->contains(
            fn (array $row): bool => $row['id'] === $tenant->id
                && $row['tenant_status'] === $status
                && $row['status'] === 'healthy'
        ));

    $this->get(route('landlord.tenants.show', ['tenant' => $tenant->id, 'tab' => 'settings']))
        ->assertOk()
        ->assertViewHas('tenantStatus', $status)
        ->assertViewHas('summary', fn (array $summary): bool => $summary['tenant_status'] === $status);

    expect($tenant->fresh()->accessProfile->metadata)->toBe($metadata);
})->with(['active', 'inactive', 'suspended']);
