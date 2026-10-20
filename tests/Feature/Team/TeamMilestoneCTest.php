<?php

use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true]);
    Http::preventStrayRequests();
    Bus::fake();
    Notification::fake();
    $this->travelTo(\Carbon\Carbon::parse('2026-03-07T18:00:00Z'));
});

function teamRule(array $changes = []): array
{
    return array_replace(['frequency' => 'daily', 'interval' => 1, 'timezone' => 'America/New_York', 'overlap' => 'reject'], $changes);
}
function teamRepeat($tenant, $owner, array $task, array $rule = []): array
{
    return teamPost($tenant, $owner, ['kind' => 'task.recurrence', 'id' => $task['id'], 'expected_version' => $task['version'], 'rule' => teamRule($rule), 'acknowledge_sharing' => true])->assertOk()->json('result');
}
function teamTemplate($tenant, $owner, array $project, string $kind, ?string $task = null): array
{
    $id = (string) Str::uuid();
    $input = ['kind' => 'project.template', 'id' => $project['id'], 'expected_version' => $project['version'], 'template_id' => $id, 'template_type' => $kind, 'name' => 'Reviewed fictional blueprint'];
    if ($task !== null) {
        $input['source_task_id'] = $task;
    }
    $project = teamPost($tenant, $owner, $input)->assertOk()->json('result');

    return [$project, $id];
}

test('date-only repeats use prior calendar deadlines and completion clock without duplicate forks', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Repeat inspection', 'due_on' => '2026-03-07', 'description' => 'Retained source text', 'priority' => 'high'])->assertOk()->json('result');
    $task = teamRepeat($tenant, $owner, $task, ['timezone' => 'Pacific/Auckland']);
    $this->travelTo(\Carbon\Carbon::parse('2026-04-10T12:34:00Z'));
    $command = ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2, 'completed' => true, 'idempotency_key' => (string) Str::uuid()];
    $done = teamPost($tenant, $owner, $command)->assertOk()->json('result');
    expect($done['completed_at'])->toStartWith('2026-04-10T12:34:00');
    $next = app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$done['next_occurrence']['id']];
    expect($next['due_on'])->toBe('2026-03-08')->and($next['description'])->toBe('Retained source text')->and($next['priority'])->toBe('high')->and($next['comments'])->toBe([]);
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('replayed', true);
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 3, 'completed' => false])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 4])->assertOk();
    expect(DB::table('team_tasks')->count())->toBe(2);
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

test('timed daily repeats preserve local wall time across spring DST', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Morning', 'due_at' => '2026-03-07T09:00:00-05:00'])->assertOk()->json('result');
    $task = teamRepeat($tenant, $owner, $task);
    $done = teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => $task['version']])->assertOk()->json('result');
    expect(app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$done['next_occurrence']['id']]['due_at'])->toBe('2026-03-08T13:00:00+00:00');
});

test('a DST gap rejects completion atomically until the user reviews the due time', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Gap', 'due_at' => '2026-03-07T02:30:00-05:00'])->assertOk()->json('result');
    $task = teamRepeat($tenant, $owner, $task);
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2])->assertUnprocessable()->assertJsonPath('error', 'recurrence_dst_gap_review_required');
    expect(DB::table('team_tasks')->count())->toBe(1)->and(app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$task['id']]['completed_at'])->toBeNull();
    $task = teamPost($tenant, $owner, ['kind' => 'task.update', 'id' => $task['id'], 'expected_version' => 2, 'changes' => ['due_at' => '2026-03-07T03:30:00-05:00']])->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => $task['version']])->assertOk();
});

test('fall repeated hours require the reviewed overlap choice', function (string $policy, ?string $expected): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Overlap', 'due_at' => '2026-10-31T01:30:00-04:00'])->assertOk()->json('result');
    $task = teamRepeat($tenant, $owner, $task, ['overlap' => $policy]);
    $response = teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2]);
    if ($expected === null) {
        $response->assertUnprocessable()->assertJsonPath('error', 'recurrence_dst_overlap_review_required');
        expect(DB::table('team_tasks')->count())->toBe(1);
    } else {
        $id = $response->assertOk()->json('result.next_occurrence.id');
        expect(app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$id]['due_at'])->toBe($expected);
    }
})->with([['reject', null], ['earlier', '2026-11-01T05:30:00+00:00'], ['later', '2026-11-01T06:30:00+00:00']]);

