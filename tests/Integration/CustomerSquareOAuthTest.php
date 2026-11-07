<?php

use App\Http\Controllers\PosSquareSetupController;
use App\Http\Controllers\SquareCustomerOAuthController;
use App\Models\LandlordOperatorAction;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantMarketingSetting;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Marketing\SquareCustomerOAuth;
use App\Services\Pos\PosStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(Tests\TestCase::class);

beforeEach(function () {
    // Provider boundaries must execute without RefreshDatabase's outer transaction.
    // Rebuild only this process's disposable in-memory SQLite fixture database.
    if (DB::connection()->getDriverName() !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
        throw new RuntimeException('Customer Square OAuth fixtures require isolated in-memory SQLite.');
    }
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    Http::preventStrayRequests();
    config(['pos_live.enabled' => false, 'pos_live.runtime_id' => null, 'pos_live.square_application_id' => 'fictional-square-app',
        'pos_live.square_application_secret' => 'fictional-square-secret', 'pos_live.square_environment' => 'sandbox',
        'pos_live.square_oauth_redirect_uri' => 'https://app.example.test/pos/setup/square/callback',
        'customer_sources.square.redirect_uri' => 'https://app.example.test/workspace/customers/square/callback', 'customer_sources.square.environment' => 'sandbox']);
    app('session.store')->start();
    if (! Route::has('customers.square.connect')) {
        require base_path('routes/square-customer.php');
        Route::getRoutes()->refreshNameLookups();
    }
});

function squareOAuthWorkspace(string $role = 'owner'): array
{
    $tenant = Tenant::create(['name' => 'Fictional Square source company', 'slug' => 'square-oauth-'.Str::lower(Str::random(10))]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $owner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $tenant->users()->attach($owner, ['role' => $role, 'membership_active' => true]);

    return [$tenant, $owner];
}

function squareOAuthRequest(User $actor, array $input = [], string $method = 'POST'): Request
{
    $request = Request::create('https://app.example.test/workspace/customers/square/'.($method === 'GET' ? 'callback' : 'connect'), $method, $input);
    $request->setUserResolver(fn () => $actor);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

function squareOAuthBegin(Tenant $tenant, User $owner, int $generation = 0): string
{
    $url = app(SquareCustomerOAuth::class)->begin($tenant, $owner, squareOAuthRequest($owner, ['request_id' => (string) Str::uuid(), 'expected_generation' => $generation]));
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    return $query['state'];
}

function squareOAuthFake(string $merchant = 'FICTIONALMERCHANT', array $changes = [], ?Closure $onLocations = null): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($merchant, $changes, $onLocations) {
        expect(DB::transactionLevel())->toBe(0);
        $path = parse_url($request->url(), PHP_URL_PATH);
        $result = match ($path) {
            '/oauth2/token' => ['access_token' => 'fictional-source-access', 'refresh_token' => 'fictional-source-refresh', 'expires_at' => now()->addDay()->toIso8601String(), 'merchant_id' => $merchant],
            '/oauth2/token/status' => ['client_id' => 'fictional-square-app', 'merchant_id' => $merchant, 'scopes' => SquareCustomerOAuth::SCOPES, 'expires_at' => now()->addDay()->toIso8601String()],
            '/v2/merchants/'.$merchant => ['merchant' => ['id' => $merchant, 'status' => 'ACTIVE']],
            '/v2/locations' => ['locations' => [['id' => 'FICTIONALLOCATION', 'merchant_id' => $merchant, 'status' => 'ACTIVE', 'country' => 'US', 'currency' => 'USD', 'capabilities' => ['CREDIT_CARD_PROCESSING']]]],
            '/v2/locations/FICTIONALLOCATION' => ['location' => ['id' => 'FICTIONALLOCATION', 'merchant_id' => $merchant, 'status' => 'ACTIVE', 'country' => 'US', 'currency' => 'USD', 'capabilities' => ['CREDIT_CARD_PROCESSING']]],
            default => throw new RuntimeException('Unexpected request reached the fictional OAuth provider.'),
        };
        if ($path === '/v2/locations' && $onLocations) {
            $onLocations();
        }

        return Http::response(array_replace($result, $changes[$path] ?? []));
    });
}

