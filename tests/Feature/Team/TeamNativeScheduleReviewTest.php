<?php

use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_work.mobile_enabled' => true, 'team_operations.enabled' => true]);
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $this->event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'kind' => 'event', 'title' => 'Fictional sparse-date show', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'data' => []]);
    $this->url = '/api/mobile/v1/workspaces/'.$this->tenant->slug.'/team-work/operations/records/'.$this->event->id.'/review?area='.$this->area->id;
    $this->input = ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true, 'action' => 'schedule', 'schedule' => ['timezone' => 'America/New_York', 'schedule_complete' => true, 'sessions' => array_map(fn ($row) => ['id' => (string) Str::uuid(), 'kind' => $row[0], 'date' => $row[1], 'opens' => null, 'closes' => null], [['setup', '2026-11-05'], ['selling', '2026-11-06'], ['selling', '2026-11-08']])]];
});

test('native schedule review requires its exact command identity and preserves sparse dates on replay and stale edits', function () {
    $token = $this->owner->createToken('Teams fixture', ['mobile:read', 'mobile:write']);
    $this->withToken($token->plainTextToken)->postJson($this->url, $this->input)->assertUnprocessable();
    $this->withHeader('Idempotency-Key', $this->input['request_id'])->postJson($this->url, $this->input)->assertOk()
        ->assertJsonPath('version', 2)->assertJsonPath('data.event_schedule.overall_starts_on', '2026-11-05')
        ->assertJsonPath('data.event_schedule.selling_dates', ['2026-11-06', '2026-11-08']);
    $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('version', 2);
    $this->assertDatabaseCount('team_operation_attempts', 1);
    $stale = array_replace($this->input, ['request_id' => (string) Str::uuid()]);
    $this->withHeader('Idempotency-Key', $stale['request_id'])->postJson($this->url, $stale)->assertConflict();
    expect($this->event->fresh()->version)->toBe(2)->and($this->event->fresh()->starts_on->toDateString())->toBe('2026-11-06');
});

test('native schedule review refuses a browser cookie and a read-only bearer token', function () {
    $this->actingAs($this->owner)->withHeader('Idempotency-Key', $this->input['request_id'])->postJson($this->url, $this->input)->assertForbidden();
    Auth::forgetGuards();
    $token = $this->owner->createToken('Read only fixture', ['mobile:read']);
    $this->withToken($token->plainTextToken)->postJson($this->url, $this->input)->assertForbidden();
    expect($this->event->fresh()->version)->toBe(1);
});

test('native schedule review cannot edit foreign records or an area where the member is a viewer', function () {
    [$otherTenant] = teamWorkspace();
    $otherArea = studioArea($otherTenant);
    $other = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $otherTenant->id, 'area_id' => $otherArea->id, 'kind' => 'event', 'title' => 'Private other company', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'data' => []]);
    $token = $this->owner->createToken('Teams fixture', ['mobile:read', 'mobile:write']);
    $this->withToken($token->plainTextToken)->withHeader('Idempotency-Key', $this->input['request_id'])
        ->postJson(str_replace($this->event->id, $other->id, $this->url), $this->input)->assertNotFound();
    $viewer = teamMember($this->tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);
    Auth::forgetGuards();
    $token = $viewer->createToken('Viewer fixture', ['mobile:read', 'mobile:write']);
    $this->withToken($token->plainTextToken)->postJson($this->url, $this->input)->assertForbidden();
    expect($other->fresh()->version)->toBe(1)->and($this->event->fresh()->version)->toBe(1);
});
