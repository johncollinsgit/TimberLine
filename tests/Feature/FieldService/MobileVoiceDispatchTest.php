<?php

use App\Models\FieldServiceCrewStatus;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNotification;
use App\Models\FieldServiceTimeSession;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/** @return array{Tenant,User,User} */
function voiceDispatchWorkspace(string $suffix): array
{
    $tenant = Tenant::query()->create(['name' => 'Dispatch '.$suffix, 'slug' => 'dispatch-'.$suffix]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test',
    ]);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'included_in_plan', 'entitlement_source' => 'test', 'price_source' => 'catalog',
    ]);
    $manager = User::factory()->create(['is_active' => true]);
    $employee = User::factory()->create(['is_active' => true]);
    $manager->tenants()->attach($tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);

    return [$tenant, $manager, $employee];
}

test('voice drafts use OpenAI transcription and parse electrical material quantities', function (): void {
    [$tenant, , $employee] = voiceDispatchWorkspace('voice');
    config()->set('services.openai.api_key', 'test-key');
    config()->set('services.openai.field_voice_model', 'gpt-transcribe');
    Http::fake(['api.openai.com/*' => Http::response(['text' => '200 feet of 12/2 Romex'], 200)]);
    Sanctum::actingAs($employee, ['mobile:read', 'mobile:write']);

    $this->post('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/voice/transcriptions', [
        'audio' => UploadedFile::fake()->create('job-note.m4a', 80, 'audio/mp4'),
        'context' => 'material_request',
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('transcript', '200 feet of 12/2 Romex')
        ->assertJsonPath('material.name', '12/2 Romex')
        ->assertJsonPath('material.quantity', 200)
        ->assertJsonPath('material.unit', 'ft')
        ->assertJsonPath('review_required', true);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/audio/transcriptions');
});

test('voice drafts fail clearly when transcription is not configured and reject oversized files', function (): void {
    [$tenant, , $employee] = voiceDispatchWorkspace('voice-errors');
    config()->set('services.openai.api_key', null);
    Sanctum::actingAs($employee, ['mobile:read', 'mobile:write']);
    $url = '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/voice/transcriptions';

    $this->post($url, [
        'audio' => UploadedFile::fake()->create('job-note.m4a', 80, 'audio/mp4'), 'context' => 'job_note',
    ], ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('message', 'Voice transcription is not configured yet.');
    $this->post($url, [
        'audio' => UploadedFile::fake()->create('too-large.m4a', 15_361, 'audio/mp4'), 'context' => 'job_note',
    ], ['Accept' => 'application/json'])->assertUnprocessable();
});

test('dispatch is manager only tenant scoped and reflects clocks and field statuses', function (): void {
    [$tenant, $manager, $employee] = voiceDispatchWorkspace('board');
    [$otherTenant, , $otherEmployee] = voiceDispatchWorkspace('board-other');
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id, 'assigned_user_id' => $employee->id, 'title' => 'Service upgrade',
        'customer_name' => 'Homeowner', 'status' => 'open', 'operational_status' => 'active',
    ]);
    FieldServiceJob::query()->create(['tenant_id' => $otherTenant->id, 'assigned_user_id' => $otherEmployee->id, 'title' => 'Private job', 'status' => 'open', 'operational_status' => 'active']);
    FieldServiceTimeSession::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'user_id' => $employee->id,
        'client_uuid' => fake()->uuid(), 'active_user_key' => $employee->id, 'status' => 'running',
        'clocked_in_at' => now()->subHour(), 'break_seconds' => 0, 'source' => 'mobile',
    ]);

    Sanctum::actingAs($employee, ['mobile:read']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/dispatch')->assertForbidden();

    Sanctum::actingAs($manager, ['mobile:read']);
    $response = $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/dispatch')
        ->assertOk()->assertJsonPath('summary.working', 1)->assertJsonPath('summary.unassigned_jobs', 0);
    expect(collect($response->json('crew'))->pluck('user.id'))->toContain($manager->id, $employee->id)->not->toContain($otherEmployee->id);
    expect(collect($response->json('jobs'))->pluck('title'))->toContain('Service upgrade')->not->toContain('Private job');
});

test('dispatch assignment is tenant safe and notifies the new employee with a job destination', function (): void {
    [$tenant, $manager, $employee] = voiceDispatchWorkspace('assign');
    [, , $otherEmployee] = voiceDispatchWorkspace('assign-other');
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id, 'title' => 'Generator hookup', 'status' => 'open', 'operational_status' => 'active',
    ]);
    $url = '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/dispatch/jobs/'.$job->id;
    Sanctum::actingAs($manager, ['mobile:read', 'mobile:write']);

    $this->patchJson($url, ['assigned_user_id' => $otherEmployee->id])->assertUnprocessable();
    $this->patchJson($url, ['assigned_user_id' => $employee->id])->assertOk();
    expect($job->fresh()->assigned_user_id)->toBe($employee->id);
    $notification = FieldServiceJobNotification::query()->forTenantId((int) $tenant->id)
        ->where('user_id', $employee->id)->where('event_type', 'assigned')->firstOrFail();
    expect(data_get($notification->metadata, 'destination.kind'))->toBe('field_service_job')
        ->and((int) data_get($notification->metadata, 'destination.id'))->toBe($job->id);
});

test('employees can publish only authorized job statuses for the dispatch board', function (): void {
    [$tenant, $manager, $employee] = voiceDispatchWorkspace('status');
    [$otherTenant] = voiceDispatchWorkspace('status-other');
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id, 'assigned_user_id' => $employee->id, 'title' => 'Trim out', 'status' => 'open', 'operational_status' => 'active',
    ]);
    $foreignJob = FieldServiceJob::query()->create(['tenant_id' => $otherTenant->id, 'title' => 'Foreign', 'status' => 'open']);
    Sanctum::actingAs($employee, ['mobile:read', 'mobile:write']);
    $url = '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/dispatch/status';

    $this->postJson($url, ['status' => 'on_site', 'job_id' => $foreignJob->id])->assertNotFound();
    $this->postJson($url, ['status' => 'on_site', 'job_id' => $job->id])->assertOk();
    expect(FieldServiceCrewStatus::query()->forTenantId((int) $tenant->id)->where('user_id', $employee->id)->sole()->status)->toBe('on_site');

    Sanctum::actingAs($manager, ['mobile:read']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/dispatch')
        ->assertOk()->assertJsonPath('summary.on_site', 1);
});