test('monthly repeats retain the original month-day anchor and stop at the occurrence limit', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Month end', 'due_on' => '2026-01-31'])->assertOk()->json('result');
    $task = teamRepeat($tenant, $owner, $task, ['frequency' => 'monthly', 'max_occurrences' => 3]);
    foreach (['2026-02-28', '2026-03-31'] as $due) {
        $done = teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => $task['version']])->assertOk()->json('result');
        $task = app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$done['next_occurrence']['id']];
        expect($task['due_on'])->toBe($due);
    }
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => $task['version']])->assertOk()->assertJsonPath('result.next_occurrence', null);
    expect(DB::table('team_tasks')->count())->toBe(3);
});

test('weekly interval and inclusive end date use local calendar dates with fractional-hour zones', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Fortnight', 'due_at' => '2026-12-25T09:00:00+05:45'])->assertOk()->json('result');
    $task = teamRepeat($tenant, $owner, $task, ['frequency' => 'weekly', 'interval' => 2, 'timezone' => 'Asia/Kathmandu', 'until_on' => '2027-01-08']);
    $done = teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2])->assertOk()->json('result');
    $next = app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$done['next_occurrence']['id']];
    expect($next['due_at'])->toBe('2027-01-08T03:15:00+00:00');
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $next['id'], 'expected_version' => $next['version']])->assertOk()->assertJsonPath('result.next_occurrence', null);
});

test('repeat access loss archive children and unverified assignee cannot silently produce new work', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Guarded', 'due_on' => '2026-03-08', 'assignee_id' => $member->id, 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    $task = teamRepeat($tenant, $owner, $task);
    $member->forceFill(['email_verified_at' => null])->save();
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2])->assertUnprocessable()->assertJsonPath('error', 'invalid_assignee');
    $member->forceFill(['email_verified_at' => now()])->save();
    teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Independent child', 'parent_id' => $task['id']])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2])->assertUnprocessable()->assertJsonPath('error', 'recurrence_subtasks_require_review');
    teamPost($tenant, $owner, ['kind' => 'task.recurrence', 'id' => $task['id'], 'expected_version' => 2, 'rule' => null])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 3])->assertOk();
    expect(DB::table('team_tasks')->count())->toBe(2);
});

test('invalid repeat rules and viewer or foreign task writes fail closed', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $viewer = teamMember($tenant, 'viewer');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamTask($tenant, $owner, $project);
    teamPost($tenant, $owner, ['kind' => 'task.recurrence', 'id' => $task['id'], 'expected_version' => 1, 'rule' => teamRule(), 'acknowledge_sharing' => true])->assertUnprocessable()->assertJsonPath('error', 'recurrence_requires_due_date');
    foreach ([['interval' => 0], ['timezone' => 'Invalid/Zone'], ['frequency' => 'hourly'], ['max_occurrences' => 1001]] as $invalid) {
        teamPost($tenant, $owner, ['kind' => 'task.recurrence', 'id' => $task['id'], 'expected_version' => 1, 'rule' => teamRule($invalid)])->assertUnprocessable();
    }
    teamPost($tenant, $viewer, ['kind' => 'task.recurrence', 'id' => $task['id'], 'expected_version' => 1, 'rule' => null])->assertForbidden();
    [$other, $otherOwner] = teamWorkspace();
    teamPost($other, $otherOwner, ['kind' => 'task.recurrence', 'id' => $task['id'], 'expected_version' => 1, 'rule' => null])->assertNotFound();
});

