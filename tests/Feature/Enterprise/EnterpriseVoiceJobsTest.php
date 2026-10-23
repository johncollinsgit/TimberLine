<?php

use App\Enterprise\RequestWorkflow;
use App\Enterprise\WorkspaceAccess;
use App\Models\TeamOperationRecord;
use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true,
        'enterprise.writes_enabled' => true, 'enterprise.operations_enabled' => true, 'enterprise.ask_enabled' => true, 'enterprise.voice_jobs_enabled' => true]);
    Route::middleware('web')->prefix('voice-jobs-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Http::preventStrayRequests();
});

function voiceCommand($tenant, $owner, array $input): array
{
    return app(RequestWorkflow::class)->execute($tenant, $owner, $input + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0]);
}

function voiceFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id]]);
    $area = voiceCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Fictional Operations', 'visibility' => 'department_only'])['id'];
    $project = voiceCommand($tenant, $owner, ['action' => 'project.create', 'area_id' => $area, 'title' => 'Fictional maintenance'])['id'];
    $request = voiceCommand($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Fictional dorm pipe burst',
        'description' => 'Synthetic repair example; no student records.', 'location' => 'Fictional dorm west entrance', 'latitude' => '36.162', 'longitude' => '-81.872', 'due_on' => '2026-10-23']);
    $request = voiceCommand($tenant, $owner, ['action' => 'request.triage', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => $request['version'], 'note' => 'Fictional staff inspection']);
    $request = voiceCommand($tenant, $owner, ['action' => 'request.assign', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => $request['version'], 'project_id' => $project, 'assignee_id' => (string) $owner->id]);

    return [$tenant, $owner, $area, $project, $request['data']['task_id']];
}

function voiceLookup($tenant, $user, string $area, string $question, ?string $focus = null)
{
    return test()->actingAs($user)->postJson(route('enterprise.voice.jobs', ['tenant' => $tenant->slug]), array_filter(['area_id' => $area, 'question' => $question, 'focus' => $focus], fn ($value) => $value !== null));
}

test('voice lookup reads an actual scoped job and request location without dispatch or business writes', function (): void {
    [$tenant, $owner, $area, $project, $task] = voiceFixture();
    $before = [DB::table('team_tasks')->count(), DB::table('team_operation_records')->count(), DB::table('team_operation_revisions')->count(), DB::table('team_operation_attempts')->count()];
    config(['enterprise.writes_enabled' => false]);
    $response = voiceLookup($tenant, $owner, $area, 'Please bring up the dorm pipe burst job')->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $result = $response
        ->assertJsonPath('outcome', 'matched')->assertJsonPath('provider_dispatch', false)->assertJsonPath('mode', 'record_backed')
        ->assertJsonPath('job.id', $task)->assertJsonPath('job.location.label', 'Fictional dorm west entrance')
        ->assertJsonPath('scope.user_id', (string) $owner->id)->assertJsonPath('scope.tenant_id', (string) $tenant->id)->assertJsonPath('scope.area_id', $area)->json();
    expect($result['reply'])->toContain('Fictional dorm pipe burst')->toContain('2026-10-23');
    expect($result['job']['source_url'])->toContain('task='.$task)->toContain('selected='.$project);
    expect($result['job'])->not->toHaveKey('description')->not->toHaveKey('costs')->not->toHaveKey('comments');
    voiceLookup($tenant, $owner, $area, 'Where is it?', $task)->assertOk()->assertJsonPath('job.id', $task);
    expect([DB::table('team_tasks')->count(), DB::table('team_operation_records')->count(), DB::table('team_operation_revisions')->count(), DB::table('team_operation_attempts')->count()])->toBe($before);
    Http::assertNothingSent();
});

test('ambiguity and absent facts do not invent or automatically choose a job', function (): void {
    [$tenant, $owner, $area, $project] = voiceFixture();
    app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true), [
        'kind' => 'task.create', 'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'title' => 'Fictional dorm window', 'memberships' => [$project => []]]);
    voiceLookup($tenant, $owner, $area, 'Show dorm job')->assertOk()->assertJsonPath('outcome', 'choose')->assertJsonCount(2, 'candidates')->assertJsonPath('job', null);
    voiceLookup($tenant, $owner, $area, 'Show the missing boiler job')->assertOk()->assertJsonPath('outcome', 'no_match')->assertJsonPath('job', null);
    voiceLookup($tenant, $owner, $area, 'Show window job')->assertOk()->assertJsonPath('job.location', null)->assertJsonPath('job.due_on', null);
    Http::assertNothingSent();
});

test('read and communication requests remain separate with no voice confirmation path', function (): void {
    [$tenant, $owner, $area, , $task] = voiceFixture();
    foreach (['Send an email about the pipe', 'Text the faculty member', 'Approve the pipe repair', 'Pay the contractor', 'Complete the job'] as $question) {
        voiceLookup($tenant, $owner, $area, $question, $task)->assertOk()->assertJsonPath('outcome', 'review_required')->assertJsonPath('job', null)->assertJsonCount(0, 'candidates');
    }
    expect(TeamOperationRecord::query()->whereIn('kind', ['ent_email', 'ent_ai_usage'])->count())->toBe(0);
    Http::assertNothingSent();
});

test('current tenant department and task policies are enforced again for focus and revoked membership', function (): void {
    [$tenant, $owner, $area, $project, $task] = voiceFixture();
    $member = teamMember($tenant, 'member');
    voiceLookup($tenant, $member, $area, 'Show dorm job')->assertNotFound();
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $member->id, 'role' => 'viewer']);
    voiceLookup($tenant, $member, $area, 'Show dorm job')->assertOk()->assertJsonPath('job.id', $task);
    $elsewhere = voiceCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Fictional restricted IT', 'visibility' => 'department_only'])['id'];
    voiceLookup($tenant, $owner, $elsewhere, 'Where is it?', $task)->assertNotFound();
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $member->id])->update(['membership_active' => false]);
    voiceLookup($tenant, $member, $area, 'Where is it?', $task)->assertForbidden();
    [$otherTenant, $otherOwner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id, (string) $otherTenant->id]]);
    voiceLookup($otherTenant, $otherOwner, $area, 'Where is it?', $task)->assertNotFound();
    Http::assertNothingSent();
});

test('voice flag authentication and bounded inputs fail closed', function (): void {
    [$tenant, $owner, $area] = voiceFixture();
    voiceLookup($tenant, $owner, $area, str_repeat('x', 1501))->assertUnprocessable();
    config(['enterprise.voice_jobs_enabled' => false]);
    voiceLookup($tenant, $owner, $area, 'Show dorm job')->assertNotFound();
    config(['enterprise.voice_jobs_enabled' => true]);
    auth()->logout();
    test()->postJson(route('enterprise.voice.jobs', ['tenant' => $tenant->slug]), ['area_id' => $area, 'question' => 'Show dorm job'])->assertUnauthorized();
    Http::assertNothingSent();
});
