<?php

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Services\Team\Operations\OperationsSync;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    Http::preventStrayRequests();
});

function noteFieldFixture(array $overrides = []): array
{
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional', 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200'], 'external_user_id' => '300', 'scopes' => 'tasks:read']]);
    $notes = "Venue Address: Fictional Hall - 100 Forest Road\nBooth Number: 423\nBooth Size: 10x10\nSetup Time: 10:00";
    $task = ['gid' => '400', 'name' => 'Fictional event', 'notes' => $notes, 'completed' => false, 'modified_at' => '2026-11-06T05:00:48.901Z', 'workspace' => ['gid' => '100'], 'assignee' => ['gid' => '300'], 'memberships' => [['project' => ['gid' => '200']]]];
    // Emulate records imported before Venue Address and distinct booth fields existed.
    $data = array_replace(['_generation' => 1, 'source_id' => '400', 'source_project' => '200', 'source_workspace' => '100', 'source_version' => $task['modified_at'], 'original_notes' => $notes, 'fields' => [['field' => 'booth_number', 'value' => '423', 'passage' => 'Booth Number: 423', 'review_state' => 'needs_review'], ['field' => 'booth', 'value' => '10x10', 'passage' => 'Booth Size: 10x10', 'review_state' => 'needs_review']]], $overrides);
    $event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'connection_id' => $connection->id, 'kind' => 'event', 'title' => 'Fictional event', 'source_key' => hash('sha256', 'asana:100:400'), 'status' => 'source', 'version' => 1, 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-06', 'data' => $data]);

    return [$tenant, $owner, $area, $connection, $task, $event];
}
function noteFieldUrl($tenant, $event): string
{
    return '/team/operations/records/'.$event->id.'/review?tenant='.$tenant->slug;
}
function noteFieldInput(TeamOperationRecord $event, array $indices = [0, 1, 2], int $version = 1): array
{
    return ['request_id' => (string) Str::uuid(), 'expected_version' => $version, 'reviewed' => true, 'action' => 'fields', 'field_indices' => $indices, 'expected_fields_revision' => app(OperationsSync::class)->eventFieldsRevision($event->data)];
}

test('exact labelled event fields preserve venue address and distinguish booth size from booth number', function () {
    $sync = app(OperationsSync::class);
    $fields = $sync->extract("Venue: Fictional Hall\nVenue Address: Fictional Hall - 100 Forest Road\nBooth Number: 423\nBooth Size: 10x10\nVenue Fee: $200\nBooth: vague");
    expect(array_column($fields, 'field'))->toBe(['venue', 'address', 'booth_number', 'booth_size'])
        ->and(array_column($fields, 'value'))->toBe(['Fictional Hall', 'Fictional Hall - 100 Forest Road', '423', '10x10']);
    $aliases = $sync->extract("Address: 100 Forest Road\nBooth #: 99\nSet-Up Time: 10am\nSetup Time: 11am\nShow Hours: noon-5pm\nEvent Hours: noon-6pm");
    expect(array_column($aliases, 'field'))->toBe(['address', 'booth_number', 'setup_time', 'setup_time', 'event_hours', 'event_hours']);
    $old = ['original_notes' => 'Booth Size: 10x10', 'fields' => [['field' => 'booth', 'value' => '10x10', 'passage' => 'Booth Size: 10x10', 'review_state' => 'approved']], 'reviewed_fields' => ['booth' => '10x10']];
    expect($sync->eventFields($old)[0]['field'])->toBe('booth_size')->and($sync->eventFields($old)[0]['review_state'])->toBe('needs_review')->and($old['reviewed_fields']['booth'])->toBe('10x10');
});

