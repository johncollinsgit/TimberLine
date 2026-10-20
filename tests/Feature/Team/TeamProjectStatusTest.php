<?php

use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.mobile_enabled' => true]);
    Http::preventStrayRequests();
    Bus::fake();
    Notification::fake();
    $this->travelTo(\Carbon\Carbon::parse('2026-10-19T12:00:00Z'));
});

function statusCommand(array $project, array $changes = []): array
{
    return array_replace(['kind' => 'project.status', 'id' => $project['id'], 'expected_version' => $project['version'], 'idempotency_key' => (string) Str::uuid(), 'status_code' => 'on_track', 'summary' => 'Fictional weekly field review', 'highlights' => 'New highlight', 'blockers' => 'Reviewed blocker', 'next_steps' => 'New next step', 'acknowledge_sharing' => true], $changes);
}
function overviewPath($tenant, array $project, array $query = []): string
{
    return route('team.projects.overview', ['tenant' => $tenant->slug, 'id' => $project['id']] + $query);
}

test('project overview preserves description and dates and counts readable project tasks once', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $project = teamPost($tenant, $owner, ['kind' => 'project.update', 'id' => $project['id'], 'expected_version' => 1, 'changes' => ['description' => "Original project notes\nSecond line", 'start_on' => '2026-10-01', 'due_on' => '2026-12-01']])->assertOk()->json('result');
    $task = teamTask($tenant, $owner, $project);
    $other = teamProject($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'task.membership', 'id' => $task['id'], 'expected_version' => 1, 'project_id' => $other['id'], 'acknowledge_sharing' => true])->assertOk();
    $second = teamTask($tenant, $owner, $project);
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $second['id'], 'expected_version' => 1, 'completed' => true])->assertOk();
    $this->actingAs($owner)->getJson(overviewPath($tenant, $project))->assertOk()->assertJsonPath('project.description', "Original project notes\nSecond line")->assertJsonPath('project.start_on', '2026-10-01')->assertJsonPath('project.due_on', '2026-12-01')->assertJsonPath('progress.visible_tasks', 2)->assertJsonPath('progress.completed_tasks', 1)->assertJsonPath('can_post_status', true)->assertJsonCount(0, 'updates')->assertJsonPath('project_url', route('team.index', ['tenant' => $tenant->slug, 'project' => $project['id']]));
});

test('five status states append verified history without overwriting original notes or completing tasks', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $task = teamTask($tenant, $owner, $project);
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    $ids = [];
    foreach (['on_track', 'at_risk', 'off_track', 'on_hold', 'complete'] as $code) {
        $project = teamPost($tenant, $owner, statusCommand($project, ['status_code' => $code]))->assertOk()->json('result');
        $ids[] = $project['posted_status_id'];
    }
    $response = $this->getJson(overviewPath($tenant, $project))->assertOk()->assertJsonCount(5, 'updates')->assertJsonPath('project.current_status.code', 'complete')->assertJsonPath('project.archived', false)->assertJsonPath('updates.0.highlights', 'New highlight')->assertJsonPath('updates.0.blockers', 'Reviewed blocker')->assertJsonPath('updates.0.next_steps', 'New next step')->json();
    expect(array_column($response['updates'], 'id'))->toBe(array_reverse($ids));
    $state = app(TeamWorkRepository::class)->state($tenant->id);
    expect($state['tasks'])->toBe($before['tasks'])->and($state['projects'][$project['id']]['description'])->toBe($before['projects'][$project['id']]['description']);
    expect(DB::table('team_work_versions')->where('entity_type', 'project')->where('entity_id', $project['id'])->count())->toBe(6)->and(DB::table('team_inbox_items')->count())->toBe(0);
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

test('status requires current owner review and exact project revision including privacy changes', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $member = teamMember($tenant, 'member');
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $member->id, 'access' => 'editor'])->assertOk();
    $project = app(TeamWorkRepository::class)->state($tenant->id)['projects'][$project['id']];
    teamPost($tenant, $member, statusCommand($project))->assertForbidden();
    teamPost($tenant, $owner, statusCommand($project, ['acknowledge_sharing' => false]))->assertUnprocessable()->assertJsonPath('error', 'sharing_review_required');
    $old = statusCommand($project);
    teamPost($tenant, $owner, ['kind' => 'project.update', 'id' => $project['id'], 'expected_version' => $project['version'], 'changes' => ['privacy' => 'workspace'], 'acknowledge_sharing' => true])->assertOk();
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    teamPost($tenant, $owner, $old)->assertConflict()->assertJsonPath('saved', false);
    expect(app(TeamWorkRepository::class)->state($tenant->id))->toBe($before);
});

