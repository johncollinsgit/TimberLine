<?php

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Services\Team\Operations\OperationsProviders;
use App\Services\Team\Operations\OperationsSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'team_operations.asana_create_enabled' => true]);
    Http::preventStrayRequests();
    [$this->tenant, $this->actor] = teamWorkspace();
    $this->area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'Fictional dated shows', 'slug' => 'dated-shows', 'profile' => 'events', 'settings' => []]);
    $this->event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'kind' => 'event', 'title' => 'Fictional split show', 'source_key' => hash('sha256', (string) Str::uuid()), 'status' => 'draft', 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'data' => [
        'notes' => 'Original organizer instructions remain.', 'timezone' => 'America/New_York', 'schedule_complete' => true,
        'sessions' => [
            ['id' => (string) Str::uuid(), 'kind' => 'setup', 'date' => '2026-11-05', 'opens' => '10:00', 'closes' => '17:00'],
            ['id' => (string) Str::uuid(), 'kind' => 'selling', 'date' => '2026-11-06', 'opens' => '10:00', 'closes' => '18:00'],
            ['id' => (string) Str::uuid(), 'kind' => 'selling', 'date' => '2026-11-08', 'opens' => '11:00', 'closes' => '17:00'],
        ],
    ]]);
    $this->connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->actor->id, 'area_id' => $this->area->id, 'provider' => 'asana', 'account_key' => hash('sha256', (string) Str::uuid()), 'account_name' => 'Fictional source owner', 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional-only'], 'settings' => ['send_authorized' => true, 'source' => ['asana_workspace' => '100', 'asana_project' => '200']], 'sync_state' => []]);
    $this->url = '/team/communications?tenant='.$this->tenant->slug.'&area='.$this->area->id;
    $this->command = ['request_id' => (string) Str::uuid(), 'kind' => 'asana.create', 'record_id' => $this->event->id, 'expected_record_version' => 1, 'connection_id' => $this->connection->id, 'reviewed' => true];
    $this->dispatchUrl = fn ($id) => '/team/communications/'.$id.'/dispatch?tenant='.$this->tenant->slug.'&area='.$this->area->id;
    $this->actingAs($this->actor);
});

test('Asana creation previews a single date anchor and exact sparse sessions without filling gap days', function () {
    $preview = $this->postJson($this->url, $this->command)->assertOk()->json('preview');
    expect($preview['asana_payload']['due_on'])->toBe('2026-11-06')->and($preview['asana_date_anchor_basis'])->toBe('suggested_first_selling_date')
        ->and($preview['asana_payload'])->not->toHaveKey('start_on')
        ->and($preview['asana_payload']['notes'])->toContain('Setup: 2026-11-05', 'Selling: 2026-11-06', 'Selling: 2026-11-08', 'Original organizer instructions remain.', 'not establish paid staff shifts')
        ->and($preview['asana_payload']['notes'])->not->toContain('2026-11-07');
    $this->postJson($this->url, $this->command)->assertOk()->assertJsonPath('preview.asana_payload', $preview['asana_payload']);
    expect(TeamOperationAttempt::count())->toBe(1);
    Http::assertNothingSent();
});

test('Asana anchor can be explicitly reviewed or omitted independently from event bounds', function (?string $anchor) {
    $this->postJson($this->url, [...$this->command, 'asana_due_on' => $anchor])->assertOk()
        ->assertJsonPath('preview.asana_payload.due_on', $anchor)->assertJsonPath('preview.asana_date_anchor_basis', 'explicit_review');
    expect($this->event->fresh()->starts_on->toDateString())->toBe('2026-11-06')->and($this->event->fresh()->ends_on->toDateString())->toBe('2026-11-08');
    Http::assertNothingSent();
})->with(['2026-11-05', null]);

test('source dispatch sends the frozen reviewed payload outside its admission transaction', function () {
    $prepared = $this->postJson($this->url, $this->command)->assertOk()->json();
    $reviewed = $prepared['preview']['asana_payload'];
    $this->event->update(['title' => 'Unversioned unrelated writer', 'data' => ['notes' => 'Not part of the reviewed payload']]);
    $level = DB::transactionLevel();
    $provider = $this->mock(OperationsProviders::class);
    $provider->shouldReceive('read')->once()->andReturn([]);
    $provider->shouldReceive('http')->once()->withArgs(function ($connection, $method, $url, $payload) use ($reviewed, $level) {
        expect($method)->toBe('POST')->and($url)->toBe('https://app.asana.com/api/1.0/tasks')
            ->and($payload['data'])->toBe($reviewed)->and(DB::transactionLevel())->toBe($level);

        return true;
    })->andReturn(['data' => ['gid' => '300']]);
    $this->mock(OperationsSync::class)->shouldReceive('adoptCreatedEvent')->once()->andReturn($this->event);
    $this->postJson(($this->dispatchUrl)($prepared['id']), ['reviewed' => true])->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson(($this->dispatchUrl)($prepared['id']), ['reviewed' => true])->assertOk()->assertJsonPath('status', 'accepted');
    Http::assertNothingSent();
});

