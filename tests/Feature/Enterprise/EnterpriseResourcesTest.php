<?php

use App\Enterprise\LocationCatalog;
use App\Enterprise\Resources;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true, 'enterprise.writes_enabled' => true, 'enterprise.resources_enabled' => true]);
    Route::middleware('web')->prefix('enterprise-resource-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
});

function resourceFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id]]);
    $area = TeamArea::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => 'fixture-'.Str::lower(Str::random(8)),
        'name' => 'Synthetic resources', 'profile' => 'enterprise_department', 'version' => 1, 'settings' => ['synthetic_fixture' => true]]);

    return [$tenant, $owner, $area];
}

function resourceMember($tenant, $area, string $role = 'editor')
{
    $user = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

function resourceCommand($tenant, $user, $area, string $action, array $input = [])
{
    return test()->actingAs($user)->postJson(route('enterprise.resources.command', ['tenant' => $tenant->slug]), $input + [
        'action' => $action, 'area_id' => $area->id, 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0,
    ]);
}

function resourceLocation($tenant, $user, $area, array $input = []): array
{
    return resourceCommand($tenant, $user, $area, 'location.save', $input + ['title' => 'Synthetic facility', 'level' => 'facility'])->assertOk()->json();
}

test('official catalog preserves all 68 entries aliases source and unverified geography without operational writes', function (): void {
    $catalog = app(LocationCatalog::class)->all();
    expect($catalog['count'])->toBe(68)->and($catalog['source_page'])->toBe(2);
    expect(array_column($catalog['entries'], 'map_number'))->toBe(array_map('strval', range(1, 68)));
    expect(app(LocationCatalog::class)->entry('24')['name'])->toBe('Needham Alumni House');
    expect(app(LocationCatalog::class)->entry('49')['name'])->toBe('Alumni House');
    expect(app(LocationCatalog::class)->entry('35')['name'])->toBe('Cannon Cottage');
    expect(app(LocationCatalog::class)->entry('51')['name'])->toBe('Annie L. Cannon Cottage');
    expect(app(LocationCatalog::class)->entry('6')['aliases'])->toBe('Cannon Student Center');
    expect(app(LocationCatalog::class)->entry('57')['name'])->toBe('Cannon Administration Building');
    expect(app(LocationCatalog::class)->entry('50')['name'])->toBe('Green House');
    foreach ($catalog['entries'] as $entry) {
        expect($entry['latitude'])->toBeNull()->and($entry['longitude'])->toBeNull()->and($entry['ownership'])->toBeNull()->and($entry['campus_area'])->toBeNull();
    }
    expect(app(LocationCatalog::class)->all('Financial Aid')['entries'][0]['map_number'])->toBe('6');
    expect(DB::table('team_operation_records')->count())->toBe(0);
});

test('catalog seed is explicit synthetic local testing only repeat safe and reversibly withdrawn with audit', function (): void {
    [$tenant, $owner, $area] = resourceFixture();
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()];
    resourceCommand($tenant, $owner, $area, 'fixture.seed', $input)->assertOk()->assertJsonPath('changed', 68);
    resourceCommand($tenant, $owner, $area, 'fixture.seed', $input)->assertOk()->assertJsonPath('replayed', true);
    resourceCommand($tenant, $owner, $area, 'fixture.seed')->assertOk()->assertJsonPath('changed', 0);
    expect(TeamOperationRecord::where('kind', Resources::KINDS['location'])->count())->toBe(68);
    $results = $this->actingAs($owner)->getJson(route('enterprise.resources', ['tenant' => $tenant->slug, 'area_id' => $area->id, 'q' => 'Cannon Student Center']))->assertOk();
    $results->assertJsonCount(1, 'items')->assertJsonPath('counts.location', 1)->assertJsonPath('items.0.data.map_number', '6');
    $results->assertJsonPath('items.0.data.latitude', null)->assertJsonPath('items.0.data.parent_id', null);
    resourceCommand($tenant, $owner, $area, 'fixture.withdraw')->assertOk()->assertJsonPath('changed', 68);
    expect(TeamOperationRecord::where('status', 'withdrawn')->count())->toBe(68);
    expect(DB::table('team_operation_revisions')->count())->toBe(136);
    resourceCommand($tenant, $owner, $area, 'fixture.seed')->assertOk()->assertJsonPath('changed', 68);
    expect(TeamOperationRecord::where('status', 'active')->count())->toBe(68);
    $area->settings = [];
    $area->save();
    resourceCommand($tenant, $owner, $area, 'fixture.seed')->assertForbidden();
    expect(DB::table('tenant_user')->count())->toBe(1)->and(DB::table('team_area_members')->count())->toBe(0);
});

