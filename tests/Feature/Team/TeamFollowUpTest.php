<?php

use App\Models\TeamTask;
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
});

function reviewedFollowUp(array $source, array $changes = []): array
{
    return array_replace(['kind' => 'task.follow_up', 'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
        'source_task_id' => $source['id'], 'source_version' => $source['version'], 'title' => 'New independently reviewed work',
        'description' => 'New notes, not a copy of the original.', 'acknowledge_sharing' => true], $changes);
}

test('reviewed followup creates one canonical personal task without copying or changing source history', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $source = teamTask($tenant, $owner, $project);
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    $created = teamPost($tenant, $owner, reviewedFollowUp($source, ['assignee_id' => $owner->id, 'due_on' => '2026-12-01']))->assertOk()->assertJsonPath('replayed', false)->json('result');
    expect($created['id'])->not->toBe($source['id']);
    expect($created['memberships'])->toBe([])->and($created['parent_id'])->toBeNull()->and($created['completed_at'])->toBeNull()->and($created['comments'])->toBe([])->and($created['description'])->toBe('New notes, not a copy of the original.')->and($created['due_on'])->toBe('2026-12-01')->and($created['follow_up']['task_id'])->toBe($source['id'])->and($created)->not->toHaveKey('follow_up_source')->and($created)->not->toHaveKey('recurrence');
    $after = app(TeamWorkRepository::class)->state($tenant->id);
    expect($after['tasks'][$source['id']])->toBe($before['tasks'][$source['id']])->and($after['projects'])->toBe($before['projects']);
    expect(array_values(array_filter($after['events'], fn ($event) => $event['entity_id'] === $source['id'])))->toBe(array_values(array_filter($before['events'], fn ($event) => $event['entity_id'] === $source['id'])));
    expect(DB::table('team_work_versions')->where('entity_id', $created['id'])->count())->toBe(1)->and(DB::table('field_service_tasks')->count())->toBe(0);
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

test('comment followup keeps the exact source comment version and original text untouched', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $source = teamTask($tenant, $owner);
    $commentId = (string) Str::uuid();
    $source = teamPost($tenant, $owner, ['kind' => 'task.comment', 'id' => $source['id'], 'expected_version' => 1, 'comment_id' => $commentId, 'body' => 'Original field note, including exact punctuation!'])->assertOk()->json('result');
    $before = app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$source['id']];
    $created = teamPost($tenant, $owner, reviewedFollowUp($source, ['source_comment_id' => $commentId]))->assertOk()->assertJsonPath('result.follow_up.comment_id', $commentId)->json('result');
    $state = app(TeamWorkRepository::class)->state($tenant->id);
    expect($state['tasks'][$source['id']])->toBe($before)->and($state['tasks'][$created['id']]['follow_up_source']['version'])->toBe(2)->and($created['comments'])->toBe([]);
    expect(json_encode($state['tasks'][$created['id']]))->not->toContain('Original field note');
});

test('review source version and acknowledgement are mandatory and failed attempts are atomic', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $source = teamTask($tenant, $owner);
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    teamPost($tenant, $owner, reviewedFollowUp($source, ['acknowledge_sharing' => false]))->assertUnprocessable()->assertJsonPath('error', 'sharing_review_required')->assertJsonPath('saved', false);
    teamPost($tenant, $owner, reviewedFollowUp($source, ['source_version' => 99]))->assertConflict()->assertJsonPath('error', 'conflict')->assertJsonPath('saved', false);
    $missing = reviewedFollowUp($source);
    unset($missing['source_version']);
    teamPost($tenant, $owner, $missing)->assertUnprocessable();
    expect(app(TeamWorkRepository::class)->state($tenant->id))->toBe($before);
});