test('old source records expose and approve fresh exact fields with an idempotent audited receipt', function () {
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture();
    Http::fake(fn () => Http::response(['data' => $task]));
    $response = $this->actingAs($owner)->getJson('/team/operations/records/'.$event->id.'?tenant='.$tenant->slug)->assertOk();
    expect(array_column($response->json('data.fields'), 'field'))->toBe(['address', 'booth_number', 'booth_size', 'setup_time'])
        ->and($response->json('data.fields_revision'))->toBe(app(OperationsSync::class)->eventFieldsRevision($event->data));
    $input = noteFieldInput($event);
    $saved = $this->postJson(noteFieldUrl($tenant, $event), $input)->assertOk();
    expect($saved->json('data.reviewed_fields'))->toMatchArray(['address' => 'Fictional Hall - 100 Forest Road', 'booth_number' => '423', 'booth_size' => '10x10'])
        ->and($saved->json('version'))->toBe(2);
    $this->postJson(noteFieldUrl($tenant, $event), $input)->assertOk()->assertJsonPath('version', 2);
    $event->refresh();
    expect($event->data['original_notes'])->toBe($task['notes'])->and($event->data['source_version'])->toBe($task['modified_at'])
        ->and($event->starts_on->toDateString())->toBe('2026-11-06')->and(TeamOperationAttempt::where('record_id', $event->id)->where('kind', 'record.enrich')->count())->toBe(1);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('changed source version or changed selected passage fails without changing the record', function (bool $differentVersion) {
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture();
    if ($differentVersion) {
        $task['modified_at'] = '2026-11-07T00:00:00Z';
    } else {
        $task['notes'] = str_replace('Booth Number: 423', 'Booth Number: 424', $task['notes']);
    }
    Http::fake(fn () => Http::response(['data' => $task]));
    $this->actingAs($owner)->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event, [1]))->assertConflict();
    expect($event->fresh()->version)->toBe(1)->and($event->fresh()->data['reviewed_fields'] ?? [])->toBe([])
        ->and(TeamOperationAttempt::where('record_id', $event->id)->count())->toBe(0);
})->with([true, false]);

test('conflicting reviewed values and duplicate conflicting candidates are never overwritten', function () {
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture(['reviewed_fields' => ['address' => 'Previously reviewed address']]);
    Http::fake(function () use (&$task) {
        return Http::response(['data' => $task]);
    });
    $this->actingAs($owner)->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event, [0]))->assertConflict();
    expect($event->fresh()->version)->toBe(1)->and($event->fresh()->data['reviewed_fields']['address'])->toBe('Previously reviewed address');
    $data = $event->data;
    unset($data['reviewed_fields']);
    $data['original_notes'] .= "\nAddress: Other Hall";
    $task['notes'] = $data['original_notes'];
    $event->update(['data' => $data]);
    $this->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event, [0, 4]))->assertConflict();
    expect($event->fresh()->version)->toBe(1)->and($event->fresh()->data['reviewed_fields'] ?? [])->toBe([]);
});

test('a stale record, no selection, wrong tenant and revoked source fail closed', function () {
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture();
    $denied = false;
    Http::fake(function () use (&$task, &$denied) {
        return $denied ? Http::response([], 403) : Http::response(['data' => $task]);
    });
    $this->actingAs($owner)->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event, [0], 2))->assertConflict();
    $this->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event, []))->assertUnprocessable();
    [$foreign, $foreignOwner] = teamWorkspace();
    $this->actingAs($foreignOwner)->postJson(noteFieldUrl($foreign, $event), noteFieldInput($event))->assertNotFound();
    $denied = true;
    $this->actingAs($owner)->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event))->assertForbidden();
    expect($event->fresh()->version)->toBe(1)->and(TeamOperationAttempt::where('record_id', $event->id)->count())->toBe(0);
});

test('old browser indexes and changed candidate revisions cannot approve a different field', function () {
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture();
    Http::fake(fn () => Http::response(['data' => $task]));
    $oldPage = noteFieldInput($event, [0]);
    unset($oldPage['expected_fields_revision']);
    $this->actingAs($owner)->postJson(noteFieldUrl($tenant, $event), $oldPage)->assertConflict();
    $wrong = noteFieldInput($event, [0]);
    $wrong['expected_fields_revision'] = str_repeat('0', 64);
    $this->postJson(noteFieldUrl($tenant, $event), $wrong)->assertConflict();
    expect($event->fresh()->version)->toBe(1)->and($event->fresh()->data['reviewed_fields'] ?? [])->toBe([]);
});

test('an area viewer with their own connected source cannot approve fields', function () {
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture();
    $viewer = teamMember($tenant, 'member');
    \Illuminate\Support\Facades\DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
    $own = $connection->replicate(['id', 'user_id', 'account_key']);
    $own->id = (string) Str::uuid();
    $own->user_id = $viewer->id;
    $own->account_key = hash('sha256', Str::uuid());
    $own->save();
    Http::fake(fn () => Http::response(['data' => $task]));
    $this->actingAs($viewer)->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event))->assertForbidden();
    expect($event->fresh()->version)->toBe(1);
});