test('location hierarchy persists exact scope and refuses cycles level changes missing parents and stale edits', function (): void {
    [$tenant, $owner, $area] = resourceFixture();
    $institution = resourceLocation($tenant, $owner, $area, ['title' => 'Synthetic institution', 'level' => 'institution']);
    $campus = resourceLocation($tenant, $owner, $area, ['title' => 'Synthetic area', 'level' => 'campus_area', 'parent_id' => $institution['id']]);
    $facility = resourceLocation($tenant, $owner, $area, ['parent_id' => $campus['id']]);
    $floor = resourceLocation($tenant, $owner, $area, ['title' => 'Synthetic floor', 'level' => 'floor', 'parent_id' => $facility['id']]);
    $room = resourceLocation($tenant, $owner, $area, ['title' => 'Synthetic room', 'level' => 'room', 'parent_id' => $floor['id']]);
    resourceCommand($tenant, $owner, $area, 'location.save', ['id' => $room['id'], 'expected_version' => 1, 'title' => 'Cycle', 'level' => 'room', 'parent_id' => $room['id']])->assertUnprocessable();
    resourceCommand($tenant, $owner, $area, 'location.save', ['id' => $room['id'], 'expected_version' => 1, 'title' => 'Wrong level', 'level' => 'facility'])->assertConflict();
    resourceCommand($tenant, $owner, $area, 'location.save', ['title' => 'Orphan', 'level' => 'room'])->assertUnprocessable();
    resourceCommand($tenant, $owner, $area, 'location.save', ['id' => $facility['id'], 'expected_version' => 0, 'title' => 'Stale', 'level' => 'facility'])->assertConflict();
    $official = resourceCommand($tenant, $owner, $area, 'location.save', ['title' => 'Renamed college', 'level' => 'facility', 'catalog_map_number' => '50'])->assertOk()->assertJsonPath('title', 'Green House')->assertJsonPath('data.ownership', null)->json();
    resourceCommand($tenant, $owner, $area, 'location.save', ['id' => $official['id'], 'expected_version' => 1, 'title' => 'Inferred greenhouse', 'level' => 'facility'])->assertOk()->assertJsonPath('title', 'Green House');
    $viewer = resourceMember($tenant, $area, 'viewer');
    $this->actingAs($viewer)->getJson(route('enterprise.resources.show', ['tenant' => $tenant->slug, 'id' => $room['id']]))->assertOk()->assertJsonPath('data.parent_id', $floor['id']);
    resourceCommand($tenant, $viewer, $area, 'location.save', ['title' => 'Denied', 'level' => 'facility'])->assertNotFound();
});

