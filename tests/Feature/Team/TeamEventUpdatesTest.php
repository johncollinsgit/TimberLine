<?php

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Operations\EventTasks;
use App\Services\Team\Operations\EventUpdates;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Operations\OperationsProviders;
use App\Services\Team\TeamFileScanner;
use App\Services\Team\TeamFileService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'team_operations.asana_write_enabled' => true]);
    Http::preventStrayRequests();
    Storage::fake('team_private');
    app()->instance(TeamFileScanner::class, new class extends TeamFileScanner
    {
        public function scan(string $path, string $mime, string $hash): string
        {
            return 'clean';
        }
    });
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'Events', 'slug' => 'events', 'profile' => 'events', 'settings' => []]);
    $this->event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'kind' => 'event', 'title' => 'Fictional market', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'research', 'data' => []]);
    $this->query = '?tenant='.$this->tenant->slug.'&area='.$this->area->id;
    $this->updates = '/team/events/'.$this->event->id.'/updates'.$this->query;
    $this->actingAs($this->owner);
});

function eventUpdateUpload($case, string $bytes = 'Fictional event evidence', array $extra = []): array
{
    $meta = array_replace(['client_key' => (string) Str::uuid(), 'filename' => 'Fictional.txt', 'mime_type' => 'text/plain', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], $extra);
    $upload = $case->postJson('/team/events/'.$case->event->id.'/updates/files'.$case->query, $meta)->assertOk()->assertJsonPath('target_type', 'event')->assertJsonPath('event_id', $case->event->id)->assertJsonPath('task_id', null)->json();
    foreach (str_split($bytes, TeamFileService::CHUNK_BYTES) as $index => $part) {
        $case->call('PUT', '/team/files/'.$upload['id'].'/chunks/'.$index.'?tenant='.$case->tenant->slug, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $part)->assertOk();
    }

    return $case->postJson('/team/files/'.$upload['id'].'/complete?tenant='.$case->tenant->slug)->assertOk()->json();
}

function eventUpdateSource($case, ?Closure $post = null): void
{
    $case->connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $case->tenant->id, 'area_id' => $case->area->id, 'user_id' => $case->owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional account', 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional-token'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200'], 'external_user_id' => '300', 'scopes' => 'tasks:read tasks:write stories:write', 'send_authorized' => true]]);
    $case->task = ['gid' => '400', 'name' => 'Fictional source event', 'notes' => 'Fictional organizer notes', 'completed' => false, 'due_on' => '2026-11-20', 'modified_at' => '2026-11-01T12:00:00Z', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]];
    $case->event->update(['connection_id' => $case->connection->id, 'data' => ['source_workspace' => '100', 'source_project' => '200', 'source_id' => '400', '_generation' => 1]]);
    $case->stories = [];
    $case->story_post_count = 0;
    Http::fake(function ($request) use ($case, $post) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/tasks/400/stories')) {
            $case->story_post_count++;
            if ($post) {
                return $post($request);
            }
            $story = ['gid' => '500', 'text' => $request['data']['text'], 'resource_subtype' => 'comment_added', 'created_at' => now()->toIso8601String(), 'created_by' => ['name' => 'Fictional author']];
            $case->stories[] = $story;

            return Http::response(['data' => $story]);
        }
        if (str_contains($request->url(), '/stories')) {
            return Http::response(['data' => $case->stories]);
        }
        if (isset($case->on_source_read)) {
            ($case->on_source_read)($request);
        }

        return Http::response(['data' => $case->task]);
    });
}

function eventUpdateObserve(string $kind, Closure $observe, Closure $perform): mixed
{
    $previous = TeamOperationAttempt::getEventDispatcher();
    TeamOperationAttempt::setEventDispatcher(clone $previous);
    try {
        TeamOperationAttempt::{$kind}($observe);

        return $perform();
    } finally {
        TeamOperationAttempt::setEventDispatcher($previous);
    }
}