test('status replay returns original posted identity after later updates without duplicate history', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $command = statusCommand($project);
    $first = teamPost($tenant, $owner, $command)->assertOk()->json('result');
    $second = teamPost($tenant, $owner, statusCommand($first, ['summary' => 'Later update']))->assertOk()->json('result');
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('result.posted_status_id', $first['posted_status_id'])->assertJsonPath('result.current_status.id', $second['posted_status_id']);
    teamPost($tenant, $owner, array_replace($command, ['summary' => 'Changed retry']))->assertConflict()->assertJsonPath('error', 'idempotency_mismatch');
    $this->getJson(overviewPath($tenant, $project))->assertOk()->assertJsonCount(2, 'updates');
});

test('former owner may confirm an executed status receipt but cannot post new updates after transfer', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    $project = teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $member->id, 'access' => 'editor'])->assertOk()->json('result');
    $command = statusCommand($project);
    $posted = teamPost($tenant, $owner, $command)->assertOk()->json('result');
    $transferred = teamPost($tenant, $owner, ['kind' => 'project.transfer_owner', 'id' => $project['id'], 'expected_version' => $posted['version'], 'user_id' => $member->id, 'acknowledge_sharing' => true])->assertOk()->json('result');
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('result.posted_status_id', $posted['posted_status_id']);
    teamPost($tenant, $owner, statusCommand($transferred))->assertForbidden();
});

test('task references require current visibility project membership and reviewed task versions', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $task = teamTask($tenant, $owner, $project);
    $outside = teamTask($tenant, $owner);
    teamPost($tenant, $owner, statusCommand($project, ['references' => [$outside['id'] => 1]]))->assertUnprocessable()->assertJsonPath('error', 'invalid_status_reference_project');
    teamPost($tenant, $owner, statusCommand($project, ['references' => [$task['id'] => 9]]))->assertConflict();
    $posted = teamPost($tenant, $owner, statusCommand($project, ['references' => [$task['id'] => 1]]))->assertOk()->assertJsonPath('result.current_status.references.0.id', $task['id'])->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 1, 'changes' => ['title' => 'Current referenced title']])->assertOk();
    $this->getJson(overviewPath($tenant, $project))->assertOk()->assertJsonPath('updates.0.references.0.title', 'Current referenced title')->assertJsonPath('updates.0.summary', 'Fictional weekly field review');
    expect(app(TeamWorkRepository::class)->state($tenant->id)['projects'][$project['id']]['current_status']['reference_versions'][$task['id']])->toBe(1);
    expect($posted['current_status'])->not->toHaveKey('reference_versions');
});

test('restricted parent task hides reference identity and excludes it from visible progress and selections', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $visible = teamTask($tenant, $owner, $project);
    $parent = teamTask($tenant, $owner);
    $restricted = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Private ancestor secret reference', 'parent_id' => $parent['id'], 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    teamPost($tenant, $owner, statusCommand($project, ['references' => [$restricted['id'] => 1]]))->assertOk();
    $this->actingAs($member)->getJson(overviewPath($tenant, $project, ['selected' => [$restricted['id']]]))->assertOk()->assertJsonPath('progress.visible_tasks', 1)->assertJsonCount(0, 'updates.0.references')->assertJsonCount(0, 'selected_references')->assertDontSee($restricted['id'])->assertDontSee('Private ancestor secret');
});

