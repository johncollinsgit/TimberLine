<?php

use App\Models\FieldServiceReminderSetting;
use App\Models\FieldServiceVehicle;
use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantFleetTrackingSetting;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\FleetTracking\FleetMapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(18, 0));
});

afterEach(fn () => $this->travelBack());

function selectedFleetWorkspace(string $slug = 'selected-test', string $role = 'admin'): array
{
    $tenant = Tenant::create(['name' => 'Isolated Fleet QA', 'slug' => $slug]);
    $user = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $user->tenants()->attach($tenant->id, ['role' => $role, 'membership_active' => true]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    foreach (['field_service', 'fleet', 'time_tracking', 'fleet_tracking'] as $module) {
        TenantModuleState::create(['tenant_id' => $tenant->id, 'module_key' => $module, 'enabled_override' => true, 'setup_status' => 'configured']);
    }
    config(['services.fleet_tracking.enabled' => true, 'services.fleet_tracking.bouncie_api_base' => 'https://api.bouncie.dev/v1']);
    TenantFleetTrackingSetting::create(['tenant_id' => $tenant->id, 'bouncie_tracking_enabled' => true, 'policy_version' => 'qa', 'policy_sha256' => hash('sha256', 'qa'), 'approval_basis' => 'owner', 'approval_reference' => 'Isolated QA only', 'approved_at' => now(), 'retention_days' => 30]);
    $connection = IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'bouncie', 'status' => 'connected', 'access_token' => 'isolated-provider-token', 'expires_at' => now()->addHour()]);
    $vehicles = collect();
    foreach (['Crew Van 01', 'Crew Van 02', 'Spare Van'] as $i => $name) {
        $v = FieldServiceVehicle::create(['tenant_id' => $tenant->id, 'name' => $name, 'status' => 'active']);
        if ($i < 2) {
            FleetTrackingDevice::create(['tenant_id' => $tenant->id, 'field_service_vehicle_id' => $v->id, 'provider' => 'bouncie', 'external_device_id' => $slug.'-'.$i, 'status' => 'active']);
        }
        $vehicles->push($v);
    }

    return [$tenant, $user, $vehicles, $connection];
}