function eventUpdateAutocommitRace(Closure $perform): mixed
{
    $db = DB::connection();
    $safe = $db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:';
    $safe = $safe || ($db->getDriverName() === 'mysql' && getenv('TEAM_WORK_DISPOSABLE_MYSQL') === '1' && in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true) && ($db->getDatabaseName() === 'everbranch_ci' || str_starts_with($db->getDatabaseName(), 'codex_teams_')));
    if (! app()->environment('testing') || ! $safe) {
        throw new RuntimeException('Receipt race needs an explicitly disposable database.');
    }
    // A committed competing insert must survive the failed candidate insert.
    // A test's outer savepoint would otherwise roll both of them back together,
    // which cannot represent a separately committed request in production.
    while ($db->transactionLevel() > 0) {
        $db->commit();
    }
    try {
        return $perform();
    } finally {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
    }
}

test('native event updates use one durable receipt and remain tenant and event scoped', function () {
    $input = ['request_id' => (string) Str::uuid(), 'body' => 'Booth is ready.'];
    $posted = $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('item.body', 'Booth is ready.')->assertJsonPath('item.origin', 'teams')->json();
    $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('id', $posted['id']);
    $this->postJson($this->updates, [...$input, 'body' => 'Different text'])->assertConflict();
    expect(TeamOperationAttempt::where('kind', 'event.update')->count())->toBe(1);
    $this->getJson($this->updates)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.body', 'Booth is ready.')->assertJsonPath('can_post', true)->assertJsonPath('requires_update_connection', false);
    [$other, $outsider] = teamWorkspace();
    $this->actingAs($outsider)->getJson('/team/events/'.$this->event->id.'/updates?tenant='.$other->slug)->assertNotFound();
    $this->actingAs($this->owner)->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => ''])->assertUnprocessable();
});

test('typed event files cannot be exposed by a graph task with the same UUID or a forged target type', function () {
    $file = eventUpdateUpload($this);
    $intruder = teamMember($this->tenant, 'member');
    teamPost($this->tenant, $intruder, ['kind' => 'task.create', 'id' => $this->event->id, 'title' => 'Unrelated private task', 'memberships' => []])->assertOk();
    $this->actingAs($intruder)->getJson('/team/tasks/'.$this->event->id.'/files?tenant='.$this->tenant->slug)->assertOk()->assertJsonCount(0, 'files');
    $this->getJson('/team/files/'.$file['id'].'/download?tenant='.$this->tenant->slug)->assertForbidden();
    $this->postJson('/team/files/'.$file['id'].'/complete?tenant='.$this->tenant->slug)->assertForbidden();
    $this->getJson('/team/events/'.$this->event->id.'/updates/files/'.$file['id'].'/preview?tenant='.$this->tenant->slug)->assertForbidden();
    $this->actingAs($this->owner)->getJson('/team/tasks/'.$this->event->id.'/files?tenant='.$this->tenant->slug)->assertNotFound();
    $this->getJson('/team/files/'.$file['id'].'/download?tenant='.$this->tenant->slug)->assertOk();
    expect(DB::table('team_file_uploads')->where('id', $file['id'])->first())->task_id->toBe('event-updates')->target_type->toBe('event');
    expect(DB::table('team_tasks')->where('id', $this->event->id)->value('version'))->toBe(1);
});

