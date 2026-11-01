<?php

use App\Models\CandleCashBalance;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\Pos\PosCandleCashLifecycle;
use App\Services\Pos\PosException;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    config(['pos_live.runtime_id' => 'fixture_runtime', 'pos_live.in_person_rewards_enabled' => true]);
    $this->tenant = Tenant::create(['name' => 'Lifecycle fixture', 'slug' => 'life-'.Str::random(10), 'status' => 'active']);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Test']);
    CandleCashBalance::create(['marketing_profile_id' => $this->profile->id, 'balance' => 7.345]);
    $this->store = app(PosStore::class);
    $this->saleId = bin2hex(random_bytes(16));
    $this->registerId = bin2hex(random_bytes(16));
    $this->paymentId = bin2hex(random_bytes(16));
    $this->paymentRequest = (string) Str::uuid();
    $binding = (new MerchantBinding($this->tenant->id, $this->registerId, 'square_native', 'fixture_merchant', 'fixture_location', 'live', 'fixture_runtime'))->snapshot();
    $lines = [['line_id' => 'basket', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => 500, 'tax_amount_minor' => 35]];
    $snapshot = ['binding' => $binding, 'sale' => (new SaleSnapshot($this->saleId, 'usd', $lines))->snapshot(),
        'terms' => ['pos_candle_cash' => ['profile_id' => $this->profile->id, 'amount_minor' => 500,
            'policy_version' => 'reviewed-v1', 'refund_policy' => 'restore_full_review_partial']]];
    $this->store->rows('sales', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->saleId, 'register_id' => $this->registerId, 'location_id' => 'fixture_location', 'actor_id' => 1,
        'request_id' => (string) Str::uuid(), 'payment_request_id' => $this->paymentRequest, 'sdk_attempt_id' => (string) Str::uuid(),
        'snapshot' => $this->store->encrypt($snapshot), 'snapshot_hash' => Contract::hash($snapshot), 'amount_minor' => 535, 'currency' => 'usd',
        'commerce_source' => 'native', 'state' => 'unknown', 'attempt_id' => $this->paymentId, 'stock_state' => 'held',
        'created_at' => now(), 'expires_at' => now()->addMinutes(5),
    ]));
    $this->holdId = (string) Str::uuid();
    DB::table('pos_candle_cash_reservations')->insert(['id' => $this->holdId, 'runtime_id' => 'fixture_runtime',
        'tenant_id' => $this->tenant->id, 'sale_id' => $this->saleId, 'marketing_profile_id' => $this->profile->id,
        'amount_minor' => 500, 'policy_version' => 'reviewed-v1', 'authorization_reference' => 'nonsecret-proof',
        'state' => 'held', 'created_at' => now(), 'updated_at' => now()]);
    $this->operation = ['id' => $this->paymentId, 'tenant_id' => $this->tenant->id, 'sale_id' => $this->saleId,
        'request_id' => $this->paymentRequest, 'kind' => 'payment', 'state' => 'unknown', 'provider_id' => null,
        'amount_minor' => 535, 'currency' => 'usd', 'binding' => $binding];
    $journal = Mockery::mock(PaymentJournal::class);
    $journal->shouldReceive('get')->andReturnUsing(fn ($tenant, $id) => $this->operation);
    $journal->shouldReceive('findByRequest')->andReturn(null);
    app()->instance(PaymentJournal::class, $journal);
    $this->service = app(PosCandleCashLifecycle::class);
});

afterEach(function (): void {
    if (isset($this->holdId)) {
        DB::table('pos_candle_cash_reservations')->where('id', $this->holdId)->delete();
    }
});

it('keeps unknown payments held and finalizes a confirmed payment exactly once', function (): void {
    expect($this->service->reconcile($this->tenant->id, $this->saleId))->toBe('held');
    $this->operation['state'] = 'succeeded';
    $this->operation['provider_id'] = 'fixture-payment';
    $this->store->rows('sales', $this->tenant->id)->where('id', $this->saleId)->update(['state' => 'succeeded']);
    expect($this->service->reconcile($this->tenant->id, $this->saleId))->toBe('spent')
        ->and($this->service->reconcile($this->tenant->id, $this->saleId))->toBe('spent')
        ->and(CandleCashBalance::find($this->profile->id)->balance)->toBe(7.345);
});

