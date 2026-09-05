<?php

use App\Models\FieldServiceJob;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceReminderSetting;
use App\Models\FieldServiceTimeBreak;
use App\Models\FieldServiceTimeEntry;
use App\Models\FieldServiceTimeSession;
use App\Models\FieldServiceVehicle;
use App\Models\LandlordOperatorAction;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/** @return array{Tenant,User,User} */
function mobileTimeHoursWorkspace(string $suffix = 'primary'): array
{
    $tenant = Tenant::query()->create(['name' => 'Time Hours '.$suffix, 'slug' => 'time-hours-'.$suffix]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    foreach (['field_service', 'time_tracking'] as $module) {
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
    FieldServiceReminderSetting::query()->create(['tenant_id' => $tenant->id, 'timezone' => 'America/New_York']);
    $manager = User::factory()->create(['is_active' => true]);
    $employee = User::factory()->create(['is_active' => true]);
    $manager->tenants()->attach($tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);

    return [$tenant, $manager, $employee];
}

test('mobile managers receive tenant scoped time analytics and a unified paginated ledger', function (): void {
    [$tenant, $manager, $employee] = mobileTimeHoursWorkspace('analytics');
    $inactiveEmployee = User::factory()->create(['is_active' => false]);
    $inactiveEmployee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'assigned_user_id' => $employee->id, 'title' => 'Panel replacement', 'status' => 'open', 'operational_status' => 'active']);
    FieldServiceTimeSession::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $job->id,
        'user_id' => $employee->id,
        'client_uuid' => '11111111-1111-4111-8111-111111111111',
        'status' => 'approved',
        'clocked_in_at' => Carbon::parse('2026-09-02 13:00:00 UTC'),
        'clocked_out_at' => Carbon::parse('2026-09-02 16:00:00 UTC'),
        'break_seconds' => 900,
        'duration_seconds' => 9900,
        'source' => 'mobile',
        'reviewed_at' => now(),
    ]);
    FieldServiceTimeSession::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $job->id,
        'user_id' => $employee->id,
        'client_uuid' => '22222222-2222-4222-8222-222222222222',
        'active_user_key' => $employee->id,
        'status' => 'running',
        'clocked_in_at' => Carbon::parse('2026-09-03 13:00:00 UTC'),
        'break_seconds' => 0,
        'source' => 'mobile',
    ]);
    FieldServiceTimeEntry::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $job->id,
        'user_id' => $employee->id,
        'work_date' => '2026-09-02',
        'started_at' => '08:00:00',
        'ended_at' => '10:30:00',
        'break_minutes' => 30,
        'duration_minutes' => 120,
        'status' => 'submitted',
    ]);

    Sanctum::actingAs($manager, ['mobile:read', 'mobile:write']);
    $response = $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/time-clock-hours?range=custom&start_date=2026-09-01&end_date=2026-09-03&per_page=10');

    $response->assertOk()
        ->assertJsonPath('contract_version', 1)
        ->assertJsonPath('range.timezone', 'America/New_York')
        ->assertJsonPath('summary.total_seconds', 17100)
        ->assertJsonPath('summary.approved_seconds', 9900)
        ->assertJsonPath('summary.submitted_seconds', 7200)
        ->assertJsonPath('summary.active_count', 1)
        ->assertJsonPath('summary.entry_count', 3)
        ->assertJsonPath('summary.average_shift_seconds', 8550)
        ->assertJsonPath('by_employee.0.user.id', $employee->id)
        ->assertJsonPath('by_job.0.job.id', $job->id)
        ->assertJsonPath('entries.total', 3)
        ->assertJsonCount(3, 'entries.data')
        ->assertJsonPath('edit_options.jobs.0.id', $job->id);
    expect(collect($response->json('edit_options.employees'))->pluck('id'))
        ->toContain($manager->id, $employee->id)
        ->not->toContain($inactiveEmployee->id);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/time-clock-hours?range=custom&start_date=2025-01-01&end_date=2026-09-03')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_date');

    Sanctum::actingAs($employee, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/time-clock-hours?range=custom&start_date=2026-09-01&end_date=2026-09-03&employee_id='.$manager->id)
        ->assertOk()
        ->assertJsonPath('scope', 'my_hours')
        ->assertJsonPath('summary.total_seconds', 17100)
        ->assertJsonPath('by_employee.0.user.id', $employee->id)
        ->assertJsonPath('edit_options.employees', []);

    TenantModuleEntitlement::query()->forTenantId((int) $tenant->id)->where('module_key', 'time_tracking')->update(['enabled_status' => 'disabled']);
    Sanctum::actingAs($manager, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/time-clock-hours?range=week')->assertForbidden();
});