test('photo only updates reuse scanned bytes and protect inline previews and event association', function () {
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $file = eventUpdateUpload($this, $bytes, ['filename' => 'Booth.png', 'mime_type' => 'image/png']);
    $reply = $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => '', 'file_ids' => [$file['id']]])->assertOk()->assertJsonPath('item.body', '')->assertJsonPath('item.attachments.0.filename', 'Booth.png')->json();
    $preview = $this->get($reply['item']['attachments'][0]['preview_url'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($preview->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $otherEvent = $this->event->replicate();
    $otherEvent->id = (string) Str::uuid();
    $otherEvent->source_key = hash('sha256', Str::uuid());
    $otherEvent->save();
    $this->getJson('/team/events/'.$otherEvent->id.'/updates/files/'.$file['id'].'/preview'.$this->query)->assertNotFound();
    $this->postJson('/team/events/'.$otherEvent->id.'/updates'.$this->query, ['request_id' => (string) Str::uuid(), 'body' => 'Wrong event', 'file_ids' => [$file['id']]])->assertUnprocessable();
});

test('a scanner outage never publishes attachments or lets an update attach them', function () {
    app()->instance(TeamFileScanner::class, new class extends TeamFileScanner
    {
        public function scan(string $path, string $mime, string $hash): string
        {
            return 'unavailable';
        }
    });
    $file = eventUpdateUpload($this);
    expect($file['status'])->toBe('quarantined')->and($file['downloadable'])->toBeFalse();
    $this->getJson('/team/files/'.$file['id'].'/download?tenant='.$this->tenant->slug)->assertNotFound();
    $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'Still scanning', 'file_ids' => [$file['id']]])->assertUnprocessable();
    expect(TeamOperationAttempt::where('kind', 'event.update')->count())->toBe(0);
});

test('event and task uploads cannot reuse one client key across target types', function () {
    $task = teamPost($this->tenant, $this->owner, ['kind' => 'task.create', 'id' => $this->event->id, 'title' => 'Native task', 'memberships' => []])->assertOk()->json('result');
    $key = (string) Str::uuid();
    $file = eventUpdateUpload($this, 'Fictional', ['client_key' => $key]);
    $input = ['client_key' => $key, 'filename' => 'Fictional.txt', 'mime_type' => 'text/plain', 'size' => 9, 'sha256' => hash('sha256', 'Fictional')];
    $this->postJson('/team/tasks/'.$task['id'].'/files?tenant='.$this->tenant->slug, $input)->assertConflict();
    $this->getJson('/team/tasks/'.$task['id'].'/files?tenant='.$this->tenant->slug)->assertOk()->assertJsonCount(0, 'files');
    $this->postJson('/team/tasks/'.$task['id'].'/files?tenant='.$this->tenant->slug, [...$input, 'client_key' => (string) Str::uuid(), 'target_type' => 'event', 'event_id' => $this->event->id])->assertOk()->assertJsonPath('target_type', 'task')->assertJsonPath('event_id', null);
    expect(DB::table('team_file_uploads')->where('id', $file['id'])->value('event_id'))->toBe($this->event->id);
});

test('source updates use the Asana command once and retain typed file association on the source story', function () {
    eventUpdateSource($this);
    $file = eventUpdateUpload($this);
    $input = ['request_id' => (string) Str::uuid(), 'body' => 'Setup is complete.', 'file_ids' => [$file['id']]];
    $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('item.id', 'asana:500')->assertJsonPath('item.origin', 'asana')->assertJsonCount(1, 'item.attachments');
    $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('status', 'succeeded');
    $posts = Http::recorded(fn ($request) => $request->method() === 'POST');
    expect($posts)->toHaveCount(1)->and($posts->first()[0]['data']['text'])->toContain('Setup is complete.', 'Files in Teams:', '/team/files/'.$file['id'].'/download');
    $this->getJson($this->updates)->assertOk()->assertJsonPath('items.0.id', 'asana:500')->assertJsonPath('items.0.body', 'Setup is complete.')->assertJsonPath('items.0.attachments.0.id', $file['id']);
    expect(DB::table('team_tasks')->count())->toBe(0);
});

test('uncertain source comments never retry a provider POST and expose only the actors original recovery intent', function () {
    eventUpdateSource($this, fn () => throw new ConnectionException('Fictional lost response'));
    $input = ['request_id' => (string) Str::uuid(), 'body' => 'Original update'];
    $first = $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('status', 'unknown')->json();
    $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('id', $first['id'])->assertJsonPath('status', 'unknown');
    $this->postJson('/team/events/'.$this->event->id.'/updates/'.$first['id'].'/reconcile'.$this->query)->assertOk()->assertJsonPath('status', 'unknown');
    expect($this->story_post_count)->toBe(1);
    $this->getJson($this->updates)->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('pending.0.request_id', $input['request_id'])->assertJsonPath('pending.0.body', 'Original update');
});

