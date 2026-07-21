<?php

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceTimeClockService;
use App\Services\FieldService\FieldServiceWorkCandidateService;
use App\Services\FieldService\TeamCommunicationService;
use App\Services\Tenancy\TenantEmployeeInvitationService;
use Illuminate\Validation\ValidationException;

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