test('mobile managers can drill into one employee time ledger without mixing other employees', function (): void {
    [$tenant, $manager, $employee] = mobileTimeHoursWorkspace('employee-drilldown');
    $otherEmployee = User::factory()->create(['is_active' => true]);
    $otherEmployee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'assigned_user_id' => $employee->id, 'title' => 'Employee drilldown job', 'status' => 'open', 'operational_status' => 'active']);

    foreach ([[$employee, 7200, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'], [$otherEmployee, 3600, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']] as [$worker, $duration, $uuid]) {
        FieldServiceTimeSession::query()->create([
            'tenant_id' => $tenant->id,
            'field_service_job_id' => $job->id,
            'user_id' => $worker->id,
            'client_uuid' => $uuid,
            'status' => 'submitted',
            'clocked_in_at' => Carbon::parse('2026-09-02 13:00:00 UTC'),
            'clocked_out_at' => Carbon::parse('2026-09-02 15:00:00 UTC'),
            'break_seconds' => 0,
            'duration_seconds' => $duration,
            'source' => 'mobile',
        ]);
    }

    Sanctum::actingAs($manager, ['mobile:read', 'mobile:write']);
    $response = $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/time-clock-hours?range=custom&start_date=2026-09-01&end_date=2026-09-03&employee_id='.$employee->id);

    $response->assertOk()
        ->assertJsonPath('summary.total_seconds', 7200)
        ->assertJsonCount(1, 'by_employee')
        ->assertJsonPath('by_employee.0.user.id', $employee->id)
        ->assertJsonPath('entries.total', 1)
        ->assertJsonPath('entries.data.0.user.id', $employee->id);
});

test('job hourly analytics automatically include new members and never expose another tenant or employee', function (): void {
    Carbon::setTestNow('2026-09-04 16:00:00 UTC');
    [$tenant, $manager, $employee] = mobileTimeHoursWorkspace('job-hours');
    [$otherTenant, $otherManager] = mobileTimeHoursWorkspace('job-hours-other');
    $newEmployee = User::factory()->create(['is_active' => true]);
    $newEmployee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $employee->id,
        'title' => 'Live crew job',
        'status' => 'open',
        'operational_status' => 'active',
    ]);
    $job->participants()->attach($newEmployee->id, ['tenant_id' => $tenant->id]);
    $otherJob = FieldServiceJob::query()->create(['tenant_id' => $otherTenant->id, 'title' => 'Private other tenant', 'status' => 'open']);
    foreach ([[$employee, 3600, '66666666-6666-4666-8666-666666666666'], [$newEmployee, 7200, '77777777-7777-4777-8777-777777777777']] as [$worker, $seconds, $uuid]) {
        FieldServiceTimeSession::query()->create([
            'tenant_id' => $tenant->id,
            'field_service_job_id' => $job->id,
            'user_id' => $worker->id,
            'client_uuid' => $uuid,
            'status' => 'submitted',
            'clocked_in_at' => now()->subSeconds($seconds),
            'clocked_out_at' => now(),
            'break_seconds' => 0,
            'duration_seconds' => $seconds,
            'source' => 'mobile',
        ]);
    }

    Sanctum::actingAs($manager, ['mobile:read']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/hours?range=week')
        ->assertOk()
        ->assertJsonPath('scope', 'job')
        ->assertJsonPath('summary.total_seconds', 10800)
        ->assertJsonCount(2, 'by_employee');
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$otherJob->id.'/hours?range=week')->assertNotFound();

    Sanctum::actingAs($employee, ['mobile:read']);
    $employeeResponse = $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/hours?range=week')
        ->assertOk()
        ->assertJsonPath('scope', 'my_hours')
        ->assertJsonPath('summary.total_seconds', 3600)
        ->assertJsonCount(1, 'by_employee');
    expect($employeeResponse->json('by_employee.0.user.id'))->toBe($employee->id);

    Sanctum::actingAs($otherManager, ['mobile:read']);
    $this->getJson('/api/mobile/v1/workspaces/'.$otherTenant->slug.'/field-service/jobs/'.$job->id.'/hours?range=week')->assertNotFound();
    Carbon::setTestNow();
});

