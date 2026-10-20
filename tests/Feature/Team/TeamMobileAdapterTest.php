<?php

use App\Models\FieldServiceJob;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.mobile_enabled' => true]);
    Storage::fake('team_private');
    \Illuminate\Support\Facades\Http::preventStrayRequests();
});

function teamDevice(User $user, array $abilities = ['mobile:read', 'mobile:write'], $expires = null): void
{
    Auth::forgetGuards();
    Auth::shouldUse('web');
    $token = $user->createToken('fictional-team-device', $abilities, $expires ?? now()->addHour());
    test()->withToken($token->plainTextToken);
}

test('native adapter requires actual device bearer and remains independently disabled by default', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $path = route('mobile.v1.team-work.data', ['tenant' => $tenant->slug]);
    Auth::forgetGuards();
    $this->getJson($path)->assertUnauthorized();
    $this->actingAs($owner)->getJson($path)->assertForbidden(); // No cookie-to-API CSRF bypass.
    teamDevice($owner);
    config(['team_work.mobile_enabled' => false]);
    $this->getJson($path)->assertNotFound();
    config(['team_work.mobile_enabled' => true, 'team_work.enabled' => false]);
    $this->getJson($path)->assertNotFound();
});

test('native and web operate on one canonical task with header bound replay and no duplicate event', function (): void {
    [$tenant, $owner] = teamWorkspace();
    teamDevice($owner);
    $id = (string) Str::uuid();
    $key = (string) Str::uuid();
    $path = route('mobile.v1.team-work.commands', ['tenant' => $tenant->slug]);
    $command = ['kind' => 'task.create', 'id' => $id, 'title' => 'Captured on device', 'description' => 'Preserve this original text'];
    $this->postJson($path, $command)->assertUnprocessable();
    $this->withHeader('Idempotency-Key', $key)->postJson($path, $command + ['idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    $this->postJson($path, $command)->assertOk()->assertJsonPath('contract_version', 1)->assertJsonPath('result.id', $id);
    $this->postJson($path, $command)->assertOk()->assertJsonPath('replayed', true);
    $this->getJson(route('mobile.v1.team-work.tasks.show', ['tenant' => $tenant->slug, 'id' => $id]))->assertOk()->assertJsonPath('description', 'Preserve this original text');
    expect(DB::table('team_tasks')->count())->toBe(1)->and(DB::table('team_work_events')->count())->toBe(1)->and(DB::table('field_service_tasks')->count())->toBe(0);
    $this->actingAs($owner)->getJson(route('team.tasks.show', ['tenant' => $tenant->slug, 'id' => $id]))->assertOk()->assertJsonPath('title', 'Captured on device');
});

test('native abilities and project viewer roles independently forbid mutations', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $project = teamProject($tenant, $owner);
    $task = teamTask($tenant, $owner, $project);
    $guest = teamMember($tenant, 'guest');
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $guest->id, 'access' => 'viewer'])->assertOk();
    $read = route('mobile.v1.team-work.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]);
    $write = route('mobile.v1.team-work.commands', ['tenant' => $tenant->slug]);
    $command = ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 1, 'completed' => true];
    teamDevice($owner, ['mobile:read']);
    $this->getJson($read)->assertOk();
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($write, $command)->assertForbidden();
    teamDevice($owner, ['mobile:write']);
    $this->getJson($read)->assertForbidden();
    teamDevice($guest);
    $this->getJson($read)->assertOk()->assertJsonPath('can_edit', false);
    $this->postJson($write, $command)->assertForbidden();
});

test('native rechecks private access tenant membership entitlement verification token expiry and revocation', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    [$otherTenant, $other] = teamWorkspace();
    $path = route('mobile.v1.team-work.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]);
    teamDevice($other);
    $this->getJson($path)->assertNotFound();
    teamDevice($owner);
    $this->getJson(route('mobile.v1.team-work.tasks.show', ['tenant' => $otherTenant->slug, 'id' => $task['id']]))->assertNotFound();
    DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $owner->id)->update(['membership_active' => false]);
    Auth::forgetGuards();
    $this->getJson($path)->assertNotFound();
    DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $owner->id)->update(['membership_active' => true]);
    $owner->forceFill(['email_verified_at' => null])->save();
    Auth::forgetGuards();
    $this->getJson($path)->assertForbidden();
    $owner->forceFill(['email_verified_at' => now()])->save();
    TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->where('module_key', 'team_work')->update(['enabled_status' => 'disabled']);
    Auth::forgetGuards();
    $this->getJson($path)->assertForbidden();
    teamDevice($owner, ['mobile:read'], now()->subMinute());
    $this->getJson($path)->assertUnauthorized();
    teamDevice($owner);
    $owner->tokens()->delete();
    Auth::forgetGuards();
    $this->getJson($path)->assertUnauthorized();
});

