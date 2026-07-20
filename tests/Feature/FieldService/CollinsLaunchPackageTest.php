<?php

use App\Models\Agreement;
use App\Models\CustomerEquipment;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceReminderSetting;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceTimeEntry;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Agreements\AgreementManagementService;
use App\Services\FieldService\EquipmentMaintenanceScheduler;
use App\Services\FieldService\FieldServiceJobTransitionService;

beforeEach(function (): void {
    $this->withoutVite();
});

test('collins agreement is immutable idempotent and contains priced field operations scope', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    $service = app(AgreementManagementService::class);
    $first = $service->prepareCollinsElectric($tenant, null);
    $second = $service->prepareCollinsElectric($tenant, null);
    $cards = collect($first->currentVersion->pricing_payload['cards'])->keyBy('key');

    expect($second->id)->toBe($first->id)
        ->and($first->template_key)->toBe(Agreement::TEMPLATE_COLLINS_ELECTRIC_CLIENT_SERVICES)
        ->and($first->versions()->count())->toBe(1)
        ->and($cards['everbranch_onboarding']['amount_cents'])->toBe(29900)
        ->and($cards['everbranch_launch_partner']['amount_cents'])->toBe(5900)
        ->and($cards['everbranch_standard']['amount_cents'])->toBe(14900)
        ->and($first->currentVersion->rendered_content)->toContain('Work already completed')
        ->and($first->currentVersion->rendered_content)->toContain('Payroll-hours tracking')
        ->and($first->currentVersion->rendered_content)->toContain('Equipment maintenance')
        ->and($first->currentVersion->rendered_content)->toContain('collinselectric91@gmail.com')
        ->and($first->status)->toBe('draft');
});

test('equipment scanner creates one visible maintenance job and task while unverified sms stays blocked', function (): void {
    [$tenant, $user] = collinsOperationsTenant();
    $customer = MarketingProfile::query()->create(['tenant_id' => $tenant->id, 'first_name' => 'Sam', 'last_name' => 'Owner', 'phone' => '+17045550100', 'normalized_phone' => '+17045550100', 'accepts_sms_marketing' => true]);
    $equipment = CustomerEquipment::query()->create([
        'tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'assigned_user_id' => $user->id,
        'equipment_type' => 'generator', 'name' => 'Generac 22kW', 'maintenance_interval_days' => 180,
        'installed_at' => now()->subYear(), 'next_service_due_at' => now()->addDays(5), 'status' => 'active',
    ]);
    FieldServiceReminderSetting::query()->create(['tenant_id' => $tenant->id, 'enabled' => true, 'channel' => 'sms', 'provider_status' => 'not_verified']);

    $first = app(EquipmentMaintenanceScheduler::class)->scanTenant($tenant);
    $second = app(EquipmentMaintenanceScheduler::class)->scanTenant($tenant);
    $job = FieldServiceJob::query()->where('customer_equipment_id', $equipment->id)->firstOrFail();

    expect($first['jobs_created'])->toBe(1)
        ->and($first['alerts']['customer_sms_sent'])->toBe(0)
        ->and($first['alerts']['customer_sms_blocked'])->toBe(1)
        ->and($second['jobs_created'])->toBe(0)
        ->and(FieldServiceJob::query()->where('customer_equipment_id', $equipment->id)->count())->toBe(1)
        ->and(FieldServiceTask::query()->where('field_service_job_id', $job->id)->count())->toBe(1);

    app(FieldServiceJobTransitionService::class)->transition($tenant, $job, $user, 'complete');
    expect($equipment->fresh()->last_serviced_at?->toDateString())->toBe(now()->toDateString())
        ->and($equipment->fresh()->next_service_due_at?->toDateString())->toBe(now()->addDays(180)->toDateString());
});

test('payroll hours submit review and export remain tenant and manager scoped', function (): void {
    [$tenant, $user] = collinsOperationsTenant('collins-hours');
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'assigned_user_id' => $user->id, 'title' => 'Panel service', 'status' => 'open', 'operational_status' => 'active']);

    $this->actingAs($user)->post(route('field-service.payroll-hours.store', ['tenant' => $tenant->slug]), [
        'user_id' => $user->id, 'field_service_job_id' => $job->id, 'work_date' => now()->toDateString(),
        'started_at' => '08:00', 'ended_at' => '16:30', 'break_minutes' => 30, 'notes' => 'Service and cleanup',
    ])->assertRedirect();
    $entry = FieldServiceTimeEntry::query()->firstOrFail();
    expect($entry->duration_minutes)->toBe(480)->and($entry->status)->toBe('submitted');

    $this->actingAs($user)->post(route('field-service.payroll-hours.review', ['tenant' => $tenant->slug, 'timeEntry' => $entry]), ['status' => 'approved'])->assertRedirect();
    expect($entry->fresh()->status)->toBe('approved');
    $this->actingAs($user)->get(route('field-service.payroll-hours.export', ['tenant' => $tenant->slug]))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('written consent import opts in phone customers with evidence and preserves explicit opt outs', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    $eligible = MarketingProfile::query()->create(['tenant_id' => $tenant->id, 'first_name' => 'Eligible', 'phone' => '+17045550101', 'normalized_phone' => '+17045550101', 'accepts_sms_marketing' => false]);
    $optedOut = MarketingProfile::query()->create(['tenant_id' => $tenant->id, 'first_name' => 'Stopped', 'phone' => '+17045550102', 'normalized_phone' => '+17045550102', 'accepts_sms_marketing' => false, 'sms_opted_out_at' => now()->subDay()]);
    MarketingProfile::query()->create(['tenant_id' => $tenant->id, 'first_name' => 'No Phone', 'accepts_sms_marketing' => false]);

    $this->artisan('collins-electric:import-written-sms-consent', [
        '--confirm-written-consent' => true,
        '--source-reference' => 'Written consent file retained by Collins Electric; owner confirmed 2026-07-20.',
    ])->assertSuccessful();

    expect($eligible->fresh()->accepts_sms_marketing)->toBeTrue()
        ->and($optedOut->fresh()->accepts_sms_marketing)->toBeFalse()
        ->and(MarketingConsentEvent::query()->where('marketing_profile_id', $eligible->id)->where('source_type', 'written_consent_import')->exists())->toBeTrue();
});

/** @return array{0:Tenant,1:User} */
function collinsOperationsTenant(string $slug = 'collins-electric-operations'): array
{
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => $slug]);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleState::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'equipment_maintenance', 'enabled_override' => true, 'setup_status' => 'configured']);
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    return [$tenant, $user];
}
