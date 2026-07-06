<?php

use App\Models\DevelopmentChangeLog;
use App\Models\IntegrationHealthEvent;
use App\Models\Tenant;

/**
 * Guardrail for tenant data isolation. Tenant scoping is currently OPT-IN via the
 * HasTenantScope trait's ->forTenant() local scope. These tests lock in that the
 * scope actually isolates rows, so a regression in the trait (which would become a
 * cross-tenant data leak) is caught in CI. They also document the opt-in footgun
 * that an enforced global scope would close (see the Developer Control Center vision board).
 */
test('forTenant isolates rows by tenant on a scoped model', function (): void {
    $alpha = Tenant::query()->create(['name' => 'Alpha Co', 'slug' => 'alpha']);
    $beta = Tenant::query()->create(['name' => 'Beta Co', 'slug' => 'beta']);

    DevelopmentChangeLog::query()->create(['tenant_id' => $alpha->id, 'title' => 'Alpha change', 'summary' => 'a', 'area' => 'ops']);
    DevelopmentChangeLog::query()->create(['tenant_id' => $beta->id, 'title' => 'Beta change', 'summary' => 'b', 'area' => 'ops']);

    expect(DevelopmentChangeLog::query()->forTenant($alpha->id)->pluck('title')->all())->toBe(['Alpha change']);
    expect(DevelopmentChangeLog::query()->forTenant($beta->id)->pluck('title')->all())->toBe(['Beta change']);
    expect(DevelopmentChangeLog::query()->forTenant($alpha)->count())->toBe(1); // accepts a Tenant model too
});

test('forTenant isolates integration health events by tenant', function (): void {
    $alpha = Tenant::query()->create(['name' => 'Alpha Co', 'slug' => 'alpha']);
    $beta = Tenant::query()->create(['name' => 'Beta Co', 'slug' => 'beta']);

    IntegrationHealthEvent::query()->create(['tenant_id' => $alpha->id, 'provider' => 'shopify', 'event_type' => 'order_import_stale', 'severity' => 'error', 'status' => 'open', 'occurred_at' => now()]);
    IntegrationHealthEvent::query()->create(['tenant_id' => $beta->id, 'provider' => 'shopify', 'event_type' => 'order_import_stale', 'severity' => 'error', 'status' => 'open', 'occurred_at' => now()]);

    expect(IntegrationHealthEvent::query()->forTenant($alpha->id)->count())->toBe(1);
    expect(IntegrationHealthEvent::query()->forTenant($beta->id)->count())->toBe(1);
});

test('an unscoped query returns all tenants rows — the opt-in risk this guards', function (): void {
    $alpha = Tenant::query()->create(['name' => 'Alpha Co', 'slug' => 'alpha']);
    $beta = Tenant::query()->create(['name' => 'Beta Co', 'slug' => 'beta']);

    DevelopmentChangeLog::query()->create(['tenant_id' => $alpha->id, 'title' => 'Alpha change', 'summary' => 'a', 'area' => 'ops']);
    DevelopmentChangeLog::query()->create(['tenant_id' => $beta->id, 'title' => 'Beta change', 'summary' => 'b', 'area' => 'ops']);

    // A forgotten ->forTenant() (or a null argument) returns everything. This is the
    // exact behavior an enforced global scope would eliminate.
    expect(DevelopmentChangeLog::query()->count())->toBe(2);
    expect(DevelopmentChangeLog::query()->forTenant(null)->count())->toBe(2);
});