test('template preview and shared milestone commands preserve native bearer ability boundaries', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Native shared repeat', 'due_on' => '2026-03-07', 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    [$project, $template] = teamTemplate($tenant, $owner, $project, 'task', $task['id']);
    config(['team_work.mobile_enabled' => true]);
    \Illuminate\Support\Facades\Auth::forgetGuards();
    \Illuminate\Support\Facades\Auth::shouldUse('web');
    $this->withToken($owner->createToken('fictional-c-read', ['mobile:read'])->plainTextToken);
    $preview = route('mobile.v1.team-work.templates.show', ['tenant' => $tenant->slug, 'id' => $project['id'], 'template' => $template]);
    $this->getJson($preview)->assertOk()->assertJsonPath('contract_version', 1)->assertJsonPath('tasks.0.title', 'Native shared repeat');
    $commandPath = route('mobile.v1.team-work.commands', ['tenant' => $tenant->slug]);
    $input = ['kind' => 'task.recurrence', 'id' => $task['id'], 'expected_version' => 1, 'rule' => teamRule(), 'acknowledge_sharing' => true];
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($commandPath, $input)->assertForbidden();
    \Illuminate\Support\Facades\Auth::forgetGuards();
    \Illuminate\Support\Facades\Auth::shouldUse('web');
    $this->withToken($owner->createToken('fictional-c-write', ['mobile:read', 'mobile:write'])->plainTextToken);
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($commandPath, $input)->assertOk()->assertJsonPath('result.recurrence.frequency', 'daily');
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($commandPath, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2])->assertOk()->assertJsonPath('result.completed_at', '2026-03-07T18:00:00+00:00');
    expect(DB::table('team_tasks')->count())->toBe(2)->and(DB::table('field_service_tasks')->count())->toBe(0);
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

test('template copies require project edit access and revocation hides the snapshot before any copy', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $reader = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    teamTask($tenant, $owner, $project);
    $project = teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $reader->id, 'access' => 'viewer'])->assertOk()->json('result');
    [$project, $template] = teamTemplate($tenant, $owner, $project, 'project');
    $url = route('team.templates.show', ['tenant' => $tenant->slug, 'id' => $project['id'], 'template' => $template]);
    $this->actingAs($reader)->getJson($url)->assertOk();
    teamPost($tenant, $reader, ['kind' => 'project.from_template', 'name' => 'No editor grant', 'project_id' => $project['id'], 'project_version' => 3, 'template_id' => $template, 'template_version' => 1, 'acknowledge_sharing' => true])->assertForbidden();
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 3, 'user_id' => $reader->id, 'access' => null])->assertOk();
    $this->actingAs($reader)->getJson($url)->assertForbidden();
    expect(DB::table('team_projects')->count())->toBe(1);
});

test('dependencies reject self and transitive cycles and allow advisory completion', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $tasks = [teamTask($tenant, $owner), teamTask($tenant, $owner), teamTask($tenant, $owner)];
    foreach ([[0, 1], [1, 2]] as [$target, $blocker]) {
        teamPost($tenant, $owner, ['kind' => 'task.dependency', 'id' => $tasks[$target]['id'], 'expected_version' => 1, 'blocker_id' => $tasks[$blocker]['id']])->assertOk()->assertJsonPath('result.is_blocked', true);
    }
    foreach ([0, 2] as $blocker) {
        teamPost($tenant, $owner, ['kind' => 'task.dependency', 'id' => $tasks[2]['id'], 'expected_version' => 1, 'blocker_id' => $tasks[$blocker]['id']])->assertUnprocessable()->assertJsonPath('error', 'dependency_cycle');
    }
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $tasks[0]['id'], 'expected_version' => 2])->assertOk()->assertJsonPath('result.is_blocked', true);
});

test('dependency details stay private after revocation and cross-tenant endpoints never link', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $shared = teamProject($tenant, $owner, 'workspace');
    $private = teamProject($tenant, $owner);
    $dependent = teamTask($tenant, $owner, $shared);
    $blocker = teamTask($tenant, $owner, $private);
    teamPost($tenant, $owner, ['kind' => 'task.dependency', 'id' => $dependent['id'], 'expected_version' => 1, 'blocker_id' => $blocker['id']])->assertOk();
    $body = $this->actingAs($member)->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $dependent['id']]))->assertOk()->assertJsonCount(0, 'dependencies')->assertJsonPath('dependency_details_unavailable', true)->assertJsonPath('is_blocked', true)->json();
    expect(json_encode($body))->not->toContain($blocker['id']);
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $blocker['id'], 'expected_version' => 1])->assertOk();
    $this->actingAs($member)->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $dependent['id']]))->assertOk()->assertJsonPath('is_blocked', true);
    teamPost($tenant, $member, ['kind' => 'task.dependency', 'id' => $dependent['id'], 'expected_version' => 2, 'remove_unavailable' => true])->assertOk()->assertJsonPath('result.is_blocked', false);
    [$other, $otherOwner] = teamWorkspace();
    $foreign = teamTask($other, $otherOwner);
    teamPost($tenant, $owner, ['kind' => 'task.dependency', 'id' => $dependent['id'], 'expected_version' => 3, 'blocker_id' => $foreign['id']])->assertForbidden();
});