function squareOAuthComplete(User $actor, string $state): array
{
    return app(SquareCustomerOAuth::class)->complete($actor, squareOAuthRequest($actor, ['state' => $state, 'code' => 'fictional-one-use-code'], 'GET'));
}

function squareOAuthFailure(Closure $call, int $expected): void
{
    try {
        $call();
        test()->fail('The incomplete or unauthorized source operation must fail closed.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe($expected);
    }
}

test('a verified Core tenant administrator requests only source read scopes without Pay or marketing access', function (string $role) {
    [$tenant, $owner] = squareOAuthWorkspace($role);
    $request = squareOAuthRequest($owner, ['request_id' => (string) Str::uuid(), 'expected_generation' => '0']);
    $url = app(SquareCustomerOAuth::class)->begin($tenant, $owner, $request);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect($owner->canAccessMarketing())->toBeFalse()->and(config('pos_live.enabled'))->toBeFalse()
        ->and(parse_url($url, PHP_URL_HOST))->toBe('connect.squareupsandbox.com')
        ->and(explode(' ', $query['scope']))->toBe(SquareCustomerOAuth::SCOPES)
        ->and($query['scope'])->not->toContain('WRITE', 'PAYOUTS', 'BANK_ACCOUNTS', 'LOYALTY')
        ->and($query['redirect_uri'])->toBe(config('customer_sources.square.redirect_uri'))
        ->and($request->session()->get(SquareCustomerOAuth::SESSION)['tenant_id'])->toBe($tenant->id)
        ->and(app(SquareCustomerOAuth::class)->status($tenant, $owner)['connection'])->toBeNull();
    Http::assertNothingSent();
})->with(['owner', 'tenant_owner', 'admin']);

test('source onboarding requires a fresh active verified owner administrator pivot even for platform staff', function (string $denial) {
    [$tenant, $owner] = squareOAuthWorkspace();
    match ($denial) {
        'member' => $tenant->users()->updateExistingPivot($owner, ['role' => 'member']),
        'manager' => $tenant->users()->updateExistingPivot($owner, ['role' => 'manager']),
        'inactive_membership' => $tenant->users()->updateExistingPivot($owner, ['membership_active' => false]),
        'inactive_user' => $owner->update(['is_active' => false]),
        'unverified' => $owner->forceFill(['email_verified_at' => null])->save(),
        'foreign' => $tenant->users()->detach($owner),
        'platform_without_pivot' => [$owner->update(['role' => 'platform_admin']), $tenant->users()->detach($owner)],
        'customers_disabled' => TenantModuleState::create(['tenant_id' => $tenant->id, 'module_key' => 'customers', 'enabled_override' => false]),
    };
    squareOAuthFailure(fn () => squareOAuthBegin($tenant, $owner), 403);
    expect(TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'square_config')->exists())->toBeFalse();
    Http::assertNothingSent();
})->with(['member', 'manager', 'inactive_membership', 'inactive_user', 'unverified', 'foreign', 'platform_without_pivot', 'customers_disabled']);

test('source OAuth stores encrypted tokens and safe audited metadata without provisioning Pay or rewards', function () {
    [$tenant, $owner] = squareOAuthWorkspace();
    $state = squareOAuthBegin($tenant, $owner);
    squareOAuthFake();
    $result = squareOAuthComplete($owner, $state);
    $value = TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'square_config')->firstOrFail()->value;
    $status = app(SquareCustomerOAuth::class)->status($tenant, $owner);

    expect($value['connection_id'])->toStartWith('square-source:')->and($value['generation'])->toBe(1)
        ->and($value['merchant_id'])->toBe('FICTIONALMERCHANT')->and($value['status'])->toBe('active')
        ->and($value)->not->toHaveKeys(['access_token', 'refresh_token', 'pending_oauth'])
        ->and(app(PosStore::class)->decrypt($value['credentials'])['access_token'])->toBe('fictional-source-access')
        ->and(json_encode($value))->not->toContain('fictional-source-access', 'fictional-source-refresh')
        ->and(json_encode([$status, $result['connection'], LandlordOperatorAction::where('tenant_id', $tenant->id)->get()->toArray()]))->not->toContain('fictional-source-access', 'fictional-source-refresh', 'fictional-square-secret', 'fictional-one-use-code')
        ->and(DB::table('pos_live_connections')->count())->toBe(0)->and(DB::table('pos_live_registers')->count())->toBe(0)
        ->and(DB::table('candle_cash_transactions')->count())->toBe(0)->and(config('pos_live.enabled'))->toBeFalse();
    Http::assertSentCount(4);
    squareOAuthFailure(fn () => squareOAuthComplete($owner, $state), 403);
    Http::assertSentCount(4);
});

