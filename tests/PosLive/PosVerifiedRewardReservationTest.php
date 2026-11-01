<?php

use App\Models\CandleCashBalance;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\Pos\PosCandleCashReservation;
use App\Services\Pos\PosCustomerRewards;
use App\Services\Pos\PosException;
use App\Services\Pos\PosRewardCustomerProof;
use App\Services\Pos\PosRewardRedemptionPolicy;
use App\Services\Pos\PosRewardRedemptionSettings;
use App\Services\Pos\PosStore;
use App\Services\Pos\PosVerifiedRewardReservationAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    config(['pos_live.runtime_id' => 'fixture_runtime', 'pos_live.in_person_rewards_enabled' => true]);
    $this->tenant = Tenant::create(['name' => 'Verified fixture', 'slug' => 'verified-'.Str::random(10), 'status' => 'active']);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Test']);
    CandleCashBalance::create(['marketing_profile_id' => $this->profile->id, 'balance' => 10]);
    foreach (['candle_cash_program_config' => ['redeem_increment_dollars' => 10],
        'candle_cash_policy_config' => ['access_state' => ['launch_state' => 'published']]] as $key => $value) {
        TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => $key, 'value' => $value]);
    }
    app()->instance(PaymentJournal::class, Mockery::mock(PaymentJournal::class));
    $this->store = app(PosStore::class);
    $settings = app(PosRewardRedemptionSettings::class);
    $programVersion = app(PosCustomerRewards::class)->policy($this->tenant->id)['version'];
    $raw = ['enabled' => true, 'max_minor' => 500, 'refund_policy' => 'restore_full_review_partial'];
    $this->version = $settings->version($raw, $programVersion);
    TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => PosRewardRedemptionSettings::SETTING_KEY,
        'value' => [...$raw, 'reviewed_version' => $this->version, 'reviewed_by' => 1, 'reviewed_at' => now()->toIso8601String()]]);
    $register = bin2hex(random_bytes(16));
    $this->store->rows('registers', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $register, 'name' => 'Fixture', 'location_id' => 'fixture', 'location_name' => 'Fixture', 'connection_id' => str_repeat('c', 32),
        'catalog_source' => 'native', 'configuration' => $this->store->encrypt(['pos_candle_cash_reviewed_version' => $this->version]), 'active' => true,
    ]));
    $this->saleId = bin2hex(random_bytes(16));
    $binding = (new MerchantBinding($this->tenant->id, $register, 'square_native', 'fixture_merchant', 'fixture_location', 'live', 'fixture_runtime'))->snapshot();
    $lines = [['line_id' => 'basket', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => 500, 'tax_amount_minor' => 35]];
    $snapshot = ['binding' => $binding, 'sale' => (new SaleSnapshot($this->saleId, 'usd', $lines))->snapshot(),
        'customer' => ['id' => $this->profile->id], 'terms' => ['subtotal_minor' => 1000, 'discount_minor' => 500,
            'pos_candle_cash' => ['profile_id' => $this->profile->id, 'amount_minor' => 500, 'eligible_minor' => 1000,
                'policy_version' => $this->version, 'refund_policy' => 'restore_full_review_partial']]];
    $this->store->rows('sales', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->saleId, 'register_id' => $register, 'location_id' => 'fixture', 'actor_id' => 1, 'request_id' => (string) Str::uuid(),
        'payment_request_id' => (string) Str::uuid(), 'sdk_attempt_id' => (string) Str::uuid(), 'snapshot' => $this->store->encrypt($snapshot),
        'snapshot_hash' => Contract::hash($snapshot), 'amount_minor' => 535, 'currency' => 'usd', 'commerce_source' => 'native',
        'state' => 'quoted', 'stock_state' => 'unprepared', 'created_at' => now(), 'expires_at' => now()->addMinutes(5),
    ]));
    $this->proofs = app(PosRewardCustomerProof::class);
    $this->service = new PosCandleCashReservation($this->store, app(PosVerifiedRewardReservationAuthority::class), new PosRewardRedemptionPolicy);
});

