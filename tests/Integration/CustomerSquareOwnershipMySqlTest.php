<?php

use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\SquareCustomerOAuth;
use App\Services\Pos\PosStore;
use App\Services\Pos\SquareMerchantOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PosProcessRace;

uses(Tests\TestCase::class);

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Explicit disposable MySQL ownership acceptance only.');
    }
    if (! app()->environment('testing') || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1'
        || ! str_starts_with($db->getDatabaseName(), 'codex_teams_') || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Requires explicitly authorized local codex_teams_ MySQL.');
    }
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Independent PHP processes required.');
    }
    config(['pos_live.runtime_id' => 'fictional-square-ownership']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    Http::preventStrayRequests();
});

function squareOwnershipClaim(int $tenant, string $merchant, string $kind): string
{
    try {
        return DB::transaction(function () use ($tenant, $merchant, $kind): string {
            app(SquareMerchantOwnership::class)->claim($tenant, $merchant, 'sandbox');
            if ($kind === 'core') {
                $existing = TenantMarketingSetting::where('tenant_id', $tenant)->where('key', 'square_config')->lockForUpdate()->first();
                TenantMarketingSetting::updateOrCreate(['tenant_id' => $tenant, 'key' => 'square_config'], ['value' => [
                    'merchant_id' => $merchant, 'environment' => 'sandbox', 'connection_id' => $existing?->value['connection_id'] ?? 'square-source:'.Str::uuid(),
                    'generation' => ($existing?->value['generation'] ?? 0) + 1, 'status' => 'active',
                    'credentials' => app(PosStore::class)->encrypt(['access_token' => 'fictional-no-provider-token', 'expires_at' => now()->addDay()->toIso8601String()]),
                ]]);
            } else {
                $store = app(PosStore::class);
                $store->rows('merchants', $tenant)->insertOrIgnore($store->values($tenant, ['merchant_id' => $merchant, 'environment' => 'sandbox']));
                abort_unless($store->rows('merchants', $tenant)->where('merchant_id', $merchant)->where('environment', 'sandbox')->lockForUpdate()->exists(), 409);
            }

            return $kind.'_claimed';
        }, 3);
    } catch (HttpException $error) {
        if ($error->getStatusCode() !== 409) {
            throw $error;
        }

        return 'blocked';
    }
}

test('MySQL Core and Pay ownership contenders see the committed merchant claim in both orders', function (bool $coreFirst) {
    $first = Tenant::create(['name' => 'Fictional first merchant owner', 'slug' => 'square-first-'.Str::lower(Str::random(12))]);
    $second = Tenant::create(['name' => 'Fictional competing merchant owner', 'slug' => 'square-second-'.Str::lower(Str::random(12))]);
    $merchant = 'FICT'.strtoupper(bin2hex(random_bytes(10)));
    $firstKind = $coreFirst ? 'core' : 'pay';
    $secondKind = $coreFirst ? 'pay' : 'core';
    $results = PosProcessRace::run(fn () => squareOwnershipClaim($first->id, $merchant, $firstKind), fn () => squareOwnershipClaim($second->id, $merchant, $secondKind),
        fn ($query) => preg_match('/select.*from [`"]marketing_settings[`"].*for update/is', $query->sql)
            && in_array('square_customer_merchant_ownership_mutex', $query->bindings, true));

    expect($results)->toBe([$firstKind.'_claimed', 'blocked'])
        ->and(TenantMarketingSetting::where('tenant_id', $second->id)->where('key', 'square_config')->exists())->toBeFalse()
        ->and(app(PosStore::class)->rows('merchants', $second->id)->where('merchant_id', $merchant)->exists())->toBeFalse();
    Http::assertNothingSent();
})->with(['Core first' => true, 'Pay first' => false]);

test('MySQL same tenant reconnect retains its original connection ID across source and Pay ownership claims', function () {
    $tenant = Tenant::create(['name' => 'Fictional reconnect owner', 'slug' => 'square-reconnect-'.Str::lower(Str::random(12))]);
    $merchant = 'FICT'.strtoupper(bin2hex(random_bytes(10)));
    expect(squareOwnershipClaim($tenant->id, $merchant, 'core'))->toBe('core_claimed');
    $original = TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'square_config')->firstOrFail()->value;
    expect(squareOwnershipClaim($tenant->id, $merchant, 'pay'))->toBe('pay_claimed')
        ->and(squareOwnershipClaim($tenant->id, $merchant, 'core'))->toBe('core_claimed');
    $next = TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'square_config')->firstOrFail()->value;
    expect($next['connection_id'])->toBe($original['connection_id'])->and($next['merchant_id'])->toBe($merchant)->and($next['generation'])->toBe(2);
    Http::assertNothingSent();
});