test('OAuth rejects wrong state session actor expired requests and revoked callback membership before HTTP', function (string $denial) {
    [$tenant, $owner] = squareOAuthWorkspace();
    $state = squareOAuthBegin($tenant, $owner);
    if ($denial === 'state') {
        $state = 'not-the-authorized-state';
    }
    if ($denial === 'session') {
        app('session.store')->setId(Str::random(40));
    }
    if ($denial === 'expired') {
        $this->travel(11)->minutes();
    }
    if ($denial === 'membership') {
        $tenant->users()->updateExistingPivot($owner, ['membership_active' => false]);
    }
    $actor = $denial === 'actor' ? User::factory()->create(['email_verified_at' => now()]) : $owner;
    squareOAuthFailure(fn () => squareOAuthComplete($actor, $state), 403);
    Http::assertNothingSent();
})->with(['state', 'session', 'actor', 'expired', 'membership']);

test('reconnect preserves original merchant environment identity and advances only its generation', function () {
    [$tenant, $owner] = squareOAuthWorkspace();
    squareOAuthFake();
    squareOAuthComplete($owner, squareOAuthBegin($tenant, $owner));
    $original = app(SquareCustomerOAuth::class)->status($tenant, $owner)['connection'];
    squareOAuthComplete($owner, squareOAuthBegin($tenant, $owner, 1));
    $next = app(SquareCustomerOAuth::class)->status($tenant, $owner)['connection'];
    expect($next['connection_id'])->toBe($original['connection_id'])->and($next['merchant_id'])->toBe($original['merchant_id'])
        ->and($next['environment'])->toBe($original['environment'])->and($next['generation'])->toBe(2);
    $state = squareOAuthBegin($tenant, $owner, 2);
    squareOAuthFake('DIFFERENTMERCHANT');
    squareOAuthFailure(fn () => squareOAuthComplete($owner, $state), 409);
    $retained = app(SquareCustomerOAuth::class)->status($tenant, $owner)['connection'];
    expect($retained['connection_id'])->toBe($original['connection_id'])->and($retained['merchant_id'])->toBe('FICTIONALMERCHANT')->and($retained['generation'])->toBe(2);
});

test('incomplete read grants and foreign locations never install source credentials', function (string $denial) {
    [$tenant, $owner] = squareOAuthWorkspace();
    $state = squareOAuthBegin($tenant, $owner);
    $changes = match ($denial) {
        'scopes' => ['/oauth2/token/status' => ['scopes' => ['MERCHANT_PROFILE_READ', 'PAYMENTS_READ']]],
        'client' => ['/oauth2/token/status' => ['client_id' => 'different-client']],
        'merchant' => ['/oauth2/token/status' => ['merchant_id' => 'DIFFERENTMERCHANT']],
        'location' => ['/v2/locations' => ['locations' => [['id' => 'FOREIGNLOCATION', 'merchant_id' => 'DIFFERENTMERCHANT']]]],
    };
    squareOAuthFake('FICTIONALMERCHANT', $changes);
    squareOAuthFailure(fn () => squareOAuthComplete($owner, $state), 409);
    expect(TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'square_config')->firstOrFail()->value)->not->toHaveKey('credentials');
})->with(['scopes', 'client', 'merchant', 'location']);

test('a lost exchange response consumes the request once without retry or leaked token payloads', function () {
    [$tenant, $owner] = squareOAuthWorkspace();
    $state = squareOAuthBegin($tenant, $owner);
    Http::fake(['*' => Http::response(['access_token' => 'never-expose-this-provider-value', 'error' => 'fictional failure'], 503)]);
    squareOAuthFailure(fn () => squareOAuthComplete($owner, $state), 503);
    squareOAuthFailure(fn () => squareOAuthComplete($owner, $state), 403);
    Http::assertSentCount(1);
    expect(json_encode(TenantMarketingSetting::where('tenant_id', $tenant->id)->firstOrFail()->value))->not->toContain('never-expose-this-provider-value');
});

