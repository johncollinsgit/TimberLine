<?php

use App\Models\CandleCashBalance;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CustomerMergeException;
use App\Services\Marketing\CustomerMergeService;
use App\Services\Pos\PosCandleCashReservation;
use App\Services\Pos\PosException;
use App\Services\Pos\PosRewardRedemptionPolicy;
use App\Services\Pos\PosRewardReservationAuthority;
use App\Services\Pos\PosStore;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    config(['pos_live.runtime_id' => 'fixture_runtime', 'pos_live.in_person_rewards_enabled' => true]);
    $this->tenant = Tenant::create(['name' => 'Rewards fixture', 'slug' => 'reward-'.Str::random(10), 'status' => 'active']);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Test']);
    CandleCashBalance::create(['marketing_profile_id' => $this->profile->id, 'balance' => 12.345]);
    $this->store = app(PosStore::class);
    $this->sale = bin2hex(random_bytes(16));
    $this->store->rows('sales', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->sale, 'register_id' => str_repeat('b', 32), 'location_id' => 'fixture', 'actor_id' => 1,
        'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(), 'sdk_attempt_id' => (string) Str::uuid(),
        'snapshot' => 'fixture', 'snapshot_hash' => str_repeat('a', 64), 'amount_minor' => 1000, 'currency' => 'USD',
        'commerce_source' => 'native', 'state' => 'quoted', 'stock_state' => 'unprepared',
        'created_at' => now(), 'expires_at' => now()->addMinutes(5),
    ]));
    $this->authority = Mockery::mock(PosRewardReservationAuthority::class);
    $this->terms = ['profile_id' => $this->profile->id, 'expected_amount_minor' => 500, 'limit_minor' => 500, 'eligible_minor' => 800,
        'policy_version' => 'published-policy-v1', 'authorization_reference' => 'verified-proof-1'];
    $this->authority->shouldReceive('authorize')->andReturnUsing(function ($tenant, $sale, $proof, $amount) {
        if ($proof !== 'customer-approved') {
            throw new PosException('reward_authorization_invalid', 403);
        }

        return [...$this->terms, 'expected_amount_minor' => $amount];
    });
    $this->service = new PosCandleCashReservation($this->store, $this->authority, new PosRewardRedemptionPolicy);
});

afterEach(function (): void {
    if (isset($this->sale)) {
        DB::table('pos_candle_cash_reservations')->where('runtime_id', 'fixture_runtime')->where('sale_id', $this->sale)->delete();
    }
});

it('debits the shared ledger once and preserves fractional balance across retries', function (): void {
    $hold = $this->service->reserve($this->tenant->id, $this->sale, 500, 'customer-approved');
    $again = $this->service->reserve($this->tenant->id, $this->sale, 500, 'customer-approved');
    expect($again->id)->toBe($hold->id)->and($hold->state)->toBe('held')
        ->and(CandleCashBalance::find($this->profile->id)->balance)->toBe(7.345)
        ->and(DB::table('candle_cash_transactions')->where('source', 'pos_reservation')->count())->toBe(1);
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 400, 'customer-approved'))
        ->toThrow(PosException::class, 'reward_reservation_conflict');
});

it('fails closed for disabled policy, authorization, sale limits and cross-tenant identity', function (): void {
    config(['pos_live.in_person_rewards_enabled' => false]);
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 100, 'customer-approved'))
        ->toThrow(PosException::class, 'in_person_rewards_disabled');
    config(['pos_live.in_person_rewards_enabled' => true]);
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 100, 'unapproved'))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 501, 'customer-approved'))
        ->toThrow(PosException::class, 'reward_amount_unavailable');
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.Str::random(10), 'status' => 'active']);
    $foreign = MarketingProfile::create(['tenant_id' => $other->id, 'first_name' => 'Other']);
    $this->terms['profile_id'] = $foreign->id;
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 100, 'customer-approved'))
        ->toThrow(PosException::class, 'customer_not_found');
    expect(CandleCashBalance::find($this->profile->id)->balance)->toBe(12.345)
        ->and(DB::table('pos_candle_cash_reservations')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('holds uncertain payments and refuses a new reservation after presentation', function (): void {
    $this->service->reserve($this->tenant->id, $this->sale, 500, 'customer-approved');
    $this->store->rows('sales', $this->tenant->id)->where('id', $this->sale)->update(['state' => 'unknown']);
    $hold = $this->service->reserve($this->tenant->id, $this->sale, 500, 'customer-approved');
    expect($hold->state)->toBe('held')->and(CandleCashBalance::find($this->profile->id)->balance)->toBe(7.345);
});

it('caps the hold to eligible discounted subtotal and actual balance', function (): void {
    $this->terms['eligible_minor'] = 250;
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 251, 'customer-approved'))
        ->toThrow(PosException::class, 'reward_amount_unavailable');
    $this->terms['eligible_minor'] = 800;
    CandleCashBalance::find($this->profile->id)->update(['balance' => 1.005]);
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 101, 'customer-approved'))
        ->toThrow(PosException::class, 'reward_amount_unavailable');
    $this->service->reserve($this->tenant->id, $this->sale, 100, 'customer-approved');
    expect(CandleCashBalance::find($this->profile->id)->balance)->toBe(0.005);
});

