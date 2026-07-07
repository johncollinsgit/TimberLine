<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use Illuminate\Support\Facades\Route;

function moduleGateTenant(string $slug, string $plan, string $mode): Tenant
{
    $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => $plan,
        'operating_mode' => $mode,
        'source' => 'test',
    ]);

    return $tenant;
}

function moduleGateActor(Tenant $tenant): User
{
    $user = User::factory()->tenantAdmin()->create([
        'is_active' => true,
        'email_verified_at' => now(),
        'approved_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    return $user;
}

beforeEach(function (): void {
    // Synthetic route exercising ONLY the reusable module:{key} gate (no controller
    // logic), so this proves the middleware itself, independent of field-service.
    Route::middleware(['web', 'auth', 'tenant.access', 'module:field_service'])
        ->get('/__test/module-gate', fn () => response('ok'));
});

test('the module gate allows a tenant entitled to the module', function (): void {
    $tenant = moduleGateTenant('gate-allow', 'base', 'direct'); // base plan includes field_service
    $user = moduleGateActor($tenant);

    $this->actingAs($user)
        ->withSession(['tenant_id' => $tenant->id])
        ->get('/__test/module-gate')
        ->assertOk();
});

test('the module gate blocks a tenant not entitled to the module', function (): void {
    $tenant = moduleGateTenant('gate-block', 'starter', 'shopify'); // starter plan does not include field_service
    $user = moduleGateActor($tenant);

    $this->actingAs($user)
        ->withSession(['tenant_id' => $tenant->id])
        ->get('/__test/module-gate')
        ->assertForbidden();
});