test('disconnect during provider authorization fences a late callback and retains the original binding', function () {
    [$tenant, $owner] = squareOAuthWorkspace();
    squareOAuthFake();
    squareOAuthComplete($owner, squareOAuthBegin($tenant, $owner));
    $original = app(SquareCustomerOAuth::class)->status($tenant, $owner)['connection'];
    $state = squareOAuthBegin($tenant, $owner, 1);
    $disconnect = ['request_id' => (string) Str::uuid(), 'expected_generation' => '1'];
    squareOAuthFake('FICTIONALMERCHANT', [], fn () => app(SquareCustomerOAuth::class)->disconnect($tenant, $owner, $disconnect));
    squareOAuthFailure(fn () => squareOAuthComplete($owner, $state), 409);
    $value = TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'square_config')->firstOrFail()->value;
    expect($value['status'])->toBe('disconnected')->and($value['generation'])->toBe(2)->and($value['connection_id'])->toBe($original['connection_id'])
        ->and($value['merchant_id'])->toBe('FICTIONALMERCHANT')->and($value)->not->toHaveKeys(['credentials', 'access_token', 'refresh_token']);
    expect(app(SquareCustomerOAuth::class)->disconnect($tenant, $owner, $disconnect)['generation'])->toBe(2);
});

test('callback persistence rechecks a membership revoked during its provider read', function () {
    [$tenant, $owner] = squareOAuthWorkspace();
    $state = squareOAuthBegin($tenant, $owner);
    squareOAuthFake('FICTIONALMERCHANT', [], fn () => $tenant->users()->updateExistingPivot($owner, ['membership_active' => false]));
    squareOAuthFailure(fn () => squareOAuthComplete($owner, $state), 403);
    expect(TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'square_config')->firstOrFail()->value)->not->toHaveKey('credentials');
});

test('callback routes its safe result to the original tenant rather than another selected workspace', function () {
    [$tenant, $owner] = squareOAuthWorkspace();
    [$other] = squareOAuthWorkspace();
    $state = squareOAuthBegin($tenant, $owner);
    squareOAuthFake();
    $request = squareOAuthRequest($owner, ['state' => $state, 'code' => 'fictional-one-use-code'], 'GET');
    $request->headers->set('Accept', 'application/json');
    $request->attributes->set('current_tenant', $other);
    $response = app(SquareCustomerOAuthController::class)->callback($request, app(SquareCustomerOAuth::class));
    $result = $response->getData(true);

    expect($result['redirect_url'])->toContain('tenant='.$tenant->slug)->not->toContain($other->slug)
        ->and($response->getContent())->not->toContain('fictional-source-access', 'fictional-source-refresh', 'fictional-one-use-code')
        ->and(TenantMarketingSetting::where('tenant_id', $other->id)->where('key', 'square_config')->exists())->toBeFalse();
});

test('orphaned Square history cannot be silently attached to a new merchant', function () {
    [$tenant, $owner] = squareOAuthWorkspace();
    \App\Models\SquareOrder::create(['tenant_id' => $tenant->id, 'square_order_id' => 'fictional-original-order', 'state' => 'COMPLETED']);
    squareOAuthFailure(fn () => squareOAuthBegin($tenant, $owner), 409);
    Http::assertNothingSent();
    expect(\App\Models\SquareOrder::where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'square_config')->exists())->toBeFalse();
});

test('local disconnect can stop an unverified legacy source without inventing a merchant identity', function () {
    [$tenant, $owner] = squareOAuthWorkspace();
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'square_config', 'value' => ['access_token' => 'fictional-old-token', 'environment' => 'sandbox', 'location_ids' => ['FICTIONALLOCATION']]]);
    $result = app(SquareCustomerOAuth::class)->disconnect($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_generation' => 0]);
    $value = TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'square_config')->firstOrFail()->value;

    expect($result['status'])->toBe('disconnected')->and($result['generation'])->toBe(1)
        ->and($value)->not->toHaveKeys(['merchant_id', 'access_token', 'credentials']);
    Http::assertNothingSent();
});