function selectedFleetRequest(User $user, array $query = []): Request
{
    $request = Request::create('/field-service/fleet-tracking/map', 'GET', $query);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function selectedFleetTrip(string $imei, string $id = 'trip-1', array $extra = []): array
{
    return array_replace(['imei' => $imei, 'transactionId' => $id, 'startTime' => '2026-10-15T12:00:00Z', 'endTime' => '2026-10-15T12:20:00Z', 'distance' => 4.2,
        'gps' => json_encode(['type' => 'LineString', 'coordinates' => [[-82.60, 34.82], [-82.601, 34.821], [-82.602, 34.822]]])], $extra);
}

test('every configured van remains visible and latest locations are ranked independently without leaking other accounts', function () {
    [$tenant,$user,$vehicles] = selectedFleetWorkspace();
    [$other] = selectedFleetWorkspace('other-tenant');
    $devices = FleetTrackingDevice::forTenantId($tenant->id)->orderBy('id')->get();
    foreach ($devices as $i => $device) {
        FleetLocationPoint::create(['tenant_id' => $tenant->id, 'source' => 'bouncie', 'event_key' => 'point-'.$i, 'fleet_tracking_device_id' => $device->id,
            'field_service_vehicle_id' => $device->field_service_vehicle_id, 'latitude' => 34.8 + $i * .01, 'longitude' => -82.6, 'event_type' => 'tripData', 'recorded_at' => now()->subMinutes($i ? 30 : 1), 'received_at' => now(), 'safe_payload' => ['speed' => $i ? 0 : 35]]);
    }
    $snapshot = app(FleetMapService::class)->snapshot($tenant, selectedFleetRequest($user));
    expect($snapshot['vehicles'])->toHaveCount(3)
        ->and($snapshot['vehicles'][0]['motion'])->toBe('Moving')
        ->and($snapshot['vehicles'][1]['status'])->toBe('stale')
        ->and($snapshot['vehicles'][1]['motion'])->toBe('Status unknown')
        ->and($snapshot['vehicles'][2]['status'])->toBe('unmapped')
        ->and($snapshot['vehicles'][2]['location'])->toBeNull();
    expect(json_encode($snapshot))->not->toContain('isolated-provider-token')->not->toContain('other-tenant');
    // A busy van used to consume the global 200-point limit.
    for ($i = 0; $i < 205; $i++) {
        FleetLocationPoint::create(['tenant_id' => $tenant->id, 'source' => 'bouncie', 'event_key' => 'busy-'.$i, 'fleet_tracking_device_id' => $devices[0]->id, 'latitude' => 34.82, 'longitude' => -82.60, 'recorded_at' => now()->subSeconds($i), 'received_at' => now()]);
    }
    expect(app(FleetMapService::class)->snapshot($tenant, selectedFleetRequest($user))['vehicles'][1]['location'])->not->toBeNull();
});

test('Today and Yesterday use account local days and honor DST and retention', function () {
    [$tenant,$user] = selectedFleetWorkspace();
    $service = app(FleetMapService::class);
    expect($service->range($tenant, selectedFleetRequest($user))['from'])->toBe('2026-10-15T04:00:00+00:00');
    FieldServiceReminderSetting::create(['tenant_id' => $tenant->id, 'timezone' => 'America/Los_Angeles']);
    $tenant->unsetRelation('fieldServiceReminderSetting');
    expect($service->range($tenant, selectedFleetRequest($user))['from'])->toBe('2026-10-15T07:00:00+00:00');
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-02T18:00:00Z'));
    FieldServiceReminderSetting::forTenantId($tenant->id)->update(['timezone' => 'America/New_York']);
    $tenant->unsetRelation('fieldServiceReminderSetting');
    $range = $service->range($tenant, selectedFleetRequest($user, ['start' => '2026-11-01', 'end' => '2026-11-01']));
    expect($range['from'])->toBe('2026-11-01T04:00:00+00:00')->and($range['until'])->toBe('2026-11-02T05:00:00+00:00');
    expect(fn () => $service->range($tenant, selectedFleetRequest($user, ['start' => '2026-01-01'])))->toThrow(ValidationException::class);
    expect(fn () => $service->range($tenant, selectedFleetRequest($user, ['start' => '2026-11-02', 'end' => '2026-11-01'])))->toThrow(ValidationException::class);
});

test('documented provider trips keep separate geometry and gaps and use provider distance without invented paths', function () {
    [$tenant,$user,$vehicles] = selectedFleetWorkspace();
    $imei = FleetTrackingDevice::forTenantId($tenant->id)->first()->external_device_id;
    Http::fake(['api.bouncie.dev/v1/trips*' => Http::response([
        selectedFleetTrip($imei), selectedFleetTrip($imei, 'trip-2', ['gps' => ['type' => 'MultiLineString', 'coordinates' => [[[-82.61, 34.82], [-82.611, 34.821], [-82.612, 34.822]], [[-82.62, 34.83], [-82.621, 34.831], [-82.622, 34.832]]]]]),
        selectedFleetTrip($imei, 'jump', ['gps' => ['type' => 'LineString', 'coordinates' => [[-82.6, 34.8], [-82.601, 34.801], [-82.2, 35.2], [-82.201, 35.201]]]]),
        selectedFleetTrip($imei, 'missing', ['gps' => 'bad-json', 'distance' => null]), selectedFleetTrip('another-account'),
        selectedFleetTrip($imei, 'outside', ['startTime' => '2026-10-13T12:00:00Z', 'endTime' => '2026-10-13T12:30:00Z']),
    ])]);
    $h = app(FleetMapService::class)->history($tenant, $vehicles[0]->id, selectedFleetRequest($user));
    expect($h['status'])->toBe('ready')->and($h['trips'])->toHaveCount(4)
        ->and($h['trips'][0]['distance_miles'])->toBe(4.2)->and($h['trips'][0]['duration_seconds'])->toBe(1200)
        ->and($h['trips'][1]['segments'])->toHaveCount(2)->and($h['trips'][2]['segments'])->toHaveCount(2)
        ->and($h['trips'][3]['geometry_status'])->toBe('missing')->and($h['trips'][3]['distance_miles'])->toBeNull();
    Http::assertSent(fn ($r) => $r['imei'] === $imei && $r['gps-format'] === 'geojson' && $r->header('Authorization') === ['isolated-provider-token']);
    expect(json_encode($h))->not->toContain($imei)->not->toContain('isolated-provider-token');
});

test('a failed van or expired authorization does not prevent other vans returning genuine trips', function () {
    [$tenant,$user,$vehicles] = selectedFleetWorkspace();
    $devices = FleetTrackingDevice::forTenantId($tenant->id)->get();
    Http::fake(['api.bouncie.dev/v1/trips*' => fn ($r) => $r['imei'] === $devices[0]->external_device_id ? Http::response([], 401) : Http::response([selectedFleetTrip($devices[1]->external_device_id)])]);
    $service = app(FleetMapService::class);
    expect($service->history($tenant, $vehicles[0]->id, selectedFleetRequest($user))['status'])->toBe('authorization_expired')
        ->and($service->history($tenant, $vehicles[1]->id, selectedFleetRequest($user))['trips'])->toHaveCount(1);
});

test('custom history windows stay within provider limits and deduplicate overlapping queries', function () {
    [$tenant,$user,$vehicles] = selectedFleetWorkspace();
    $imei = FleetTrackingDevice::forTenantId($tenant->id)->first()->external_device_id;
    Http::fake(['api.bouncie.dev/v1/trips*' => Http::response([selectedFleetTrip($imei)])]);
    $h = app(FleetMapService::class)->history($tenant, $vehicles[0]->id, selectedFleetRequest($user, ['start' => '2026-09-20', 'end' => '2026-10-15']));
    expect($h['trips'])->toHaveCount(1);
    foreach (Http::recorded() as [$r]) {
        expect(\Carbon\CarbonImmutable::parse($r['starts-after'])->diffInSeconds(\Carbon\CarbonImmutable::parse($r['ends-before'])))->toBeLessThanOrEqual(604800);
    }
    Http::assertSentCount(6);
});

test('no trips disconnected and policy-paused vehicles have explicit states and never call forbidden providers', function () {
    [$tenant,$user,$vehicles,$connection] = selectedFleetWorkspace();
    Http::fake(['api.bouncie.dev/v1/trips*' => Http::response([])]);
    $service = app(FleetMapService::class);
    expect($service->history($tenant, $vehicles[0]->id, selectedFleetRequest($user))['status'])->toBe('no_trips');
    $connection->update(['status' => 'disconnected']);
    expect($service->history($tenant, $vehicles[0]->id, selectedFleetRequest($user))['status'])->toBe('disconnected');
    TenantFleetTrackingSetting::forTenantId($tenant->id)->update(['bouncie_tracking_enabled' => false]);
    expect($service->history($tenant, $vehicles[0]->id, selectedFleetRequest($user))['status'])->toBe('disabled');
    Http::assertSentCount(1);
});

test('new map and history routes enforce membership role entitlement and foreign vehicle boundaries', function () {
    [$tenant,$admin,$vehicles] = selectedFleetWorkspace();
    [$other,$outsider,$foreign] = selectedFleetWorkspace('foreign-test');
    Http::fake(['api.bouncie.dev/*' => Http::response([])]);
    $path = '/field-service/fleet-tracking';
    $this->actingAs($admin)->getJson($path.'/map?tenant='.$tenant->slug)->assertOk()->assertJsonCount(3, 'vehicles')->assertHeader('Cache-Control');
    $this->getJson($path.'/map/vehicles/'.$foreign[0]->id.'/history?tenant='.$tenant->slug)->assertNotFound();
    $this->actingAs($outsider)->getJson($path.'/map?tenant='.$tenant->slug)->assertForbidden();
    $member = User::factory()->create(['role' => 'member']);
    $member->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($member)->getJson($path.'/map?tenant='.$tenant->slug)->assertForbidden();
    $this->getJson($path.'/map/vehicles/'.$vehicles[0]->id.'/history?tenant='.$tenant->slug)->assertForbidden();
    config(['services.fleet_tracking.enabled' => false]);
    $this->actingAs($admin)->getJson($path.'/map?tenant='.$tenant->slug)->assertForbidden();
});

test('exports an isolated rendered browser fixture when explicitly requested', function () {
    if (! getenv('FLEET_QA_EXPORT')) {
        $this->markTestSkipped('Opt-in browser fixture export.');
    }
    $this->app->forgetInstance(\Illuminate\Foundation\Vite::class);
    config(['app.url' => 'http://127.0.0.1:4328']);
    \Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1:4328');
    [$tenant,$admin,$vehicles] = selectedFleetWorkspace('qa-collins-fleet');
    $tenant->update(['name' => 'Collins Upstate Electric · Isolated QA']);
    $admin->update(['name' => 'QA Dispatcher', 'email' => 'fleet-preview@example.test']);
    $devices = FleetTrackingDevice::forTenantId($tenant->id)->get();
    foreach ($devices as $i => $d) {
        FleetLocationPoint::create(['tenant_id' => $tenant->id, 'source' => 'bouncie', 'event_key' => 'qa-'.$i, 'fleet_tracking_device_id' => $d->id, 'field_service_vehicle_id' => $d->field_service_vehicle_id, 'latitude' => 34.82 + $i * .04, 'longitude' => -82.6 + $i * .03, 'event_type' => 'tripData', 'recorded_at' => now()->subMinutes(1), 'received_at' => now(), 'safe_payload' => ['speed' => $i ? 0 : 25]]);
    }
    $stale = FieldServiceVehicle::create(['tenant_id' => $tenant->id, 'name' => 'Service Van 03', 'status' => 'active']);
    $d = FleetTrackingDevice::create(['tenant_id' => $tenant->id, 'field_service_vehicle_id' => $stale->id, 'provider' => 'bouncie', 'external_device_id' => 'qa-stale', 'status' => 'active']);
    FleetLocationPoint::create(['tenant_id' => $tenant->id, 'source' => 'bouncie', 'event_key' => 'qa-stale', 'fleet_tracking_device_id' => $d->id, 'latitude' => 34.79, 'longitude' => -82.65, 'event_type' => 'vehicleSnapshot', 'recorded_at' => now()->subHours(2), 'received_at' => now()]);
    Http::fake(['api.bouncie.dev/v1/vehicles*' => Http::response([]), 'api.bouncie.dev/v1/trips*' => function ($r) {
        if ($r['imei'] === 'qa-stale') {
            return Http::response([], 503);
        }
        $offset = str_ends_with($r['imei'], '-1') ? .035 : 0;
        $line = function ($pairs) use ($offset) {
            $dense = [];
            foreach ($pairs as $i => $p) {
                if ($i > 0) {
                    $previous = $pairs[$i - 1];
                    for ($step = 1; $step < 10; $step++) {
                        $dense[] = [$previous[0] + ($p[0] - $previous[0]) * $step / 10 + $offset, $previous[1] + ($p[1] - $previous[1]) * $step / 10];
                    }
                }
                $dense[] = [$p[0] + $offset, $p[1]];
            }

            return $dense;
        };

        return Http::response([
            selectedFleetTrip($r['imei'], 'morning-'.$r['imei'], ['gps' => ['type' => 'LineString', 'coordinates' => $line([[-82.62, 34.81], [-82.62, 34.82], [-82.61, 34.82], [-82.60, 34.83], [-82.60, 34.84]])]]),
            selectedFleetTrip($r['imei'], 'afternoon-'.$r['imei'], ['startTime' => '2026-10-15T15:10:00Z', 'endTime' => '2026-10-15T15:35:00Z', 'distance' => 6.8, 'gps' => ['type' => 'LineString', 'coordinates' => $line([[-82.59, 34.83], [-82.59, 34.84], [-82.58, 34.84], [-82.578, 34.843]])]])]);
    }]);
    $response = $this->actingAs($admin)->get('/field-service/fleet-tracking?tenant='.$tenant->slug)->assertOk();
    $folder = base_path('artifacts/collins-fleet/preview');
    @mkdir($folder, 0777, true);
    $html = str_replace('<section id="selected-fleet"', '<section id="selected-fleet"', $response->getContent());
    $html = str_replace('<div class="fleet-heading">', '<p class="fleet-notice">Isolated QA preview · fictional locations and trips · no live provider access</p><div class="fleet-heading">', $html);
    file_put_contents($folder.'/index.html', $html);
    $snapshot = app(FleetMapService::class)->snapshot($tenant, selectedFleetRequest($admin));
    file_put_contents($folder.'/snapshot.json', json_encode($snapshot, JSON_PRETTY_PRINT));
    foreach ($snapshot['vehicles'] as $v) {
        file_put_contents($folder.'/history-'.$v['id'].'.json', json_encode(app(FleetMapService::class)->history($tenant, $v['id'], selectedFleetRequest($admin)), JSON_PRETTY_PRINT));
    }
    expect(file_exists($folder.'/index.html'))->toBeTrue();
});

test('logical paths remove redundant samples while preserving real turns and never inventing coordinates', function () {
    [$tenant,$user,$vehicles] = selectedFleetWorkspace();
    $imei = FleetTrackingDevice::forTenantId($tenant->id)->first()->external_device_id;
    $dense = [];
    for ($i = 0; $i <= 50; $i++) {
        $dense[] = [-82.6, 34.8 + $i * .0001];
    }
    for ($i = 1; $i <= 50; $i++) {
        $dense[] = [-82.6 + $i * .0001, 34.805];
    }
    Http::fake(['api.bouncie.dev/v1/trips*' => Http::response([selectedFleetTrip($imei, 'dense', ['gps' => ['type' => 'LineString', 'coordinates' => $dense]])])]);
    $h = app(FleetMapService::class)->history($tenant, $vehicles[0]->id, selectedFleetRequest($user));
    $path = $h['trips'][0]['segments'][0];
    expect($path)->toHaveCount(3)->and($path[1])->toBe([34.805, -82.6])
        ->and($h['trips'][0]['trace_segments'])->toBe([true])->and($h['samples'])->toBe([]);
    foreach ($path as $point) {
        expect(in_array([$point[1], $point[0]], $dense, true))->toBeTrue();
    }
});

test('one failed date chunk still returns available history and representative samples are bounded', function () {
    [$tenant,$user,$vehicles] = selectedFleetWorkspace();
    $device = FleetTrackingDevice::forTenantId($tenant->id)->first();
    for ($i = 0; $i < 220; $i++) {
        FleetLocationPoint::create(['tenant_id' => $tenant->id, 'source' => 'bouncie', 'event_key' => 'sample-'.$i, 'fleet_tracking_device_id' => $device->id, 'latitude' => 34.82, 'longitude' => -82.6, 'recorded_at' => now()->subMinutes($i * 5), 'received_at' => now()]);
    }
    Http::fake(['api.bouncie.dev/v1/trips*' => Http::sequence()->push([], 503)->push([], 503)->push([selectedFleetTrip($device->external_device_id)])]);
    $h = app(FleetMapService::class)->history($tenant, $vehicles[0]->id, selectedFleetRequest($user, ['start' => '2026-10-07', 'end' => '2026-10-15']));
    expect($h['status'])->toBe('partial')->and($h['trips'])->toHaveCount(1)->and($h['samples'])->toHaveCount(100)->and($h['samples_truncated'])->toBeTrue();
});

test('signed disconnect signals are timestamped and late duplicate events never replace a newer reconnect', function () {
    [$tenant,$user] = selectedFleetWorkspace();
    $device = FleetTrackingDevice::forTenantId($tenant->id)->first();
    config(['services.fleet_tracking.bouncie_webhook_key' => 'isolated-signal-key']);
    $deliver = function ($type, $timestamp, $key = 'isolated-signal-key') use ($device) {
        $request = Request::create('/webhooks/bouncie', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_BOUNCIE_AUTHORIZATION' => $key], json_encode(['eventType' => $type, 'imei' => $device->external_device_id, $type => ['timestamp' => $timestamp]]));

        return app(\App\Services\FleetTracking\FleetLocationIngestionService::class)->ingestBouncie($request);
    };
    $deliver('disconnect', now()->subMinutes(2)->toISOString());
    expect(app(FleetMapService::class)->snapshot($tenant, selectedFleetRequest($user))['vehicles'][0]['status'])->toBe('disconnected');
    expect(FleetLocationPoint::count())->toBe(0);
    $deliver('connect', now()->subMinute()->toISOString());
    $deliver('disconnect', now()->subMinutes(2)->toISOString());
    expect(app(\App\Services\FleetTracking\FleetDeviceHealthService::class)->latest($tenant, $device)['status'])->toBe('connected');
    expect(fn () => $deliver('disconnect', now()->toISOString(), 'wrong'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('inactive membership and disabled module never expose map data', function () {
    [$tenant,$admin] = selectedFleetWorkspace();
    Http::fake(['api.bouncie.dev/*' => Http::response([])]);
    $admin->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
    $this->actingAs($admin)->getJson('/field-service/fleet-tracking/map?tenant='.$tenant->slug)->assertForbidden();
    Http::assertNothingSent();
});

test('a disabled Fleet entitlement denies new endpoints before provider access', function () {
    [$tenant,$admin] = selectedFleetWorkspace();
    TenantModuleState::forTenantId($tenant->id)->where('module_key', 'fleet_tracking')->update(['enabled_override' => false]);
    $this->actingAs($admin)->getJson('/field-service/fleet-tracking/map?tenant='.$tenant->slug)->assertForbidden();
    Http::assertNothingSent();
});

test('an ambiguous tracker never retrieves another accounts history', function () {
    [$tenant,$admin,$vehicles] = selectedFleetWorkspace();
    [$other,$user,$otherVehicles] = selectedFleetWorkspace('collision');
    $device = FleetTrackingDevice::forTenantId($tenant->id)->first();
    FleetTrackingDevice::forTenantId($other->id)->first()->update(['external_device_id' => $device->external_device_id]);
    $history = app(FleetMapService::class)->history($tenant, $vehicles[0]->id, selectedFleetRequest($admin));
    expect($history['message'])->toContain('ambiguous')->and($history['trips'])->toBe([]);
    Http::assertNothingSent();
});

test('a dispatcher with the existing manager role retains Fleet read access', function () {
    [$tenant,$manager] = selectedFleetWorkspace('dispatch', 'manager');
    Http::fake(['api.bouncie.dev/*' => Http::response([])]);
    $this->actingAs($manager)->getJson('/field-service/fleet-tracking/map?tenant='.$tenant->slug)->assertOk()->assertJsonCount(3, 'vehicles');
});

test('widely spaced points cannot masquerade as a continuous road path', function () {
    [$tenant,$user,$vehicles] = selectedFleetWorkspace();
    $imei = FleetTrackingDevice::forTenantId($tenant->id)->first()->external_device_id;
    Http::fake(['api.bouncie.dev/v1/trips*' => Http::response([selectedFleetTrip($imei, 'sparse', ['gps' => ['type' => 'LineString', 'coordinates' => [[-82.60, 34.8], [-82.59, 34.81], [-82.58, 34.82]]]])])]);
    $trip = app(FleetMapService::class)->history($tenant, $vehicles[0]->id, selectedFleetRequest($user))['trips'][0];
    expect($trip['geometry_status'])->toBe('sparse')->and($trip['trace_segments'])->toBe([false, false, false]);
});