test('source or area access revoked after upload blocks every remaining file and update action', function () {
    eventUpdateSource($this);
    $file = eventUpdateUpload($this);
    $this->task = [...$this->task, 'memberships' => []];
    $this->getJson('/team/files/'.$file['id'].'/download?tenant='.$this->tenant->slug)->assertForbidden();
    $this->postJson('/team/files/'.$file['id'].'/complete?tenant='.$this->tenant->slug)->assertForbidden();
    $this->getJson($this->updates)->assertForbidden();
    $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'No longer admitted'])->assertForbidden();
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(0);
});

test('only deliberate source review closes an uncertain comment and never turns it into confirmed evidence', function () {
    eventUpdateSource($this, function ($request) {
        if ($this->story_post_count === 1) {
            throw new ConnectionException('Fictional lost response');
        }

        return Http::response(['data' => ['gid' => '501', 'text' => $request['data']['text']]]);
    });
    $original = ['request_id' => (string) Str::uuid(), 'body' => 'Uncertain original'];
    $receipt = $this->postJson($this->updates, $original)->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('can_acknowledge', true)->json();
    $review = '/team/events/'.$this->event->id.'/updates/'.$receipt['id'].'/reconcile'.$this->query;
    $this->postJson($review, ['acknowledge' => true])->assertUnprocessable();
    $this->postJson($review, [])->assertOk()->assertJsonPath('status', 'unknown');
    $this->postJson($review, ['acknowledge' => true, 'reviewed' => true])->assertOk()->assertJsonPath('status', 'reviewed')->assertJsonPath('item', null)->assertJsonPath('file_ids', []);
    $this->postJson($this->updates, $original)->assertOk()->assertJsonPath('status', 'reviewed');
    expect($this->story_post_count)->toBe(1);
    $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'Deliberate new update'])->assertOk()->assertJsonPath('status', 'succeeded');
    expect($this->story_post_count)->toBe(2);
    $retained = TeamOperationAttempt::findOrFail($receipt['id']);
    expect($retained->payload['body'])->toBe('Uncertain original')->and($retained->result['reviewed_by'])->toBe($this->owner->id);
});

test('source comment permission is required and Asana awaiting creation never silently becomes a native discussion', function () {
    eventUpdateSource($this);
    $settings = $this->connection->settings;
    $settings['scopes'] = 'tasks:read tasks:write';
    $this->connection->update(['settings' => $settings]);
    $this->getJson($this->updates)->assertOk()->assertJsonPath('can_post', false)->assertJsonPath('requires_update_connection', true);
    $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'Cannot post'])->assertForbidden();
    $this->event->update(['connection_id' => null, 'data' => ['source_authority' => 'asana', 'source_sync_status' => 'awaiting_source_creation']]);
    $this->getJson($this->updates)->assertOk()->assertJsonPath('can_post', false)->assertJsonCount(0, 'items');
    $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'No native fallback'])->assertForbidden();
    expect(TeamOperationAttempt::where('kind', 'event.update')->count())->toBe(0);
});

test('native update continuation is bound to event and current viewer', function () {
    for ($i = 0; $i < 31; $i++) {
        TeamOperationAttempt::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'record_id' => $this->event->id, 'user_id' => $this->owner->id, 'request_id' => (string) Str::uuid(), 'kind' => 'event.update', 'input_hash' => hash('sha256', $i), 'status' => 'succeeded', 'payload' => ['body' => 'Fictional '.$i, 'origin' => 'teams', 'file_ids' => []], 'result' => ['posted_at' => now()->toIso8601String()]]);
    }
    $page = $this->getJson($this->updates)->assertOk()->assertJsonCount(30, 'items')->assertJsonPath('has_more', true)->json();
    $this->getJson($this->updates.'&cursor='.urlencode($page['next_cursor']))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('has_more', false);
    $viewer = teamMember($this->tenant, 'viewer');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($viewer)->getJson($this->updates.'&cursor='.urlencode($page['next_cursor']))->assertUnprocessable();
    $this->getJson($this->updates)->assertOk()->assertJsonPath('can_post', false)->assertJsonPath('can_upload', false)->assertJsonPath('requires_update_connection', false)->assertJsonCount(0, 'pending');
    $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'Viewer cannot post'])->assertForbidden();
});