function squarePayOAuthComplete(Tenant $tenant, User $owner): void
{
    config(['pos_live.enabled' => true, 'pos_live.runtime_id' => 'fictional-square-runtime']);
    $controller = app(PosSquareSetupController::class);
    $response = $controller->start(squareOAuthRequest($owner, ['tenant_id' => $tenant->id]));
    parse_str(parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
    $controller->callback(squareOAuthRequest($owner, ['state' => $query['state'], 'code' => 'fictional-pay-code'], 'GET'));
}

test('Core and Pay cannot claim another tenants original Square merchant in either acquisition order', function (bool $coreFirst) {
    [$originalTenant, $originalOwner] = squareOAuthWorkspace();
    [$otherTenant, $otherOwner] = squareOAuthWorkspace();
    config(['pos_live.runtime_id' => 'fictional-square-runtime']);
    squareOAuthFake();
    if ($coreFirst) {
        squareOAuthComplete($originalOwner, squareOAuthBegin($originalTenant, $originalOwner));
        squareOAuthFailure(fn () => squarePayOAuthComplete($otherTenant, $otherOwner), 409);
        expect(DB::table('pos_live_merchants')->where('tenant_id', $otherTenant->id)->count())->toBe(0);
    } else {
        squarePayOAuthComplete($originalTenant, $originalOwner);
        squareOAuthFailure(fn () => squareOAuthComplete($otherOwner, squareOAuthBegin($otherTenant, $otherOwner)), 409);
        expect(TenantMarketingSetting::where('tenant_id', $otherTenant->id)->where('key', 'square_config')->firstOrFail()->value)->not->toHaveKey('credentials');
    }
})->with(['Core then Pay' => true, 'Pay then Core' => false]);

test('isolated Enterprise Pay OAuth keeps its original ownership and verification without retail tables', function () {
    [$tenant, $owner] = squareOAuthWorkspace();
    Schema::rename('marketing_settings', 'fictional_retained_marketing_settings');
    Schema::rename('tenant_marketing_settings', 'fictional_retained_tenant_settings');
    try {
        squareOAuthFake();
        squarePayOAuthComplete($tenant, $owner);
        squarePayOAuthComplete($tenant, $owner);
        expect(DB::table('pos_live_merchants')->where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and(DB::table('pos_live_connections')->where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and(DB::table('pos_live_connections')->where('tenant_id', $tenant->id)->value('verified_at'))->not->toBeNull();
    } finally {
        Schema::rename('fictional_retained_marketing_settings', 'marketing_settings');
        Schema::rename('fictional_retained_tenant_settings', 'tenant_marketing_settings');
    }
});

test('legacy reconnect normalizes omitted environment and location metadata without changing the original merchant', function (bool $locations) {
    [$tenant, $owner] = squareOAuthWorkspace();
    $legacy = ['merchant_id' => 'FICTIONALMERCHANT', 'access_token' => 'fictional-old-source-token'];
    if ($locations) {
        $legacy['location_ids'] = ['FICTIONALLOCATION'];
    }
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'square_config', 'value' => $legacy]);
    $state = squareOAuthBegin($tenant, $owner);
    $pending = app('session.store')->get(SquareCustomerOAuth::SESSION);
    expect($pending['environment'])->toBe('production')->and($pending['original_merchant_id'])->toBe('FICTIONALMERCHANT');
    squareOAuthFake();
    $result = squareOAuthComplete($owner, $state)['connection'];
    expect($result['environment'])->toBe('production')->and($result['merchant_id'])->toBe('FICTIONALMERCHANT')
        ->and($result['connection_id'])->toBe('tenant-config')->and($result['generation'])->toBe(1);
})->with(['with original locations' => true, 'merchant-only' => false]);

function squareRenewalFixture(): array
{
    [$tenant, $owner] = squareOAuthWorkspace();
    $value = ['connection_id' => 'square-source:'.Str::uuid(), 'generation' => 1, 'merchant_id' => 'FICTIONALMERCHANT',
        'environment' => 'sandbox', 'location_ids' => ['FICTIONALLOCATION'], 'status' => 'active',
        'credential_flow' => 'core_source_authorization_code', 'oauth_client_id' => 'fictional-square-app',
        'credential_issued_at' => now()->subDays(8)->toIso8601String(), 'expires_at' => now()->subMinute()->toIso8601String(),
        'granted_scopes' => SquareCustomerOAuth::SCOPES];
    $value['credentials'] = app(PosStore::class)->encrypt(['access_token' => 'fictional-expired-source-access', 'refresh_token' => 'fictional-original-refresh', 'expires_at' => $value['expires_at']]);
    $row = TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'square_config', 'value' => $value]);

    return [$tenant, $owner, $row];
}