it('cannot create a hold once collection was presented', function (): void {
    $this->store->rows('sales', $this->tenant->id)->where('id', $this->sale)->update(['descriptor_issued_at' => now()]);
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 100, 'customer-approved'))
        ->toThrow(PosException::class, 'reward_sale_not_editable');
    expect(CandleCashBalance::find($this->profile->id)->balance)->toBe(12.345);
});

it('blocks customer merge while a held or spent reward may require restoration', function (): void {
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Other']);
    $hold = $this->service->reserve($this->tenant->id, $this->sale, 500, 'customer-approved');
    $merge = app(CustomerMergeService::class);
    $create = fn () => $merge->createOperation($this->tenant->id, [$this->profile->id, $other->id], $other->id, 'retail', 'hold-merge');
    expect($create)->toThrow(CustomerMergeException::class, 'A reward purchase or refund needs review');
    DB::table('pos_candle_cash_reservations')->where('id', $hold->id)->update(['state' => 'spent']);
    expect($create)->toThrow(CustomerMergeException::class, 'A reward purchase or refund needs review');
    DB::table('pos_candle_cash_reservations')->where('id', $hold->id)->update(['state' => 'refunded']);
    expect($create()->status)->toBe('draft');
});

it('rejects a new hold while a customer merge is unresolved', function (): void {
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Other']);
    app(CustomerMergeService::class)->createOperation($this->tenant->id, [$this->profile->id, $other->id], $other->id, 'retail', 'pending-merge');
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 500, 'customer-approved'))
        ->toThrow(PosException::class, 'customer_identity_review_required');
    expect(CandleCashBalance::find($this->profile->id)->balance)->toBe(12.345);
});

it('serializes a concurrent reward hold and customer merge on MySQL', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires explicitly authorized local disposable MySQL and process forking.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing unsafe reward merge race.');
    }
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Other']);
    $tenantId = $this->tenant->id;
    $profileId = $this->profile->id;
    $saleId = $this->sale;
    $directory = sys_get_temp_dir().'/pos-merge-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        foreach (['reserve', 'merge'] as $i => $action) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork reward merge race.');
            }
            if ($pid === 0) {
                try {
                    while (! file_exists($directory.'/go')) {
                        usleep(1000);
                        clearstatcache();
                    }
                    try {
                        if ($action === 'reserve') {
                            $store = new PosStore(DB::connection(), app('encrypter'));
                            $reservation = new PosCandleCashReservation($store, $this->authority, new PosRewardRedemptionPolicy);
                            $reservation->reserve($tenantId, $saleId, 500, 'customer-approved');
                        } else {
                            app(CustomerMergeService::class)->createOperation($tenantId, [$profileId, $other->id], $other->id, 'retail', 'concurrent-merge');
                        }
                        $result = 'success';
                    } catch (PosException $error) {
                        $result = $error->blocker;
                    } catch (CustomerMergeException $error) {
                        $result = $error->publicCode();
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
        touch($directory.'/go');
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $reserve = file_get_contents($directory.'/0');
        $merge = file_get_contents($directory.'/1');
        expect([$reserve, $merge])->toBeIn([
            ['success', 'pos_reward_merge_review_required'],
            ['customer_identity_review_required', 'success'],
        ]);
        expect(DB::table('pos_candle_cash_reservations')->where('sale_id', $saleId)->count())->toBe($reserve === 'success' ? 1 : 0)
            ->and((float) CandleCashBalance::find($profileId)->balance)->toBe($reserve === 'success' ? 7.345 : 12.345);
    } finally {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('rejects malformed or secret authorization references and oversized policy versions', function (): void {
    $this->terms['authorization_reference'] = 'customer-approved';
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 100, 'customer-approved'))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    $this->terms['authorization_reference'] = str_repeat('a', 129);
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 100, 'customer-approved'))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    $this->terms['authorization_reference'] = 'proof-reference';
    $this->terms['policy_version'] = str_repeat('v', 65);
    expect(fn () => $this->service->reserve($this->tenant->id, $this->sale, 100, 'customer-approved'))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    expect(CandleCashBalance::find($this->profile->id)->balance)->toBe(12.345);
});

it('records a fractional-dollar redemption in the canonical ledger', function (): void {
    $this->service->reserve($this->tenant->id, $this->sale, 249, 'customer-approved');
    expect(CandleCashBalance::find($this->profile->id)->balance)->toBe(9.855)
        ->and((float) DB::table('candle_cash_transactions')->where('source', 'pos_reservation')
            ->where('marketing_profile_id', $this->profile->id)->value('candle_cash_delta'))->toBe(-2.49);
});
