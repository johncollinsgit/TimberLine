<?php

use App\Models\CandleCashBalance;
use App\Models\MarketingProfile;
use App\Models\Tenant;
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
        ->and(DB::table('pos_candle_cash_reservations')->count())->toBe(0);
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
