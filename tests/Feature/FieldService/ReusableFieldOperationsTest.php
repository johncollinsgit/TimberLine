<?php

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceTimeSession;
use App\Models\FieldServiceVehicle;
use App\Models\FieldServiceWorkShift;
use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\FleetTrackingPolicyAcknowledgement;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantFleetTrackingSetting;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\FieldService\FieldServiceTimeClockService;
use App\Services\FieldService\FieldServiceWorkCandidateService;
use App\Services\FieldService\FieldServiceWorkforceService;
use App\Services\FieldService\TeamCommunicationService;
use App\Services\FleetTracking\FleetLocationIngestionService;
use App\Services\Tenancy\TenantEmployeeInvitationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

test('the field clock enforces one active job and reconciles idempotent breaks and stop actions', function (): void {
    [$tenant, $employee] = fieldOperationsWorkspace('clock');
    $first = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'assigned_user_id' => $employee->id, 'title' => 'Panel swap', 'status' => 'open', 'operational_status' => 'active']);
    $second = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'assigned_user_id' => $employee->id, 'title' => 'Service call', 'status' => 'open', 'operational_status' => 'scheduled']);
    $clock = app(FieldServiceTimeClockService::class);

    $session = $clock->start($tenant, $employee, $first, '11111111-1111-4111-8111-111111111111');
    expect($clock->start($tenant, $employee, $first, '11111111-1111-4111-8111-111111111111')->id)->toBe($session->id)
        ->and($session->active_user_key)->toBe($employee->id);
    expect(fn () => $clock->start($tenant, $employee, $second, '22222222-2222-4222-8222-222222222222'))->toThrow(ValidationException::class);

    $this->travel(30)->minutes();
    expect($clock->startBreak($tenant, $employee, '33333333-3333-4333-8333-333333333333')->status)->toBe('paused');
    $this->travel(10)->minutes();
    expect($clock->resume($tenant, $employee, '44444444-4444-4444-8444-444444444444')->status)->toBe('running');
    $this->travel(20)->minutes();
    $stopped = $clock->stop($tenant, $employee, '55555555-5555-4555-8555-555555555555', 'Finished and cleaned up');

    expect($stopped->status)->toBe('submitted')
        ->and($stopped->active_user_key)->toBeNull()
        ->and($stopped->break_seconds)->toBe(600)
        ->and($stopped->duration_seconds)->toBe(3000)
        ->and($stopped->clock_out_notes)->toBe('Finished and cleaned up');
});

test('a tenant can require an assigned shift window before an employee clocks into the assigned job', function (): void {
    [$tenant, $employee] = fieldOperationsWorkspace('scheduled-clock');
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'assigned_user_id' => $employee->id, 'title' => 'Scheduled panel swap', 'status' => 'open', 'operational_status' => 'scheduled']);
    $workforce = app(FieldServiceWorkforceService::class);
    $workforce->settings($tenant)->forceFill(['enforce_scheduled_clocking' => true, 'clock_early_minutes' => 15, 'clock_late_minutes' => 15])->save();
    $clock = app(FieldServiceTimeClockService::class);

    expect(fn () => $clock->start($tenant, $employee, $job, '12345678-1111-4111-8111-111111111111'))->toThrow(ValidationException::class);
    FieldServiceWorkShift::query()->create(['tenant_id' => $tenant->id, 'user_id' => $employee->id, 'field_service_job_id' => $job->id, 'status' => 'scheduled', 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHours(2), 'unpaid_break_minutes' => 30]);

    expect($clock->start($tenant, $employee, $job, '12345678-2222-4222-8222-222222222222')->status)->toBe('running');
});

test('employee timecard corrections retain the requested snapshot and return corrected sessions to review', function (): void {
    [$tenant, $employee] = fieldOperationsWorkspace('time-change');
    $manager = User::factory()->create();
    $manager->tenants()->attach($tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'assigned_user_id' => $employee->id, 'title' => 'Service correction', 'status' => 'open', 'operational_status' => 'active']);
    $clock = app(FieldServiceTimeClockService::class);
    $session = $clock->start($tenant, $employee, $job, '12345678-3333-4333-8333-333333333333');
    $this->travel(2)->hours();
    $session = $clock->stop($tenant, $employee, '12345678-4444-4444-8444-444444444444');
    $workforce = app(FieldServiceWorkforceService::class);
    $change = $workforce->requestSessionCorrection($tenant, $employee, $session, ['clocked_in_at' => $session->clocked_in_at->copy()->subMinutes(15)->toDateTimeString(), 'clocked_out_at' => $session->clocked_out_at->copy()->addMinutes(15)->toDateTimeString(), 'break_minutes' => 15, 'clock_out_notes' => 'Missed break'], 'I missed the first 15 minutes and a break.');
    $resolved = $workforce->resolveSessionCorrection($tenant, $manager, $change, 'approved', 'Reviewed against dispatch notes.');

    expect($resolved->status)->toBe('approved')
        ->and($resolved->before_snapshot['duration_seconds'])->toBe(7200)
        ->and($session->fresh()->status)->toBe('submitted')
        ->and($session->fresh()->break_seconds)->toBe(900)
        ->and($session->fresh()->reviewed_at)->toBeNull();
});