test('status history cursor is opaque scoped and duplicate free across unrelated project edits', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $other = teamProject($tenant, $owner);
    $ids = [];
    for ($i = 0; $i < 12; $i++) {
        $project = teamPost($tenant, $owner, statusCommand($project, ['summary' => 'Update '.$i]))->assertOk()->json('result');
        $ids[] = $project['posted_status_id'];
        if ($i === 4) {
            $project = teamPost($tenant, $owner, ['kind' => 'project.update', 'id' => $project['id'], 'expected_version' => $project['version'], 'changes' => ['name' => 'Renamed project']])->assertOk()->json('result');
        }
    }
    $page = $this->getJson(overviewPath($tenant, $project))->assertOk()->assertJsonCount(10, 'updates')->json();
    expect($page['next_before'])->toBeString();
    $older = $this->getJson(overviewPath($tenant, $project, ['before' => $page['next_before']]))->assertOk()->assertJsonCount(2, 'updates')->assertJsonPath('next_before', null)->json();
    expect(array_column([...$page['updates'], ...$older['updates']], 'id'))->toBe(array_reverse($ids));
    $this->getJson(overviewPath($tenant, $other, ['before' => $page['next_before']]))->assertUnprocessable();
    $this->getJson(overviewPath($tenant, $project, ['before' => 'tampered']))->assertUnprocessable();
});

test('archived project keeps history readable but cannot post until reopened', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $project = teamPost($tenant, $owner, statusCommand($project))->assertOk()->json('result');
    $project = teamPost($tenant, $owner, ['kind' => 'project.archive', 'id' => $project['id'], 'expected_version' => $project['version'], 'archived' => true])->assertOk()->json('result');
    $this->getJson(overviewPath($tenant, $project))->assertOk()->assertJsonCount(1, 'updates')->assertJsonPath('can_post_status', false);
    teamPost($tenant, $owner, statusCommand($project))->assertForbidden();
    $project = teamPost($tenant, $owner, ['kind' => 'project.archive', 'id' => $project['id'], 'expected_version' => $project['version'], 'archived' => false])->assertOk()->json('result');
    teamPost($tenant, $owner, statusCommand($project))->assertOk();
});

test('malformed status fields cannot write a partial project revision or event', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    foreach ([['status_code' => 'unknown'], ['summary' => '   '], ['summary' => str_repeat('x', 4001)], ['blockers' => str_repeat('x', 2001)], ['references' => array_fill_keys(array_map(fn () => (string) Str::uuid(), range(1, 21)), 1)]] as $changes) {
        teamPost($tenant, $owner, statusCommand($project, $changes))->assertUnprocessable();
    }
    expect(app(TeamWorkRepository::class)->state($tenant->id))->toBe($before);
});

test('status text stays out of directory payload while authorized overview keeps original history', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    teamPost($tenant, $owner, statusCommand($project, ['summary' => 'Unique full status narrative']))->assertOk();
    $this->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertOk()->assertJsonPath('projects.0.current_status.code', 'on_track')->assertDontSee('Unique full status narrative');
    $this->getJson(overviewPath($tenant, $project))->assertOk()->assertJsonPath('updates.0.summary', 'Unique full status narrative');
});

test('cross workspace history links and status writes cannot expose or modify another tenant', function (): void {
    [$first, $owner] = teamWorkspace();
    [$second] = teamWorkspace();
    $second->users()->attach($owner->id, ['role' => 'member', 'membership_active' => true]);
    $project = teamProject($first, $owner);
    $project = teamPost($first, $owner, statusCommand($project, ['summary' => 'Tenant-specific narrative']))->assertOk()->json('result');
    $before = app(TeamWorkRepository::class)->state($first->id);
    $this->getJson(overviewPath($second, $project))->assertNotFound()->assertDontSee('Tenant-specific narrative');
    teamPost($second, $owner, statusCommand($project))->assertNotFound();
    $this->getJson(route('team.index', ['tenant' => $second->slug, 'project' => $project['id']]))->assertNotFound();
    expect(app(TeamWorkRepository::class)->state($first->id))->toBe($before);
});