test('source credential renewal recovers expired authorization once outside transactions and advances its original generation', function () {
    [$tenant, $owner, $row] = squareRenewalFixture();
    $original = $row->value;
    Http::fake(function ($request) use ($tenant, $owner) {
        expect(DB::transactionLevel())->toBe(0);
        if (parse_url($request->url(), PHP_URL_PATH) === '/oauth2/token') {
            expect($request['grant_type'])->toBe('refresh_token')->and($request['refresh_token'])->toBe('fictional-original-refresh')
                ->and($request['scopes'])->toBe(SquareCustomerOAuth::SCOPES);
            expect(app(SquareCustomerOAuth::class)->status($tenant, $owner)['connection']['status'])->toBe('renewal_unconfirmed');
            squareOAuthFailure(fn () => app(SquareCustomerOAuth::class)->renewIfNeeded($tenant->id), 409);

            return Http::response(['access_token' => 'fictional-renewed-source', 'refresh_token' => 'fictional-original-refresh', 'merchant_id' => 'FICTIONALMERCHANT', 'expires_at' => now()->addDays(30)->toIso8601String()]);
        }

        return Http::response(['client_id' => 'fictional-square-app', 'merchant_id' => 'FICTIONALMERCHANT', 'scopes' => SquareCustomerOAuth::SCOPES]);
    });
    app(SquareCustomerOAuth::class)->renewIfNeeded($tenant->id);
    app(SquareCustomerOAuth::class)->renewIfNeeded($tenant->id);
    $value = $row->fresh()->value;
    expect($value['generation'])->toBe(2)->and($value['connection_id'])->toBe($original['connection_id'])
        ->and($value['merchant_id'])->toBe($original['merchant_id'])->and($value)->not->toHaveKey('credential_refresh')
        ->and(app(PosStore::class)->decrypt($value['credentials'])['access_token'])->toBe('fictional-renewed-source')
        ->and(json_encode([app(SquareCustomerOAuth::class)->status($tenant, $owner), LandlordOperatorAction::where('tenant_id', $tenant->id)->get()->toArray()]))->not->toContain('fictional-renewed-source', 'fictional-original-refresh');
    Http::assertSentCount(2);
    expect(DB::table('candle_cash_transactions')->count())->toBe(0)->and(DB::table('pos_live_connections')->count())->toBe(0);
});

test('unconfirmed source renewal is retained and cannot be replayed by history refresh or maintenance', function () {
    [$tenant, $owner, $row] = squareRenewalFixture();
    $original = $row->value['credentials'];
    Http::fake(fn () => Http::response(['error' => 'fictional-provider-secret'], 503));
    squareOAuthFailure(fn () => app(SquareCustomerOAuth::class)->renewIfNeeded($tenant->id), 503);
    app(SquareCustomerOAuth::class)->renewIfNeeded($tenant->id);
    squareOAuthFailure(fn () => app(\App\Services\Marketing\SquareConnectionContext::class)->resolve($tenant->id), 409);
    $this->artisan('customer-sources:renew-square', ['--tenant-id' => $tenant->id])->assertSuccessful();
    $value = $row->fresh()->value;
    expect($value['status'])->toBe('reconnect_required')->and($value['credential_refresh']['status'])->toBe('unknown')
        ->and($value['credentials'])->toBe($original)->and($value['generation'])->toBe(1)
        ->and(json_encode(app(SquareCustomerOAuth::class)->status($tenant, $owner)))->not->toContain('fictional-provider-secret', 'fictional-original-refresh');
    Http::assertSentCount(1);
});