it('binds verified proof to the frozen customer and exact discounted quote', function (): void {
    $proof = $this->proofs->issueForAuthenticatedProfile($this->profile)['qr_payload'];
    $hold = $this->service->reserve($this->tenant->id, $this->saleId, 500, $proof);
    expect($hold->marketing_profile_id)->toBe($this->profile->id)
        ->and($hold->amount_minor)->toBe(500)
        ->and(CandleCashBalance::find($this->profile->id)->balance)->toBe(5.0);
    $this->service->reserve($this->tenant->id, $this->saleId, 500, $proof);
    expect(DB::table('pos_candle_cash_reservations')->where('sale_id', $this->saleId)->count())->toBe(1)
        ->and(DB::table('pos_reward_authorizations')->where('sale_id', $this->saleId)->count())->toBe(1)
        ->and(json_encode($hold))->not->toContain($proof);
});

it('rolls proof binding back when amount or customer is wrong', function (): void {
    $proof = $this->proofs->issueForAuthenticatedProfile($this->profile)['qr_payload'];
    expect(fn () => $this->service->reserve($this->tenant->id, $this->saleId, 400, $proof))->toThrow(PosException::class, 'reward_quote_invalid');
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Other']);
    $wrongProof = $this->proofs->issueForAuthenticatedProfile($other)['qr_payload'];
    expect(fn () => $this->service->reserve($this->tenant->id, $this->saleId, 500, $wrongProof))->toThrow(PosException::class, 'customer_identity_review_required');
    expect(DB::table('pos_reward_authorizations')->where('tenant_id', $this->tenant->id)->whereNotNull('sale_id')->count())->toBe(0)
        ->and(CandleCashBalance::find($this->profile->id)->balance)->toBe(10.0);
});

it('never reserves a live customer balance for a sandbox sale', function (): void {
    $proof = $this->proofs->issueForAuthenticatedProfile($this->profile)['qr_payload'];
    $sale = $this->store->rows('sales', $this->tenant->id)->where('id', $this->saleId)->firstOrFail();
    $snapshot = $this->store->decrypt($sale->snapshot);
    $snapshot['binding']['environment'] = 'test';
    $this->store->rows('sales', $this->tenant->id)->where('id', $this->saleId)->update([
        'snapshot' => $this->store->encrypt($snapshot), 'snapshot_hash' => Contract::hash($snapshot),
    ]);
    expect(fn () => $this->service->reserve($this->tenant->id, $this->saleId, 500, $proof))
        ->toThrow(PosException::class, 'in_person_rewards_live_payment_required');
    expect(CandleCashBalance::find($this->profile->id)->balance)->toBe(10.0)
        ->and($this->proofs->preview($this->tenant->id, $proof)->sale_id)->toBeNull();
});

it('rolls back proof, ledger and balance if the enclosing quote transaction fails', function (): void {
    $proof = $this->proofs->issueForAuthenticatedProfile($this->profile)['qr_payload'];
    expect(fn () => DB::transaction(function () use ($proof): void {
        $this->service->reserve($this->tenant->id, $this->saleId, 500, $proof);
        throw new RuntimeException('fictional quote insertion failure');
    }))->toThrow(RuntimeException::class, 'fictional quote insertion failure');
    expect(DB::table('pos_reward_authorizations')->where('tenant_id', $this->tenant->id)->whereNotNull('sale_id')->count())->toBe(0)
        ->and(DB::table('pos_candle_cash_reservations')->where('sale_id', $this->saleId)->count())->toBe(0)
        ->and(DB::table('candle_cash_transactions')->where('marketing_profile_id', $this->profile->id)->count())->toBe(0)
        ->and(CandleCashBalance::find($this->profile->id)->balance)->toBe(10.0);
    expect($this->proofs->preview($this->tenant->id, $proof)->sale_id)->toBeNull();
});
