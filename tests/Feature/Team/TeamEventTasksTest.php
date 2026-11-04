<?php

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamProviderConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'team_operations.asana_write_enabled' => true]);
    Http::preventStrayRequests();
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'Events', 'slug' => 'events', 'profile' => 'events', 'settings' => []]);
    $this->connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'area_id' => $this->area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'fixture@example.invalid', 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional-access'],
        'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200'], 'external_user_id' => '300', 'scopes' => 'tasks:read tasks:write', 'send_authorized' => true]]);
    $this->task = ['gid' => '400', 'name' => 'Fictional weekend event', 'notes' => 'Original notes must survive.', 'completed' => false, 'due_on' => '2026-11-06', 'start_on' => null, 'modified_at' => '2026-11-04T12:00:00Z', 'workspace' => ['gid' => '100'], 'assignee' => ['gid' => '300', 'name' => 'Same name'], 'memberships' => [['project' => ['gid' => '200']]]];
    $this->query = '?tenant='.$this->tenant->slug.'&area='.$this->area->id;
    $this->detail = '/team/events/asana/tasks/400'.$this->query.'&connection_id='.$this->connection->id;
    $this->actingAs($this->owner);
});

function eventTaskFake($case, ?Closure $mutate = null): void
{
    Http::fake(function ($request) use ($case, $mutate) {
        if ($request->method() === 'PUT') {
            if ($mutate) {
                return $mutate($request);
            }

            return Http::response(['data' => array_replace($case->task, $request['data'])]);
        }

        return Http::response(['data' => $case->task]);
    });
}
function eventTaskPrepare($case, array $changes = ['completed' => true], array $extra = [])
{
    $version = $case->getJson($case->detail)->assertOk()->json('version');

    return $case->postJson('/team/events/asana/changes'.$case->query, array_replace(['connection_id' => $case->connection->id, 'task_id' => '400', 'request_id' => (string) Str::uuid(), 'version' => $version, 'changes' => $changes], $extra));
}
function eventTaskDispatch($case, string $id)
{
    return $case->postJson('/team/events/asana/changes/'.$id.'/dispatch'.$case->query, ['reviewed' => true]);
}

test('event my tasks filters exact identity workspace and selected project and binds pagination', function () {
    $other = array_replace($this->task, ['gid' => '401', 'assignee' => ['gid' => '999', 'name' => 'Same name']]);
    Http::fake(['*' => Http::response(['data' => [$this->task, $other, array_replace($this->task, ['gid' => '402', 'workspace' => ['gid' => '999']]), array_replace($this->task, ['gid' => '403', 'memberships' => []])], 'next_page' => ['offset' => 'next-page']])]);
    $page = $this->getJson('/team/events/my-tasks'.$this->query)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.gid', '400')->json();
    Http::assertSent(fn ($request) => $request['assignee'] === '300' && $request['workspace'] === '100');
    $this->connection->update(['generation' => 2]);
    $this->getJson('/team/events/my-tasks'.$this->query.'&cursor='.urlencode($page['next_cursor']))->assertUnprocessable();
});

test('event source access rejects another account area tenant and removed entitlement', function () {
    eventTaskFake($this);
    $other = teamMember($this->tenant, 'admin');
    $this->actingAs($other)->getJson($this->detail)->assertForbidden();
    $this->actingAs($this->owner);
    $foreign = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => 999, 'name' => 'Other', 'slug' => 'other', 'profile' => 'events']);
    $this->getJson(str_replace($this->area->id, $foreign->id, $this->detail))->assertNotFound();
    config(['team_operations.enabled' => false]);
    $this->getJson($this->detail)->assertNotFound();
    Http::assertNothingSent();
});

test('event detail returns original notes and paged related source data through correct endpoints', function () {
    $task = $this->task;
    Http::fake(function ($request) use ($task) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/api/1.0/tasks/400') {
            return Http::response(['data' => $task]);
        }
        expect($path)->toBe('/api/1.0/attachments');
        expect($request['parent'])->toBe('400');

        return Http::response(['data' => [['gid' => '500', 'name' => 'Instructions.pdf', 'permanent_url' => 'https://app.asana.com/asset/500']], 'next_page' => ['offset' => 'attachment-next']]);
    });
    $this->getJson($this->detail)->assertOk()->assertJsonPath('notes', $task['notes']);
    $this->getJson(str_replace('/400?', '/400/attachments?', $this->detail))->assertOk()->assertJsonPath('has_more', true)->assertJsonCount(1, 'items');
});

test('event subtasks require verified parent relationship without project membership', function () {
    $parent = $this->task;
    Http::fake(fn ($r) => Http::response(['data' => str_contains($r->url(), '/401?') ? array_replace($parent, ['gid' => '401', 'memberships' => [], 'parent' => ['gid' => '400']]) : $parent]));
    $url = str_replace('/400?', '/401?', $this->detail);
    $this->getJson($url)->assertForbidden();
    $this->getJson($url.'&parent_id=400')->assertOk()->assertJsonPath('source_url', 'https://app.asana.com/0/200/401');
    $this->getJson($url.'&parent_id=999')->assertForbidden();
});

test('event writes require feature grant and real connection rather than saved snapshot', function (string $gate) {
    eventTaskFake($this);
    if ($gate === 'feature') {
        config(['team_operations.asana_write_enabled' => false]);
    } else {
        $this->connection->update(['settings' => array_replace($this->connection->settings, $gate === 'snapshot' ? ['read_only_snapshot' => true] : ['send_authorized' => false])]);
    }
    eventTaskPrepare($this)->assertConflict();
    Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
})->with(['feature', 'grant', 'snapshot']);