test('MySQL competing source renewals serialize one token request before any provider transport', function () {
    $tenant = Tenant::create(['name' => 'Fictional source renewal owner', 'slug' => 'square-renewal-'.Str::lower(Str::random(12))]);
    $merchant = 'FICT'.strtoupper(bin2hex(random_bytes(10)));
    config(['pos_live.square_application_id' => 'fictional-renewal-app', 'pos_live.square_application_secret' => 'fictional-renewal-secret']);
    $row = TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'square_config', 'value' => [
        'merchant_id' => $merchant, 'environment' => 'sandbox', 'connection_id' => 'square-source:'.Str::uuid(), 'generation' => 1, 'status' => 'active',
        'credential_flow' => 'core_source_authorization_code', 'oauth_client_id' => 'fictional-renewal-app',
        'credential_issued_at' => now()->subDays(8)->toIso8601String(), 'expires_at' => now()->subMinute()->toIso8601String(),
        'credentials' => app(PosStore::class)->encrypt(['access_token' => 'fictional-old-source', 'refresh_token' => 'fictional-source-refresh', 'expires_at' => now()->subMinute()->toIso8601String()]),
    ]]);
    $receipts = tempnam(sys_get_temp_dir(), 'square-source-renewal-');
    chmod($receipts, 0600);
    $work = function () use ($tenant, $merchant, $receipts): string {
        Http::fake(function ($request) use ($merchant, $receipts) {
            if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
                throw new RuntimeException('Source credential provider transport entered a transaction.');
            }
            if (parse_url($request->url(), PHP_URL_PATH) === '/oauth2/token') {
                file_put_contents($receipts, "token-request\n", FILE_APPEND | LOCK_EX);

                return Http::response(['access_token' => 'fictional-renewed-source', 'refresh_token' => 'fictional-source-refresh', 'merchant_id' => $merchant, 'expires_at' => now()->addDays(30)->toIso8601String()]);
            }

            return Http::response(['client_id' => 'fictional-renewal-app', 'merchant_id' => $merchant, 'scopes' => SquareCustomerOAuth::SCOPES]);
        });
        try {
            app(SquareCustomerOAuth::class)->renewIfNeeded($tenant->id);

            return 'completed';
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }

            return 'blocked';
        }
    };
    try {
        $results = PosProcessRace::run($work, $work, fn ($query) => preg_match('/update [`"]tenant_marketing_settings[`"]/i', $query->sql)
            && collect($query->bindings)->contains(fn ($value) => is_string($value) && str_contains($value, 'credential_refresh')));
        expect($results[0])->toBe('completed')->and($results[1])->toBeIn(['blocked', 'completed'])
            ->and(file_get_contents($receipts))->toBe("token-request\n");
        $value = $row->fresh()->value;
        expect($value['generation'])->toBe(2)->and($value['merchant_id'])->toBe($merchant)->and($value['status'])->toBe('active')
            ->and($value)->not->toHaveKey('credential_refresh');
    } finally {
        unlink($receipts);
    }
});

test('MySQL legacy source ownership without environment retains its production merchant namespace', function () {
    $original = Tenant::create(['name' => 'Fictional legacy merchant owner', 'slug' => 'square-legacy-'.Str::lower(Str::random(12))]);
    $other = Tenant::create(['name' => 'Fictional other merchant owner', 'slug' => 'square-other-'.Str::lower(Str::random(12))]);
    $merchant = 'FICT'.strtoupper(bin2hex(random_bytes(10)));
    TenantMarketingSetting::create(['tenant_id' => $original->id, 'key' => 'square_config', 'value' => ['merchant_id' => $merchant, 'access_token' => 'fictional-legacy-token']]);
    try {
        DB::transaction(fn () => app(SquareMerchantOwnership::class)->claim($other->id, $merchant, 'production'));
        test()->fail('A legacy production merchant remains owned by its original tenant.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }
    DB::transaction(fn () => app(SquareMerchantOwnership::class)->claim($original->id, $merchant, 'production'));
    expect(TenantMarketingSetting::where('tenant_id', $other->id)->where('key', 'square_config')->exists())->toBeFalse();
    Http::assertNothingSent();
});