test('native feed orders a late successful retry by posting time and keeps that position stable on replay', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-01T12:00:00Z'));
    $input = ['request_id' => (string) Str::uuid(), 'body' => 'Prepared earlier, posted last'];
    $receipt = $this->postJson($this->updates, $input)->assertOk()->json();
    // Retain the real frozen payload but simulate an interrupted native commit.
    TeamOperationAttempt::whereKey($receipt['id'])->firstOrFail()->update(['status' => 'prepared', 'result' => null]);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-08T12:00:00Z'));
    for ($i = 0; $i < 30; $i++) {
        $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'Posted before retry '.$i])->assertOk();
    }
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-08T13:00:00Z'));
    $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('item.created_at', '2026-11-08T13:00:00+00:00');
    $page = $this->getJson($this->updates)->assertOk()->assertJsonCount(30, 'items')->assertJsonPath('items.0.body', $input['body'])->json();
    $older = $this->getJson($this->updates.'&cursor='.urlencode($page['next_cursor']))->assertOk()->assertJsonCount(1, 'items')->json();
    expect(array_intersect(array_column($page['items'], 'id'), array_column($older['items'], 'id')))->toBe([]);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09T12:00:00Z'));
    $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('item.created_at', '2026-11-08T13:00:00+00:00');
    $this->postJson('/team/events/'.$this->event->id.'/updates/'.$receipt['id'].'/reconcile'.$this->query)->assertOk()->assertJsonPath('item.created_at', '2026-11-08T13:00:00+00:00');
    expect(TeamOperationAttempt::findOrFail($receipt['id'])->updated_at->toIso8601String())->toBe('2026-11-08T13:00:00+00:00');
    $this->travelBack();
});

test('native finalization retires a stale authority when adoption wins before its event row lock', function () {
    $request = (string) Str::uuid();
    eventUpdateObserve('created', function ($attempt) use ($request) {
        if ($attempt->request_id === $request) {
            TeamOperationRecord::whereKey($attempt->record_id)->firstOrFail()->update(['data' => ['source_authority' => 'asana'], 'version' => 2]);
        }
    }, fn () => $this->postJson($this->updates, ['request_id' => $request, 'body' => 'Not a source post'])->assertConflict());
    $saved = TeamOperationAttempt::where('request_id', $request)->firstOrFail();
    expect($saved->status)->toBe('prepared')->and($saved->result)->toBeNull();
    $this->getJson($this->updates)->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('can_post', false);
    Http::assertNothingSent();
});

test('native finalization rejects a withdrawn or differently authoritative work area before committing', function () {
    $request = (string) Str::uuid();
    eventUpdateObserve('created', function ($attempt) use ($request) {
        if ($attempt->request_id === $request) {
            TeamOperationRecord::whereKey($attempt->record_id)->update(['status' => 'withdrawn']);
        }
    }, fn () => $this->postJson($this->updates, ['request_id' => $request, 'body' => 'Withdrawn during admission'])->assertNotFound());
    expect(TeamOperationAttempt::where('request_id', $request)->value('status'))->toBe('prepared');
    $this->event->refresh()->update(['status' => 'research']);
    $request = (string) Str::uuid();
    eventUpdateObserve('created', function ($attempt) use ($request) {
        if ($attempt->request_id === $request) {
            TeamArea::whereKey($attempt->area_id)->firstOrFail()->update(['settings' => ['source_authority' => 'asana']]);
        }
    }, fn () => $this->postJson($this->updates, ['request_id' => $request, 'body' => 'Area authority changed'])->assertConflict());
    expect(TeamOperationAttempt::where('request_id', $request)->value('status'))->toBe('prepared');
});

