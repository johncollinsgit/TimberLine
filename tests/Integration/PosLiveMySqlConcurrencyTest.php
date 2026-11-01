<?php

use App\Services\Pos\NativePosCommerce;
use App\Services\Pos\PosException;
use App\Services\Pos\PosSchema;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('holds the last location stock unit for exactly one of four simultaneous cashiers', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires explicitly authorized local disposable MySQL and process forking.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing unsafe POS contention test.');
    }
    PosSchema::install();
    config(['pos_live.runtime_id' => 'stock_race_fixture']);
    $store = app(PosStore::class);
    $tenant = 900000001;
    $item = bin2hex(random_bytes(16));
    $sales = [];
    $store->rows('items', $tenant)->insert($store->values($tenant, ['id' => $item, 'location_id' => 'fixture_location', 'sku' => $item, 'title' => 'Fictional final stock unit',
        'kind' => 'merchandise', 'unit_amount_minor' => 1000, 'tax_bps' => 0, 'tax_basis' => 'Fictional reviewed test', 'tax_reviewed_at' => now(),
        'reviewed_by' => 900000002, 'revision' => str_repeat('a', 64), 'tracked' => true, 'quantity' => 1, 'active' => true]));
    $snapshot = ['terms' => ['source' => 'native', 'selections' => [['item_id' => $item, 'quantity' => 1, 'revision' => str_repeat('a', 64)]], 'stock' => [['item_id' => $item, 'quantity' => 1, 'revision' => str_repeat('a', 64)]]]];
    for ($i = 0; $i < 4; $i++) {
        $sales[] = $id = bin2hex(random_bytes(16));
        $store->rows('sales', $tenant)->insert($store->values($tenant, ['id' => $id, 'register_id' => str_repeat((string) ($i + 1), 32), 'location_id' => 'fixture_location',
            'actor_id' => 900000002 + $i, 'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(), 'sdk_attempt_id' => (string) Str::uuid(),
            'snapshot' => $store->encrypt($snapshot), 'snapshot_hash' => str_repeat('b', 64), 'amount_minor' => 1000, 'currency' => 'usd', 'commerce_source' => 'native',
            'state' => 'quoted', 'created_at' => now(), 'expires_at' => now()->addMinutes(5)]));
    }
    $directory = sys_get_temp_dir().'/pos-stock-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        foreach ($sales as $i => $id) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork stock race.');
            }
            if ($pid === 0) {
                try {
                    $child = new PosStore(DB::connection(), app('encrypter'));
                    try {
                        (new NativePosCommerce($child))->prepare($child->rows('sales', $tenant)->where('id', $id)->firstOrFail(), $snapshot);
                        $result = 'held';
                    } catch (PosException $error) {
                        $result = $error->blocker;
                    }
                    file_put_contents($directory.'/'.$i, $result);
                    DB::disconnect();
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(fn ($i) => file_get_contents($directory.'/'.$i), range(0, 3));
        expect(count(array_filter($results, fn ($v) => $v === 'held')))->toBe(1)
            ->and(count(array_filter($results, fn ($v) => $v === 'stock_unavailable')))->toBe(3)
            ->and((int) DB::table('pos_live_holds')->whereIn('sale_id', $sales)->sum('quantity'))->toBe(1);
    } finally {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        DB::table('pos_live_holds')->whereIn('sale_id', $sales)->delete();
        DB::table('pos_live_events')->whereIn('sale_id', $sales)->delete();
        DB::table('pos_live_sales')->whereIn('id', $sales)->delete();
        DB::table('pos_live_items')->where('id', $item)->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        } rmdir($directory);
    }
});

