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

test('ownership transfers only project control with a replayable version fence', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $member->id, 'access' => 'editor'])->assertOk();
    $command = ['kind' => 'project.transfer_owner', 'id' => $project['id'], 'expected_version' => 2, 'user_id' => $member->id, 'acknowledge_sharing' => true, 'idempotency_key' => (string) Str::uuid()];
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('result.owner_id', (string) $member->id)->assertJsonPath('result.version', 3);
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('result.version', 3);
    teamPost($tenant, $owner, [...$command, 'expected_version' => 3, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    teamPost($tenant, $member, ['kind' => 'project.update', 'id' => $project['id'], 'expected_version' => 2, 'changes' => ['name' => 'Stale overwrite']])->assertConflict();
    teamPost($tenant, $member, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 3, 'user_id' => $owner->id, 'access' => null])->assertOk();
    teamPost($tenant, $owner, $command)->assertForbidden();
    expect(collect(app(TeamWorkRepository::class)->state($tenant->id)['events'])->where('kind', 'project.transfer_owner')->count())->toBe(1);
    expect(DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $member->id)->value('role'))->toBe('member');
});

test('ownership rejects unshared viewer guest inactive unverified and foreign targets', function (): void {
    [$tenant, $owner] = teamWorkspace();
    [$otherTenant, $foreign] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $version = 1;
    foreach (['member', 'guest', 'viewer', 'member', 'member'] as $index => $role) {
        $target = teamMember($tenant, $role);
        if ($index > 0) {
            teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => $version++, 'user_id' => $target->id, 'access' => 'editor'])->assertOk();
        }
        if ($index === 3) {
            $tenant->users()->updateExistingPivot($target->id, ['membership_active' => false]);
        }
        if ($index === 4) {
            $target->forceFill(['email_verified_at' => null])->save();
        }
        teamPost($tenant, $owner, ['kind' => 'project.transfer_owner', 'id' => $project['id'], 'expected_version' => $version, 'user_id' => $target->id, 'acknowledge_sharing' => true])->assertUnprocessable()->assertJsonPath('error', 'invalid_owner');
    }
    teamPost($tenant, $owner, ['kind' => 'project.transfer_owner', 'id' => $project['id'], 'expected_version' => $version, 'user_id' => $foreign->id, 'acknowledge_sharing' => true])->assertUnprocessable();
    expect(app(TeamWorkRepository::class)->state($tenant->id)['projects'][$project['id']]['owner_id'])->toBe((string) $owner->id);
});

test('transfer needs explicit review and cannot bypass owner or archive boundaries', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $admin = teamMember($tenant, 'admin');
    $project = teamProject($tenant, $owner, 'workspace');
    teamPost($tenant, $admin, ['kind' => 'project.transfer_owner', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $admin->id, 'acknowledge_sharing' => true])->assertForbidden();
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $admin->id, 'access' => 'editor'])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'project.transfer_owner', 'id' => $project['id'], 'expected_version' => 2, 'user_id' => $admin->id])->assertUnprocessable()->assertJsonPath('error', 'sharing_review_required');
    teamPost($tenant, $owner, ['kind' => 'project.archive', 'id' => $project['id'], 'expected_version' => 2])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'project.transfer_owner', 'id' => $project['id'], 'expected_version' => 3, 'user_id' => $admin->id, 'acknowledge_sharing' => true])->assertUnprocessable()->assertJsonPath('error', 'project_archived');
});

test('creator shares a personal task and inherited children without exposing grants to recipients', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $guest = teamMember($tenant, 'guest');
    $task = teamTask($tenant, $owner);
    $child = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Child context', 'parent_id' => $task['id']])->assertOk()->json('result');
    $command = ['kind' => 'task.collaborator', 'id' => $task['id'], 'expected_version' => 1, 'user_id' => $guest->id, 'access' => 'viewer', 'acknowledge_sharing' => true, 'idempotency_key' => (string) Str::uuid()];
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('result.collaborators.'.$guest->id, 'viewer');
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('replayed', true);
    $this->actingAs($guest)->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $child['id']]))->assertOk()->assertJsonMissingPath('collaborators')->assertJsonPath('can_edit', false);
    teamPost($tenant, $guest, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 2, 'changes' => ['title' => 'No']])->assertForbidden();
    teamPost($tenant, $owner, ['kind' => 'task.collaborator', 'id' => $task['id'], 'expected_version' => 2, 'user_id' => $guest->id, 'access' => null])->assertOk();
    $this->actingAs($guest)->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $child['id']]))->assertForbidden();
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => 'Child']))->assertOk()->assertJsonCount(0, 'tasks');
});

