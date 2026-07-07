<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Exercises the enforced tenant-isolation backstop (App\Models\Scopes\TenantScope)
 * via IntegrationConnection, the first model to adopt BelongsToTenant. Proves the
 * two fail-open guarantees (flag-gated, null-context-safe) AND that when armed it
 * actually isolates, auto-stamps, and offers an escape hatch.
 */
function scopeTenant(string $slug): Tenant
{
    return Tenant::query()->create(['name' => $slug, 'slug' => $slug]);
}

function connectionFor(Tenant $tenant, string $account): IntegrationConnection
{
    return IntegrationConnection::query()->withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'shopify',
        'external_account_id' => $account,
        'status' => IntegrationConnection::STATUS_CONNECTED,
    ]);
}

beforeEach(function (): void {
    app(TenantContext::class)->forget();
});

test('with the flag OFF the global scope is inert (current opt-in behavior preserved)', function (): void {
    config()->set('features.enforced_tenant_scope', false);
    $alpha = scopeTenant('alpha');
    $beta = scopeTenant('beta');
    connectionFor($alpha, 'a');
    connectionFor($beta, 'b');

    app(TenantContext::class)->set($alpha->id); // set, but flag is off

    expect(IntegrationConnection::query()->count())->toBe(2);
});

test('with the flag ON and a tenant context set, queries are isolated to that tenant', function (): void {
    config()->set('features.enforced_tenant_scope', true);
    $alpha = scopeTenant('alpha');
    $beta = scopeTenant('beta');
    connectionFor($alpha, 'a');
    connectionFor($beta, 'b');

    app(TenantContext::class)->set($alpha->id);
    expect(IntegrationConnection::query()->pluck('external_account_id')->all())->toBe(['a']);

    app(TenantContext::class)->set($beta->id);
    expect(IntegrationConnection::query()->pluck('external_account_id')->all())->toBe(['b']);
});

test('with the flag ON but NO tenant context, no filter is applied (null-safe for CLI/queue/landlord)', function (): void {
    config()->set('features.enforced_tenant_scope', true);
    $alpha = scopeTenant('alpha');
    $beta = scopeTenant('beta');
    connectionFor($alpha, 'a');
    connectionFor($beta, 'b');

    // No context set — must see everything, exactly as CLI/queue/landlord do.
    expect(IntegrationConnection::query()->count())->toBe(2);
});

test('forAllTenants escapes the enforced scope', function (): void {
    config()->set('features.enforced_tenant_scope', true);
    $alpha = scopeTenant('alpha');
    $beta = scopeTenant('beta');
    connectionFor($alpha, 'a');
    connectionFor($beta, 'b');

    app(TenantContext::class)->set($alpha->id);

    expect(IntegrationConnection::query()->count())->toBe(1);
    expect(IntegrationConnection::query()->forAllTenants()->count())->toBe(2);
});

test('TenantContext::withoutScope suppresses the filter within the callback only', function (): void {
    config()->set('features.enforced_tenant_scope', true);
    $alpha = scopeTenant('alpha');
    $beta = scopeTenant('beta');
    connectionFor($alpha, 'a');
    connectionFor($beta, 'b');

    $context = app(TenantContext::class);
    $context->set($alpha->id);

    $all = $context->withoutScope(fn () => IntegrationConnection::query()->count());
    expect($all)->toBe(2);

    // Scope restored after the callback.
    expect(IntegrationConnection::query()->count())->toBe(1);
});

test('creating a record with the flag ON and a context set auto-stamps tenant_id', function (): void {
    config()->set('features.enforced_tenant_scope', true);
    $alpha = scopeTenant('alpha');
    app(TenantContext::class)->set($alpha->id);

    $connection = IntegrationConnection::query()->create([
        'provider' => 'square',
        'external_account_id' => 'loc_auto',
        'status' => IntegrationConnection::STATUS_PENDING,
    ]);

    expect($connection->tenant_id)->toBe($alpha->id);
});
