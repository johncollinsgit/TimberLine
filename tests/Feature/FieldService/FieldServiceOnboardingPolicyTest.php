<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Mobile\TenantMobileModuleRegistry;
use App\Services\Tenancy\TenantModuleActivationPolicyService;

test('electrician onboarding recommends the reusable field operations bundle without auto activating add ons', function (): void {
    $policies = app(TenantModuleActivationPolicyService::class);
    $decisions = $policies->forTemplate('electrician', 'base')->keyBy('module_key');

    expect($decisions->keys()->all())->toContain(
        'field_service',
        'time_tracking',
        'team_communication',
        'field_inventory',
        'fleet',
        'documents',
        'quickbooks',
    )
        ->and($decisions['field_service']['policy'])->toBe('baseline_auto')
        ->and($decisions['field_service']['auto_activate'])->toBeTrue()
        ->and($decisions['time_tracking']['policy'])->toBe('template_recommended')
        ->and($decisions['time_tracking']['auto_activate'])->toBeFalse()
        ->and($decisions['quickbooks']['policy'])->toBe('integration_required')
        ->and($decisions['quickbooks']['requires_integration_readiness'])->toBeTrue();
});

test('mobile module manifest enforces minimum app version when the client reports it', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Versioned Field Team', 'slug' => 'versioned-field-team']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    foreach (['customers', 'field_service'] as $module) {
        TenantModuleEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'module_key' => $module,
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'included_in_plan',
            'entitlement_source' => 'test',
            'price_source' => 'catalog',
        ]);
    }
    $employee = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $employee->tenants()->attach($tenant->id, ['role' => 'member']);
    $registry = app(TenantMobileModuleRegistry::class);

    expect(collect($registry->manifest((int) $tenant->id, $employee, '2.1.0'))->pluck('module_key'))->not->toContain('field_service')
        ->and(collect($registry->manifest((int) $tenant->id, $employee, '2.2.0'))->pluck('module_key'))->toContain('field_service');
});
