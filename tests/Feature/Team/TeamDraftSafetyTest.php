<?php

use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true]);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
});

test('page actor and workspace fences refuse stale browser reads and writes before any mutation', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamTask($tenant, $owner, $project);
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    $command = ['kind' => 'task.comment', 'id' => $task['id'], 'expected_version' => 1, 'comment_id' => (string) Str::uuid(), 'body' => 'Retained owner draft', 'idempotency_key' => (string) Str::uuid()];
    $this->actingAs($member)->withHeaders(['X-Team-Viewer' => (string) $owner->id, 'X-Team-Workspace' => (string) $tenant->id])
        ->postJson(route('team.commands', ['tenant' => $tenant->slug]), $command)->assertForbidden()->assertJsonPath('error', 'page_identity_changed')->assertJsonPath('saved', false);
    $this->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertForbidden()->assertJsonMissingPath('tasks');
    $this->withHeaders(['X-Team-Viewer' => (string) $member->id, 'X-Team-Workspace' => '999999'])->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertForbidden();
    expect(app(TeamWorkRepository::class)->state($tenant->id))->toBe($before);
});

test('matching page binding returns actual identity but never overrides task permissions', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $member = teamMember($tenant, 'member');
    $this->actingAs($owner)->withHeaders(['X-Team-Viewer' => (string) $owner->id, 'X-Team-Workspace' => (string) $tenant->id])->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))
        ->assertOk()->assertHeader('X-Team-Viewer', (string) $owner->id)->assertHeader('X-Team-Workspace', (string) $tenant->id);
    $this->actingAs($member)->withHeaders(['X-Team-Viewer' => (string) $member->id, 'X-Team-Workspace' => (string) $tenant->id])->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertForbidden();
});

test('exact comment save confirmation after later updates adds only one comment and event', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $commentId = (string) Str::uuid();
    $request = ['kind' => 'task.comment', 'id' => $task['id'], 'expected_version' => 1, 'comment_id' => $commentId, 'body' => 'One original unsent comment', 'idempotency_key' => (string) Str::uuid()];
    $first = teamPost($tenant, $owner, $request)->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => $first['version'], 'changes' => ['title' => 'Later authorized edit']])->assertOk();
    teamPost($tenant, $owner, $request)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('result.comments.'.$commentId.'.body', 'One original unsent comment')->assertJsonPath('result.title', 'Later authorized edit');
    teamPost($tenant, $owner, array_replace($request, ['body' => 'Changed retry payload']))->assertConflict()->assertJsonPath('error', 'idempotency_mismatch');
    $state = app(TeamWorkRepository::class)->state($tenant->id);
    expect(count($state['tasks'][$task['id']]['comments']))->toBe(1)->and(collect($state['events'])->where('kind', 'task.comment')->count())->toBe(1);
});

test('lost task edit can confirm its exact receipt while newer saved text stays intact', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $request = ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 1, 'changes' => ['description' => 'Reviewed user draft'], 'idempotency_key' => (string) Str::uuid()];
    teamPost($tenant, $owner, $request)->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 2, 'changes' => ['description' => 'Newer saved text']])->assertOk();
    teamPost($tenant, $owner, $request)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('result.description', 'Newer saved text');
    expect(DB::table('team_work_versions')->where('entity_type', 'task')->where('entity_id', $task['id'])->count())->toBe(3);
});
