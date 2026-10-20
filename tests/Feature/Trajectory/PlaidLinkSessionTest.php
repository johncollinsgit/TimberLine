<?php

use App\Jobs\Trajectory\SyncBank;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Connection;
use App\Models\User;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\PlaidService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withCredentials()->withCookie(config('session.cookie'), str_repeat('a', 40));
    config([
        'trajectory.enabled' => true, 'trajectory.plaid.client_id' => 'fixture', 'trajectory.plaid.secret' => 'fixture',
        'trajectory.plaid.environment' => 'sandbox', 'app.url' => 'https://trajectory.test',
        'trajectory.plaid.redirect_uri' => 'https://trajectory.test/trajectory',
    ]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    Queue::fake();
    $this->exchangeFails = false;
    Http::preventStrayRequests();
    Http::fake([
        '*/link/token/create' => Http::response(['link_token' => 'link-sandbox-original', 'expiration' => now()->addHour()->toIso8601String()]),
        '*/item/public_token/exchange' => fn () => $this->exchangeFails ? Http::response(['error_code' => 'PROVIDER_UNAVAILABLE'], 500) : Http::response(['item_id' => 'fictional-bank-item', 'access_token' => 'private-provider-token']),
        '*/item/remove' => Http::response([]),
    ]);
    $this->business = function () {
        $tenant = Tenant::create(['slug' => 'bank-link-company', 'name' => 'Bank link company']);
        TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
        $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
        [, $space] = app(PilotService::class)->prepare($this->owner, $tenant, true);
        app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);

        return $space;
    };
});

test('business bank links request transactions only with the registered OAuth return', function (): void {
    $space = ($this->business)();
    $response = $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$space->id}/banks/link")->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/link/token/create')
        && $request['products'] === ['transactions'] && ! isset($request['additional_consented_products'])
        && $request['redirect_uri'] === 'https://trajectory.test/trajectory'
        && $request['user']['client_user_id'] === "trajectory:user:{$this->owner->id}:space:{$space->id}");
});

test('new links fail closed without a same-origin HTTPS callback while existing provider access remains ready', function (): void {
    foreach ([null, 'http://trajectory.test/trajectory', 'https://other.test/trajectory', 'https://trajectory.test/trajectory?space=1', 'https://trajectory.test/wrong'] as $redirect) {
        config(['trajectory.plaid.redirect_uri' => $redirect]);
        expect(app(PlaidService::class)->ready())->toBeTrue()->and(app(PlaidService::class)->linkReady())->toBeFalse();
        $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/banks/link")->assertStatus(503)->assertSee('Existing feeds and statement imports remain available');
    }
    Http::assertNothingSent();
});