it('serializes two simultaneous POS reward reservations against one shared balance', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires explicitly authorized local disposable MySQL and process forking.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing unsafe reward contention test.');
    }
    config(['pos_live.runtime_id' => 'reward_race_fixture', 'pos_live.in_person_rewards_enabled' => true]);
    $tenant = \App\Models\Tenant::create(['name' => 'Reward race', 'slug' => 'reward-race-'.Str::random(10), 'status' => 'active']);
    $profile = \App\Models\MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fixture']);
    \App\Models\CandleCashBalance::create(['marketing_profile_id' => $profile->id, 'balance' => 5]);
    $store = app(PosStore::class);
    $sales = [];
    for ($i = 0; $i < 2; $i++) {
        $sales[] = $id = bin2hex(random_bytes(16));
        $store->rows('sales', $tenant->id)->insert($store->values($tenant->id, ['id' => $id, 'register_id' => str_repeat((string) ($i + 1), 32),
            'location_id' => 'fixture', 'actor_id' => 1, 'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(),
            'sdk_attempt_id' => (string) Str::uuid(), 'snapshot' => 'fixture', 'snapshot_hash' => str_repeat('b', 64),
            'amount_minor' => 1000, 'currency' => 'usd', 'commerce_source' => 'native', 'state' => 'quoted',
            'created_at' => now(), 'expires_at' => now()->addMinutes(5)]));
    }
    $directory = sys_get_temp_dir().'/pos-reward-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        foreach ($sales as $i => $id) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork reward race.');
            }
            if ($pid === 0) {
                try {
                    $child = new PosStore(DB::connection(), app('encrypter'));
                    $authority = new class((int) $profile->id) implements \App\Services\Pos\PosRewardReservationAuthority
                    {
                        public function __construct(private int $profileId) {}

                        public function authorize(int $tenant, object $sale, string $proof, int $amountMinor): array
                        {
                            return ['profile_id' => $this->profileId, 'expected_amount_minor' => $amountMinor, 'limit_minor' => 500,
                                'eligible_minor' => 1000, 'policy_version' => 'test-policy', 'authorization_reference' => 'verified-proof'];
                        }
                    };
                    try {
                        (new \App\Services\Pos\PosCandleCashReservation($child, $authority, new \App\Services\Pos\PosRewardRedemptionPolicy))
                            ->reserve((int) $tenant->id, $id, 500, 'fictional-proof');
                        $result = 'held';
                    } catch (PosException $error) {
                        $result = $error->blocker;
                    }
                    file_put_contents($directory.'/'.$i, $result);
                    DB::disconnect();
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(fn ($i) => file_get_contents($directory.'/'.$i), range(0, 1));
        expect(count(array_filter($results, fn ($v) => $v === 'held')))->toBe(1)
            ->and(count(array_filter($results, fn ($v) => $v === 'reward_amount_unavailable')))->toBe(1)
            ->and((float) DB::table('candle_cash_balances')->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(0.0)
            ->and(DB::table('pos_candle_cash_reservations')->whereIn('sale_id', $sales)->count())->toBe(1)
            ->and(DB::table('candle_cash_transactions')->where('marketing_profile_id', $profile->id)->where('source', 'pos_reservation')->count())->toBe(1);
    } finally {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        DB::table('pos_candle_cash_reservations')->whereIn('sale_id', $sales)->delete();
        DB::table('pos_live_sales')->whereIn('id', $sales)->delete();
        DB::table('candle_cash_transactions')->where('marketing_profile_id', $profile->id)->delete();
        DB::table('candle_cash_balances')->where('marketing_profile_id', $profile->id)->delete();
        DB::table('marketing_profiles')->where('id', $profile->id)->delete();
        DB::table('tenants')->where('id', $tenant->id)->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('binds one customer proof to exactly one of two simultaneous sales', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires explicitly authorized local disposable MySQL and process forking.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing unsafe reward proof contention test.');
    }
    config(['pos_live.runtime_id' => 'proof_race_fixture', 'pos_live.in_person_rewards_enabled' => true]);
    (require database_path('migrations/2026_11_01_160000_add_pos_reward_authorization_terms.php'))->up();
    $tenant = \App\Models\Tenant::create(['name' => 'Proof race', 'slug' => 'proof-race-'.Str::random(10), 'status' => 'active']);
    $profile = \App\Models\MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fixture']);
    foreach (['candle_cash_program_config' => ['redeem_increment_dollars' => 10],
        'candle_cash_policy_config' => ['access_state' => ['launch_state' => 'published']]] as $key => $value) {
        \App\Models\TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => $key, 'value' => $value]);
    }
    $programVersion = app(\App\Services\Pos\PosCustomerRewards::class)->policy($tenant->id)['version'];
    $raw = ['enabled' => true, 'max_minor' => 500, 'refund_policy' => 'restore_full_review_partial'];
    $version = app(\App\Services\Pos\PosRewardRedemptionSettings::class)->version($raw, $programVersion);
    \App\Models\TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => \App\Services\Pos\PosRewardRedemptionSettings::SETTING_KEY,
        'value' => [...$raw, 'reviewed_version' => $version, 'reviewed_by' => 1, 'reviewed_at' => now()->toIso8601String()]]);
    $accepted = ['max_minor' => 500, 'policy_version' => $version, 'disclosure_version' => \App\Services\Pos\PosRewardCustomerProof::DISCLOSURE_VERSION];
    $accountLink = \Tests\Support\PosVerifiedAccountFixture::create($profile);
    $proof = app(\App\Services\Pos\PosRewardCustomerProof::class)->issueForAuthenticatedProfile($profile, $accepted, $accountLink)['qr_payload'];
    $sales = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
    $directory = sys_get_temp_dir().'/pos-proof-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        foreach ($sales as $i => $id) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork proof race.');
            }
            if ($pid === 0) {
                try {
                    $store = new PosStore(DB::connection(), app('encrypter'));
                    $service = new \App\Services\Pos\PosRewardCustomerProof($store, app(\App\Services\Marketing\CanonicalMarketingProfileResolver::class));
                    try {
                        DB::transaction(fn () => $service->bind((int) $tenant->id, $proof, $id));
                        $result = 'bound';
                    } catch (PosException $error) {
                        $result = $error->blocker;
                    }
                    file_put_contents($directory.'/'.$i, $result);
                    DB::disconnect();
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(fn ($i) => file_get_contents($directory.'/'.$i), range(0, 1));
        expect(count(array_filter($results, fn ($v) => $v === 'bound')))->toBe(1)
            ->and(count(array_filter($results, fn ($v) => $v === 'reward_authorization_invalid')))->toBe(1)
            ->and(DB::table('pos_reward_authorizations')->where('tenant_id', $tenant->id)->whereIn('sale_id', $sales)->count())->toBe(1);
    } finally {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        DB::table('pos_reward_authorizations')->where('tenant_id', $tenant->id)->delete();
        DB::table('marketing_profiles')->where('id', $profile->id)->delete();
        DB::table('tenants')->where('id', $tenant->id)->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('durably fences a simultaneous payment start and cancellation', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires explicitly authorized local disposable MySQL and process forking.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing unsafe start claim contention test.');
    }
    config(['pos_live.runtime_id' => 'start_race_fixture', 'pos_live.durable_start_claims_enabled' => true]);
    $store = app(PosStore::class);
    $tenant = 900000001;
    $sale = bin2hex(random_bytes(16));
    $store->rows('sales', $tenant)->insert($store->values($tenant, ['id' => $sale, 'register_id' => bin2hex(random_bytes(16)),
        'location_id' => 'fixture', 'actor_id' => 1, 'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(),
        'sdk_attempt_id' => (string) Str::uuid(), 'snapshot' => 'fixture', 'snapshot_hash' => str_repeat('b', 64),
        'amount_minor' => 1000, 'currency' => 'usd', 'commerce_source' => 'native', 'state' => 'quoted',
        'created_at' => now(), 'expires_at' => now()->addMinutes(5)]));
    $directory = sys_get_temp_dir().'/pos-start-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        foreach (['starting', 'cancelled'] as $i => $action) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork start-claim race.');
            }
            if ($pid === 0) {
                try {
                    $child = new PosStore(DB::connection(), app('encrypter'));
                    $claims = new \App\Services\Pos\PosPaymentStartClaim($child);
                    try {
                        $result = $action === 'starting' ? $claims->beginCollection($tenant, $sale)->state : $claims->cancel($tenant, $sale)->state;
                    } catch (PosException $error) {
                        $result = $error->blocker;
                    }
                    file_put_contents($directory.'/'.$i, $result);
                    DB::disconnect();
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(fn ($i) => file_get_contents($directory.'/'.$i), range(0, 1));
        expect(count(array_filter($results, fn ($value) => $value === 'payment_claim_conflict')))->toBe(1)
            ->and(count(array_filter($results, fn ($value) => in_array($value, ['starting', 'cancelled'], true))))->toBe(1);
        $claim = DB::table('pos_payment_start_claims')->where('sale_id', $sale)->first();
        $state = DB::table('pos_live_sales')->where('id', $sale)->value('state');
        expect($claim->state)->toBe(in_array('starting', $results, true) ? 'starting' : 'cancelled')
            ->and($state)->toBe($claim->state === 'cancelled' ? 'cancelled' : 'quoted');
    } finally {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        DB::table('pos_payment_start_claims')->where('sale_id', $sale)->delete();
        DB::table('pos_live_sales')->where('id', $sale)->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('allows only one simultaneous start to proceed beyond the durable fence', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires explicitly authorized local disposable MySQL and process forking.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing unsafe start claim contention test.');
    }
    config(['pos_live.runtime_id' => 'duplicate_start_race_fixture', 'pos_live.durable_start_claims_enabled' => true]);
    $store = app(PosStore::class);
    $tenant = 900000001;
    $sale = bin2hex(random_bytes(16));
    $store->rows('sales', $tenant)->insert($store->values($tenant, ['id' => $sale, 'register_id' => bin2hex(random_bytes(16)),
        'location_id' => 'fixture', 'actor_id' => 1, 'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(),
        'sdk_attempt_id' => (string) Str::uuid(), 'snapshot' => 'fixture', 'snapshot_hash' => str_repeat('b', 64),
        'amount_minor' => 1000, 'currency' => 'usd', 'commerce_source' => 'native', 'state' => 'quoted',
        'created_at' => now(), 'expires_at' => now()->addMinutes(5)]));
    $directory = sys_get_temp_dir().'/pos-double-start-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        foreach (range(0, 1) as $i) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork start race.');
            }
            if ($pid === 0) {
                try {
                    $claims = new \App\Services\Pos\PosPaymentStartClaim(new PosStore(DB::connection(), app('encrypter')));
                    try {
                        $result = $claims->beginCollection($tenant, $sale)->state;
                    } catch (PosException $error) {
                        $result = $error->blocker;
                    }
                    file_put_contents($directory.'/'.$i, $result);
                    DB::disconnect();
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(fn ($i) => file_get_contents($directory.'/'.$i), range(0, 1));
        sort($results);
        expect($results)->toBe(['payment_recovery_required', 'starting'])
            ->and(DB::table('pos_payment_start_claims')->where('sale_id', $sale)->count())->toBe(1);
    } finally {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        DB::table('pos_payment_start_claims')->where('sale_id', $sale)->delete();
        DB::table('pos_live_sales')->where('id', $sale)->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