test('asset custody keeps holder location return condition and history without granting access', function (): void {
    [$tenant, $owner, $area] = resourceFixture();
    $location = resourceLocation($tenant, $owner, $area);
    $asset = resourceCommand($tenant, $owner, $area, 'asset.save', ['title' => 'Synthetic PA system', 'location_id' => $location['id'], 'condition' => 'Fixture only'])->assertOk()->json();
    $holder = resourceMember($tenant, $area);
    $other = resourceMember($tenant, $area);
    $checkout = ['id' => $asset['id'], 'request_id' => (string) Str::uuid(), 'expected_version' => 1, 'holder_id' => $holder->id,
        'location_id' => $location['id'], 'expected_return_at' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'), 'condition' => 'Working'];
    resourceCommand($tenant, $holder, $area, 'asset.checkout', $checkout)->assertOk()->assertJsonPath('status', 'checked_out')->assertJsonPath('data.holder_id', (string) $holder->id);
    resourceCommand($tenant, $holder, $area, 'asset.checkout', $checkout)->assertOk()->assertJsonPath('replayed', true);
    resourceCommand($tenant, $holder, $area, 'asset.checkout', array_replace($checkout, ['request_id' => (string) Str::uuid()]))->assertConflict();
    resourceCommand($tenant, $other, $area, 'asset.return', ['id' => $asset['id'], 'expected_version' => 2, 'location_id' => $location['id']])->assertForbidden();
    resourceCommand($tenant, $holder, $area, 'asset.return', ['id' => $asset['id'], 'expected_version' => 2, 'location_id' => $location['id'], 'condition' => 'Returned intact'])->assertOk()->assertJsonPath('status', 'available')->assertJsonPath('data.holder_id', null)->assertJsonPath('data.condition', 'Returned intact');
    $this->actingAs($owner)->getJson(route('enterprise.resources.history', ['tenant' => $tenant->slug, 'id' => $asset['id']]))->assertOk()->assertJsonCount(3, 'items');
    expect(DB::table('tenant_user')->count())->toBe(3)->and(DB::table('team_area_members')->count())->toBe(2)->and(DB::table('team_project_members')->count())->toBe(0);
});

test('custody denies assignment to outsiders and revoked permissions block exact replay and history', function (): void {
    [$tenant, $owner, $area] = resourceFixture();
    $location = resourceLocation($tenant, $owner, $area);
    $asset = resourceCommand($tenant, $owner, $area, 'asset.save', ['title' => 'Synthetic projector', 'location_id' => $location['id']])->assertOk()->json();
    $member = resourceMember($tenant, $area);
    $outsider = teamMember($tenant, 'member');
    $checkout = ['id' => $asset['id'], 'request_id' => (string) Str::uuid(), 'expected_version' => 1, 'holder_id' => $outsider->id,
        'location_id' => $location['id'], 'expected_return_at' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z')];
    resourceCommand($tenant, $owner, $area, 'asset.checkout', $checkout)->assertUnprocessable();
    $checkout['holder_id'] = $member->id;
    resourceCommand($tenant, $member, $area, 'asset.checkout', $checkout)->assertOk();
    DB::table('team_area_members')->where('user_id', $member->id)->delete();
    resourceCommand($tenant, $member, $area, 'asset.checkout', $checkout)->assertNotFound();
    $this->actingAs($member)->getJson(route('enterprise.resources.history', ['tenant' => $tenant->slug, 'id' => $asset['id']]))->assertNotFound();
    $this->actingAs($owner)->getJson(route('enterprise.resources.show', ['tenant' => $tenant->slug, 'id' => $asset['id']]))->assertOk()->assertJsonPath('data.holder_name', 'Unavailable member');
    expect(DB::table('team_area_members')->count())->toBe(0);
});

test('exclusive UTC reservations retain conflicts for explicit manager resolution without rescheduling', function (): void {
    [$tenant, $owner, $area] = resourceFixture();
    $facility = resourceLocation($tenant, $owner, $area);
    $room = resourceLocation($tenant, $owner, $area, ['title' => 'Synthetic event room', 'level' => 'room', 'parent_id' => $facility['id']]);
    $first = resourceCommand($tenant, $owner, $area, 'reservation.save', ['title' => 'Synthetic class', 'resource_id' => $room['id'], 'starts_at' => '2026-11-01T14:00:00Z', 'ends_at' => '2026-11-01T15:00:00Z', 'category' => 'class'])->assertOk()->assertJsonPath('status', 'scheduled')->json();
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'title' => 'Synthetic repair window', 'resource_id' => $room['id'],
        'starts_at' => '2026-11-01T14:30:00Z', 'ends_at' => '2026-11-01T16:00:00Z', 'category' => 'maintenance'];
    $second = resourceCommand($tenant, $owner, $area, 'reservation.save', $input)->assertOk()->assertJsonPath('status', 'conflicted')->json();
    resourceCommand($tenant, $owner, $area, 'reservation.save', $input)->assertOk()->assertJsonPath('replayed', true);
    $conflict = TeamOperationRecord::where('kind', Resources::KINDS['conflict'])->sole();
    resourceCommand($tenant, $owner, $area, 'conflict.resolve', ['id' => $conflict->id, 'expected_version' => 1, 'note' => 'Pretend resolved'])->assertConflict();
    $editor = resourceMember($tenant, $area);
    resourceCommand($tenant, $editor, $area, 'reservation.cancel', ['id' => $second['id'], 'expected_version' => 1, 'note' => 'Denied'])->assertNotFound();
    resourceCommand($tenant, $owner, $area, 'reservation.cancel', ['id' => $second['id'], 'expected_version' => 1, 'note' => 'Manager explicitly cancelled repair window'])->assertOk();
    resourceCommand($tenant, $owner, $area, 'conflict.resolve', ['id' => $conflict->id, 'expected_version' => 1, 'note' => 'Repair window cancelled; class retained unchanged'])->assertOk()->assertJsonPath('status', 'resolved');
    $this->actingAs($owner)->getJson(route('enterprise.resources.show', ['tenant' => $tenant->slug, 'id' => $first['id']]))->assertOk()->assertJsonPath('data.starts_at', '2026-11-01T14:00:00Z')->assertJsonPath('version', 1);
    resourceCommand($tenant, $owner, $area, 'reservation.save', ['title' => 'Adjacent event', 'resource_id' => $room['id'], 'starts_at' => '2026-11-01T15:00:00Z', 'ends_at' => '2026-11-01T16:00:00Z'])->assertOk()->assertJsonPath('status', 'scheduled');
    expect(TeamOperationRecord::where('kind', Resources::KINDS['conflict'])->count())->toBe(1);
    $kept = resourceCommand($tenant, $owner, $area, 'reservation.save', ['title' => 'Retain exact window', 'resource_id' => $room['id'],
        'starts_at' => '2026-11-01T14:15:00Z', 'ends_at' => '2026-11-01T14:45:00Z'])->assertOk()->assertJsonPath('status', 'conflicted')->json();
    $remainingConflict = TeamOperationRecord::where('kind', Resources::KINDS['conflict'])->where('status', 'open')->sole();
    resourceCommand($tenant, $owner, $area, 'reservation.cancel', ['id' => $first['id'], 'expected_version' => 1, 'note' => 'Manager cancels original instead'])->assertOk();
    resourceCommand($tenant, $owner, $area, 'conflict.resolve', ['id' => $remainingConflict->id, 'expected_version' => 1, 'note' => 'Keep second reviewed window unchanged'])->assertOk();
    $this->actingAs($owner)->getJson(route('enterprise.resources.show', ['tenant' => $tenant->slug, 'id' => $kept['id']]))->assertOk()
        ->assertJsonPath('status', 'scheduled')->assertJsonPath('version', 2)->assertJsonPath('data.starts_at', '2026-11-01T14:15:00Z')->assertJsonPath('data.ends_at', '2026-11-01T14:45:00Z');
});

test('foreign tenant and department resource references fail without writes and rollout gates fail closed', function (): void {
    [$tenant, $owner, $area] = resourceFixture();
    $location = resourceLocation($tenant, $owner, $area);
    $otherArea = TeamArea::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => 'other', 'name' => 'Other synthetic', 'profile' => 'enterprise_department', 'version' => 1]);
    resourceCommand($tenant, $owner, $otherArea, 'asset.save', ['title' => 'Foreign location', 'location_id' => $location['id']])->assertNotFound();
    [$foreignTenant, $foreignOwner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id, (string) $foreignTenant->id]]);
    resourceCommand($foreignTenant, $foreignOwner, $area, 'asset.save', ['title' => 'Foreign tenant', 'location_id' => $location['id']])->assertNotFound();
    $outsider = teamMember($tenant, 'member');
    $this->actingAs($outsider)->getJson(route('enterprise.resources', ['tenant' => $tenant->slug, 'area_id' => $area->id]))->assertNotFound();
    config(['enterprise.resources_enabled' => false]);
    $this->actingAs($owner)->getJson(route('enterprise.resources.show', ['tenant' => $tenant->slug, 'id' => $location['id']]))->assertNotFound();
    config(['enterprise.resources_enabled' => true, 'enterprise.writes_enabled' => false]);
    resourceCommand($tenant, $owner, $area, 'location.save', ['title' => 'Frozen', 'level' => 'facility'])->assertForbidden();
    expect(TeamOperationRecord::where('kind', Resources::KINDS['location'])->count())->toBe(1)->and(TeamOperationRecord::where('kind', Resources::KINDS['asset'])->count())->toBe(0);
});