test('personal editors cannot delegate or move private work and grants cannot reactivate through project moves', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $task = teamTask($tenant, $owner);
    $project = teamProject($tenant, $owner, 'workspace');
    teamPost($tenant, $owner, ['kind' => 'task.collaborator', 'id' => $task['id'], 'expected_version' => 1, 'user_id' => $member->id, 'access' => 'editor', 'acknowledge_sharing' => true])->assertOk();
    teamPost($tenant, $member, ['kind' => 'task.collaborator', 'id' => $task['id'], 'expected_version' => 2, 'user_id' => $owner->id, 'access' => 'viewer', 'acknowledge_sharing' => true])->assertForbidden();
    foreach ([$member, $owner] as $actor) {
        teamPost($tenant, $actor, ['kind' => 'task.membership', 'id' => $task['id'], 'expected_version' => 2, 'project_id' => $project['id'], 'acknowledge_sharing' => true])->assertUnprocessable()->assertJsonPath('error', 'personal_sharing_review_required');
    }
    teamPost($tenant, $member, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 2, 'changes' => ['description' => 'Authorized edit']])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.collaborator', 'id' => $task['id'], 'expected_version' => 2, 'user_id' => $member->id, 'access' => null])->assertConflict();
    teamPost($tenant, $owner, ['kind' => 'task.collaborator', 'id' => $task['id'], 'expected_version' => 3, 'user_id' => $member->id, 'access' => null])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.membership', 'id' => $task['id'], 'expected_version' => 4, 'project_id' => $project['id'], 'acknowledge_sharing' => true])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.collaborator', 'id' => $task['id'], 'expected_version' => 5, 'user_id' => $member->id, 'access' => 'viewer', 'acknowledge_sharing' => true])->assertForbidden();
});

test('personal sharing requires review verified current tenant targets and preserves view-only role', function (): void {
    [$tenant, $owner] = teamWorkspace();
    [$otherTenant, $foreign] = teamWorkspace();
    $viewer = teamMember($tenant, 'viewer');
    $task = teamTask($tenant, $owner);
    foreach ([[$viewer->id, 'editor', true], [$foreign->id, 'viewer', true], [$owner->id, 'viewer', true], [$viewer->id, 'viewer', false]] as [$userId, $grant, $ack]) {
        teamPost($tenant, $owner, ['kind' => 'task.collaborator', 'id' => $task['id'], 'expected_version' => 1, 'user_id' => $userId, 'access' => $grant, 'acknowledge_sharing' => $ack])->assertUnprocessable();
    }
    teamPost($tenant, $owner, ['kind' => 'task.collaborator', 'id' => $task['id'], 'expected_version' => 1, 'user_id' => $viewer->id, 'access' => 'viewer', 'acknowledge_sharing' => true])->assertOk();
    $tenant->users()->updateExistingPivot($viewer->id, ['membership_active' => false]);
    $this->actingAs($viewer)->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertForbidden();
});