test('a same request inserted concurrently returns its one durable receipt instead of a duplicate key error', function () {
    $request = (string) Str::uuid();
    $winnerId = (string) Str::uuid();
    eventUpdateAutocommitRace(fn () => eventUpdateObserve('creating', function ($attempt) use ($request, $winnerId) {
        if ($attempt->request_id === $request) {
            DB::table('team_operation_attempts')->insert([...$attempt->getAttributes(), 'id' => $winnerId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }, fn () => $this->postJson($this->updates, ['request_id' => $request, 'body' => 'One concurrent update'])->assertOk()->assertJsonPath('id', $winnerId)->assertJsonPath('status', 'succeeded')));
    expect(TeamOperationAttempt::where('request_id', $request)->count())->toBe(1);
    $this->getJson($this->updates)->assertOk()->assertJsonCount(1, 'items');
});

test('a conflicting request inserted concurrently is rejected without exposing or replacing its receipt', function () {
    $request = (string) Str::uuid();
    $winnerId = (string) Str::uuid();
    eventUpdateAutocommitRace(fn () => eventUpdateObserve('creating', function ($attempt) use ($request, $winnerId) {
        if ($attempt->request_id === $request) {
            DB::table('team_operation_attempts')->insert([...$attempt->getAttributes(), 'id' => $winnerId, 'input_hash' => hash('sha256', 'Different concurrent content'), 'created_at' => now(), 'updated_at' => now()]);
        }
    }, fn () => $this->postJson($this->updates, ['request_id' => $request, 'body' => 'Must not replace winner'])->assertConflict()));
    expect(TeamOperationAttempt::where('request_id', $request)->count())->toBe(1)->and(TeamOperationAttempt::findOrFail($winnerId)->status)->toBe('prepared');
});

test('edited and deleted Asana comments never resurrect an original receipt body or its files', function () {
    eventUpdateSource($this);
    $file = eventUpdateUpload($this);
    $receipt = $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'Old sensitive instruction', 'file_ids' => [$file['id']]])->assertOk()->json();
    $this->getJson($this->updates)->assertOk()->assertJsonPath('items.0.body', 'Old sensitive instruction')->assertJsonCount(1, 'items.0.attachments');
    $this->stories[0] = [...$this->stories[0], 'text' => 'Corrected source instruction', 'created_by' => ['name' => 'Current author']];
    $this->getJson($this->updates)->assertOk()->assertJsonPath('items.0.body', 'Corrected source instruction')->assertJsonPath('items.0.author_name', 'Current author')->assertJsonCount(0, 'items.0.attachments');
    $this->stories = [];
    $this->getJson($this->updates)->assertOk()->assertJsonCount(0, 'items');
    $original = TeamOperationAttempt::findOrFail($receipt['id']);
    expect($original->payload['body'])->toBe('Old sensitive instruction')->and($original->payload['file_ids'])->toBe([$file['id']]);
});

test('reviewing an unclaimed source draft fences an old poster before it can create its child', function () {
    eventUpdateSource($this);
    $input = ['request_id' => (string) Str::uuid(), 'body' => 'Retained before child creation'];
    eventUpdateAutocommitRace(fn () => eventUpdateObserve('created', function ($attempt) use ($input) {
        if ($attempt->request_id === $input['request_id']) {
            $reviewed = app(EventUpdates::class)->reconcile($this->tenant, $this->owner, $this->event->fresh(), $attempt->id, true, 'unsent');
            expect($reviewed['status'])->toBe('reviewed')->and($reviewed['not_sent'])->toBeTrue();
        }
    }, fn () => $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('status', 'reviewed')->assertJsonPath('not_sent', true)->assertJsonPath('item', null)));
    $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('status', 'reviewed');
    expect($this->story_post_count)->toBe(0)->and(TeamOperationAttempt::where('kind', 'asana.update')->count())->toBe(1);
    $retained = TeamOperationAttempt::where('kind', 'event.update')->firstOrFail();
    $source = $retained->payload['source'];
    $child = $this->postJson('/team/events/asana/changes'.$this->query, ['connection_id' => $source['connection_id'], 'task_id' => $source['task_id'], 'request_id' => $source['request_id'], 'version' => $source['version'], 'changes' => ['comment' => $retained->payload['comment']]])->assertOk()->assertJsonPath('status', 'reviewed')->json();
    $this->postJson('/team/events/asana/changes/'.$child['id'].'/dispatch'.$this->query, ['reviewed' => true])->assertOk()->assertJsonPath('status', 'reviewed');
    expect($this->story_post_count)->toBe(0)->and(TeamOperationAttempt::where('kind', 'asana.update')->count())->toBe(1);
});

test('a changed source draft with a prepared child can be explicitly reviewed as not sent and edited safely', function () {
    eventUpdateSource($this);
    $file = eventUpdateUpload($this);
    $this->on_source_read = function () {
        if (TeamOperationAttempt::where(['kind' => 'asana.update', 'status' => 'prepared'])->exists()) {
            $this->task['modified_at'] = '2026-11-08T14:00:00Z';
            $this->on_source_read = null;
        }
    };
    $input = ['request_id' => (string) Str::uuid(), 'body' => 'Changed before claim', 'file_ids' => [$file['id']]];
    $this->postJson($this->updates, $input)->assertConflict();
    $pending = $this->getJson($this->updates)->assertOk()->assertJsonPath('pending.0.status', 'prepared')->assertJsonPath('pending.0.can_acknowledge', true)->assertJsonPath('pending.0.review_kind', 'unsent')->json('pending.0');
    $review = '/team/events/'.$this->event->id.'/updates/'.$pending['id'].'/reconcile'.$this->query;
    $this->postJson($review, ['reviewed' => true, 'acknowledge' => true])->assertConflict();
    $this->postJson($review, ['reviewed' => true, 'acknowledge' => true, 'review_kind' => 'unsent'])->assertOk()->assertJsonPath('status', 'reviewed')->assertJsonPath('not_sent', true)->assertJsonPath('body', $input['body'])->assertJsonPath('file_ids.0', $file['id']);
    $child = TeamOperationAttempt::where('kind', 'asana.update')->firstOrFail();
    expect($child->status)->toBe('reviewed')->and($child->result['not_sent'])->toBeTrue()->and($this->story_post_count)->toBe(0);
    // Even the pre-existing generic dispatch endpoint cannot claim this child.
    $this->postJson('/team/events/asana/changes/'.$child->id.'/dispatch'.$this->query, ['reviewed' => true])->assertOk()->assertJsonPath('status', 'reviewed');
    $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('not_sent', true);
    expect($this->story_post_count)->toBe(0);
    $this->postJson($this->updates, [...$input, 'request_id' => (string) Str::uuid(), 'body' => 'Explicit edited update'])->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonCount(1, 'item.attachments');
    expect($this->story_post_count)->toBe(1);
});

test('a claimed child can never be called unsent even without a shared cache lock', function () {
    app()->instance(EventTasks::class, new class(app(OperationsAccess::class), app(OperationsProviders::class)) extends EventTasks
    {
        public function withSourceTaskLock(Tenant $tenant, string $workspace, string $taskId, callable $perform): mixed
        {
            // Model the process-local cache store of two separate workers.
            return $perform();
        }
    });
    eventUpdateSource($this, function ($request) {
        $parent = TeamOperationAttempt::where('kind', 'event.update')->firstOrFail();
        $current = app(EventUpdates::class)->listing($this->tenant, $this->owner, $this->event->fresh());
        expect($current['pending'][0]['status'])->toBe('executing')->and($current['pending'][0]['can_acknowledge'])->toBeFalse();
        $review = app(EventUpdates::class)->reconcile($this->tenant, $this->owner, $this->event->fresh(), $parent->id, true, 'unsent');
        expect($review['status'])->toBe('executing')->and($review['not_sent'])->toBeFalse();

        return Http::response(['data' => ['gid' => '501', 'text' => $request['data']['text']]]);
    });
    $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'Already claimed'])->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('not_sent', false);
    expect($this->story_post_count)->toBe(1);
});