test('a signed Bouncie vehicle event maps only to the configured tenant device and deduplicates retries', function (): void {
    [$tenant] = fieldOperationsWorkspace('bouncie');
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    foreach (['fleet', 'time_tracking', 'fleet_tracking'] as $module) {
        TenantModuleState::query()->create(['tenant_id' => $tenant->id, 'module_key' => $module, 'enabled_override' => true, 'setup_status' => 'configured']);
    }
    $vehicle = FieldServiceVehicle::query()->create(['tenant_id' => $tenant->id, 'name' => 'Service Van', 'status' => 'active']);
    FleetTrackingDevice::query()->create(['tenant_id' => $tenant->id, 'field_service_vehicle_id' => $vehicle->id, 'provider' => 'bouncie', 'external_device_id' => '8675309', 'status' => 'active']);
    TenantFleetTrackingSetting::query()->create(['tenant_id' => $tenant->id, 'bouncie_tracking_enabled' => true, 'policy_version' => '2026-08', 'policy_sha256' => hash('sha256', 'approved policy'), 'counsel_review_reference' => 'Counsel review 2026-08-13', 'legal_reviewed_at' => now(), 'retention_days' => 30]);
    config()->set('services.fleet_tracking.enabled', true);
    config()->set('services.fleet_tracking.bouncie_webhook_key', 'test-bouncie-key');
    $payload = ['id' => 'evt-1', 'type' => 'tripData', 'device' => ['imei' => '8675309'], 'location' => ['latitude' => 35.2271, 'longitude' => -80.8431], 'timestamp' => now()->toIso8601String()];
    $request = Request::create('/webhooks/bouncie', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_BOUNCIE_AUTHORIZATION' => 'test-bouncie-key'], json_encode($payload, JSON_THROW_ON_ERROR));
    $service = app(FleetLocationIngestionService::class);

    expect($service->ingestBouncie($request))->toBe(['accepted' => 1, 'ignored' => 0]);
    expect($service->ingestBouncie($request))->toBe(['accepted' => 1, 'ignored' => 0])
        ->and(FleetLocationPoint::query()->forTenantId($tenant->id)->count())->toBe(1);
});

test('a manager sees only current on-duty locations from their own workspace', function (): void {
    [$tenant, $manager] = fieldOperationsWorkspace('crew-map', 'manager');
    $employee = User::factory()->create(['is_active' => true]);
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    foreach (['fleet', 'time_tracking', 'fleet_tracking'] as $module) {
        TenantModuleState::query()->create(['tenant_id' => $tenant->id, 'module_key' => $module, 'enabled_override' => true, 'setup_status' => 'configured']);
    }
    config()->set('services.fleet_tracking.enabled', true);
    $settings = TenantFleetTrackingSetting::query()->create([
        'tenant_id' => $tenant->id, 'phone_tracking_enabled' => true, 'policy_version' => '2026-09',
        'policy_sha256' => hash('sha256', 'approved policy'), 'counsel_review_reference' => 'Counsel review 2026-09-05',
        'legal_reviewed_at' => now(), 'retention_days' => 30,
    ]);
    FleetTrackingPolicyAcknowledgement::query()->create([
        'tenant_id' => $tenant->id, 'user_id' => $employee->id, 'policy_version' => $settings->policy_version,
        'policy_sha256' => $settings->policy_sha256, 'accepted_at' => now(), 'acceptance_source' => 'mobile',
    ]);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'assigned_user_id' => $employee->id, 'title' => 'Smoke detector trim-out', 'status' => 'open', 'operational_status' => 'active']);
    $session = FieldServiceTimeSession::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'user_id' => $employee->id,
        'client_uuid' => '77777777-7777-4777-8777-777777777777', 'active_user_key' => $employee->id,
        'status' => 'running', 'clocked_in_at' => now()->subHour(), 'break_seconds' => 0, 'source' => 'mobile',
    ]);
    FleetLocationPoint::query()->create([
        'tenant_id' => $tenant->id, 'user_id' => $employee->id, 'field_service_time_session_id' => $session->id,
        'source' => 'mobile', 'event_key' => hash('sha256', 'crew-map-point'), 'latitude' => 34.8526,
        'longitude' => -82.3940, 'accuracy_meters' => 12, 'recorded_at' => now()->subSeconds(15), 'received_at' => now(),
    ]);

    Sanctum::actingAs($manager, ['mobile:read']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/crew-map')
        ->assertOk()
        ->assertJsonPath('tracking.available', true)
        ->assertJsonPath('summary.on_duty', 1)
        ->assertJsonPath('summary.sharing_now', 1)
        ->assertJsonFragment(['id' => $employee->id, 'name' => $employee->name, 'role' => 'member'])
        ->assertJsonFragment(['title' => 'Smoke detector trim-out'])
        ->assertJsonFragment(['freshness' => 'live']);

    $session->forceFill(['status' => 'paused', 'active_user_key' => null])->save();
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/crew-map')
        ->assertOk()->assertJsonPath('summary.on_duty', 0)->assertJsonPath('summary.sharing_now', 0);
});