test('renewed token identity and permission failures keep encrypted candidate evidence without adopting it', function (string $denial) {
    [$tenant, $owner, $row] = squareRenewalFixture();
    $original = $row->value['credentials'];
    squareOAuthFake('FICTIONALMERCHANT', $denial === 'merchant'
        ? ['/oauth2/token' => ['merchant_id' => 'FOREIGNMERCHANT']]
        : ['/oauth2/token/status' => ['scopes' => ['PAYMENTS_READ']]]);
    squareOAuthFailure(fn () => app(SquareCustomerOAuth::class)->renewIfNeeded($tenant->id), 503);
    $value = $row->fresh()->value;
    expect($value['credentials'])->toBe($original)->and($value['generation'])->toBe(1)->and($value['status'])->toBe('reconnect_required')
        ->and(app(PosStore::class)->decrypt($value['credential_refresh']['candidate_credentials'])['access_token'])->toBe('fictional-source-access')
        ->and(json_encode($value))->not->toContain('fictional-source-access', 'fictional-source-refresh')
        ->and(app(SquareCustomerOAuth::class)->status($tenant, $owner)['connection']['merchant_id'])->toBe('FICTIONALMERCHANT');
})->with(['merchant', 'permissions']);

test('disconnect fences a late renewal result and does not restore cleared credentials', function () {
    [$tenant, $owner, $row] = squareRenewalFixture();
    Http::fake(function ($request) use ($tenant, $owner) {
        expect(DB::transactionLevel())->toBe(0);
        if (parse_url($request->url(), PHP_URL_PATH) === '/oauth2/token') {
            app(SquareCustomerOAuth::class)->disconnect($tenant, $owner, ['expected_generation' => 1, 'request_id' => (string) Str::uuid()]);

            return Http::response(['access_token' => 'fictional-renewed-source', 'refresh_token' => 'fictional-original-refresh', 'merchant_id' => 'FICTIONALMERCHANT', 'expires_at' => now()->addDays(30)->toIso8601String()]);
        }

        return Http::response(['client_id' => 'fictional-square-app', 'merchant_id' => 'FICTIONALMERCHANT', 'scopes' => SquareCustomerOAuth::SCOPES]);
    });
    squareOAuthFailure(fn () => app(SquareCustomerOAuth::class)->renewIfNeeded($tenant->id), 503);
    $value = $row->fresh()->value;
    expect($value['status'])->toBe('disconnected')->and($value['generation'])->toBe(2)->and($value)->not->toHaveKeys(['credentials', 'credential_refresh']);
});

test('renewal never handles legacy Pay foreign connection or outer transaction grants', function () {
    [$tenant, $owner, $row] = squareRenewalFixture();
    app(SquareCustomerOAuth::class)->renewIfNeeded($tenant->id, 'other-original-pay-connection');
    DB::transaction(function () use ($tenant) {
        try {
            app(SquareCustomerOAuth::class)->renewIfNeeded($tenant->id);
            test()->fail('Renewal must reject an outer transaction before claiming or HTTP.');
        } catch (\App\Services\Pos\PosException $error) {
            expect($error->blocker)->toBe('provider_transaction_boundary');
        }
    });
    $value = $row->fresh()->value;
    unset($value['credential_flow']);
    $row->update(['value' => $value]);
    app(SquareCustomerOAuth::class)->renewIfNeeded($tenant->id);
    expect($row->fresh()->value)->not->toHaveKey('credential_refresh');
    Http::assertNothingSent();
});

test('maintenance renews a due original Core grant and skips claimed and legacy settings', function () {
    [$tenant, $owner, $row] = squareRenewalFixture();
    [$other, , $claimed] = squareRenewalFixture();
    $claimed->update(['value' => [...$claimed->value, 'credential_refresh' => ['id' => 'fictional-old-claim', 'status' => 'claimed']]]);
    [$legacy] = squareOAuthWorkspace();
    TenantMarketingSetting::create(['tenant_id' => $legacy->id, 'key' => 'square_config', 'value' => ['access_token' => 'never-refresh-legacy-token']]);
    squareOAuthFake();
    $this->artisan('customer-sources:renew-square', ['--limit' => 2])->assertSuccessful();
    expect($row->fresh()->value['generation'])->toBe(2)->and($claimed->fresh()->value['generation'])->toBe(1);
    Http::assertSentCount(2);
});