test('the shared dispatch lock rejects unsent review while the original provider post is still running', function () {
    eventUpdateSource($this, function ($request) {
        $parent = TeamOperationAttempt::where('kind', 'event.update')->firstOrFail();
        try {
            app(EventUpdates::class)->reconcile($this->tenant, $this->owner, $this->event->fresh(), $parent->id, true, 'unsent');
            test()->fail('A running source dispatch must own its lock.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            expect($error->getStatusCode())->toBe(409);
        }

        return Http::response(['data' => ['gid' => '501', 'text' => $request['data']['text']]]);
    });
    $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'Original post in progress'])->assertOk()->assertJsonPath('status', 'succeeded');
    expect($this->story_post_count)->toBe(1);
});

test('an unknown provider outcome still requires source review and is never inferred to be unsent', function () {
    eventUpdateSource($this, fn () => throw new ConnectionException('Fictional lost response'));
    $receipt = $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'May have posted'])->assertOk()->assertJsonPath('status', 'unknown')->json();
    $review = '/team/events/'.$this->event->id.'/updates/'.$receipt['id'].'/reconcile'.$this->query;
    $this->postJson($review, ['reviewed' => true, 'acknowledge' => true, 'review_kind' => 'unsent'])->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('not_sent', false)->assertJsonPath('review_kind', 'source');
    $this->postJson($review, ['reviewed' => true, 'acknowledge' => true, 'review_kind' => 'source'])->assertOk()->assertJsonPath('status', 'reviewed')->assertJsonPath('not_sent', false);
    expect($this->story_post_count)->toBe(1);
});