test('quickbooks estimates and unlinked open invoices enter an explicit tenant review queue', function (): void {
    [$tenant, $owner] = fieldOperationsWorkspace('candidates', 'admin');
    $estimate = FieldServiceFinancialDocument::query()->create(['tenant_id' => $tenant->id, 'source' => 'quickbooks', 'document_type' => 'estimate', 'external_id' => 'EST-1', 'document_number' => '101', 'status' => 'Pending', 'total_amount' => 1250, 'balance' => 1250]);
    FieldServiceFinancialDocument::query()->create(['tenant_id' => $tenant->id, 'source' => 'quickbooks', 'document_type' => 'invoice', 'external_id' => 'INV-PAID', 'status' => 'Paid', 'total_amount' => 200, 'balance' => 0]);
    $service = app(FieldServiceWorkCandidateService::class);

    $pending = $service->pending($tenant);
    expect($pending)->toHaveCount(1)->and($pending->first()->source_type)->toBe('estimate');
    $job = $service->createJob($tenant, $owner, $pending->first());

    expect($job->tenant_id)->toBe($tenant->id)
        ->and($job->operational_status)->toBe('needs_details')
        ->and($estimate->fresh()->field_service_job_id)->toBe($job->id)
        ->and($pending->first()->fresh()->status)->toBe('converted');
});

test('job channels are permission scoped and message client uuids are idempotent', function (): void {
    [$tenant, $manager] = fieldOperationsWorkspace('channels', 'manager');
    $employee = User::factory()->create();
    $outsider = User::factory()->create();
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $outsider->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'assigned_user_id' => $employee->id, 'title' => 'Generator', 'status' => 'open', 'operational_status' => 'active']);
    $team = app(TeamCommunicationService::class);
    $channel = $team->jobChannel($tenant, $employee, $job);
    $uuid = '66666666-6666-4666-8666-666666666666';

    $first = $team->post($tenant, $employee, $channel, 'Bring the transfer switch.', $uuid, [$manager->id]);
    $second = $team->post($tenant, $employee, $channel, 'Duplicate retry', $uuid);

    expect($second->id)->toBe($first->id)->and($channel->messages()->count())->toBe(1);
    expect(fn () => $team->assertAccess($tenant, $outsider, $channel))->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

test('employee invitations are single use and preserve an existing explicit tenant role', function (): void {
    [$tenant, $manager] = fieldOperationsWorkspace('invites', 'manager');
    $employee = User::factory()->create(['email' => 'crew@example.com']);
    $employee->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => false]);
    $service = app(TenantEmployeeInvitationService::class);
    $result = $service->create($tenant, $manager, null, 'crew@example.com', 'member');
    parse_str((string) parse_url($result['invite_url'], PHP_URL_QUERY), $query);

    expect($service->accept($employee, $query['token'])->id)->toBe($tenant->id);
    $membership = $employee->tenants()->whereKey($tenant->id)->firstOrFail()->pivot;
    expect($membership->role)->toBe('admin')->and((bool) $membership->membership_active)->toBeTrue();
    expect(fn () => $service->accept($employee, $query['token']))->toThrow(ValidationException::class);
});

test('the text invitation link authenticates before joining the employee to the workspace', function (): void {
    [$tenant, $manager] = fieldOperationsWorkspace('invite-link', 'manager');
    $employee = User::factory()->create(['email' => 'field-link@example.com']);
    $result = app(TenantEmployeeInvitationService::class)->create($tenant, $manager, null, $employee->email, 'member');
    parse_str((string) parse_url($result['invite_url'], PHP_URL_QUERY), $query);
    $path = '/join-team?token='.$query['token'];

    $this->get($path)->assertRedirect(route('login'));
    $this->actingAs($employee)->get($path)->assertOk()->assertSee('Join '.$tenant->name);
    $this->post(route('employee-invitations.accept'), ['token' => $query['token']])->assertRedirect();

    $membership = $employee->tenants()->whereKey($tenant->id)->firstOrFail()->pivot;
    expect($membership->role)->toBe('member')->and((bool) $membership->membership_active)->toBeTrue();
});

/** @return array{0:Tenant,1:User} */
function fieldOperationsWorkspace(string $suffix, string $role = 'member'): array
{
    $tenant = Tenant::query()->create(['name' => 'Field Team '.$suffix, 'slug' => 'field-team-'.$suffix]);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id, ['role' => $role, 'membership_active' => true]);

    return [$tenant, $user];
}
