<?php

use App\Models\LandlordOperatorAction;
use App\Models\OperatorAlertLog;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleAccessRequest;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Marketing\TwilioSmsService;

beforeEach(function (): void {
    $this->withoutVite();
});

test('marketing modules page renders tenant-aware module catalog for authenticated tenant users', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Direct Catalog Tenant',
        'slug' => 'direct-catalog-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'direct_starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    $this->actingAs($user)
        ->get(route('marketing.modules'))
        ->assertOk()
        ->assertSeeText('Choose what helps your business grow next.')
        ->assertSeeText('Find a Branch')
        ->assertSeeText('Categories')
        ->assertSeeText('SMS')
        ->assertSeeText('Browse Branches')
        ->assertSee('data-branch-card', false)
        ->assertSee('x-model.debounce.100ms="query"', false);
});

test('marketing modules page can activate and request module access', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Ridge Company',
        'slug' => 'ridge-company',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'direct_starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'is_active' => true,
        'email' => 'owner@ridgecompany.co',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);
    config()->set('everbranch.operator_alert_sms_enabled', true);
    config()->set('everbranch.operator_alert_phone', '+1 (555) 010-0101');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');

    $twilio = \Mockery::mock(TwilioSmsService::class);
    $twilio->shouldReceive('sendSms')
        ->once()
        ->with(
            '15550100101',
            'Everbranch: Ridge Company requested the Advanced Diagnostics Branch.',
            \Mockery::on(fn (array $options): bool => ($options['source_type'] ?? null) === 'operator_alert')
        )
        ->andReturn(['success' => true, 'provider' => 'twilio', 'error_code' => null]);
    app()->instance(TwilioSmsService::class, $twilio);

    $this->actingAs($user)
        ->post(route('marketing.modules.activate', ['moduleKey' => 'sms']))
        ->assertRedirect(route('marketing.modules', ['module' => 'sms']));

    expect(TenantModuleEntitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'sms')
        ->value('enabled_status'))->toBe('enabled');

    $this->actingAs($user)
        ->post('http://ridge-company.theeverbranch.com'.route('marketing.modules.request', ['moduleKey' => 'diagnostics_advanced'], absolute: false))
        ->assertRedirect(route('marketing.modules', ['module' => 'diagnostics_advanced']));

    expect(TenantModuleEntitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'diagnostics_advanced')
        ->value('availability_status'))->toBe('requested');

    expect(TenantModuleAccessRequest::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'diagnostics_advanced')
        ->value('status'))->toBe('pending');

    expect(LandlordOperatorAction::query()
        ->where('tenant_id', $tenant->id)
        ->where('action_type', 'tenant_module_access_request_created')
        ->exists())->toBeTrue();

    expect(OperatorAlertLog::query()
        ->where('event_key', 'branch_access_request.created')
        ->where('tenant_id', $tenant->id)
        ->value('status'))->toBe('sent');
});

test('marketing module activation is blocked for hidden or unsafe modules', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Unsafe Activation Tenant',
        'slug' => 'unsafe-activation-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    $this->actingAs($user)
        ->post(route('marketing.modules.activate', ['moduleKey' => 'square']))
        ->assertRedirect(route('marketing.modules', ['module' => 'square']));

    expect(TenantModuleEntitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'square')
        ->exists())->toBeFalse();
});

test('marketing module activation resolves an existing pending access request', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Pending Resolution Tenant',
        'slug' => 'pending-resolution-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    $request = TenantModuleAccessRequest::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'sms',
        'status' => 'pending',
        'requested_by' => $user->id,
        'source' => 'test',
        'request_reason' => 'add_on_required',
        'requested_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->post(route('marketing.modules.activate', ['moduleKey' => 'sms']))
        ->assertRedirect();

    expect($request->fresh()?->status)->toBe('approved')
        ->and(LandlordOperatorAction::query()
            ->where('tenant_id', $tenant->id)
            ->where('action_type', 'tenant_module_access_request_resolved')
            ->exists())->toBeTrue();
});

test('marketing module actions fail closed when a user forces a foreign tenant token', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Primary Tenant',
        'slug' => 'primary-tenant',
    ]);
    $otherTenant = Tenant::query()->create([
        'name' => 'Other Tenant',
        'slug' => 'other-tenant',
    ]);

    foreach ([$tenant, $otherTenant] as $index => $currentTenant) {
        TenantAccessProfile::query()->create([
            'tenant_id' => $currentTenant->id,
            'plan_key' => 'starter',
            'operating_mode' => 'direct',
            'source' => 'test-'.$index,
        ]);
    }

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    $this->actingAs($user)
        ->post(route('marketing.modules.activate', ['moduleKey' => 'sms']).'?tenant='.$otherTenant->slug)
        ->assertForbidden();

    expect(TenantModuleEntitlement::query()
        ->where('tenant_id', $otherTenant->id)
        ->where('module_key', 'sms')
        ->exists())->toBeFalse();
});