test('invalid time windows idempotency changes and fixture withdrawal with references retain existing evidence', function (): void {
    [$tenant, $owner, $area] = resourceFixture();
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'title' => 'One facility', 'level' => 'facility'];
    resourceCommand($tenant, $owner, $area, 'location.save', $input)->assertOk();
    resourceCommand($tenant, $owner, $area, 'location.save', array_replace($input, ['title' => 'Changed retry']))->assertConflict();
    $room = resourceLocation($tenant, $owner, $area, ['title' => 'Room', 'level' => 'room', 'parent_id' => $input['id']]);
    resourceCommand($tenant, $owner, $area, 'reservation.save', ['title' => 'Bad time', 'resource_id' => $room['id'], 'starts_at' => '2026-11-01T15:00:00Z', 'ends_at' => '2026-11-01T14:00:00Z'])->assertUnprocessable();
    resourceCommand($tenant, $owner, $area, 'reservation.save', ['title' => 'Unreviewed offset', 'resource_id' => $room['id'], 'starts_at' => '2026-11-01T14:00:00-05:00', 'ends_at' => '2026-11-01T15:00:00-05:00'])->assertUnprocessable();
    resourceCommand($tenant, $owner, $area, 'fixture.seed')->assertOk();
    $seed = TeamOperationRecord::where('kind', Resources::KINDS['location'])->get()->first(fn ($row) => ($row->data['map_number'] ?? null) === '52');
    resourceCommand($tenant, $owner, $area, 'asset.save', ['title' => 'Synthetic PA', 'location_id' => $seed->id])->assertOk();
    resourceCommand($tenant, $owner, $area, 'fixture.withdraw')->assertConflict();
    expect(TeamOperationRecord::where('status', 'withdrawn')->count())->toBe(0);
    expect(TeamOperationRecord::where('kind', Resources::KINDS['reservation'])->count())->toBe(0);
});