test('unavailable source comment cannot create a partial followup', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $source = teamTask($tenant, $owner);
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    teamPost($tenant, $owner, reviewedFollowUp($source, ['source_comment_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonPath('error', 'source_comment_unavailable');
    expect(app(TeamWorkRepository::class)->state($tenant->id))->toBe($before);
});

test('viewer and inaccessible source cannot create followups or gain source access', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $source = teamTask($tenant, $owner);
    $member = teamMember($tenant, 'member');
    $viewer = teamMember($tenant, 'viewer');
    foreach ([$member, $viewer] as $user) {
        teamPost($tenant, $user, reviewedFollowUp($source))->assertForbidden()->assertDontSee('Native context');
    }
    expect(DB::table('team_tasks')->count())->toBe(1);
});

test('followup target and source remain within current workspace and target editor permission', function (): void {
    [$first, $owner] = teamWorkspace();
    [$second, $other] = teamWorkspace();
    $second->users()->attach($owner->id, ['role' => 'member', 'membership_active' => true]);
    $source = teamTask($first, $owner);
    $outside = teamProject($second, $other);
    $before = app(TeamWorkRepository::class)->state($first->id);
    teamPost($second, $owner, reviewedFollowUp($source))->assertForbidden();
    teamPost($first, $owner, reviewedFollowUp($source, ['memberships' => [$outside['id'] => []]]))->assertForbidden();
    $sameTenantOther = teamMember($first, 'member');
    $private = teamProject($first, $sameTenantOther);
    teamPost($first, $owner, reviewedFollowUp($source, ['memberships' => [$private['id'] => []]]))->assertForbidden();
    expect(app(TeamWorkRepository::class)->state($first->id)['tasks'])->toBe($before['tasks']);
});

test('guest needs an editable destination and cannot create a private personal followup', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $guest = teamMember($tenant, 'guest');
    $project = teamProject($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $guest->id, 'access' => 'editor'])->assertOk();
    $source = teamTask($tenant, $owner, $project);
    teamPost($tenant, $guest, reviewedFollowUp($source))->assertForbidden();
    teamPost($tenant, $guest, reviewedFollowUp($source, ['memberships' => [$project['id'] => []], 'destination_versions' => [$project['id'] => 2]]))->assertOk()->assertJsonPath('result.memberships.'.$project['id'].'.section_id', null);
});

test('assignee must be verified active and permitted in the new task independently of source access', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $source = teamTask($tenant, $owner);
    teamPost($tenant, $owner, reviewedFollowUp($source, ['assignee_id' => $member->id]))->assertUnprocessable()->assertJsonPath('error', 'assignee_lacks_access');
    $member->forceFill(['email_verified_at' => null])->save();
    teamPost($tenant, $owner, reviewedFollowUp($source, ['assignee_id' => $member->id]))->assertUnprocessable()->assertJsonPath('error', 'invalid_assignee');
    expect(DB::table('team_tasks')->count())->toBe(1);
});

test('retry is idempotent and current source revocation redacts provenance without removing intentional new work', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $member->id, 'access' => 'editor'])->assertOk();
    $source = teamTask($tenant, $owner, $project);
    $command = reviewedFollowUp($source);
    $new = teamPost($tenant, $member, $command)->assertOk()->json('result');
    $receipts = DB::table('team_work_receipts')->count();
    teamPost($tenant, $member, $command)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('result.id', $new['id']);
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 2, 'user_id' => $member->id, 'access' => null])->assertOk();
    $response = teamPost($tenant, $member, $command)->assertOk()->assertJsonPath('replayed', true)->assertDontSee($source['id'])->assertDontSee('Native context');
    expect($response->json('result'))->not->toHaveKey('follow_up')->not->toHaveKey('follow_up_source');
    expect(DB::table('team_tasks')->count())->toBe(2)->and(DB::table('team_work_receipts')->count())->toBe($receipts + 1);
    $this->actingAs($member)->getJson(route('team.data', ['tenant' => $tenant->slug]))->assertOk()->assertDontSee($source['id']);
});

test('changed payload on the same followup key cannot create or overwrite another task', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $source = teamTask($tenant, $owner);
    $command = reviewedFollowUp($source);
    teamPost($tenant, $owner, $command)->assertOk();
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    teamPost($tenant, $owner, array_replace($command, ['title' => 'Changed request']))->assertConflict()->assertJsonPath('error', 'idempotency_mismatch');
    expect(app(TeamWorkRepository::class)->state($tenant->id))->toBe($before);
});

test('source updates are identified but never overwrite independent followup text or frozen provenance', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $source = teamTask($tenant, $owner);
    $new = teamPost($tenant, $owner, reviewedFollowUp($source))->assertOk()->json('result');
    teamPost($tenant, $owner, ['kind' => 'task.update', 'id' => $source['id'], 'expected_version' => 1, 'changes' => ['title' => 'Updated source title', 'description' => 'Changed source text']])->assertOk();
    $this->actingAs($owner)->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $new['id']]))->assertOk()->assertJsonPath('follow_up.source_changed', true)->assertJsonPath('follow_up.title', 'Updated source title')->assertJsonPath('description', 'New notes, not a copy of the original.');
    expect(app(TeamWorkRepository::class)->state($tenant->id)['tasks'][$new['id']]['follow_up_source']['version'])->toBe(1);
});