test('mobile managers edit completed timer and manual submissions with recomputed durations and audit evidence', function (): void {
    [$tenant, $manager, $employee] = mobileTimeHoursWorkspace('editing');
    [$otherTenant, $otherManager, $otherEmployee] = mobileTimeHoursWorkspace('other');
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Service call', 'status' => 'open']);
    $otherJob = FieldServiceJob::query()->create(['tenant_id' => $otherTenant->id, 'title' => 'Other tenant job', 'status' => 'open']);
    $timer = FieldServiceTimeSession::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'user_id' => $employee->id,
        'client_uuid' => '33333333-3333-4333-8333-333333333333', 'status' => 'submitted',
        'clocked_in_at' => Carbon::parse('2026-09-02 12:00:00 UTC'), 'clocked_out_at' => Carbon::parse('2026-09-02 14:00:00 UTC'),
        'break_seconds' => 0, 'duration_seconds' => 7200, 'source' => 'mobile',
    ]);
    $rawBreak = FieldServiceTimeBreak::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_time_session_id' => $timer->id,
        'client_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'started_at' => Carbon::parse('2026-09-02 12:30:00 UTC'),
        'ended_at' => Carbon::parse('2026-09-02 12:35:00 UTC'),
        'duration_seconds' => 300,
    ]);
    $active = FieldServiceTimeSession::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'user_id' => $employee->id,
        'client_uuid' => '44444444-4444-4444-8444-444444444444', 'active_user_key' => $employee->id, 'status' => 'running',
        'clocked_in_at' => Carbon::parse('2026-09-03 12:00:00 UTC'), 'break_seconds' => 0, 'source' => 'mobile',
    ]);
    $manual = FieldServiceTimeEntry::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'user_id' => $employee->id,
        'work_date' => '2026-09-02', 'started_at' => '08:00:00', 'ended_at' => '10:00:00',
        'break_minutes' => 0, 'duration_minutes' => 120, 'status' => 'submitted',
    ]);
    $otherTimer = FieldServiceTimeSession::query()->create([
        'tenant_id' => $otherTenant->id, 'field_service_job_id' => $otherJob->id, 'user_id' => $otherEmployee->id,
        'client_uuid' => '55555555-5555-4555-8555-555555555555', 'status' => 'submitted',
        'clocked_in_at' => now()->subHours(2), 'clocked_out_at' => now(), 'break_seconds' => 0, 'duration_seconds' => 7200, 'source' => 'mobile',
    ]);

    Sanctum::actingAs($manager, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/time-clock-hours';
    $this->patchJson($base.'/timer/'.$timer->id, [
        'started_at' => '2026-09-02T08:00:00-04:00',
        'ended_at' => '2026-09-02T10:00:00-04:00',
        'break_seconds' => 600,
        'status' => 'approved',
        'notes' => 'Corrected from dispatch record.',
    ])->assertOk()->assertJsonPath('entry.duration_seconds', 6600)->assertJsonPath('entry.status', 'approved');
    expect($timer->fresh()->reviewed_by_user_id)->toBe($manager->id)
        ->and($timer->fresh()->break_seconds)->toBe(600)
        ->and($rawBreak->fresh()->duration_seconds)->toBe(300);

    FieldServiceTimeSession::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'user_id' => $manager->id,
        'client_uuid' => $timer->client_uuid, 'status' => 'submitted',
        'clocked_in_at' => Carbon::parse('2026-09-01 12:00:00 UTC'), 'clocked_out_at' => Carbon::parse('2026-09-01 13:00:00 UTC'),
        'break_seconds' => 0, 'duration_seconds' => 3600, 'source' => 'mobile',
    ]);
    $this->patchJson($base.'/timer/'.$timer->id, ['user_id' => $manager->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');

    $this->patchJson($base.'/manual/'.$manual->id, [
        'started_at' => '2026-09-02T08:00:00-04:00',
        'ended_at' => '2026-09-02T12:00:00-04:00',
        'break_seconds' => 1800,
        'status' => 'approved',
    ])->assertOk()->assertJsonPath('entry.duration_seconds', 12600);
    expect($manual->fresh()->duration_minutes)->toBe(210)
        ->and(LandlordOperatorAction::query()->forTenantId((int) $tenant->id)->where('action_type', 'field_service.time_hours.updated')->count())->toBe(2);
    $timerAudit = LandlordOperatorAction::query()->forTenantId((int) $tenant->id)
        ->where('action_type', 'field_service.time_hours.updated')
        ->where('target_type', 'field_service_time_session')
        ->sole();
    expect($timerAudit->context['raw_break_events_preserved'])->toBeTrue()
        ->and($timerAudit->before_state['raw_break_seconds'])->toBe(300)
        ->and($timerAudit->after_state['raw_break_seconds'])->toBe(300);

    $this->patchJson($base.'/timer/'.$active->id, ['status' => 'approved'])->assertUnprocessable();
    $this->patchJson($base.'/timer/'.$otherTimer->id, ['status' => 'approved'])->assertNotFound();
    $this->patchJson($base.'/manual/'.$manual->id, ['job_id' => $otherJob->id])->assertUnprocessable();
    $this->patchJson($base.'/manual/'.$manual->id, ['user_id' => $otherEmployee->id])->assertUnprocessable();
});

