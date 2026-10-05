<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Dashboard\UnifiedDashboardService;
use Illuminate\Http\Request;

test('Fleet Tracker appears on the web home only for an authorized workspace admin', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    foreach (['field_service', 'fleet', 'time_tracking', 'fleet_tracking'] as $module) {
        TenantModuleState::query()->create(['tenant_id' => $tenant->id, 'module_key' => $module, 'enabled_override' => true, 'setup_status' => 'configured']);
    }
    config()->set('services.fleet_tracking.enabled', true);

    $admin = User::factory()->create(['role' => 'member']);
    $employee = User::factory()->create(['role' => 'member']);
    $admin->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $request = Request::create('/dashboard');
    $request->attributes->set('current_tenant', $tenant);
    $dashboard = app(UnifiedDashboardService::class);
    expect(data_get($dashboard->forRequest($request, $admin), 'fleet_tracker.href'))
        ->toBe(route('field-service.fleet-tracking.index'))
        ->and(data_get($dashboard->forRequest($request, $admin), 'fleet_tracker.hours_href'))->toBe(route('field-service.payroll-hours'))
        ->and(data_get($dashboard->forRequest($request, $employee), 'fleet_tracker'))->toBeNull();

    config()->set('services.fleet_tracking.enabled', false);
    expect(data_get($dashboard->forRequest($request, $admin), 'fleet_tracker'))->toBeNull();
});