test('task deep links authorize before rendering and identify the correct workspace', function (): void {
    [$first, $owner] = teamWorkspace();
    [$second] = teamWorkspace();
    $second->users()->attach($owner->id, ['role' => 'member', 'membership_active' => true]);
    $source = teamTask($first, $owner);
    $stranger = teamMember($first, 'member');
    $url = route('team.index', ['tenant' => $first->slug, 'task' => $source['id']]);
    $this->actingAs($owner)->get($url)->assertOk()->assertSee('data-initial-task="'.$source['id'].'"', false)->assertSee('data-workspace-id="'.$first->id.'"', false);
    $this->getJson(route('team.tasks.show', ['tenant' => $first->slug, 'id' => $source['id']]))->assertOk()->assertJsonPath('url', $url);
    $this->actingAs($stranger)->getJson($url)->assertForbidden()->assertDontSee('Native context');
    $this->actingAs($owner)->getJson(route('team.index', ['tenant' => $second->slug, 'task' => $source['id']]))->assertNotFound();
    $this->getJson(route('team.index', ['tenant' => $first->slug, 'task' => 'invalid']))->assertUnprocessable();
    $this->getJson(route('team.index', ['tenant' => $first->slug, 'comment' => (string) Str::uuid()]))->assertUnprocessable();
});

test('dates remain explicit and invalid dates cannot write a followup', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $source = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Timed source', 'due_at' => '2026-11-01T01:30:00-04:00'])->assertOk()->json('result');
    $new = teamPost($tenant, $owner, reviewedFollowUp($source))->assertOk()->json('result');
    expect($new['due_on'])->toBeNull()->and($new['due_at'])->toBeNull();
    teamPost($tenant, $owner, reviewedFollowUp($source, ['due_on' => '2026-02-30']))->assertUnprocessable();
    teamPost($tenant, $owner, reviewedFollowUp($source, ['due_on' => '2026-11-01', 'due_at' => '2026-11-01T01:30:00-04:00']))->assertUnprocessable()->assertJsonPath('error', 'invalid_date');
    expect(DB::table('team_tasks')->count())->toBe(2);
});

test('native write ability and header-bound retry use the same followup command and canonical deep link', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $source = teamTask($tenant, $owner);
    $command = reviewedFollowUp($source);
    Auth::forgetGuards();
    Auth::shouldUse('web');
    $token = $owner->createToken('fictional-followup-device', ['mobile:read', 'mobile:write'], now()->addHour());
    $this->withToken($token->plainTextToken);
    $path = route('mobile.v1.team-work.commands', ['tenant' => $tenant->slug]);
    $this->postJson($path, $command)->assertUnprocessable();
    $this->withHeader('Idempotency-Key', $command['idempotency_key'])->postJson($path, $command)->assertOk()->assertJsonPath('contract_version', 1);
    $this->postJson($path, $command)->assertOk()->assertJsonPath('replayed', true);
    $this->getJson(route('mobile.v1.team-work.tasks.show', ['tenant' => $tenant->slug, 'id' => $command['id']]))->assertOk()->assertJsonPath('url', route('team.index', ['tenant' => $tenant->slug, 'task' => $command['id']]));
    expect(TeamTask::query()->count())->toBe(2);
});

test('destination revision review prevents silently widening a followup after project privacy changes', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $source = teamTask($tenant, $owner);
    $target = teamProject($tenant, $owner);
    $command = reviewedFollowUp($source, ['memberships' => [$target['id'] => []], 'destination_versions' => [$target['id'] => 1]]);
    teamPost($tenant, $owner, ['kind' => 'project.update', 'id' => $target['id'], 'expected_version' => 1, 'changes' => ['privacy' => 'workspace'], 'acknowledge_sharing' => true])->assertOk();
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    teamPost($tenant, $owner, $command)->assertConflict()->assertJsonPath('saved', false);
    expect(app(TeamWorkRepository::class)->state($tenant->id))->toBe($before);
    teamPost($tenant, $owner, array_replace($command, ['destination_versions' => [$target['id'] => 2]]))->assertOk()->assertJsonPath('result.memberships.'.$target['id'].'.section_id', null);
    expect(DB::table('team_tasks')->count())->toBe(2);
});

test('each followup destination needs its exact reviewed version and no unrelated destination versions', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $source = teamTask($tenant, $owner);
    $target = teamProject($tenant, $owner);
    $before = app(TeamWorkRepository::class)->state($tenant->id);
    teamPost($tenant, $owner, reviewedFollowUp($source, ['memberships' => [$target['id'] => []]]))->assertUnprocessable()->assertJsonPath('error', 'invalid_destination_review');
    teamPost($tenant, $owner, reviewedFollowUp($source, ['destination_versions' => [$target['id'] => 1]]))->assertUnprocessable()->assertJsonPath('error', 'invalid_destination_review');
    expect(app(TeamWorkRepository::class)->state($tenant->id))->toBe($before);
});