test('home and date filters use literal dates and the selected zone for timed work', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $this->travelTo(\Carbon\Carbon::parse('2026-10-18T00:30:00Z'));
    foreach (['Literal today' => ['due_on' => '2026-10-17'], 'Timed today' => ['due_at' => '2026-10-18T01:00:00Z'], 'Overdue' => ['due_on' => '2026-10-16'], 'Tomorrow' => ['due_on' => '2026-10-18'], 'Undated' => []] as $title => $dates) {
        teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => $title, 'assignee_id' => $owner->id, ...$dates])->assertOk();
    }
    $url = fn ($extra = []) => route('team.data', ['tenant' => $tenant->slug, 'mine' => 1, 'timezone' => 'America/Los_Angeles', ...$extra]);
    $this->getJson($url(['date_filter' => 'today', 'sort' => 'title']))->assertOk()->assertJsonCount(2, 'tasks')->assertJsonPath('tasks.0.title', 'Literal today')->assertJsonPath('home.counts.all', 5)->assertJsonPath('home.counts.today', 2)->assertJsonPath('home.counts.overdue', 1)->assertJsonPath('home.counts.week', 4)->assertJsonPath('home.today', '2026-10-17');
    $this->getJson($url(['date_filter' => 'tomorrow']))->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'Tomorrow');
    $this->getJson($url(['date_filter' => 'undated']))->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'Undated');
    $this->getJson($url(['timezone' => 'UTC', 'date_filter' => 'today']))->assertOk()->assertJsonCount(2, 'tasks');
});

test('home counts all authorized assigned work before pagination and ignores unrelated search filters', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    for ($i = 0; $i < 53; $i++) {
        teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => sprintf('Page task %02d', $i), 'assignee_id' => $member->id, 'memberships' => [$project['id'] => []]])->assertOk();
    }
    teamTask($tenant, $owner); // Private, unassigned: cannot affect another member's totals.
    $this->actingAs($member)->getJson(route('team.data', ['tenant' => $tenant->slug, 'mine' => 1, 'page' => 2, 'sort' => 'title']))->assertOk()->assertJsonCount(3, 'tasks')->assertJsonPath('total', 53)->assertJsonPath('home.counts.all', 53)->assertJsonPath('tasks.0.title', 'Page task 50');
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'q' => 'No match']))->assertOk()->assertJsonCount(0, 'tasks')->assertJsonPath('home.counts.all', 53);
});

test('completion and access removal immediately remove home work without cross-tenant totals', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $member->id, 'access' => 'editor'])->assertOk();
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Private assigned', 'assignee_id' => $member->id, 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 1])->assertOk();
    $this->actingAs($member)->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertOk()->assertJsonPath('home.counts.all', 0);
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2, 'completed' => false])->assertOk();
    $this->actingAs($member)->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertOk()->assertJsonPath('home.counts.all', 1);
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 2, 'user_id' => $member->id, 'access' => null])->assertOk();
    $this->actingAs($member)->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertOk()->assertJsonPath('home.counts.all', 0)->assertJsonCount(0, 'tasks');
    [$otherTenant, $other] = teamWorkspace();
    $this->actingAs($other)->getJson(route('team.data', ['tenant' => $otherTenant->slug]))->assertOk()->assertJsonPath('home.counts.all', 0);
});

test('priority and due sorting remain stable with undated work last and reject unsupported controls', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $first = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'First due', 'due_on' => '2026-10-20'])->assertOk()->json('result');
    $urgent = teamTask($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'task.update', 'id' => $urgent['id'], 'expected_version' => 1, 'changes' => ['priority' => 'urgent']])->assertOk();
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'sort' => 'priority']))->assertOk()->assertJsonPath('tasks.0.id', $urgent['id']);
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'sort' => 'due']))->assertOk()->assertJsonPath('tasks.0.id', $first['id']);
    foreach ([['timezone' => 'Invented/Zone'], ['date_filter' => 'last_year'], ['sort' => 'date_assigned']] as $invalid) {
        $this->getJson(route('team.data', ['tenant' => $tenant->slug, ...$invalid]))->assertUnprocessable();
    }
});

test('timed sorting compares instants across DST offsets while day filters retain local dates', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-01T06:00:00Z'));
    $later = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Later repeated hour', 'due_at' => '2026-11-01T01:15:00-05:00'])->assertOk()->json('result');
    $earlier = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Earlier repeated hour', 'due_at' => '2026-11-01T01:30:00-04:00'])->assertOk()->json('result');
    $this->getJson(route('team.data', ['tenant' => $tenant->slug, 'date_filter' => 'today', 'sort' => 'due', 'timezone' => 'America/New_York']))
        ->assertOk()->assertJsonCount(2, 'tasks')->assertJsonPath('tasks.0.id', $earlier['id'])->assertJsonPath('tasks.1.id', $later['id']);
});