test('catalog fixture provisioning refuses production and asset exclusivity uses the same UTC conflict contract', function (): void {
    [$tenant, $owner, $area] = resourceFixture();
    $this->app['env'] = 'production';
    try {
        expect(fn () => app(Resources::class)->execute($tenant, $owner, ['action' => 'fixture.seed', 'id' => (string) Str::uuid(),
            'area_id' => $area->id, 'request_id' => (string) Str::uuid(), 'expected_version' => 0]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        expect(TeamOperationRecord::count())->toBe(0);
    } finally {
        $this->app['env'] = 'testing';
    }
    $location = resourceLocation($tenant, $owner, $area);
    $asset = resourceCommand($tenant, $owner, $area, 'asset.save', ['title' => 'Synthetic sound system', 'location_id' => $location['id']])->assertOk()->json();
    $booking = ['title' => 'Synthetic event equipment', 'resource_id' => $asset['id'], 'starts_at' => '2026-11-01T14:00:00Z', 'ends_at' => '2026-11-01T15:00:00Z'];
    resourceCommand($tenant, $owner, $area, 'reservation.save', $booking)->assertOk()->assertJsonPath('status', 'scheduled');
    resourceCommand($tenant, $owner, $area, 'reservation.save', $booking)->assertOk()->assertJsonPath('status', 'conflicted');
    $conflict = TeamOperationRecord::where('kind', Resources::KINDS['conflict'])->sole();
    expect($conflict->data['starts_at'])->toBe('2026-11-01T14:00:00Z')->and($conflict->data['ends_at'])->toBe('2026-11-01T15:00:00Z');
    expect($asset['qr_url'])->toBe($asset['url'])->and($asset['url'])->toContain('tenant='.$tenant->slug);
});

test('resource attention excludes catalog inventory and preserves urgent scope without tenant or department leakage', function (): void {
    [$tenant, $owner, $area] = resourceFixture();
    $this->travelTo(now()->utc()->setDate(2026, 10, 22)->setTime(12, 0));
    $location = resourceLocation($tenant, $owner, $area);
    for ($i = 0; $i < 205; $i++) {
        TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
            'kind' => Resources::KINDS['location'], 'title' => 'A directory location '.$i, 'source_key' => hash('sha256', 'attention-location-'.$i),
            'visibility' => 'area', 'version' => 1, 'status' => 'active', 'data' => ['level' => 'facility']]);
    }
    $asset = resourceCommand($tenant, $owner, $area, 'asset.save', ['title' => 'Z overdue asset', 'location_id' => $location['id']])->assertOk()->json();
    resourceCommand($tenant, $owner, $area, 'asset.checkout', ['id' => $asset['id'], 'expected_version' => 1,
        'holder_id' => $owner->id, 'location_id' => $location['id'], 'expected_return_at' => '2026-10-22T13:00:00Z'])->assertOk();
    $this->travelTo(now()->utc()->setTime(14, 0));
    $projection = app(Resources::class)->attention($tenant, $owner, $area->id);
    expect(array_column($projection['items'], 'id'))->toBe([$asset['id']])->and($projection['partial'])->toBeTrue();
    expect($projection['coverage'])->toContain('location inventory is excluded');
    $otherArea = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => 'attention-other',
        'name' => 'Private synthetic workspace', 'profile' => 'enterprise_department', 'version' => 1]);
    $member = resourceMember($tenant, $otherArea);
    expect(fn () => app(Resources::class)->attention($tenant, $member, $area->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(app(Resources::class)->attention($tenant, $member, $otherArea->id)['items'])->toBe([]);
    [$foreignTenant, $foreignOwner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id, (string) $foreignTenant->id]]);
    expect(fn () => app(Resources::class)->attention($foreignTenant, $foreignOwner, $area->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $this->travelBack();
});