test('dependency unblocking is recipient-authorized internal Inbox only and replay-safe', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $dependent = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Next visit', 'assignee_id' => $member->id, 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    $blocker = teamTask($tenant, $owner, $project);
    teamPost($tenant, $owner, ['kind' => 'task.dependency', 'id' => $dependent['id'], 'expected_version' => 1, 'blocker_id' => $blocker['id']])->assertOk();
    $command = ['kind' => 'task.complete', 'id' => $blocker['id'], 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()];
    teamPost($tenant, $owner, $command)->assertOk();
    teamPost($tenant, $owner, $command)->assertOk();
    expect(DB::table('team_inbox_items')->where('event_type', 'dependency.unblocked')->count())->toBe(1);
    $this->actingAs($member)->getJson(route('team.inbox', ['tenant' => $tenant->slug]))->assertOk()->assertJsonFragment(['event_type' => 'dependency.unblocked', 'task_id' => $dependent['id']]);
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

test('project templates copy reviewed structure into a private project without conversations or grants', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner, 'workspace');
    $section = (string) Str::uuid();
    $project = teamPost($tenant, $owner, ['kind' => 'project.section', 'id' => $project['id'], 'expected_version' => 1, 'section_id' => $section, 'name' => 'Inspect'])->assertOk()->json('result');
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Source inspection', 'description' => 'Original description', 'priority' => 'urgent', 'assignee_id' => $owner->id, 'due_on' => '2026-03-08', 'memberships' => [$project['id'] => ['section_id' => $section]]])->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.comment', 'id' => $task['id'], 'expected_version' => 1, 'comment_id' => (string) Str::uuid(), 'body' => 'Original conversation'])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Child checklist', 'parent_id' => $task['id']])->assertOk();
    [$project, $templateId] = teamTemplate($tenant, $owner, $project, 'project');
    expect($project['templates'][0]['task_count'])->toBe(2)->and(json_encode($project['templates']))->not->toContain('Original description');
    $newId = (string) Str::uuid();
    $command = ['kind' => 'project.from_template', 'id' => $newId, 'name' => 'Private reviewed copy', 'project_id' => $project['id'], 'project_version' => $project['version'], 'template_id' => $templateId, 'template_version' => 1, 'acknowledge_sharing' => true, 'idempotency_key' => (string) Str::uuid()];
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('result.privacy', 'private');
    teamPost($tenant, $owner, $command)->assertOk()->assertJsonPath('replayed', true);
    $state = app(TeamWorkRepository::class)->state($tenant->id);
    $copies = array_values(array_filter($state['tasks'], fn ($row) => isset($row['template_provenance'])));
    expect($copies)->toHaveCount(2)->and($state['projects'][$newId]['members'])->toBe([(string) $owner->id => 'editor']);
    foreach ($copies as $copy) {
        expect($copy['comments'])->toBe([])->and($copy['assignee_id'])->toBeNull()->and($copy['due_on'])->toBeNull()->and($copy['completed_at'])->toBeNull();
    }
    expect($state['tasks'][$task['id']]['comments'])->toHaveCount(1)->and($state['tasks'][$task['id']]['description'])->toBe('Original description');
    Bus::assertNothingDispatched();
});

test('task templates are versioned source-authorized and create full child tasks without source references in public results', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamTask($tenant, $owner, $project);
    teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Nested', 'parent_id' => $task['id']])->assertOk();
    [$project, $template] = teamTemplate($tenant, $owner, $project, 'task', $task['id']);
    $command = ['kind' => 'task.from_template', 'project_id' => $project['id'], 'project_version' => $project['version'], 'template_id' => $template, 'template_version' => 1, 'acknowledge_sharing' => true];
    $preview = $this->actingAs($owner)->getJson(route('team.templates.show', ['tenant' => $tenant->slug, 'id' => $project['id'], 'template' => $template]))->assertOk()->assertJsonPath('tasks.0.description', 'Native context')->json();
    expect(json_encode($preview))->not->toContain($task['id'])->and($preview['project_version'])->toBe($project['version']);
    $copy = teamPost($tenant, $member, $command)->assertOk()->assertJsonMissingPath('result.template_provenance')->json('result');
    $this->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $copy['id']]))->assertOk()->assertJsonCount(1, 'subtasks');
    teamPost($tenant, $owner, [...$command, 'template_version' => 99])->assertConflict();
    teamPost($tenant, $owner, [...$command, 'acknowledge_sharing' => false])->assertUnprocessable();
    teamPost($tenant, $owner, ['kind' => 'task.delete', 'id' => $task['id'], 'expected_version' => 1])->assertOk();
    $this->getJson(route('team.projects.show', ['tenant' => $tenant->slug, 'id' => $project['id']]))->assertOk()->assertJsonCount(0, 'templates');
    teamPost($tenant, $owner, $command)->assertForbidden();
    $this->getJson(route('team.templates.show', ['tenant' => $tenant->slug, 'id' => $project['id'], 'template' => $template]))->assertNotFound();
});