test('previously approved values and reviewer evidence remain unchanged', function () {
    $prior = ['field' => 'booth_number', 'value' => '423', 'passage' => 'Booth Number: 423', 'review_state' => 'approved', 'reviewed_by' => 98, 'reviewed_at' => '2026-10-01T00:00:00Z', 'source_version' => '2026-11-06T05:00:48.901Z'];
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture(['fields' => [$prior], 'reviewed_fields' => ['booth_number' => '423'], '_teams_enrichment' => ['field_reviews' => [$prior]]]);
    Http::fake(fn () => Http::response(['data' => $task]));
    $this->actingAs($owner)->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event, [0, 1]))->assertOk();
    $data = $event->fresh()->data;
    expect($data['fields'][1]['reviewed_by'])->toBe(98)->and($data['fields'][1]['reviewed_at'])->toBe('2026-10-01T00:00:00Z')
        ->and($data['reviewed_fields']['booth_number'])->toBe('423')->and($data['reviewed_fields']['address'])->toBe('Fictional Hall - 100 Forest Road');
});

test('source connection generation changes during verification prevent approval', function () {
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture();
    $calls = 0;
    Http::fake(function () use (&$calls, $connection, $task) {
        if (++$calls === 2) {
            $connection->update(['generation' => 2]);
        }

        return Http::response(['data' => $task]);
    });
    $this->actingAs($owner)->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event))->assertConflict();
    expect($event->fresh()->version)->toBe(1)->and($event->fresh()->data['reviewed_fields'] ?? [])->toBe([])
        ->and(TeamOperationAttempt::where('record_id', $event->id)->count())->toBe(0);
});

test('native bearer receives the same field revision and must submit it with its idempotent review', function () {
    config(['team_work.mobile_enabled' => true]);
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture();
    Http::fake(fn () => Http::response(['data' => $task]));
    $token = $owner->createToken('Teams fixture', ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/team-work/operations/records/'.$event->id;
    $projection = $this->withToken($token->plainTextToken)->getJson($base.'?area='.$area->id)->assertOk();
    expect(array_column($projection->json('data.fields'), 'field'))->toBe(['address', 'booth_number', 'booth_size', 'setup_time']);
    $input = noteFieldInput($event, [1, 2]);
    expect($projection->json('data.fields_revision'))->toBe($input['expected_fields_revision']);
    $this->withHeader('Idempotency-Key', $input['request_id'])->postJson($base.'/review?area='.$area->id, $input)->assertOk()
        ->assertJsonPath('data.reviewed_fields.booth_number', '423')->assertJsonPath('data.reviewed_fields.booth_size', '10x10');
    $this->postJson($base.'/review?area='.$area->id, $input)->assertOk()->assertJsonPath('version', 2);
    expect(TeamOperationAttempt::where('record_id', $event->id)->where('kind', 'record.enrich')->count())->toBe(1);
});

test('a saved Asana snapshot cannot be promoted as a current verified source', function () {
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture();
    $settings = $connection->settings;
    $settings['read_only_snapshot'] = true;
    $connection->update(['settings' => $settings]);
    Http::fake(fn () => Http::response(['data' => $task]));
    $this->actingAs($owner)->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event))->assertConflict();
    expect($event->fresh()->version)->toBe(1)->and($event->fresh()->data['reviewed_fields'] ?? [])->toBe([])
        ->and(TeamOperationAttempt::where('record_id', $event->id)->count())->toBe(0);
});

test('malformed stored notes or reviewed fields fail closed without altering the source', function () {
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture();
    Http::fake(fn () => Http::response(['data' => $task]));
    $input = noteFieldInput($event);
    $data = $event->data;
    $data['original_notes'] = ['unexpected'];
    $event->update(['data' => $data]);
    $this->actingAs($owner)->postJson(noteFieldUrl($tenant, $event), $input)->assertConflict();
    $data['original_notes'] = $task['notes'];
    $data['reviewed_fields'] = 'unexpected';
    $event->update(['data' => $data]);
    $this->postJson(noteFieldUrl($tenant, $event), $input)->assertConflict();
    expect($event->fresh()->version)->toBe(1)->and($event->fresh()->data['reviewed_fields'])->toBe('unexpected');
});