test('material requests carry their requester onto manager home and managers can purchase or delete them', function (): void {
    [$tenant, $manager, $employee] = mobileTimeHoursWorkspace('materials');
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $employee->id,
        'title' => 'Rough-in materials',
        'status' => 'open',
        'operational_status' => 'active',
    ]);
    $materialsUrl = '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/materials';

    Sanctum::actingAs($employee, ['mobile:read', 'mobile:write']);
    $firstId = $this->postJson($materialsUrl.'/requests', ['name' => '12/2 cable', 'quantity' => 250, 'unit' => 'ft'])
        ->assertCreated()
        ->assertJsonPath('material.requester.id', $employee->id)
        ->assertJsonPath('material.is_request', true)
        ->assertJsonPath('material.can_delete', true)
        ->json('material.id');
    expect(FieldServiceMaterial::query()->findOrFail($firstId)->requested_by_user_id)->toBe($employee->id);

    Sanctum::actingAs($manager, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/my-day')
        ->assertOk()
        ->assertJsonPath('counts.material_requests', 1)
        ->assertJsonPath('requested_materials.0.id', $firstId)
        ->assertJsonPath('requested_materials.0.requester.id', $employee->id)
        ->assertJsonPath('requested_materials.0.destination.tab', 'materials');
    $this->patchJson($materialsUrl.'/'.$firstId, ['status' => 'purchased', 'notes' => 'On order for Friday'])
        ->assertOk()->assertJsonPath('material.status', 'purchased');
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/my-day')
        ->assertOk()->assertJsonPath('counts.material_requests', 0)->assertJsonCount(0, 'requested_materials');

    Sanctum::actingAs($employee, ['mobile:read', 'mobile:write']);
    $secondId = $this->postJson($materialsUrl.'/requests', ['name' => 'Two-pole breaker'])->assertCreated()->json('material.id');
    $this->deleteJson($materialsUrl.'/'.$secondId)->assertForbidden();
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/my-day')
        ->assertOk()->assertJsonCount(0, 'requested_materials');

    Sanctum::actingAs($manager, ['mobile:read', 'mobile:write']);
    $this->deleteJson($materialsUrl.'/'.$secondId)->assertNoContent();
    expect(FieldServiceMaterial::query()->find($secondId))->toBeNull();
    $audit = LandlordOperatorAction::query()->forTenantId((int) $tenant->id)->where('action_type', 'field_service.material_request.deleted')->sole();
    expect($audit->before_state['name'])->toBe('Two-pole breaker');

    $importedMaterial = FieldServiceMaterial::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $job->id,
        'name' => 'Imported material',
        'quantity' => 1,
        'status' => 'needed',
        'external_source' => 'quickbooks',
        'external_id' => 'material-1',
    ]);
    $this->deleteJson($materialsUrl.'/'.$importedMaterial->id)->assertUnprocessable();
    expect($importedMaterial->fresh())->not->toBeNull();
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id)
        ->assertOk()
        ->assertJsonFragment([
            'id' => $importedMaterial->id,
            'name' => 'Imported material',
            'is_request' => false,
            'can_delete' => false,
        ]);

    foreach (range(1, 26) as $number) {
        FieldServiceMaterial::query()->create([
            'tenant_id' => $tenant->id,
            'field_service_job_id' => $job->id,
            'requested_by_user_id' => $employee->id,
            'name' => 'Pending material '.$number,
            'quantity' => 1,
            'status' => 'needed',
        ]);
    }
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/my-day')
        ->assertOk()->assertJsonPath('counts.material_requests', 26)->assertJsonCount(25, 'requested_materials');
});