test('native private upload uses same chunk ledger preserves retry and quarantine without scan claims', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    teamDevice($owner);
    $bytes = 'Fictional native adapter original.';
    $key = (string) Str::uuid();
    $begin = route('mobile.v1.team-work.files.begin', ['tenant' => $tenant->slug, 'task' => $task['id']]);
    $body = ['filename' => 'fictional.txt', 'mime_type' => 'text/plain', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    $this->postJson($begin, $body)->assertUnprocessable();
    $upload = $this->withHeader('Idempotency-Key', $key)->postJson($begin, $body)->assertOk()->json();
    $this->postJson($begin, $body)->assertOk()->assertJsonPath('id', $upload['id']);
    $chunk = route('mobile.v1.team-work.files.chunk', ['tenant' => $tenant->slug, 'upload' => $upload['id'], 'index' => 0]);
    foreach ([1, 2] as $_) {
        $this->call('PUT', $chunk, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes)->assertOk();
    }
    $complete = route('mobile.v1.team-work.files.complete', ['tenant' => $tenant->slug, 'upload' => $upload['id']]);
    $this->postJson($complete)->assertOk()->assertJsonPath('status', 'quarantined');
    $this->getJson(route('mobile.v1.team-work.files.download', ['tenant' => $tenant->slug, 'upload' => $upload['id']]))->assertNotFound();
    expect(DB::table('team_file_uploads')->count())->toBe(1)->and(DB::table('team_file_chunks')->count())->toBe(1)->and(DB::table('team_work_events')->count())->toBe(1);
});

test('native My Tasks project and Inbox reuse the existing web assignment and recipient history', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Web assignment visible on device', 'assignee_id' => $member->id, 'memberships' => [$project['id'] => []]])->assertOk()->json('result');
    teamDevice($member);
    $this->getJson(route('mobile.v1.team-work.data', ['tenant' => $tenant->slug, 'mine' => 1]))->assertOk()->assertJsonPath('contract_version', 1)->assertJsonPath('tasks.0.id', $task['id']);
    $this->getJson(route('mobile.v1.team-work.projects.show', ['tenant' => $tenant->slug, 'id' => $project['id']]))->assertOk()->assertJsonPath('id', $project['id']);
    $inbox = $this->getJson(route('mobile.v1.team-work.inbox', ['tenant' => $tenant->slug]))->assertOk()->assertJsonCount(1, 'items')->json('items.0');
    $update = route('mobile.v1.team-work.inbox.update', ['tenant' => $tenant->slug, 'id' => $inbox['id']]);
    $this->postJson($update, ['read' => true])->assertOk();
    $this->postJson($update, ['archived' => true])->assertOk();
    $this->postJson($update, ['archived' => true])->assertOk();
    $this->getJson(route('mobile.v1.team-work.inbox', ['tenant' => $tenant->slug]))->assertOk()->assertJsonCount(0, 'items');
    expect(DB::table('team_inbox_items')->where('id', $inbox['id'])->whereNotNull('read_at')->whereNotNull('archived_at')->count())->toBe(1);
});

test('native Field link uses shared adapter while a crew member cannot discover its private Team task', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $task = teamTask($tenant, $owner);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Native existing fictional job', 'status' => 'in_progress', 'operational_status' => 'active']);
    teamDevice($owner);
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(route('mobile.v1.team-work.field-links.link', ['tenant' => $tenant->slug, 'id' => $task['id'], 'job' => $job->id]), ['expected_version' => 1])->assertOk();
    $this->getJson(route('mobile.v1.team-work.field-links.jobs', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertOk()->assertJsonPath('jobs.0.id', $job->id);
    $list = route('mobile.v1.team-work.field-links.tasks', ['tenant' => $tenant->slug, 'job' => $job->id]);
    $this->getJson($list)->assertOk()->assertJsonPath('tasks.0.id', $task['id']);
    teamDevice($member);
    $this->getJson($list)->assertOk()->assertJsonCount(0, 'tasks')->assertDontSee($task['title']);
    $this->getJson(route('mobile.v1.team-work.tasks.show', ['tenant' => $tenant->slug, 'id' => $task['id']]))->assertForbidden();
});
