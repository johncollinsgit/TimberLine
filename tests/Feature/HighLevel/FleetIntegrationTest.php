<?php

use App\Models\FieldServiceVehicle;
use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\HighLevel\Authorization;
use App\Models\HighLevel\EmbeddedSession;
use App\Models\HighLevel\Installation;
use App\Models\HighLevel\UserBinding;
use App\Models\HighLevel\WebhookEvent;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantFleetTrackingSetting;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\HighLevel\BouncieWebhookService;
use App\Services\HighLevel\EmbeddedSessionService;
use App\Services\HighLevel\FleetService;
use App\Services\HighLevel\HighLevelApi;
use App\Services\HighLevel\InstallationService;
use App\Services\HighLevel\LifecycleService;
use App\Services\HighLevel\OAuthStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['highlevel.enabled' => true, 'highlevel.collection_enabled' => true, 'highlevel.billing_verified' => true,
        'highlevel.subscription_required' => true, 'highlevel.app_id' => 'fleet-app', 'highlevel.plan_id' => 'fleet-monthly', 'highlevel.shared_secret' => 'test-shared-secret',
        'highlevel.pilot_locations' => ['location-one', 'location-two'], 'services.fleet_tracking.enabled' => true,
        'highlevel.parent_origins' => ['https://app.gohighlevel.com', 'https://app.bridgecitymarketing.agency']]);
    Http::preventStrayRequests();
});

function highlevelWorkspace(string $location = 'location-one'): array
{
    $tenant = Tenant::create(['name' => $location.' Fleet', 'slug' => 'hl-'.$location]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    foreach (['customers', 'field_service', 'fleet', 'time_tracking', 'fleet_tracking'] as $module) {
        TenantModuleState::create(['tenant_id' => $tenant->id, 'module_key' => $module, 'enabled_override' => true, 'setup_status' => 'configured']);
    }
    $user = User::factory()->create(['role' => 'admin', 'is_active' => false]);
    $tenant->users()->attach($user->id, ['role' => 'admin', 'membership_active' => true]);
    $install = Installation::create(['app_id' => 'fleet-app', 'company_id' => 'company-one', 'location_id' => $location,
        'tenant_id' => $tenant->id, 'actor_user_id' => $user->id, 'plan_id' => 'fleet-monthly', 'status' => 'installed',
        'payment_status' => 'COMPLETE', 'parent_origin' => 'https://app.bridgecitymarketing.agency',
        'access_token' => 'location-token-'.$location, 'refresh_token' => 'refresh-'.$location, 'expires_at' => now()->addHour()]);
    TenantFleetTrackingSetting::create(['tenant_id' => $tenant->id, 'bouncie_tracking_enabled' => true, 'phone_tracking_enabled' => false,
        'policy_version' => 'v1', 'policy_sha256' => hash('sha256', 'policy'), 'approval_basis' => 'owner', 'approval_reference' => 'owner-approved', 'approved_at' => now(), 'retention_days' => 30]);
    $connection = IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'bouncie', 'external_account_id' => 'account-'.$location,
        'access_token' => 'bouncie-token-'.$location, 'refresh_token' => 'bouncie-refresh-'.$location, 'expires_at' => now()->addHour(), 'status' => 'connected']);

    return [$install, $tenant, $user, $connection];
}

function highlevelHttpUser(string $role = 'admin', array $locations = ['location-one', 'location-two']): array
{
    return ['id' => 'crm-admin', 'name' => 'Client administrator', 'email' => 'existing@example.test',
        'roles' => ['type' => 'account', 'role' => $role, 'locationIds' => $locations]];
}

function highlevelCipher(array $payload): string
{
    $salt = random_bytes(8);
    $derived = '';
    $previous = '';
    while (strlen($derived) < 48) {
        $previous = md5($previous.config('highlevel.shared_secret').$salt, true);
        $derived .= $previous;
    }

    return base64_encode('Salted__'.$salt.openssl_encrypt(json_encode($payload), 'aes-256-cbc', substr($derived, 0, 32), OPENSSL_RAW_DATA, substr($derived, 32, 16)));
}

function highlevelSession(Installation $install, User $user): string
{
    $binding = UserBinding::create(['installation_id' => $install->id, 'provider_user_id' => 'crm-admin', 'user_id' => $user->id, 'role' => 'admin', 'verified_at' => now()]);
    $token = str_repeat('s', 80);
    EmbeddedSession::create(['installation_id' => $install->id, 'binding_id' => $binding->id, 'token_hash' => hash('sha256', $token),
        'parent_origin' => 'https://app.bridgecitymarketing.agency', 'expires_at' => now()->addMinutes(15)]);

    return $token;
}