test('manager job edit options expose only tenant vehicles and job changes are audited without lock box values', function (): void {
    [$tenant, $manager, $employee] = mobileTimeHoursWorkspace('vehicles');
    [$otherTenant] = mobileTimeHoursWorkspace('vehicles-other');
    $inactiveEmployee = User::factory()->create(['is_active' => false]);
    $inactiveEmployee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $vehicle = FieldServiceVehicle::query()->create(['tenant_id' => $tenant->id, 'name' => 'Van 4', 'identifier' => 'VAN-4', 'status' => 'active']);
    $otherVehicle = FieldServiceVehicle::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'Other van', 'identifier' => 'OTHER', 'status' => 'active']);
    $otherJob = FieldServiceJob::query()->create(['tenant_id' => $otherTenant->id, 'title' => 'Other job', 'status' => 'open']);
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Original job',
        'status' => 'open',
        'lock_box_code' => 'old-code',
        'scheduled_for' => '2026-09-04 14:00:00',
        'scheduled_end_at' => '2026-09-04 16:00:00',
    ]);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service';

    Sanctum::actingAs($manager, ['mobile:read', 'mobile:write']);
    $team = $this->getJson($base.'/team')
        ->assertOk()
        ->assertJsonPath('vehicles.0.id', $vehicle->id);
    expect(collect($team->json('vehicles'))->pluck('id'))->not->toContain($otherVehicle->id);
    expect(collect($team->json('members'))->pluck('id'))->not->toContain($inactiveEmployee->id);
    $this->patchJson($base.'/jobs/'.$job->id, [
        'title' => 'Updated job',
        'project_manager_name' => 'Alex Builder',
        'project_manager_company' => 'Builder Co',
        'lock_box_code' => 'new-code',
        'assigned_user_id' => $employee->id,
        'participant_user_ids' => [$employee->id],
        'vehicle_ids' => [$vehicle->id],
    ])->assertOk();
    expect($job->fresh()->project_manager_name)->toBe('Alex Builder')
        ->and($job->fresh()->vehicles()->whereKey($vehicle->id)->exists())->toBeTrue();
    $audit = LandlordOperatorAction::query()->forTenantId((int) $tenant->id)->where('action_type', 'field_service.job.updated')->sole();
    expect($audit->before_state['lock_box_code_present'])->toBeTrue()
        ->and($audit->after_state['lock_box_code_present'])->toBeTrue()
        ->and(json_encode($audit->before_state))->not->toContain('old-code')
        ->and(json_encode($audit->after_state))->not->toContain('new-code')
        ->and($audit->context['lock_box_code_changed'])->toBeTrue();

    $this->patchJson($base.'/jobs/'.$job->id, ['vehicle_ids' => [$otherVehicle->id]])->assertUnprocessable();
    expect($job->fresh()->vehicles()->whereKey($vehicle->id)->exists())->toBeTrue();
    $this->patchJson($base.'/jobs/'.$otherJob->id, ['title' => 'Leaked edit'])->assertNotFound();
    $this->patchJson($base.'/jobs/'.$job->id, ['assigned_user_id' => $inactiveEmployee->id])->assertUnprocessable();
    $this->patchJson($base.'/jobs/'.$job->id, ['scheduled_for' => '2026-09-04T12:00:00-04:00'])->assertUnprocessable();
    expect(LandlordOperatorAction::query()->forTenantId((int) $tenant->id)->where('action_type', 'field_service.job.updated')->count())->toBe(1);

    Sanctum::actingAs($employee, ['mobile:read']);
    $this->getJson($base.'/team')->assertOk()->assertJsonCount(0, 'vehicles');
});
