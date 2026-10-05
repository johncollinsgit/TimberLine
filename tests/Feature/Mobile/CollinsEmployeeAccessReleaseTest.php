<?php

use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

test('employees can create a private group only with active teammates in their workspace', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Field Messages', 'slug' => 'field-messages']);
    $other = Tenant::query()->create(['name' => 'Other Team', 'slug' => 'other-team']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $creator = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $teammate = User::factory()->create(['is_active' => true]);
    $inactive = User::factory()->create(['is_active' => true]);
    $deactivated = User::factory()->create(['is_active' => false]);
    $outsider = User::factory()->create(['is_active' => true]);
    foreach ([$creator, $teammate] as $user) {
        $user->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    }
    $inactive->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => false]);
    $deactivated->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $outsider->tenants()->attach($other->id, ['role' => 'member', 'membership_active' => true]);
    $base = '/api/mobile/v1/workspaces/field-messages/field-service/channels';
    Sanctum::actingAs($creator, ['mobile:read', 'mobile:write']);
    $this->getJson($base)->assertOk()->assertJsonFragment(['id' => $teammate->id, 'name' => $teammate->name, 'role' => 'member'])
        ->assertDontSee($inactive->name)->assertDontSee($deactivated->name)->assertDontSee($outsider->name);
    $this->postJson($base.'/group', ['name' => 'Service crew', 'member_ids' => [$inactive->id]])->assertUnprocessable();
    $this->postJson($base.'/group', ['name' => 'Service crew', 'member_ids' => [$deactivated->id]])->assertUnprocessable();
    $this->postJson($base.'/group', ['name' => 'Service crew', 'member_ids' => [$outsider->id]])->assertUnprocessable();
    $this->postJson($base.'/direct', ['user_id' => $deactivated->id])->assertNotFound();
    $channelId = $this->postJson($base.'/group', ['name' => 'Service crew', 'member_ids' => [$teammate->id]])
        ->assertCreated()->assertJsonPath('channel.kind', 'group')->json('channel.id');
    $this->postJson($base.'/'.$channelId.'/messages', ['body' => 'Bring the drawings.', 'client_uuid' => '22222222-2222-4222-8222-222222222222'])->assertCreated();
    Sanctum::actingAs($teammate, ['mobile:read', 'mobile:write']);
    $this->getJson($base)->assertOk()->assertJsonFragment(['id' => $channelId, 'unread_count' => 1])
        ->assertJsonFragment(['preview' => 'Bring the drawings.', 'author_name' => $creator->name]);
    $this->getJson($base.'/'.$channelId)->assertOk()->assertJsonPath('messages.0.body', 'Bring the drawings.')
        ->assertJsonPath('channel.unread_count', 0);
    $this->getJson($base)->assertOk()->assertJsonFragment(['id' => $channelId, 'unread_count' => 0]);
    $this->postJson($base.'/'.$channelId.'/unread')->assertOk();
    $this->getJson($base)->assertOk()->assertJsonFragment(['id' => $channelId, 'unread_count' => 1]);
    Sanctum::actingAs($outsider, ['mobile:read', 'mobile:write']);
    $this->getJson($base.'/'.$channelId)->assertNotFound();
    $this->postJson($base.'/'.$channelId.'/unread')->assertNotFound();
});

test('an employee sees assigned upcoming jobs and can clock only assigned current work', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Crew Schedule', 'slug' => 'crew-schedule']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test', 'metadata' => ['member_job_visibility' => 'all_operational']]);
    $employee = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $assigned = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'assigned_user_id' => $employee->id, 'title' => 'Assigned tomorrow', 'status' => 'open', 'operational_status' => 'scheduled', 'scheduled_for' => now()->addDay()]);
    $unassigned = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Another crew tomorrow', 'status' => 'open', 'operational_status' => 'scheduled', 'scheduled_for' => now()->addDay()]);
    Sanctum::actingAs($employee, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/crew-schedule/field-service';
    $this->getJson($base.'/my-day')->assertOk()->assertJsonPath('upcoming_jobs.0.id', $assigned->id)
        ->assertJsonCount(1, 'upcoming_jobs')->assertJsonPath('owner_metrics', null);
    $this->getJson($base.'?view=list&filter=active&bucket=current')->assertOk()->assertSee('Another crew tomorrow');
    $this->getJson($base.'/jobs/'.$unassigned->id)->assertOk()->assertJsonPath('job.can_clock', false)->assertJsonPath('job.financials', []);
    $this->withHeader('Accept', 'application/json')->post($base.'/jobs/'.$unassigned->id.'/photos', ['photos' => [UploadedFile::fake()->image('other.jpg')]])->assertForbidden();
    $this->withHeader('Idempotency-Key', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')->postJson($base.'/uploads/initialize', [
        'file_name' => 'other.pdf', 'mime_type' => 'application/pdf', 'file_size' => 128, 'job_id' => $unassigned->id,
    ])->assertForbidden();
    $this->postJson($base.'/clock/start', ['job_id' => $unassigned->id, 'client_uuid' => '33333333-3333-4333-8333-333333333333'])->assertNotFound();
    $this->postJson($base.'/clock/start', ['job_id' => $assigned->id, 'client_uuid' => '44444444-4444-4444-8444-444444444444'])->assertCreated()->assertJsonPath('timer.status', 'running');
    $this->postJson($base.'/clock/pause', ['client_uuid' => '55555555-5555-4555-8555-555555555555'])->assertOk()->assertJsonPath('timer.status', 'paused');
    $this->postJson($base.'/clock/resume', ['client_uuid' => '66666666-6666-4666-8666-666666666666'])->assertOk()->assertJsonPath('timer.status', 'running');
    $this->travel(15)->minutes();
    $this->postJson($base.'/clock/stop', ['client_uuid' => '77777777-7777-4777-8777-777777777777'])->assertOk()->assertJsonPath('timer.status', 'submitted');
    $this->getJson($base.'/time-clock-hours?range=week')->assertForbidden();
    $this->getJson($base.'/clock/history')->assertForbidden();
});

test('managers can invite members but cannot grant administrator roles', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Team Roles', 'slug' => 'team-roles']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $manager = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $member = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    $manager->tenants()->attach($tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $member->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $base = '/api/mobile/v1/workspaces/team-roles/employees';
    Sanctum::actingAs($manager, ['mobile:read', 'mobile:write']);
    $this->postJson($base.'/invitations', ['email' => 'newtech@example.com', 'role' => 'member'])->assertCreated()->assertJsonPath('invitation.delivery_status', 'share_link_ready');
    $this->postJson($base.'/invitations', ['email' => 'newboss@example.com', 'role' => 'manager'])->assertForbidden();
    $this->patchJson($base.'/'.$member->id, ['role' => 'admin'])->assertForbidden();
    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $this->patchJson($base.'/'.$member->id, ['role' => 'admin'])->assertOk();
    expect($member->tenants()->whereKey($tenant->id)->firstOrFail()->pivot->role)->toBe('admin');
});
