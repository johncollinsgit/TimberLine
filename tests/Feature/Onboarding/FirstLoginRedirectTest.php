<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Support\Auth\HomeRedirect;

test('new verified users without tenants are sent to the first-login workspace flow', function (): void {
    $user = User::factory()->tenantAdmin()->create([
        'email_verified_at' => now(),
        'is_active' => true,
        'approved_at' => now(),
    ]);

    expect(HomeRedirect::pathFor($user))->toBe(route('workspace.first-login', absolute: false));
});

test('platform operators still go to the landlord dashboard even when they have no tenants', function (): void {
    $user = User::factory()->platformAdmin()->create([
        'email_verified_at' => now(),
        'approved_at' => now(),
    ]);

    expect(HomeRedirect::pathFor($user))->toBe(route('landlord.dashboard', absolute: false));
});

test('a pouring user with a workspace membership lands in the pouring room, not workspace creation', function (): void {
    $tenant = \App\Models\Tenant::query()->create(['name' => 'Acme Co', 'slug' => 'acme']);

    $user = User::factory()->create([
        'role' => 'pouring',
        'is_active' => true,
        'email_verified_at' => now(),
        'approved_at' => now(),
    ]);

    $tenant->users()->syncWithoutDetaching([$user->id => ['role' => 'pouring']]);

    // Role decides the landing only once the user actually belongs to a workspace.
    expect(HomeRedirect::pathFor($user))->toBe(route('pouring.index', absolute: false));
});

test('a field service member lands on work rather than the admin dashboard', function (): void {
    $this->withoutVite();

    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    $user = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);

    $workUrl = route('field-service.index', ['tenant' => $tenant->slug], absolute: false);
    expect(HomeRedirect::pathFor($user))->toBe($workUrl);

    $this->actingAs($user)->get('https://app.theeverbranch.com/login')
        ->assertRedirect($workUrl);
    $this->get('https://app.theeverbranch.com'.$workUrl)->assertOk();
});

test('a member without Field Service lands on an available account page', function (): void {
    $this->withoutVite();

    $tenant = Tenant::query()->create(['name' => 'Member Workspace', 'slug' => 'member-workspace']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    TenantModuleState::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'field_service',
        'enabled_override' => false,
    ]);
    $user = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);

    $helpUrl = route('account-help.index', ['tenant' => $tenant->slug], absolute: false);
    expect(HomeRedirect::pathFor($user))->toBe($helpUrl);
    $this->actingAs($user)->get('https://app.theeverbranch.com'.$helpUrl)->assertOk();
});

test('a member with two workspaces lands in an entitled workspace', function (): void {
    $this->withoutVite();

    $withoutWork = Tenant::query()->create(['name' => 'A Office', 'slug' => 'a-office']);
    $withWork = Tenant::query()->create(['name' => 'B Field', 'slug' => 'b-field']);
    foreach ([$withoutWork, $withWork] as $tenant) {
        TenantAccessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'plan_key' => 'base',
            'operating_mode' => 'direct',
            'source' => 'test',
        ]);
    }
    TenantModuleState::query()->create([
        'tenant_id' => $withoutWork->id,
        'module_key' => 'field_service',
        'enabled_override' => false,
    ]);
    $user = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($withoutWork->id, ['role' => 'member', 'membership_active' => true]);
    $user->tenants()->attach($withWork->id, ['role' => 'member', 'membership_active' => true]);

    $workUrl = route('field-service.index', ['tenant' => $withWork->slug], absolute: false);
    expect(HomeRedirect::pathFor($user))->toBe($workUrl);
    $this->actingAs($user)->get('https://app.theeverbranch.com'.$workUrl)->assertOk();
});