test('reviewing a native prepared draft stops the old finalizer and retains the original body', function () {
    $input = ['request_id' => (string) Str::uuid(), 'body' => 'Retained native draft'];
    eventUpdateAutocommitRace(fn () => eventUpdateObserve('created', function ($attempt) use ($input) {
        if ($attempt->request_id === $input['request_id']) {
            $reviewed = app(EventUpdates::class)->reconcile($this->tenant, $this->owner, $this->event->fresh(), $attempt->id, true, 'unsent');
            expect($reviewed['status'])->toBe('reviewed')->and($reviewed['not_sent'])->toBeTrue()->and($reviewed['source_review_url'])->toBeNull();
        }
    }, fn () => $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('status', 'reviewed')->assertJsonPath('not_sent', true)->assertJsonPath('body', $input['body'])));
    $this->getJson($this->updates)->assertOk()->assertJsonCount(0, 'items');
    $this->postJson($this->updates, $input)->assertOk()->assertJsonPath('status', 'reviewed');
    $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'Explicit new native update'])->assertOk()->assertJsonPath('status', 'succeeded');
    $this->getJson($this->updates)->assertOk()->assertJsonCount(1, 'items');
});

test('a late provider response cannot overwrite an explicit source review', function () {
    $tasks = new class(app(OperationsAccess::class), app(OperationsProviders::class)) extends EventTasks
    {
        public ?Closure $afterDispatch = null;

        public function dispatch(Tenant $tenant, User $user, TeamArea $area, string $id, ?callable $beforeClaim = null): array
        {
            $reply = parent::dispatch($tenant, $user, $area, $id, $beforeClaim);
            ($this->afterDispatch)();

            return $reply;
        }
    };
    app()->instance(EventTasks::class, $tasks);
    eventUpdateSource($this, fn () => throw new ConnectionException('Fictional lost response'));
    $tasks->afterDispatch = function () {
        $parent = TeamOperationAttempt::where('kind', 'event.update')->firstOrFail();
        app(EventUpdates::class)->reconcile($this->tenant, $this->owner, $this->event->fresh(), $parent->id, true, 'source');
    };
    $this->postJson($this->updates, ['request_id' => (string) Str::uuid(), 'body' => 'Keep reviewed state'])->assertOk()->assertJsonPath('status', 'reviewed')->assertJsonPath('not_sent', false);
    expect(TeamOperationAttempt::where('kind', 'event.update')->value('status'))->toBe('reviewed')->and($this->story_post_count)->toBe(1);
});