test('recurrence rejects archived or hidden project scope and never restores a deleted successor', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $public = teamProject($tenant, $owner, 'workspace');
    $private = teamProject($tenant, $owner);
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Two project repeat', 'due_on' => '2026-03-07', 'memberships' => [$public['id'] => [], $private['id'] => []]])->assertOk()->json('result');
    $task = teamRepeat($tenant, $owner, $task);
    teamPost($tenant, $member, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2])->assertForbidden();
    $done = teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 2])->assertOk()->json('result');
    $next = app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$done['next_occurrence']['id']];
    teamPost($tenant, $owner, ['kind' => 'task.delete', 'id' => $next['id'], 'expected_version' => $next['version']])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 3, 'completed' => false])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 4])->assertOk()->assertJsonPath('result.next_occurrence', null);
    expect(DB::table('team_tasks')->count())->toBe(2);
    $fresh = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Archive review', 'due_on' => '2026-03-08', 'memberships' => [$private['id'] => []]])->assertOk()->json('result');
    $fresh = teamRepeat($tenant, $owner, $fresh);
    teamPost($tenant, $owner, ['kind' => 'project.archive', 'id' => $private['id'], 'expected_version' => 1])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $fresh['id'], 'expected_version' => 2])->assertForbidden();
});

test('recurring typed values stay with their authorized projects while comments and dependency edges do not repeat', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $field = (string) Str::uuid();
    $project = teamPost($tenant, $owner, ['kind' => 'project.field', 'id' => $project['id'], 'expected_version' => 1, 'field_id' => $field, 'field_type' => 'number', 'name' => 'Count'])->assertOk()->json('result');
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Measured repeat', 'due_on' => '2026-03-07', 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    $task = teamPost($tenant, $owner, ['kind' => 'task.fields', 'id' => $task['id'], 'expected_version' => 1, 'project_id' => $project['id'], 'project_version' => 2, 'values' => [$field => 0]])->assertOk()->json('result');
    $blocker = teamTask($tenant, $owner, $project);
    $task = teamPost($tenant, $owner, ['kind' => 'task.dependency', 'id' => $task['id'], 'expected_version' => 2, 'blocker_id' => $blocker['id']])->assertOk()->json('result');
    $task = teamRepeat($tenant, $owner, $task);
    $done = teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => $task['version']])->assertOk()->json('result');
    $next = app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$done['next_occurrence']['id']];
    expect($next['custom_fields'][$project['id']][$field])->toBe(0)->and($next['blocker_ids'] ?? [])->toBe([])->and($next['comments'])->toBe([]);
});

test('only the final authorized blocker emits an internal unblock event and revocation suppresses private updates', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $dependent = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Wait for both', 'assignee_id' => $member->id, 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    $first = teamTask($tenant, $owner, $project);
    $second = teamTask($tenant, $owner, $project);
    foreach ([$first, $second] as $index => $blocker) {
        teamPost($tenant, $owner, ['kind' => 'task.dependency', 'id' => $dependent['id'], 'expected_version' => $index + 1, 'blocker_id' => $blocker['id']])->assertOk();
    }
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $first['id'], 'expected_version' => 1])->assertOk();
    expect(DB::table('team_inbox_items')->where('event_type', 'dependency.unblocked')->count())->toBe(0);
    $tenant->users()->updateExistingPivot($member->id, ['membership_active' => false]);
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $second['id'], 'expected_version' => 1])->assertOk();
    expect(DB::table('team_inbox_items')->where('event_type', 'dependency.unblocked')->count())->toBe(0);
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
});