it('restores a definitively declined payment once but never an uncertain one', function (): void {
    expect($this->service->reconcile($this->tenant->id, $this->saleId))->toBe('held');
    $this->operation['state'] = 'failed';
    $this->operation['provider_id'] = 'fixture-decline';
    $this->store->rows('sales', $this->tenant->id)->where('id', $this->saleId)->update(['state' => 'failed']);
    expect($this->service->reconcile($this->tenant->id, $this->saleId))->toBe('released')
        ->and($this->service->reconcile($this->tenant->id, $this->saleId))->toBe('released')
        ->and(CandleCashBalance::find($this->profile->id)->balance)->toBe(12.345)
        ->and(DB::table('candle_cash_transactions')->where('source', 'pos_reservation_release')->where('source_id', $this->holdId)->count())->toBe(1);
});

it('fails closed for unstarted release until durable collection claims are integrated', function (): void {
    expect(fn () => $this->service->releaseUnstarted($this->tenant->id, $this->saleId))->toThrow(PosException::class, 'reward_unstarted_release_not_ready');
    $this->store->rows('sales', $this->tenant->id)->where('id', $this->saleId)->update(['state' => 'cancelled', 'attempt_id' => null]);
    expect(fn () => $this->service->releaseUnstarted($this->tenant->id, $this->saleId))->toThrow(PosException::class, 'reward_unstarted_release_not_ready');
    expect(CandleCashBalance::find($this->profile->id)->balance)->toBe(7.345);
});

it('reviews partial refunds and restores once after a verified full refund', function (): void {
    $this->operation['state'] = 'succeeded';
    $this->operation['provider_id'] = 'fixture-payment';
    $this->store->rows('sales', $this->tenant->id)->where('id', $this->saleId)->update(['state' => 'succeeded']);
    $this->service->reconcile($this->tenant->id, $this->saleId);
    $this->store->event($this->tenant->id, $this->saleId, 'square_refund_observed', ['payment_id' => 'fixture-payment', 'refund_id' => 'fixture-refund-one', 'amount_minor' => 200]);
    expect($this->service->reconcileRefund($this->tenant->id, $this->saleId))->toBe('partial_refund_review')
        ->and(CandleCashBalance::find($this->profile->id)->balance)->toBe(7.345);
    $this->store->event($this->tenant->id, $this->saleId, 'square_refund_observed', ['payment_id' => 'fixture-payment', 'refund_id' => 'fixture-refund-two', 'amount_minor' => 335]);
    expect($this->service->reconcileRefund($this->tenant->id, $this->saleId))->toBe('refunded')
        ->and($this->service->reconcileRefund($this->tenant->id, $this->saleId))->toBe('refunded')
        ->and(CandleCashBalance::find($this->profile->id)->balance)->toBe(12.345)
        ->and(DB::table('candle_cash_transactions')->where('source', 'pos_reservation_refund')->where('source_id', $this->holdId)->count())->toBe(1);
});

it('does not treat sandbox or mismatched payments as evidence', function (): void {
    $this->operation['state'] = 'succeeded';
    $this->operation['provider_id'] = 'fixture-payment';
    $this->operation['amount_minor'] = 1;
    expect(fn () => $this->service->reconcile($this->tenant->id, $this->saleId))->toThrow(PosException::class, 'reward_payment_evidence_required');
    $this->operation['amount_minor'] = 535;
    $this->operation['binding']['environment'] = 'test';
    expect(fn () => $this->service->reconcile($this->tenant->id, $this->saleId))->toThrow(PosException::class, 'reward_payment_evidence_required');
    expect(CandleCashBalance::find($this->profile->id)->balance)->toBe(7.345);
});
