<?php

use App\Models\FieldServiceJob;
use App\Models\FieldServiceTask;
use App\Models\TenantModuleEntitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true]);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
});

function teamFieldJob($tenant): FieldServiceJob
{
    TenantModuleEntitlement::query()->updateOrCreate(['tenant_id' => $tenant->id, 'module_key' => 'field_service'], ['enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);

    return FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Fictional existing job', 'status' => 'in_progress', 'operational_status' => 'active', 'description' => 'Keep original job notes', 'lock_box_code' => 'FICTIONAL-DO-NOT-PROJECT']);
}

test('field adapter links one canonical task without copying changing or sharing legacy work', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $task = teamTask($tenant, $owner);
    $job = teamFieldJob($tenant);
    $legacy = FieldServiceTask::query()->create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'title' => 'Existing crew checklist', 'description' => 'Legacy source context', 'status' => 'open', 'priority' => 'normal']);
    $originalJob = $job->fresh()->getRawOriginal();
    $originalTask = $legacy->fresh()->getRawOriginal();
    $path = route('team.field-links.link', ['tenant' => $tenant->slug, 'id' => $task['id'], 'job' => $job->id]);
    $key = (string) Str::uuid();
    $command = ['expected_version' => 1, 'idempotency_key' => $key];
    $this->actingAs($owner)->postJson($path, $command)->assertOk();
    $this->postJson($path, $command)->assertOk()->assertJsonPath('replayed', true);
    $this->getJson(route('team.field-links.jobs', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertOk()->assertJsonCount(1, 'jobs')->assertJsonPath('jobs.0.id', $job->id)->assertDontSee('FICTIONAL-DO-NOT-PROJECT')->assertDontSee('Keep original job notes');
    $list = route('team.field-links.tasks', ['tenant' => $tenant->slug, 'job' => $job->id]);
    $this->getJson($list)->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.id', $task['id']);
    $this->actingAs($member)->getJson($list)->assertOk()->assertJsonCount(0, 'tasks')->assertDontSee($task['title'])->assertDontSee($task['id']);
    expect($job->fresh()->getRawOriginal())->toBe($originalJob)->and($legacy->fresh()->getRawOriginal())->toBe($originalTask)
        ->and(DB::table('team_tasks')->count())->toBe(1)->and(DB::table('field_service_tasks')->count())->toBe(1)->and(DB::table('team_work_events')->count())->toBe(2);
});

test('field adapter requires both current task write and job scope even for managers or platform operators', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $manager = teamMember($tenant, 'manager');
    $task = teamTask($tenant, $owner);
    $job = teamFieldJob($tenant);
    [$other, $outsider] = teamWorkspace();
    $otherJob = teamFieldJob($other);
    $command = ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()];
    $path = route('team.field-links.link', ['tenant' => $tenant->slug, 'id' => $task['id'], 'job' => $job->id]);
    $this->actingAs($manager)->postJson($path, $command)->assertForbidden();
    $manager->forceFill(['role' => 'platform_admin'])->save();
    $this->actingAs($manager)->postJson($path, $command)->assertForbidden();
    $this->actingAs($owner)->postJson(route('team.field-links.link', ['tenant' => $tenant->slug, 'id' => $task['id'], 'job' => $otherJob->id]), $command)->assertNotFound();
    $this->actingAs($outsider)->getJson(route('team.field-links.tasks', ['tenant' => $tenant->slug, 'job' => $job->id]))->assertForbidden();
    expect(DB::table('team_tasks')->where('id', $task['id'])->value('version'))->toBe(1);
});

test('recycled and invoice only jobs are excluded and field entitlement loss removes references', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $job = teamFieldJob($tenant);
    $key = (string) Str::uuid();
    $path = route('team.field-links.link', ['tenant' => $tenant->slug, 'id' => $task['id'], 'job' => $job->id]);
    $command = ['expected_version' => 1, 'idempotency_key' => $key];
    $this->actingAs($owner)->postJson($path, $command)->assertOk();
    $job->forceFill(['metadata' => ['recycled_at' => '2026-10-17T01:00:00Z']])->save();
    $this->postJson($path, $command)->assertNotFound(); // Replay cannot resurrect a hidden Field object.
    $this->getJson(route('team.field-links.jobs', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertOk()->assertJsonCount(0, 'jobs');
    $job->forceFill(['metadata' => null, 'external_source' => 'quickbooks', 'external_id' => 'quickbooks:invoice:fictional'])->save();
    $this->postJson($path, $command)->assertNotFound();
    $job->forceFill(['external_source' => null, 'external_id' => null])->save();
    TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->where('module_key', 'field_service')->update(['enabled_status' => 'disabled']);
    $this->getJson(route('team.field-links.jobs', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertOk()->assertJsonCount(0, 'jobs');
    $this->postJson($path, $command)->assertForbidden();
});

test('field unlink is version fenced idempotent and preserves original relationship history', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $job = teamFieldJob($tenant);
    $path = route('team.field-links.link', ['tenant' => $tenant->slug, 'id' => $task['id'], 'job' => $job->id]);
    $this->actingAs($owner)->postJson($path, ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertOk();
    $remove = ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'remove' => true];
    $this->postJson($path, $remove)->assertConflict();
    $remove['expected_version'] = 2;
    $this->postJson($path, $remove)->assertOk();
    $this->postJson($path, $remove)->assertOk()->assertJsonPath('replayed', true);
    $this->getJson(route('team.field-links.tasks', ['tenant' => $tenant->slug, 'job' => $job->id]))->assertOk()->assertJsonCount(0, 'tasks');
    $old = json_decode(DB::table('team_work_versions')->where('entity_id', $task['id'])->where('version', 2)->value('snapshot'), true);
    expect($old['field_job_ids'][(string) $job->id])->toBeTrue()->and(DB::table('team_work_events')->where('entity_id', $task['id'])->count())->toBe(3);
});