test('revoked private project reader loses all status history and project link content', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $guest = teamMember($tenant, 'guest');
    $project = teamProject($tenant, $owner);
    $project = teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $guest->id, 'access' => 'viewer'])->assertOk()->json('result');
    $project = teamPost($tenant, $owner, statusCommand($project, ['summary' => 'Private narrative']))->assertOk()->json('result');
    $this->actingAs($guest)->getJson(overviewPath($tenant, $project))->assertOk()->assertJsonPath('can_post_status', false);
    teamPost($tenant, $guest, statusCommand($project))->assertForbidden();
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => $project['version'], 'user_id' => $guest->id, 'access' => null])->assertOk();
    $this->actingAs($guest)->getJson(overviewPath($tenant, $project))->assertForbidden()->assertDontSee('Private narrative');
    $this->getJson(route('team.index', ['tenant' => $tenant->slug, 'project' => $project['id']]))->assertForbidden();
});

test('history integrity failure is explicit instead of returning unverified or partial status text', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $posted = teamPost($tenant, $owner, statusCommand($project, ['summary' => 'Original integrity protected narrative']))->assertOk()->json('result');
    DB::table('team_work_versions')->where('entity_id', $project['id'])->where('version', $posted['version'])->update(['snapshot' => '{}']);
    $this->getJson(overviewPath($tenant, $project))->assertStatus(503)->assertDontSee('Original integrity protected narrative');
});

test('overview reauthorizes after history fetch before projecting content', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    $project = teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $member->id, 'access' => 'viewer'])->assertOk()->json('result');
    teamPost($tenant, $owner, statusCommand($project, ['summary' => 'Do not disclose after access removal']))->assertOk();
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    $after = $before;
    unset($after['projects'][$project['id']]['members'][(string) $member->id]);
    $calls = 0;
    $mock = Mockery::mock(TeamWorkRepository::class);
    $mock->shouldReceive('state')->with($tenant->id)->andReturnUsing(function () use (&$calls, $before, $after) {
        return ++$calls >= 3 ? $after : $before;
    });
    app()->instance(TeamWorkRepository::class, $mock);
    $this->actingAs($member)->getJson(overviewPath($tenant, $project))->assertForbidden()->assertDontSee('Do not disclose after access removal');
    expect($calls)->toBeGreaterThanOrEqual(3);
});

test('existing native read and write transport shares the same status and history with header bound replay', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $command = statusCommand($project);
    Auth::forgetGuards();
    Auth::shouldUse('web');
    $token = $owner->createToken('synthetic-status-device', ['mobile:read', 'mobile:write'], now()->addHour());
    $this->withToken($token->plainTextToken);
    $path = route('mobile.v1.team-work.commands', ['tenant' => $tenant->slug]);
    $this->withHeader('Idempotency-Key', $command['idempotency_key'])->postJson($path, $command)->assertOk()->assertJsonPath('contract_version', 1);
    $this->postJson($path, $command)->assertOk()->assertJsonPath('replayed', true);
    $this->getJson(route('mobile.v1.team-work.projects.overview', ['tenant' => $tenant->slug, 'id' => $project['id']]))->assertOk()->assertJsonPath('contract_version', 1)->assertJsonCount(1, 'updates');
});

test('existing scoped workspace directory identifies only its current authenticated viewer for draft recovery', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $this->actingAs($owner)->getJson(overviewPath($tenant, $project))->assertOk()->assertJsonPath('viewer_id', (string) $owner->id);
    $this->actingAs($member)->getJson(overviewPath($tenant, $project))->assertOk()->assertJsonPath('viewer_id', (string) $member->id);
    $this->actingAs($owner)->getJson(route('team.workspaces', ['tenant' => $tenant->slug]))->assertOk()->assertJsonPath('viewer_id', (string) $owner->id);
    $this->actingAs($member)->getJson(route('team.workspaces', ['tenant' => $tenant->slug]))->assertOk()->assertJsonPath('viewer_id', (string) $member->id)->assertDontSee($owner->email);
});
