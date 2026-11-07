<?php

use App\Models\TeamUserConnection;
use App\Services\Inventory\StockLedger;
use App\Services\Pos\PosOperatorContext;
use App\Services\Pos\PosStore;
use App\Services\Team\AsanaReadProvider;

uses(Tests\Support\PosLiveTestCase::class);

require_once __DIR__.'/../Support/TeamFixtures.php';

beforeEach(function (): void {
    config(['pos_live.runtime_id' => 'context_fixture']);
    $this->travelTo(\Carbon\Carbon::parse('2026-11-07T14:00:00Z'));
    [$this->tenant, $this->operator] = teamWorkspace();
    $this->pos = app(PosStore::class);
    $this->stock = app(StockLedger::class);
    $this->pool = bin2hex(random_bytes(16));
    $this->register = (object) ['id' => bin2hex(random_bytes(16)), 'configuration' => $this->pos->encrypt([
        'inventory_pool_id' => $this->pool, 'tax_valid_until' => '2026-11-09T04:59:59Z',
        'shopify' => ['tax_address' => ['address1' => '200 Coliseum Drive', 'city' => 'Macon', 'provinceCode' => 'GA', 'countryCode' => 'US', 'zip' => '31217']],
    ])];
    $this->stock->rows('pools', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'id' => $this->pool, 'name' => 'Macon', 'kind' => 'show',
        'event_key' => 'event:123', 'active' => true, 'reviewed_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $this->stock->rows('bindings', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'channel' => 'pos', 'channel_key' => 'context_fixture:'.$this->register->id,
        'pool_id' => $this->pool, 'active' => true, 'publish_enabled' => false, 'reviewed_by' => 1, 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $this->stock->rows('event_links', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'event_id' => 123, 'provider' => 'asana', 'external_id' => '12345',
        'metadata' => json_encode(['name' => 'Macon show', 'start_on' => '2026-11-06', 'end_on' => '2026-11-08', 'timezone' => 'America/New_York',
            'address' => '200 Coliseum Drive', 'city' => 'Macon', 'state' => 'GA', 'postal_code' => '31217']),
        'reviewed_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $this->assignedTo = 'staff_123';
    $this->provider = Mockery::mock(AsanaReadProvider::class);
    $this->provider->shouldReceive('connection')->with($this->tenant->id, $this->operator->id)->andReturn(new TeamUserConnection(['status' => 'connected', 'external_user_id' => 'staff_123']));
    $this->provider->shouldReceive('task')->andReturnUsing(fn () => ['completed' => false, 'assignee' => ['gid' => $this->assignedTo]]);
    app()->instance(AsanaReadProvider::class, $this->provider);
    $this->actor = ['tenant_id' => $this->tenant->id, 'actor_id' => $this->operator->id];
});

it('selects only the reviewed authorized event for the currently signed-in assignee and stops after reassignment or expiry', function (): void {
    $context = app(PosOperatorContext::class);
    expect($context->resolve($this->actor, [$this->register])['recommended_register_id'])->toBe($this->register->id);
    $this->assignedTo = 'different_staff';
    expect($context->resolve($this->actor, [$this->register])['recommended_register_id'])->toBeNull();
    $this->assignedTo = 'staff_123';
    $this->travelTo(\Carbon\Carbon::parse('2026-11-09T05:00:00Z'));
    expect($context->resolve($this->actor, [$this->register])['recommended_register_id'])->toBeNull();
});

it('does not choose an unreviewed address, another tenant event or a stale saved register', function (): void {
    $settings = $this->pos->decrypt($this->register->configuration);
    $settings['shopify']['tax_address']['city'] = 'Columbus';
    $this->register->configuration = $this->pos->encrypt($settings);
    $context = app(PosOperatorContext::class);
    $result = $context->resolve($this->actor, [$this->register]);
    expect($result['recommended_register_id'])->toBeNull()->and($result['assignments'][0]['tax_ready'])->toBeFalse();
    expect($context->resolve(['tenant_id' => $this->tenant->id + 1, 'actor_id' => $this->operator->id], [$this->register])['assignments'])->toBe([]);
    expect($context->resolve($this->actor, [])['recommended_register_id'])->toBeNull();
});

it('uses the operators own Events Asana connection with current project and workspace access', function (): void {
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    $tenant = $this->tenant;
    $owner = $this->operator;
    $area = \App\Models\TeamArea::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id,
        'name' => 'Events', 'slug' => 'events', 'profile' => 'events', 'settings' => []]);
    $connection = \App\Models\TeamProviderConnection::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id,
        'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', 'fixture'),
        'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'],
        'settings' => ['external_user_id' => '300', 'source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(['app.asana.com/*' => \Illuminate\Support\Facades\Http::response(['data' => [
        'gid' => '12345', 'completed' => false, 'assignee' => ['gid' => '300'], 'workspace' => ['gid' => '100'],
        'memberships' => [['project' => ['gid' => '200']]],
    ]])]);
    app()->instance(AsanaReadProvider::class, new AsanaReadProvider);
    $actor = ['tenant_id' => $tenant->id, 'actor_id' => $owner->id];
    $context = app(PosOperatorContext::class);
    expect($context->resolve($actor, [$this->register])['recommended_register_id'])->toBe($this->register->id);
    $connection->update(['settings' => ['external_user_id' => 'different_user', 'source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    expect($context->resolve($actor, [$this->register])['recommended_register_id'])->toBeNull();
});

it('follows a native Teams task only while it is assigned and open under current access', function (): void {
    config(['team_work.enabled' => true]);
    $tenant = $this->tenant;
    $owner = $this->operator;
    $task = teamPost($tenant, $owner, ['kind' => 'task.create', 'title' => 'Fictional current event', 'assignee_id' => (string) $owner->id])->assertOk()->json('result');
    $this->stock->rows('event_links', $tenant->id)->update(['provider' => 'teams', 'external_id' => $task['id']]);
    $actor = ['tenant_id' => $tenant->id, 'actor_id' => $owner->id];
    $context = app(PosOperatorContext::class);
    expect($context->resolve($actor, [$this->register])['recommended_register_id'])->toBe($this->register->id);
    teamPost($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => $task['version']])->assertOk();
    expect($context->resolve($actor, [$this->register])['recommended_register_id'])->toBeNull();
});