test('event save sends only reviewed fields once with encrypted audit evidence', function () {
    eventTaskFake($this);
    $id = eventTaskPrepare($this)->assertOk()->assertJsonPath('status', 'prepared')->json('id');
    eventTaskDispatch($this, $id)->assertOk()->assertJsonPath('status', 'succeeded');
    eventTaskDispatch($this, $id)->assertOk()->assertJsonPath('status', 'succeeded');
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['data'] === ['completed' => true]);
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT'))->toHaveCount(1);
    expect(\Illuminate\Support\Facades\DB::table('team_operation_attempts')->value('payload'))->not->toContain('completed');
});

test('event saves reject stale preflight data and changed connection before any write', function (bool $connectionChanged) {
    eventTaskFake($this);
    $id = eventTaskPrepare($this)->assertOk()->json('id');
    if ($connectionChanged) {
        $this->connection->update(['generation' => 2]);
    } else {
        $this->task['modified_at'] = '2026-11-04T13:00:00Z';
    }
    eventTaskDispatch($this, $id)->assertConflict();
    Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
})->with([false, true]);

test('event unknown save survives reload rejects replacements and reconciles without replay', function () {
    $writes = 0;
    eventTaskFake($this, function () use (&$writes) {
        $writes++;
        throw new \Illuminate\Http\Client\ConnectionException('Lost provider response');
    });
    $id = eventTaskPrepare($this)->assertOk()->json('id');
    eventTaskDispatch($this, $id)->assertOk()->assertJsonPath('status', 'unknown');
    eventTaskDispatch($this, $id)->assertOk()->assertJsonPath('status', 'unknown');
    eventTaskPrepare($this)->assertConflict();
    Http::fake(fn ($r) => Http::response(['data' => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/tasks') ? [$this->task] : $this->task]));
    $this->getJson('/team/events/my-tasks'.$this->query)->assertOk()->assertJsonPath('pending_updates.0.id', $id);
    $this->postJson('/team/events/asana/changes/'.$id.'/reconcile'.$this->query, ['reviewed' => true])->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.current_values_match', false);
    expect($writes)->toBe(1);
    $this->postJson('/team/events/asana/changes/'.$id.'/reconcile'.$this->query, ['reviewed' => true, 'acknowledge' => true])->assertOk()->assertJsonPath('status', 'reviewed');
    eventTaskPrepare($this)->assertOk();
});

test('event update validation protects original notes dates and request identity', function () {
    eventTaskFake($this);
    eventTaskPrepare($this, ['notes' => 'Overwrite'])->assertUnprocessable();
    eventTaskPrepare($this, ['start_on' => '2026-11-08', 'due_on' => '2026-11-06'])->assertUnprocessable();
    $request = (string) Str::uuid();
    eventTaskPrepare($this, ['completed' => true], ['request_id' => $request])->assertOk();
    eventTaskPrepare($this, ['completed' => true], ['request_id' => $request])->assertOk();
    eventTaskPrepare($this, ['name' => 'Different'], ['request_id' => $request])->assertConflict();
    expect(TeamOperationAttempt::count())->toBe(1);
});

test('event preprepared replacement cannot bypass an unknown save and concurrent lock', function () {
    eventTaskFake($this, fn () => throw new \Illuminate\Http\Client\ConnectionException('Uncertain save'));
    $first = eventTaskPrepare($this)->assertOk()->json('id');
    $second = eventTaskPrepare($this, ['name' => 'Reviewed alternate'])->assertOk()->json('id');
    $key = 'team-event-save:'.hash('sha256', $this->tenant->id.':100:400');
    $lock = \Illuminate\Support\Facades\Cache::lock($key, 180);
    expect($lock->get())->toBeTrue();
    eventTaskDispatch($this, $first)->assertConflict();
    $lock->release();
    eventTaskDispatch($this, $first)->assertOk()->assertJsonPath('status', 'unknown');
    eventTaskDispatch($this, $second)->assertConflict();
    expect(TeamOperationAttempt::find($second)->status)->toBe('prepared');
});

test('event area viewers can read their source but cannot prepare changes', function () {
    eventTaskFake($this);
    $this->tenant->users()->updateExistingPivot($this->owner->id, ['role' => 'viewer']);
    \Illuminate\Support\Facades\DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $this->owner->id, 'role' => 'viewer']);
    $this->getJson($this->detail)->assertOk()->assertJsonPath('can_write', false);
    eventTaskPrepare($this)->assertForbidden();
    Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
});

test('event date-only edits cannot discard an existing timed schedule', function () {
    $this->task['due_at'] = '2026-11-06T17:00:00Z';
    eventTaskFake($this);
    $this->getJson($this->detail)->assertOk()->assertJsonPath('due_at', $this->task['due_at']);
    eventTaskPrepare($this, ['due_on' => '2026-11-07'])->assertUnprocessable();
    $id = eventTaskPrepare($this)->assertOk()->json('id');
    eventTaskDispatch($this, $id)->assertOk()->assertJsonPath('status', 'succeeded');
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['data'] === ['completed' => true]);
});

test('installable app stays tenant bound and never caches private task responses', function () {
    $this->get('/teams/manifest.webmanifest?tenant='.$this->tenant->slug)->assertOk()->assertJsonPath('start_url', '/teams/app?app=events&tenant='.$this->tenant->slug);
    $this->get('/teams/app?app=events&tenant='.$this->tenant->slug)->assertOk()->assertSee('Install Teams')->assertSee('apple-mobile-web-app-capable');
    $response = $this->get('/teams/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/teams/');
    $worker = file_get_contents($response->baseResponse->getFile()->getPathname());
    expect($worker)->toContain('cache.add(OFFLINE)')->not->toContain('cache.put(');
    $this->get('/teams/offline')->assertOk()->assertSee('Saves require a connection.');
});