test('unknown original source creation blocks a replacement request ID for the same event', function () {
    $first = $this->postJson($this->url, $this->command)->assertOk()->json('id');
    $second = $this->postJson($this->url, [...$this->command, 'request_id' => (string) Str::uuid()])->assertOk()->json('id');
    $provider = $this->mock(OperationsProviders::class);
    $provider->shouldReceive('read')->once()->andReturn([]);
    $provider->shouldReceive('http')->once()->andReturn(['data' => []]);
    $this->postJson(($this->dispatchUrl)($first), ['reviewed' => true])->assertStatus(502);
    expect(TeamOperationAttempt::findOrFail($first)->status)->toBe('unknown');
    $this->postJson(($this->dispatchUrl)($second), ['reviewed' => true])->assertConflict();
    expect(TeamOperationAttempt::findOrFail($second)->status)->toBe('prepared');
    Http::assertNothingSent();
});

test('legacy prepared source actions cannot reinterpret dates without a fresh reviewed preview', function () {
    $id = $this->postJson($this->url, $this->command)->assertOk()->json('id');
    $attempt = TeamOperationAttempt::findOrFail($id);
    $payload = $attempt->payload;
    unset($payload['asana_payload']);
    $attempt->update(['payload' => $payload]);
    $this->postJson(($this->dispatchUrl)($id), ['reviewed' => true])->assertConflict();
    expect($attempt->fresh()->status)->toBe('prepared');
    Http::assertNothingSent();
});

test('a confirmed source identity can finish local adoption with a verified read and no second task creation', function () {
    $prepared = $this->postJson($this->url, $this->command)->assertOk()->json();
    $task = ['gid' => '300', ...$prepared['preview']['asana_payload']];
    $provider = $this->mock(OperationsProviders::class);
    $provider->shouldReceive('read')->once()->andReturn([]);
    $provider->shouldReceive('http')->once()->andReturn(['data' => ['gid' => '300']]);
    $provider->shouldReceive('verifyAsanaRecord')->once()->withArgs(fn ($connection, $gid, $project) => $gid === '300' && $project === '200')->andReturn($task);
    $adoptions = 0;
    $event = $this->event;
    $this->mock(OperationsSync::class)->shouldReceive('adoptCreatedEvent')->twice()->andReturnUsing(function () use (&$adoptions, $event) {
        if (++$adoptions === 1) {
            throw new RuntimeException('Fictional local projection failure after provider acceptance');
        }

        return $event;
    });
    $this->postJson(($this->dispatchUrl)($prepared['id']), ['reviewed' => true])->assertOk()->assertJsonPath('status', 'accepted')
        ->assertJsonPath('result.source_id', '300')->assertJsonPath('result.adoption_status', 'needs_review');
    $this->getJson($this->url)->assertOk()->assertJsonPath('items.0.adoption_recovery_available', true);
    $reconcile = '/team/communications/'.$prepared['id'].'/reconcile?tenant='.$this->tenant->slug.'&area='.$this->area->id;
    $this->postJson($reconcile)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('result.source_id', '300')->assertJsonPath('result.projection_id', $event->id);
    $this->postJson($reconcile)->assertOk()->assertJsonPath('result.projection_id', $event->id);
    $this->getJson($this->url)->assertOk()->assertJsonPath('items.0.adoption_recovery_available', false);
    expect($adoptions)->toBe(2);
    Http::assertNothingSent();
});

test('source reconciliation requires an exact standalone original marker instead of a copied prefix', function () {
    $prepared = $this->postJson($this->url, $this->command)->assertOk()->json();
    $attempt = TeamOperationAttempt::findOrFail($prepared['id']);
    $attempt->update(['status' => 'unknown']);
    $provider = $this->mock(OperationsProviders::class);
    $provider->shouldReceive('read')->once()->andReturn(['items' => [['gid' => '300', 'notes' => $prepared['preview']['asana_payload']['notes'].'-copied']], 'next_cursor' => null]);
    $provider->shouldNotReceive('verifyAsanaRecord');
    $provider->shouldNotReceive('http');
    $this->mock(OperationsSync::class)->shouldNotReceive('adoptCreatedEvent');
    $url = '/team/communications/'.$attempt->id.'/reconcile?tenant='.$this->tenant->slug.'&area='.$this->area->id;
    $this->postJson($url)->assertOk()->assertJsonPath('status', 'unknown');
    expect($attempt->fresh()->status)->toBe('unknown')->and($attempt->fresh()->result)->toBeNull();
    Http::assertNothingSent();
});

test('changed destination or event version blocks source creation before provider dispatch', function (string $changed) {
    $id = $this->postJson($this->url, $this->command)->assertOk()->json('id');
    if ($changed === 'destination') {
        $this->connection->update(['settings' => [...$this->connection->settings, 'source' => ['asana_workspace' => '100', 'asana_project' => '201']]]);
    } else {
        $this->event->increment('version');
    }
    $this->postJson(($this->dispatchUrl)($id), ['reviewed' => true])->assertConflict();
    expect(TeamOperationAttempt::findOrFail($id)->status)->toBe('prepared');
    Http::assertNothingSent();
})->with(['destination', 'version']);