test('template ownership tenant guests archive and restricted descendants remain enforced', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $guest = teamMember($tenant, 'guest');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamTask($tenant, $owner, $project);
    $input = ['kind' => 'project.template', 'id' => $project['id'], 'expected_version' => 1, 'template_id' => (string) Str::uuid(), 'template_type' => 'project', 'name' => 'No bypass'];
    teamPost($tenant, $member, $input)->assertForbidden();
    [$project, $template] = teamTemplate($tenant, $owner, $project, 'project');
    teamPost($tenant, $guest, ['kind' => 'project.from_template', 'name' => 'No', 'project_id' => $project['id'], 'project_version' => 2, 'template_id' => $template, 'template_version' => 1, 'acknowledge_sharing' => true])->assertForbidden();
    [$other, $otherOwner] = teamWorkspace();
    teamPost($other, $otherOwner, ['kind' => 'project.from_template', 'name' => 'No', 'project_id' => $project['id'], 'project_version' => 2, 'template_id' => $template, 'template_version' => 1, 'acknowledge_sharing' => true])->assertForbidden();
    $private = teamProject($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Restricted child', 'parent_id' => $task['id'], 'memberships' => [$private['id'] => []]])->assertOk();
    teamPost($tenant, $owner, [...$input, 'expected_version' => 2])->assertUnprocessable()->assertJsonPath('error', 'template_restricted_or_too_large');
    teamPost($tenant, $owner, ['kind' => 'project.archive', 'id' => $project['id'], 'expected_version' => 2])->assertOk();
    teamPost($tenant, $owner, ['kind' => 'project.from_template', 'name' => 'No', 'project_id' => $project['id'], 'project_version' => 3, 'template_id' => $template, 'template_version' => 1, 'acknowledge_sharing' => true])->assertForbidden();
});

test('template copies preserve duplicate section identities and reviewed child order', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $sections = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ($sections as $index => $section) {
        $project = teamPost($tenant, $owner, ['kind' => 'project.section', 'id' => $project['id'], 'expected_version' => $index + 1, 'section_id' => $section, 'name' => 'Same label'])->assertOk()->json('result');
    }
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Ordered source', 'memberships' => [$project['id'] => ['section_id' => $sections[1]]]])->assertOk()->json('result');
    $one = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'First originally', 'parent_id' => $task['id']])->assertOk()->json('result');
    $two = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Second originally', 'parent_id' => $task['id']])->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.subtask_order', 'id' => $task['id'], 'expected_version' => 1, 'child_ids' => [$two['id'], $one['id']]])->assertOk();
    [$project, $template] = teamTemplate($tenant, $owner, $project, 'task', $task['id']);
    $copy = teamPost($tenant, $owner, ['kind' => 'task.from_template', 'project_id' => $project['id'], 'project_version' => $project['version'], 'template_id' => $template, 'template_version' => 1, 'acknowledge_sharing' => true])->assertOk()->json('result');
    expect($copy['memberships'][$project['id']]['section_id'])->toBe($sections[1]);
    $this->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $copy['id']]))->assertOk()->assertJsonPath('subtasks.0.title', 'Second originally')->assertJsonPath('subtasks.1.title', 'First originally');
    teamPost($tenant, $owner, ['kind' => 'project.template', 'id' => $project['id'], 'expected_version' => $project['version'], 'template_id' => $template, 'remove' => true])->assertOk()->assertJsonCount(0, 'result.templates');
});

test('snapshot size guard refuses partial projects atomically and guest downgrade hides owner controls', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    for ($index = 0; $index < 51; $index++) {
        teamTask($tenant, $owner, $project);
    }
    teamPost($tenant, $owner, ['kind' => 'project.template', 'id' => $project['id'], 'expected_version' => 1, 'template_id' => (string) Str::uuid(), 'template_type' => 'project', 'name' => 'Too many nodes'])
        ->assertUnprocessable()->assertJsonPath('error', 'template_restricted_or_too_large');
    expect(app(TeamWorkRepository::class)->state($tenant->id)['projects'][$project['id']]['version'])->toBe(1)->and(DB::table('team_tasks')->count())->toBe(51);
    $tenant->users()->updateExistingPivot($owner->id, ['role' => 'guest']);
    $this->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertOk()->assertJsonPath('projects.0.can_edit', false);
});
