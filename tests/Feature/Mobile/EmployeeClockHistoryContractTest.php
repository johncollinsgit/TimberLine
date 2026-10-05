<?php

use App\Models\FieldServiceJob;
use App\Models\FieldServiceReminderSetting;
use App\Models\FieldServiceTimeChangeRequest;
use App\Models\FieldServiceTimeEntry;
use App\Models\FieldServiceTimeSession;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

test('employee clock history contains only their own workspace time and matches the build 17 contract', function (): void {
    $this->travelTo(Carbon::parse('2026-10-05 12:00:00', 'America/New_York'));
    $tenant = Tenant::query()->create(['name' => 'Field History', 'slug' => 'field-history']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'time_tracking', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    FieldServiceReminderSetting::query()->create(['tenant_id' => $tenant->id, 'timezone' => 'America/New_York']);
    $employee = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $other->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Panel installation', 'status' => 'open']);
    $session = FieldServiceTimeSession::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'user_id' => $employee->id,
        'client_uuid' => '10000000-0000-4000-8000-000000000001', 'status' => 'submitted',
        'clocked_in_at' => Carbon::parse('2026-10-05 09:00:00', 'America/New_York'),
        'clocked_out_at' => Carbon::parse('2026-10-05 11:00:00', 'America/New_York'),
        'break_seconds' => 1800, 'duration_seconds' => 5400,
    ]);
    FieldServiceTimeChangeRequest::query()->create([
        'tenant_id' => $tenant->id, 'field_service_time_session_id' => $session->id,
        'requested_by_user_id' => $employee->id, 'status' => 'pending', 'reason' => 'Start time needs review',
        'before_snapshot' => [], 'requested_snapshot' => [],
    ]);
    FieldServiceTimeEntry::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'user_id' => $employee->id,
        'work_date' => '2026-10-05', 'started_at' => '13:00', 'ended_at' => '14:00',
        'break_minutes' => 0, 'duration_minutes' => 60, 'status' => 'approved',
    ]);
    FieldServiceTimeSession::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'user_id' => $other->id,
        'client_uuid' => '10000000-0000-4000-8000-000000000002', 'status' => 'submitted',
        'clocked_in_at' => Carbon::parse('2026-10-05 08:00:00', 'America/New_York'),
        'clocked_out_at' => Carbon::parse('2026-10-05 10:00:00', 'America/New_York'),
        'duration_seconds' => 7200,
    ]);
    FieldServiceTimeEntry::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'user_id' => $employee->id,
        'work_date' => '2026-10-05', 'started_at' => '15:00', 'ended_at' => '16:00',
        'break_minutes' => 0, 'duration_minutes' => 60, 'status' => 'rejected',
    ]);

    Sanctum::actingAs($employee, ['mobile:read']);
    $base = '/api/mobile/v1/workspaces/field-history/field-service/clock/history';
    $this->getJson($base)->assertOk()
        ->assertJsonPath('contract_version', 1)
        ->assertJsonPath('period', 'day')
        ->assertJsonPath('range.timezone', 'America/New_York')
        ->assertJsonPath('total_seconds', 9000)
        ->assertJsonPath('by_day.0.total_seconds', 9000)
        ->assertJsonCount(3, 'entries')
        ->assertJsonFragment(['source' => 'timer', 'id' => $session->id, 'correction_status' => 'pending'])
        ->assertJsonFragment(['source' => 'manual', 'started_at' => '2026-10-05T13:00:00-04:00'])
        ->assertDontSee($other->name);
    $this->getJson($base.'?period=week')->assertOk()
        ->assertJsonPath('range.start_date', '2026-10-05')
        ->assertJsonPath('range.end_date', '2026-10-11')
        ->assertJsonPath('total_seconds', 9000);
    $this->getJson($base.'?period=month')->assertOk()
        ->assertJsonPath('range.start_date', '2026-10-01')
        ->assertJsonPath('range.end_date', '2026-10-31')
        ->assertJsonPath('total_seconds', 9000);
    $this->getJson($base.'?period=day&offset=1')->assertOk()->assertJsonPath('total_seconds', 0)->assertJsonCount(0, 'entries');
    $this->getJson($base.'?period=year')->assertUnprocessable();
});