test('same-source sync restores only exact audited approvals without rewriting the source', function () {
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture();
    Http::fake(fn () => Http::response(['data' => $task]));
    $this->actingAs($owner)->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event, [0, 1, 2, 3]))->assertOk();
    $audited = $event->fresh()->data['_teams_enrichment']['field_reviews'];
    $newData = ['_generation' => $connection->generation, 'source_id' => '400', 'source_project' => '200', 'source_workspace' => '100',
        'source_version' => $task['modified_at'], 'original_notes' => $task['notes'], 'fields' => app(OperationsSync::class)->extract($task['notes'])];
    $refreshed = app(OperationsSync::class)->upsert($connection, 'event', '400', 'Fictional event', 'source', $newData, '2026-11-06', '2026-11-06');
    expect(array_column($refreshed->data['fields'], 'review_state'))->toBe(['needs_review', 'needs_review', 'needs_review', 'needs_review']);
    $projected = app(OperationsSync::class)->eventFields($refreshed->data);
    expect(array_column($projected, 'review_state'))->toBe(['approved', 'approved', 'approved', 'approved'])
        ->and(array_column($projected, 'reviewed_by'))->toBe(array_column($audited, 'reviewed_by'))
        ->and(array_column($projected, 'reviewed_at'))->toBe(array_column($audited, 'reviewed_at'))
        ->and($refreshed->data['reviewed_fields']['booth_number'])->toBe('423')
        ->and($refreshed->data['_teams_enrichment']['field_reviews'])->toBe($audited)
        ->and($refreshed->data['original_notes'])->toBe($task['notes']);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('changed source and conflicting or incomplete review evidence never regain approval', function () {
    [$tenant, $owner, $area, $connection, $task, $event] = noteFieldFixture();
    Http::fake(fn () => Http::response(['data' => $task]));
    $this->actingAs($owner)->postJson(noteFieldUrl($tenant, $event), noteFieldInput($event, [1]))->assertOk();
    $data = $event->fresh()->data;
    $sync = app(OperationsSync::class);
    $booth = fn (array $projection) => collect($projection)->firstWhere('field', 'booth_number');
    expect($booth($sync->eventFields($data))['review_state'])->toBe('approved');
    $conflict = $data;
    $conflict['reviewed_fields']['booth_number'] = '999';
    expect($booth($sync->eventFields($conflict))['review_state'])->toBe('needs_review');
    $missing = $data;
    unset($missing['_teams_enrichment']['field_reviews'][0]['reviewed_at']);
    expect($booth($sync->eventFields($missing))['review_state'])->toBe('needs_review');
    $passage = $data;
    $passage['_teams_enrichment']['field_reviews'][0]['passage'] = 'Booth #: 423';
    expect($booth($sync->eventFields($passage))['review_state'])->toBe('needs_review');
    $unaudited = $data;
    unset($unaudited['_teams_enrichment']['field_reviews']);
    expect($booth($sync->eventFields($unaudited))['review_state'])->toBe('needs_review');
    $changedVersion = $data;
    $changedVersion['source_version'] = '2026-11-07T00:00:00Z';
    expect($booth($sync->eventFields($changedVersion))['review_state'])->toBe('needs_review');
    $newData = ['source_id' => '400', 'source_project' => '200', 'source_workspace' => '100',
        'source_version' => '2026-11-07T00:00:00Z', 'original_notes' => $task['notes'], 'fields' => $sync->extract($task['notes'])];
    $refreshed = $sync->upsert($connection, 'event', '400', 'Fictional event', 'source', $newData, '2026-11-06', '2026-11-06');
    expect($booth($sync->eventFields($refreshed->data))['review_state'])->toBe('needs_review')
        ->and($refreshed->data['reviewed_fields'])->toBe([])
        ->and($refreshed->data['_teams_enrichment']['stale_reviewed_fields']['booth_number'])->toBe('423')
        ->and($refreshed->data['_teams_enrichment']['field_reviews'])->toBe($data['_teams_enrichment']['field_reviews']);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});