test('issued token evidence is encrypted and exchanges require the original actor space and browser session', function (): void {
    $link = $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/banks/link")->assertOk()->json();
    $encrypted = Cache::get('trajectory:plaid:link:'.hash('sha256', $link['link_session']));
    expect($encrypted)->not->toContain('link-sandbox-original')->not->toContain('private-provider-token');
    $state = json_decode(Crypt::decryptString($encrypted), true);
    expect($state['actor_id'])->toBe($this->owner->id)->and($state['space_id'])->toBe($this->space->id);
    $business = ($this->business)();
    $this->postJson("/trajectory/spaces/{$business->id}/banks/exchange", ['public_token' => 'public-fixture', 'link_session' => $link['link_session']])->assertForbidden();
    $partner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    DB::table('trajectory_members')->insert(['space_id' => $this->space->id, 'user_id' => $partner->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($partner)->postJson("/trajectory/spaces/{$this->space->id}/banks/exchange", ['public_token' => 'public-fixture', 'link_session' => $link['link_session']])->assertForbidden();
    try {
        app(PlaidService::class)->completeLinkSession($this->space, $this->owner->id, 'another-browser-session', $link['link_session'], null, 'public-fixture', fn () => ['ok' => true]);
        $this->fail('A different browser session must not complete this link.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    Http::assertSentCount(1);
    expect(Connection::count())->toBe(0);
});

test('successful completion is idempotent without exchanging the public token twice', function (): void {
    $link = $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/banks/link")->assertOk()->json();
    $body = ['public_token' => 'public-fixture', 'institution_name' => 'Fictional Bank', 'link_session' => $link['link_session']];
    $first = $this->postJson("/trajectory/spaces/{$this->space->id}/banks/exchange", $body)->assertOk()->assertJsonPath('needs_mapping', true)->json();
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/exchange", $body)->assertOk()->assertExactJson($first);
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/exchange", [...$body, 'public_token' => 'another-public-token'])->assertConflict();
    Http::assertSentCount(2);
    expect(Connection::count())->toBe(1);
    Queue::assertNothingPushed();
});

test('expired and missing issued sessions cannot exchange an arbitrary public token', function (): void {
    $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/banks/exchange", ['public_token' => 'public-fixture'])->assertUnprocessable();
    $link = $this->postJson("/trajectory/spaces/{$this->space->id}/banks/link")->assertOk()->json();
    $this->travel(61)->minutes();
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/exchange", ['public_token' => 'public-fixture', 'link_session' => $link['link_session']])->assertStatus(410);
    Http::assertSentCount(1);
    expect(Connection::count())->toBe(0);
});

test('failed provider exchange is not silently retried with a consumed link session', function (): void {
    $this->exchangeFails = true;
    $link = $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/banks/link")->assertOk()->json();
    $body = ['public_token' => 'public-fixture', 'link_session' => $link['link_session']];
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/exchange", $body)->assertUnprocessable();
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/exchange", $body)->assertConflict();
    Http::assertSentCount(2);
});

test('permission revocation and provider disconnect prevent a stale reconnect completion', function (): void {
    $connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'owned-item', 'access_token' => 'private-existing-token', 'status' => 'connected', 'coverage' => ['owner_user_id' => $this->owner->id]]);
    $link = $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/banks/link", ['connection_id' => $connection->id])->assertOk()->assertJsonPath('connection_id', $connection->id)->json();
    $this->deleteJson("/trajectory/spaces/{$this->space->id}/banks/{$connection->id}")->assertOk();
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/{$connection->id}/sync", ['link_session' => $link['link_session']])->assertStatus(410);
    $fresh = $this->postJson("/trajectory/spaces/{$this->space->id}/banks/link", ['connection_id' => $connection->id])->assertOk()->assertJsonPath('connection_id', null)->json();
    $this->space->update(['enabled' => false]);
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/exchange", ['public_token' => 'public-fixture', 'link_session' => $fresh['link_session']])->assertForbidden();
    Queue::assertNothingPushed();
    expect($connection->fresh()->status)->toBe('disconnected')->and($connection->fresh()->access_token)->toBe('');
});

test('completed update mode queues one sync and preserves household consent behavior', function (): void {
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    $connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'owned-item', 'access_token' => 'private-existing-token', 'status' => 'connected', 'coverage' => ['owner_user_id' => $this->owner->id]]);
    $link = $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/banks/link", ['connection_id' => $connection->id])->assertOk()->json();
    $body = ['link_session' => $link['link_session'], 'institution_name' => 'Fictional Bank'];
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/{$connection->id}/sync", $body)->assertOk();
    $this->postJson("/trajectory/spaces/{$this->space->id}/banks/{$connection->id}/sync", $body)->assertOk();
    Queue::assertPushed(SyncBank::class, 1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/link/token/create') && $request['access_token'] === 'private-existing-token' && ! isset($request['products']));
});

test('business sync does not request household investment or debt products', function (): void {
    $space = ($this->business)();
    $connection = Connection::create(['space_id' => $space->id, 'external_id' => 'business-only-item', 'access_token' => 'private-existing-token', 'status' => 'connected']);
    Http::fake(['*/transactions/sync' => Http::response(['accounts' => [], 'added' => [], 'modified' => [], 'removed' => [], 'next_cursor' => 'next', 'has_more' => false])]);
    app(PlaidService::class)->sync($connection);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transactions/sync'));
});