test('encrypted context creates installation-scoped identities without matching an existing email or cookies', function () {
    [$install] = highlevelWorkspace();
    $existing = User::factory()->create(['email' => 'existing@example.test', 'role' => 'platform_admin']);
    Http::fake(['*/users/crm-admin' => Http::response(highlevelHttpUser())]);
    $service = app(EmbeddedSessionService::class);
    $cipher = highlevelCipher(['companyId' => 'company-one', 'activeLocation' => 'location-one', 'userId' => 'crm-admin', 'email' => $existing->email, 'role' => 'platform_admin']);
    $issued = $service->exchange($cipher, $service->challenge(), 'https://app.bridgecitymarketing.agency');
    $session = $service->authenticate($issued['token'], 'https://app.bridgecitymarketing.agency');
    expect($session->installation_id)->toBe($install->id)
        ->and($session->binding->user_id)->not->toBe($existing->id)
        ->and(User::find($session->binding->user_id)->role)->toBe('admin')
        ->and(User::find($session->binding->user_id)->is_active)->toBeFalse();
    expect(fn () => $service->exchange($cipher, $service->challenge(), 'https://app.bridgecitymarketing.agency'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->get('/crm/fleet/launch')->assertOk()->assertHeader('Cache-Control')->assertDontSee('Jobs')->assertDontSee('App Store');
});

test('changed administrator access revokes existing embedded sessions', function () {
    [$install, , $user] = highlevelWorkspace();
    $token = highlevelSession($install, $user);
    Http::fake(['*/users/crm-admin' => Http::response(highlevelHttpUser('user'))]);
    $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Everbranch-Parent-Origin' => 'https://app.bridgecitymarketing.agency'])
        ->getJson('/crm/fleet/api/bootstrap')->assertForbidden();
    expect(EmbeddedSession::first()->revoked_at)->not->toBeNull();
});

test('each client receives only mapped tenant vehicles and the newest provider reading', function () {
    [$one, $tenant, $user, $connection] = highlevelWorkspace();
    [$two, $other, , $otherConnection] = highlevelWorkspace('location-two');
    foreach ([[$tenant, $connection, 'imei-one', 'Client one van'], [$other, $otherConnection, 'imei-two', 'Client two van']] as [$t, $c, $imei, $name]) {
        $vehicle = FieldServiceVehicle::create(['tenant_id' => $t->id, 'name' => $name, 'status' => 'active']);
        $device = FleetTrackingDevice::create(['tenant_id' => $t->id, 'field_service_vehicle_id' => $vehicle->id, 'integration_connection_id' => $c->id, 'provider' => 'bouncie', 'external_device_id' => $imei, 'status' => 'active']);
        foreach ([now()->subHour(), now()->subDay()] as $i => $at) {
            FleetLocationPoint::create(['tenant_id' => $t->id, 'fleet_tracking_device_id' => $device->id, 'field_service_vehicle_id' => $vehicle->id,
                'source' => 'bouncie', 'event_key' => hash('sha256', $imei.$i), 'latitude' => 34 + $i, 'longitude' => -82, 'recorded_at' => $at, 'received_at' => now()]);
        }
    }
    Http::fake(['*/users/crm-admin' => Http::response(highlevelHttpUser()), 'https://api.bouncie.dev/v1/vehicles*' => Http::response([])]);
    $token = highlevelSession($one, $user);
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Everbranch-Parent-Origin' => 'https://app.bridgecitymarketing.agency'])
        ->getJson('/crm/fleet/api/bootstrap?tenant_id='.$other->id);
    $response->assertOk()->assertJsonCount(1, 'vehicles')->assertJsonPath('vehicles.0.name', 'Client one van')
        ->assertJsonPath('vehicles.0.location.latitude', 34)->assertJsonPath('vehicles.0.location.older_reading', true)->assertDontSee('Client two van');
});

test('selection is replay safe, rejects the hundred-and-first vehicle and conflicting device ownership', function () {
    [$one, , $actor] = highlevelWorkspace();
    [$two, , $otherActor] = highlevelWorkspace('location-two');
    $available = array_map(fn ($i) => ['imei' => 'imei-'.$i, 'nickName' => 'Van '.$i], range(1, 101));
    Http::fake(['https://api.bouncie.dev/v1/vehicles*' => Http::response($available)]);
    $fleet = app(FleetService::class);
    $fleet->select($one, ['imei-1'], $actor->id);
    $fleet->select($one, ['imei-1'], $actor->id);
    expect(FleetTrackingDevice::count())->toBe(1)->and(FieldServiceVehicle::count())->toBe(1);
    expect(fn () => $fleet->select($two, ['imei-1'], $otherActor->id))->toThrow(ValidationException::class);
    $fleet->select($one, array_column(array_slice($available, 0, 100), 'imei'), $actor->id);
    expect(FleetTrackingDevice::where('status', 'active')->count())->toBe(100);
    expect(fn () => $fleet->select($one, array_column($available, 'imei'), $actor->id))->toThrow(ValidationException::class);
    $fleet->select($one, [], $actor->id);
    $fleet->select($two, ['imei-1'], $otherActor->id);
    expect(FleetTrackingDevice::where('status', 'active')->sole()->tenant_id)->toBe($two->tenant_id);
});

test('native billing has bounded failure grace, recovery and uninstall cannot be undone by a late payment', function () {
    [$install] = highlevelWorkspace();
    $service = app(LifecycleService::class);
    $event = ['type' => 'APP_PAYMENT_STATUS', 'appId' => 'fleet-app', 'locationId' => 'location-one', 'companyId' => 'company-one', 'previousStatus' => 'COMPLETE', 'newStatus' => 'FAILED'];
    $service->handle($event, now());
    expect($install->refresh()->hasSubscriptionAccess())->toBeTrue();
    $this->travel(31)->days();
    expect($install->refresh()->hasSubscriptionAccess())->toBeFalse();
    $service->handle([...$event, 'previousStatus' => 'FAILED', 'newStatus' => 'COMPLETE'], now());
    expect($install->refresh()->hasSubscriptionAccess())->toBeTrue();
    $service->handle(['type' => 'UNINSTALL', 'appId' => 'fleet-app', 'locationId' => 'location-one'], now());
    $this->travel(1)->seconds();
    $service->handle([...$event, 'newStatus' => 'COMPLETE'], now());
    expect($install->refresh()->status)->toBe('uninstalled')->and($install->hasSubscriptionAccess())->toBeFalse();
});

test('webhook verification authenticates the exact raw bytes and deduplicates accepted deliveries', function () {
    Queue::fake();
    $pair = sodium_crypto_sign_keypair();
    config(['highlevel.webhook_public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
    $raw = '{ "type": "INSTALL", "appId": "fleet-app", "webhookId": "delivery-1", "companyId": "company-one", "locationId": "location-one" }';
    $sig = base64_encode(sodium_crypto_sign_detached($raw, sodium_crypto_sign_secretkey($pair)));
    foreach ([1, 2] as $i) {
        $this->call('POST', '/crm/fleet/webhooks/lifecycle', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GHL_SIGNATURE' => $sig], $raw)->assertStatus(202);
    }
    expect(WebhookEvent::count())->toBe(1)->and(DB::table('highlevel_webhook_events')->value('payload'))->not->toContain('company-one');
    $this->call('POST', '/crm/fleet/webhooks/lifecycle', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GHL_SIGNATURE' => $sig], $raw.' ')->assertUnauthorized();
});

test('uninstall revokes sessions and stops both new and legacy Bouncie routes', function () {
    [$install, $tenant, $user, $connection] = highlevelWorkspace();
    $token = highlevelSession($install, $user);
    Http::fake(['https://api.bouncie.dev/v1/vehicles*' => Http::response([['imei' => 'test-device']])]);
    app(FleetService::class)->select($install, ['test-device'], $user->id);
    app(InstallationService::class)->uninstall($install);
    app(BouncieWebhookService::class)->process(['imei' => 'test-device', 'gps' => ['lat' => 34.5, 'lon' => -82], 'timestamp' => now()->subMinute()->toIso8601String()]);
    expect(FleetLocationPoint::count())->toBe(0)->and(EmbeddedSession::first()->revoked_at)->not->toBeNull()
        ->and($connection->refresh()->access_token)->toBeNull()->and(FleetTrackingDevice::first()->status)->toBe('inactive');
});

test('expired and consumed OAuth state cannot be reused', function () {
    $states = app(OAuthStateService::class);
    $state = $states->issue('highlevel');
    $states->consume('highlevel', $state);
    expect(fn () => $states->consume('highlevel', $state))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $state = $states->issue('highlevel');
    $this->travel(11)->minutes();
    expect(fn () => $states->consume('highlevel', $state))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('raw point pruning never exceeds thirty days even with a legacy longer retention setting', function () {
    [$install, $tenant] = highlevelWorkspace();
    TenantFleetTrackingSetting::where('tenant_id', $tenant->id)->update(['retention_days' => 90]);
    FleetLocationPoint::create(['tenant_id' => $tenant->id, 'source' => 'bouncie', 'event_key' => hash('sha256', 'old'), 'latitude' => 34, 'longitude' => -82, 'recorded_at' => now()->subDays(31), 'received_at' => now()]);
    $this->artisan('fleet-tracking:prune-location-points')->assertSuccessful();
    expect(FleetLocationPoint::count())->toBe(0);
});

test('bulk authorization provisions separate workspaces once and retries do not grant unrelated modules', function () {
    $tokens = ['appId' => 'fleet-app', 'companyId' => 'company-one', 'userId' => 'agency-admin', 'userType' => 'Company', 'access_token' => 'agency-token', 'refresh_token' => 'agency-refresh', 'expires_in' => 3600];
    Http::fake([
        '*/users/agency-admin' => Http::response(['id' => 'agency-admin', 'name' => 'Agency owner', 'roles' => ['role' => 'admin', 'type' => 'agency', 'locationIds' => []]]),
        '*/users/search*' => Http::response(['users' => [['id' => 'agency-admin']], 'count' => 1]),
        '*/oauth/installedLocations*' => Http::response(['locations' => [['_id' => 'location-one', 'isInstalled' => true], ['_id' => 'location-two', 'isInstalled' => true]]]),
        '*/oauth/locationToken' => function ($request) {
            return Http::response(['appId' => 'fleet-app', 'locationId' => $request['locationId'], 'access_token' => 'token-'.$request['locationId'], 'refresh_token' => 'refresh-'.$request['locationId'], 'planId' => 'fleet-monthly']);
        },
        '*/locations/location-one' => Http::response(['location' => ['id' => 'location-one', 'companyId' => 'company-one', 'name' => 'First client']]),
        '*/locations/location-two' => Http::response(['location' => ['id' => 'location-two', 'companyId' => 'company-one', 'name' => 'Second client']]),
    ]);
    $installs = app(InstallationService::class)->authorize($tokens);
    app(InstallationService::class)->authorize($tokens);
    expect(count($installs))->toBe(2)->and(Installation::count())->toBe(2)->and(Tenant::count())->toBe(2)
        ->and($installs[0]->tenant_id)->not->toBe($installs[1]->tenant_id)
        ->and($installs[0]->payment_status)->toBe('PENDING');
    $entitlements = \App\Models\TenantModuleEntitlement::where('tenant_id', $installs[0]->tenant_id)->where('enabled_status', 'enabled')->pluck('module_key')->sort()->values()->all();
    expect($entitlements)->toBe(['customers', 'field_service', 'fleet', 'fleet_tracking', 'time_tracking']);
    expect(Authorization::first()->getRawOriginal('access_token'))->not->toContain('agency-token');
});

test('HighLevel tokens renew once under a lock and persist a rotated refresh token', function () {
    [$install] = highlevelWorkspace();
    $install->update(['expires_at' => now()->subMinute()]);
    Http::fake(['*/oauth/token' => Http::response(['locationId' => 'location-one', 'access_token' => 'renewed', 'refresh_token' => 'rotated', 'expires_in' => 3600])]);
    expect(app(HighLevelApi::class)->accessToken($install))->toBe('renewed');
    expect(app(HighLevelApi::class)->accessToken($install))->toBe('renewed');
    expect($install->refresh()->refresh_token)->toBe('rotated');
    Http::assertSentCount(1);
});

test('invalid context origin, malformed ciphertext, wrong location and expired bearer all fail closed', function () {
    [$install, , $user] = highlevelWorkspace();
    Http::fake(['*/users/crm-admin' => Http::response(highlevelHttpUser('admin', ['location-two']))]);
    $service = app(EmbeddedSessionService::class);
    $context = ['companyId' => 'company-one', 'activeLocation' => 'location-one', 'userId' => 'crm-admin'];
    expect(fn () => $service->exchange(highlevelCipher($context), $service->challenge(), 'https://evil.test'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $service->exchange('not-ciphertext', $service->challenge(), 'https://app.bridgecitymarketing.agency'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $service->exchange(highlevelCipher($context), $service->challenge(), 'https://app.bridgecitymarketing.agency'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $token = highlevelSession($install, $user);
    $this->travel(16)->minutes();
    expect(fn () => $service->authenticate($token, 'https://app.bridgecitymarketing.agency'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('duplicate and out of order Bouncie events preserve the newest valid location and malformed samples are ignored', function () {
    [$install, $tenant, $user] = highlevelWorkspace();
    Http::fake(['https://api.bouncie.dev/v1/vehicles*' => Http::response([['imei' => 'event-device']])]);
    app(FleetService::class)->select($install, ['event-device'], $user->id);
    $event = ['eventType' => 'tripData', 'imei' => 'event-device', 'transactionId' => 'trip-one', 'data' => [
        ['timestamp' => now()->subMinute()->toIso8601String(), 'gps' => ['lat' => 34.5, 'lon' => -82]],
        ['timestamp' => now()->subHour()->toIso8601String(), 'gps' => ['lat' => 35, 'lon' => -83]],
        ['timestamp' => 'invalid', 'gps' => ['lat' => 900, 'lon' => 300]],
    ]];
    app(BouncieWebhookService::class)->process($event);
    app(BouncieWebhookService::class)->process($event);
    expect(FleetLocationPoint::count())->toBe(2);
    config(['highlevel.collection_enabled' => false]);
    app(BouncieWebhookService::class)->process([...$event, 'data' => [['timestamp' => now()->subSeconds(10)->toIso8601String(), 'gps' => ['lat' => 36, 'lon' => -84]]]]);
    expect(FleetLocationPoint::count())->toBe(2);
});

test('uninstall and reinstall reuse the workspace and require fresh billing and connection authorization', function () {
    [$install, $tenant] = highlevelWorkspace();
    app(InstallationService::class)->uninstall($install);
    Http::fake(['*/locations/location-one' => Http::response(['location' => ['id' => 'location-one', 'companyId' => 'company-one', 'name' => 'First client']])]);
    $reinstalled = app(InstallationService::class)->install('company-one', 'location-one', ['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'planId' => 'fleet-monthly']);
    expect($reinstalled->tenant_id)->toBe($tenant->id)->and(Tenant::count())->toBe(1)->and($reinstalled->payment_status)->toBe('PENDING');
});

test('a signed paid install before OAuth is retained through provisioning and reinstall can be paid again', function () {
    $service = app(LifecycleService::class);
    $paid = ['type' => 'INSTALL', 'appId' => 'fleet-app', 'companyId' => 'company-one', 'locationId' => 'location-one', 'planId' => 'fleet-monthly', 'trial' => ['onTrial' => false], 'whitelabelDetails' => ['domain' => 'app.bridgecitymarketing.agency']];
    $service->handle($paid, now());
    $install = Installation::sole();
    expect($install->payment_status)->toBe('COMPLETE')->and($install->tenant_id)->toBeNull();
    Http::fake(['*/locations/location-one' => Http::response(['location' => ['id' => 'location-one', 'companyId' => 'company-one', 'name' => 'Client']])]);
    $tokens = ['access_token' => 'location-token', 'refresh_token' => 'location-refresh', 'planId' => 'fleet-monthly'];
    $install = app(InstallationService::class)->install('company-one', 'location-one', $tokens);
    expect($install->hasSubscriptionAccess())->toBeTrue()->and($install->parent_origin)->toBe('https://app.bridgecitymarketing.agency');
    app(InstallationService::class)->uninstall($install);
    $this->travel(2)->seconds();
    $install = app(InstallationService::class)->install('company-one', 'location-one', $tokens);
    $service->handle($paid, now());
    expect($install->refresh()->hasSubscriptionAccess())->toBeTrue();
});

test('Bouncie top-level authorization binds state, PKCE and the verified installation without cookies', function () {
    [$install, , $user] = highlevelWorkspace();
    $token = highlevelSession($install, $user);
    config(['services.fleet_tracking.bouncie_client_id' => 'test-client', 'services.fleet_tracking.bouncie_client_secret' => 'test-secret']);
    Http::fake(['*/users/crm-admin' => Http::response(highlevelHttpUser()),
        'https://auth.bouncie.com/oauth/token' => Http::response(['access_token' => 'new-bouncie-token', 'refresh_token' => 'new-bouncie-refresh', 'expires_in' => 3600]),
        'https://api.bouncie.dev/v1/user' => Http::response(['id' => 'client-bouncie', 'name' => 'Client Bouncie']),
    ]);
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Everbranch-Parent-Origin' => 'https://app.bridgecitymarketing.agency'])
        ->postJson('/crm/fleet/api/bouncie/connect')->assertOk();
    $launch = $this->get($response->json('url'))->assertRedirect();
    parse_str(parse_url($launch->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['redirect_uri'])->toBe(config('highlevel.bouncie_redirect_uri'))
        ->and($query['code_challenge_method'])->toBe('S256')->and(strlen($query['code_challenge']))->toBe(43);
    $this->get('/crm/fleet/bouncie/callback?'.http_build_query(['state' => $query['state'], 'code' => 'authorized-code']))->assertOk()->assertSee('Bouncie connected');
    Http::assertSent(fn ($request) => $request->url() === 'https://auth.bouncie.com/oauth/token'
        && $request['redirect_uri'] === config('highlevel.bouncie_redirect_uri') && strlen($request['code_verifier']) === 96);
    $this->get('/crm/fleet/bouncie/callback?'.http_build_query(['state' => $query['state'], 'code' => 'authorized-code']))->assertForbidden();
});

test('HighLevel refresh cannot restore credentials revoked during the provider request', function () {
    [$install] = highlevelWorkspace();
    $install->update(['expires_at' => now()->subMinute()]);
    Http::fake(['*/oauth/token' => function () use ($install) {
        $install->update(['status' => 'uninstalled', 'access_token' => null, 'refresh_token' => null]);

        return Http::response(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 86400]);
    }]);
    expect(fn () => app(HighLevelApi::class)->accessToken($install))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($install->fresh()->access_token)->toBeNull()->and($install->fresh()->status)->toBe('uninstalled');
});

test('Bouncie refresh cannot restore a disconnected account and does not retry rotating grants', function () {
    [, , , $connection] = highlevelWorkspace();
    Http::fake(['https://auth.bouncie.com/oauth/token' => function () use ($connection) {
        $connection->update(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null]);

        return Http::response(['access_token' => 'new-token', 'refresh_token' => 'new-refresh']);
    }]);
    expect(fn () => app(\App\Services\Integrations\Bouncie\BouncieConnector::class)->refresh($connection))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($connection->fresh()->access_token)->toBeNull()->and($connection->fresh()->status)->toBe('disconnected');
    Http::assertSentCount(1);
});

test('bulk reconciliation reuses completed workspaces and remains disabled before rollout', function () {
    [$install] = highlevelWorkspace();
    $agency = Authorization::create(['app_id' => 'fleet-app', 'company_id' => 'company-one', 'status' => 'authorized',
        'access_token' => 'agency-token', 'refresh_token' => 'agency-refresh', 'expires_at' => now()->addHour()]);
    Http::fake(['*/oauth/installedLocations*' => Http::response(['locations' => [['_id' => 'location-one', 'isInstalled' => true]]])]);
    $this->artisan('highlevel:fleet-reconcile')->assertSuccessful();
    Http::assertSentCount(1);
    expect(Installation::count())->toBe(1)->and($install->fresh()->tenant_id)->toBe($install->tenant_id);
    config(['highlevel.enabled' => false]);
    $this->artisan('highlevel:fleet-reconcile')->assertSuccessful();
    Http::assertSentCount(1);
});

test('free installed accounts can set up one hundred vehicles while collection remains gated', function () {
    config(['highlevel.subscription_required' => false, 'highlevel.billing_verified' => false,
        'highlevel.collection_enabled' => false, 'highlevel.pilot_locations' => [], 'highlevel.plan_id' => null]);
    [$install, $tenant, $user] = highlevelWorkspace();
    $install->update(['plan_id' => null, 'payment_status' => 'PENDING']);
    $available = array_map(fn ($i) => ['imei' => 'free-'.$i, 'nickName' => 'Free van '.$i], range(1, 101));
    Http::fake(['*/users/crm-admin' => Http::response(highlevelHttpUser()),
        'https://api.bouncie.dev/v1/vehicles*' => Http::response($available)]);
    $token = highlevelSession($install, $user);
    $headers = ['Authorization' => 'Bearer '.$token, 'X-Everbranch-Parent-Origin' => 'https://app.bridgecitymarketing.agency'];
    $this->withHeaders($headers)->getJson('/crm/fleet/api/bootstrap')->assertOk()
        ->assertJsonPath('subscription.required', false)->assertJsonPath('subscription.has_access', true)
        ->assertJsonPath('subscription.setup_allowed', true)->assertJsonPath('subscription.collection_active', false)
        ->assertJsonPath('settings.vehicle_limit', 100);
    $this->withHeaders($headers)->getJson('/crm/fleet/api/devices')->assertOk()->assertJsonPath('limit', 100);
    $this->withHeaders($headers)->putJson('/crm/fleet/api/devices', ['devices' => array_column($available, 'imei')])
        ->assertUnprocessable()->assertJsonValidationErrors('devices');
    $this->withHeaders($headers)->putJson('/crm/fleet/api/devices', ['devices' => array_column(array_slice($available, 0, 100), 'imei')])->assertOk();
    $this->withHeaders($headers)->getJson('/crm/fleet/api/bootstrap')->assertOk()->assertJsonCount(100, 'vehicles');
    $this->withHeaders($headers)->putJson('/crm/fleet/api/settings', ['retention_days' => 14, 'tracking_enabled' => true,
        'policy_version' => 'free-v1', 'policy_sha256' => hash('sha256', 'approved policy'),
        'approval_confirmed' => true, 'approval_reference' => 'owner-free-pilot'])->assertOk();
    config(['services.fleet_tracking.bouncie_client_id' => 'test-client', 'services.fleet_tracking.bouncie_client_secret' => 'test-secret']);
    $this->withHeaders($headers)->postJson('/crm/fleet/api/bouncie/connect')->assertOk();
    app(BouncieWebhookService::class)->process(['imei' => 'free-1', 'gps' => ['lat' => 34.5, 'lon' => -82], 'timestamp' => now()->subMinute()->toIso8601String()]);
    expect(FleetLocationPoint::count())->toBe(0)->and($install->collectionAllowed())->toBeFalse();
    config(['highlevel.collection_enabled' => true]);
    expect($install->collectionAllowed())->toBeFalse();
    config(['highlevel.pilot_locations' => ['location-one']]);
    expect($install->collectionAllowed())->toBeTrue();
    config(['highlevel.subscription_required' => true]);
    expect($install->hasSubscriptionAccess())->toBeFalse()->and($install->setupAllowed())->toBeFalse();
    config(['highlevel.subscription_required' => false]);
    app(InstallationService::class)->uninstall($install);
    expect($install->refresh()->hasSubscriptionAccess())->toBeFalse()->and($install->collectionAllowed())->toBeFalse();
    $this->withHeaders($headers)->getJson('/crm/fleet/api/bootstrap')->assertUnauthorized();
});

function highlevelOperationsDevice(array $workspace, string $imei = 'ops-van'): FleetTrackingDevice
{
    [$install, $tenant, , $connection] = $workspace;
    $vehicle = FieldServiceVehicle::create(['tenant_id' => $tenant->id, 'name' => 'Operations van', 'status' => 'active']);

    return FleetTrackingDevice::create(['tenant_id' => $tenant->id, 'field_service_vehicle_id' => $vehicle->id,
        'integration_connection_id' => $connection->id, 'provider' => 'bouncie', 'external_device_id' => $imei, 'status' => 'active']);
}

test('trip telemetry merges out of order events, deduplicates metrics and preserves review through replay', function () {
    $w = highlevelWorkspace();
    $device = highlevelOperationsDevice($w);
    $ingest = app(\App\Services\FleetTracking\FleetLocationIngestionService::class);
    $start = now()->subHour()->toIso8601String();
    $end = now()->subMinutes(20)->toIso8601String();
    $metrics = ['eventType' => 'tripMetrics', 'imei' => $device->external_device_id, 'transactionId' => 't1',
        'metrics' => ['timestamp' => $end, 'tripDistance' => 40, 'tripTime' => 2400, 'totalIdlingTime' => 300]];
    expect($ingest->ingestBouncieEvent($metrics))->toBeTrue();
    $ingest->ingestBouncieEvent(['eventType' => 'tripEnd', 'imei' => $device->external_device_id, 'transactionId' => 't1', 'end' => ['timestamp' => $end, 'odometer' => 10040]]);
    $ingest->ingestBouncieEvent(['eventType' => 'tripStart', 'imei' => $device->external_device_id, 'transactionId' => 't1', 'start' => ['timestamp' => $start, 'odometer' => 10000]]);
    $record = \App\Models\HighLevel\FleetOperationRecord::where('kind', 'trip')->sole();
    $ops = app(\App\Services\HighLevel\FleetOperationsService::class);
    $ops->save($w[0], 'trip', ['expected_miles' => 20, 'baseline_reference' => 'Approved route', 'review_status' => 'approved_detour', 'review_note' => 'Road closure'], record: $record);
    $ingest->ingestBouncieEvent($metrics);
    $payload = $ops->bootstrap($w[0], [['id' => $device->id]]);
    expect($payload['trips'])->toHaveCount(1)->and($payload['trips'][0]['details']['distance_miles'])->toEqual(40)
        ->and($payload['trips'][0]['drive_seconds'])->toEqual(2100)->and($payload['trips'][0]['route_review'])->toBe('review')
        ->and($payload['trips'][0]['details']['review_status'])->toBe('approved_detour');
    expect(DB::table('fleet_operation_records')->where('id', $record->id)->value('payload'))->not->toContain('Road closure');
});

test('telemetry respects collection policy, mapping, retention and newest field timestamps', function () {
    $w = highlevelWorkspace();
    $device = highlevelOperationsDevice($w);
    $service = app(\App\Services\HighLevel\FleetTelemetryService::class);
    $now = now()->subMinute()->toIso8601String();
    $old = now()->subHour()->toIso8601String();
    $service->snapshot($device, ['stats' => ['lastUpdated' => $now, 'odometer' => 12000, 'mil' => ['milOn' => true, 'lastUpdated' => $now, 'battery' => ['status' => 'low', 'lastUpdated' => $now]]]]);
    $service->snapshot($device, ['stats' => ['lastUpdated' => $old, 'odometer' => 11000]]);
    $service->snapshot($device, ['stats' => ['lastUpdated' => $now, 'odometer' => 12000, 'mil' => ['milOn' => true, 'lastUpdated' => $now, 'battery' => ['status' => 'low', 'lastUpdated' => $now]]]]);
    expect(\App\Models\HighLevel\FleetOperationRecord::where('kind', 'telemetry')->sole()->payload['odometer'])->toEqual(12000)
        ->and(\App\Models\HighLevel\FleetOperationRecord::where('kind', 'alert')->count())->toBe(2);
    config(['highlevel.collection_enabled' => false]);
    expect($service->event($device, ['eventType' => 'battery', 'battery' => ['timestamp' => $now, 'value' => 'critical']]))->toBeFalse();
    config(['highlevel.collection_enabled' => true]);
    expect($service->event($device, ['eventType' => 'battery', 'battery' => ['timestamp' => now()->subDays(31)->toIso8601String(), 'value' => 'critical']]))->toBeFalse();
    TenantFleetTrackingSetting::where('tenant_id', $w[1]->id)->update(['approved_at' => null]);
    expect($service->event($device, ['eventType' => 'battery', 'battery' => ['timestamp' => $now, 'value' => 'critical']]))->toBeFalse()
        ->and(app(\App\Services\HighLevel\FleetOperationsService::class)->bootstrap($w[0], [['id' => $device->id]])['telemetry'])->toBe([]);
});

test('due service creates one task, completion records history and advances recurring plan once', function () {
    $w = highlevelWorkspace();
    $device = highlevelOperationsDevice($w);
    $ops = app(\App\Services\HighLevel\FleetOperationsService::class);
    $plan = $ops->save($w[0], 'service_plan', ['title' => 'Oil change', 'due_date' => now()->subDay()->toDateString(), 'interval_miles' => 5000, 'interval_days' => 180, 'assignee' => 'Fleet manager'], $device->id);
    $ops->maintenance($w[0]);
    $ops->maintenance($w[0]);
    expect(\App\Models\HighLevel\FleetOperationRecord::where('kind', 'maintenance_task')->count())->toBe(1);
    $completion = ['plan_id' => $plan->id, 'odometer' => 20000, 'serviced_at' => now()->toDateString(), 'notes' => 'Invoice 12'];
    $ops->completeService($w[0], $completion);
    expect(\App\Models\HighLevel\FleetOperationRecord::where('kind', 'service_log')->count())->toBe(1)
        ->and(\App\Models\HighLevel\FleetOperationRecord::where('kind', 'maintenance_task')->sole()->status)->toBe('resolved')
        ->and(\App\Models\HighLevel\FleetOperationRecord::where('kind', 'service_plan')->where('status', 'open')->sole()->payload['due_miles'])->toEqual(25000);
    expect(fn () => $ops->completeService($w[0], $completion))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('fleet operation APIs enforce client ownership and confirmation for alert and route decisions', function () {
    $w = highlevelWorkspace();
    $other = highlevelWorkspace('location-two');
    $device = highlevelOperationsDevice($w);
    $foreign = highlevelOperationsDevice($other, 'foreign');
    $ops = app(\App\Services\HighLevel\FleetOperationsService::class);
    $alert = $ops->save($w[0], 'alert', ['type' => 'battery'], $device->id);
    $trip = $ops->save($w[0], 'trip', ['distance_miles' => 10], $device->id);
    Http::fake(['*/users/crm-admin' => Http::response(highlevelHttpUser())]);
    $this->withHeaders(['Authorization' => 'Bearer '.highlevelSession($w[0], $w[2]), 'X-Everbranch-Parent-Origin' => 'https://app.bridgecitymarketing.agency']);
    $profile = ['crew' => 'Team A', 'available' => true, 'skills' => [], 'materials' => []];
    $this->putJson('/crm/fleet/api/operations/profiles/'.$foreign->id, $profile)->assertNotFound();
    $this->putJson('/crm/fleet/api/operations/profiles/'.$device->id, $profile)->assertOk();
    $this->putJson('/crm/fleet/api/operations/alerts/'.$alert->id, ['status' => 'resolved', 'assignee' => 'Manager'])->assertUnprocessable();
    $this->putJson('/crm/fleet/api/operations/alerts/'.$alert->id, ['status' => 'resolved', 'assignee' => 'Manager', 'resolution' => 'Battery replaced'])->assertOk();
    $this->putJson('/crm/fleet/api/operations/trips/'.$trip->id, ['expected_miles' => 5, 'extra_miles' => 5, 'extra_percent' => 30, 'review_status' => 'pending'])->assertUnprocessable();
    $this->putJson('/crm/fleet/api/operations/trips/'.$trip->id, ['job_id' => 9999, 'extra_miles' => 5, 'extra_percent' => 30, 'review_status' => 'pending'])->assertNotFound();
    $w[0]->update(['status' => 'uninstalled', 'uninstalled_at' => now()]);
    $this->putJson('/crm/fleet/api/operations/profiles/'.$device->id, $profile)->assertForbidden();
});

test('dispatch excludes stale, busy and unqualified crews and leaves assignment for dispatcher', function () {
    $w = highlevelWorkspace();
    $device = highlevelOperationsDevice($w);
    $ops = app(\App\Services\HighLevel\FleetOperationsService::class);
    $ops->save($w[0], 'profile', ['crew' => 'Crew A', 'available' => true, 'skills' => ['electrical'], 'materials' => ['wire' => 100]], $device->id);
    $job = $ops->save($w[0], 'job', ['title' => 'HighLevel work', 'latitude' => 34.6, 'longitude' => -82,
        'skills' => ['electrical'], 'materials' => ['wire' => 80], 'scheduled_start' => now()->toIso8601String(), 'scheduled_end' => now()->addHour()->toIso8601String()]);
    $job->update(['status' => 'active']);
    $vehicles = [['id' => $device->id, 'name' => 'Van', 'location' => ['latitude' => 34.5, 'longitude' => -82, 'older_reading' => false, 'reported_at' => now()->toIso8601String()]]];
    expect($ops->dispatch($w[0], $job, $vehicles)['suggestions'])->toHaveCount(1)
        ->and($job->refresh()->payload)->not->toHaveKey('device_id');
    $vehicles[0]['location']['older_reading'] = true;
    expect($ops->dispatch($w[0], $job, $vehicles)['suggestions'])->toBe([]);
    $vehicles[0]['location']['older_reading'] = false;
    $ops->save($w[0], 'profile', ['skills' => [], 'materials' => []], $device->id);
    expect($ops->dispatch($w[0], $job, $vehicles)['suggestions'])->toBe([]);
    $ops->save($w[0], 'profile', ['skills' => ['electrical'], 'materials' => ['wire' => 100]], $device->id);
    $busy = $ops->save($w[0], 'job', ['device_id' => $device->id, 'scheduled_start' => now()->subHour()->toIso8601String(), 'scheduled_end' => now()->addHour()->toIso8601String()]);
    $busy->update(['status' => 'active']);
    expect($ops->dispatch($w[0], $job, $vehicles)['suggestions'])->toBe([]);
});

test('HighLevel source sync uses installed location, excludes cancelled stops and creates no Everbranch jobs', function () {
    [$install, $tenant] = highlevelWorkspace();
    Http::fake(['*/calendars/?*' => Http::response(['calendars' => [['id' => 'calendar-one', 'name' => 'Work', 'locationId' => 'location-one'], ['id' => 'foreign-calendar', 'locationId' => 'location-two']]]),
        '*/opportunities/pipelines*' => Http::response(['pipelines' => []]),
        '*/calendars/events?*' => Http::response(['events' => [['id' => 'event-one', 'calendarId' => 'calendar-one', 'title' => 'Service stop', 'appointmentStatus' => 'confirmed', 'startTime' => now()->toIso8601String()], ['id' => 'cancelled', 'calendarId' => 'calendar-one', 'title' => 'Cancelled', 'appointmentStatus' => 'cancelled']]])]);
    $service = app(\App\Services\HighLevel\FleetJobSourceService::class);
    expect($service->sources($install)['calendars'])->toHaveCount(1);
    $service->sync($install, 'calendar', 'calendar-one');
    expect(\App\Models\HighLevel\FleetOperationRecord::forTenantId($tenant->id)->where('kind', 'job')->where('status', 'active')->count())->toBe(1)
        ->and(\App\Models\FieldServiceJob::forTenantId($tenant->id)->count())->toBe(0);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/calendars/events?') && $request['locationId'] === 'location-one' && $request['calendarId'] === 'calendar-one');
    expect(fn () => $service->sync($install, 'calendar', 'foreign-calendar'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('fleet operations retention removes private trip and job data but preserves service history', function () {
    $w = highlevelWorkspace();
    $device = highlevelOperationsDevice($w);
    $ops = app(\App\Services\HighLevel\FleetOperationsService::class);
    foreach (['trip', 'job', 'telemetry', 'service_log'] as $kind) {
        $record = $ops->save($w[0], $kind, ['notes' => 'old'], $device->id);
        $record->update(['event_at' => now()->subDays(10)]);
    }
    TenantFleetTrackingSetting::where('tenant_id', $w[1]->id)->update(['retention_days' => 7]);
    $this->artisan('highlevel:fleet-maintain')->assertSuccessful();
    expect(\App\Models\HighLevel\FleetOperationRecord::count())->toBe(1)
        ->and(\App\Models\HighLevel\FleetOperationRecord::sole()->kind)->toBe('service_log');
});

test('standalone demo exposes only fictional mode and a separate testing map key', function () {
    config(['services.google_maps.fleet_demo_api_key' => 'demo-map-key', 'services.google_maps.fleet_api_key' => 'production-map-key']);
    $this->get('/crm/fleet/demo')->assertOk()->assertSee('"demo":true', false)->assertSee('demo-map-key')->assertDontSee('production-map-key');
    expect(EmbeddedSession::count())->toBe(0)->and(\App\Models\HighLevel\FleetOperationRecord::count())->toBe(0);
});

test('trip paths use precise time bounds, device ownership and configured retention', function () {
    $w = highlevelWorkspace();
    $device = highlevelOperationsDevice($w);
    $ops = app(\App\Services\HighLevel\FleetOperationsService::class);
    $trip = $ops->save($w[0], 'trip', ['started_at' => now()->subDays(10)->toIso8601String(), 'ended_at' => now()->toIso8601String()], $device->id);
    foreach ([now()->subDays(8), now()->subHour(), now()->addHour()] as $i => $at) {
        FleetLocationPoint::create(['tenant_id' => $w[1]->id, 'fleet_tracking_device_id' => $device->id, 'field_service_vehicle_id' => $device->field_service_vehicle_id,
            'source' => 'bouncie', 'event_key' => hash('sha256', 'path'.$i), 'latitude' => 34 + $i, 'longitude' => -82, 'recorded_at' => $at, 'received_at' => now()]);
    }
    TenantFleetTrackingSetting::where('tenant_id', $w[1]->id)->update(['retention_days' => 7]);
    expect($ops->path($w[0], $trip)['points'])->toHaveCount(1)
        ->and($ops->path($w[0], $trip)['points'][0]['lat'])->toBe(35.0);
    $other = highlevelWorkspace('location-two');
    expect(fn () => $ops->path($other[0], $trip))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
