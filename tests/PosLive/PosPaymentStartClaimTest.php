<?php

use App\Models\Tenant;
use App\Services\Pos\PosException;
use App\Services\Pos\PosPaymentStartClaim;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    config(['pos_live.runtime_id' => 'fixture_runtime', 'pos_live.durable_start_claims_enabled' => true]);
    $this->tenant = Tenant::create(['name' => 'Claim fixture', 'slug' => 'claim-'.Str::random(10), 'status' => 'active']);
    $this->store = app(PosStore::class);
    $this->saleId = bin2hex(random_bytes(16));
    $this->store->rows('sales', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->saleId, 'register_id' => bin2hex(random_bytes(16)), 'location_id' => 'fixture', 'actor_id' => 1,
        'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(), 'sdk_attempt_id' => (string) Str::uuid(),
        'snapshot' => 'fixture', 'snapshot_hash' => str_repeat('a', 64), 'amount_minor' => 1000, 'currency' => 'usd',
        'commerce_source' => 'native', 'state' => 'quoted', 'stock_state' => 'unprepared', 'created_at' => now(), 'expires_at' => now()->addMinutes(5),
    ]));
    $this->claims = app(PosPaymentStartClaim::class);
});

afterEach(function (): void {
    if (isset($this->saleId)) {
        DB::table('pos_payment_start_claims')->where('sale_id', $this->saleId)->delete();
    }
});

it('makes start durable and blocks cancellation even after time passes', function (): void {
    $claim = $this->claims->beginCollection($this->tenant->id, $this->saleId);
    $this->travel(10)->minutes();
    expect(fn () => $this->claims->beginCollection($this->tenant->id, $this->saleId))
        ->toThrow(PosException::class, 'payment_recovery_required');
    expect($claim->state)->toBe('starting');
    expect(fn () => $this->claims->cancel($this->tenant->id, $this->saleId))->toThrow(PosException::class, 'payment_claim_conflict');
    expect($this->store->rows('sales', $this->tenant->id)->where('id', $this->saleId)->value('state'))->toBe('quoted');
});

it('makes cancellation durable and prevents a later start', function (): void {
    $claim = $this->claims->cancel($this->tenant->id, $this->saleId);
    expect($this->claims->cancel($this->tenant->id, $this->saleId)->id)->toBe($claim->id)
        ->and($this->store->rows('sales', $this->tenant->id)->where('id', $this->saleId)->value('state'))->toBe('cancelled');
    expect(fn () => $this->claims->beginCollection($this->tenant->id, $this->saleId))->toThrow(PosException::class, 'payment_claim_conflict');
});

it('permits only the creator of a start claim to continue collection', function (): void {
    expect($this->claims->beginCollection($this->tenant->id, $this->saleId)->state)->toBe('starting');
    expect(fn () => $this->claims->beginCollection($this->tenant->id, $this->saleId))
        ->toThrow(PosException::class, 'payment_recovery_required');
    expect(fn () => $this->claims->cancel($this->tenant->id, $this->saleId))
        ->toThrow(PosException::class, 'payment_claim_conflict');
});

it('requires the exact held reward before collection can be claimed', function (): void {
    $terms = ['profile_id' => 123, 'amount_minor' => 500, 'policy_version' => 'v1'];
    expect(fn () => $this->claims->beginCollection($this->tenant->id, $this->saleId, $terms))
        ->toThrow(PosException::class, 'reward_reservation_mismatch');
    expect(DB::table('pos_payment_start_claims')->where('sale_id', $this->saleId)->count())->toBe(0);
});

it('rejects existing attempts, expired starts, foreign tenants and disabled rollout', function (): void {
    expect(fn () => $this->claims->beginCollection($this->tenant->id + 100, $this->saleId))->toThrow(PosException::class, 'sale_not_found');
    $this->store->rows('sales', $this->tenant->id)->where('id', $this->saleId)->update(['attempt_id' => bin2hex(random_bytes(16))]);
    expect(fn () => $this->claims->cancel($this->tenant->id, $this->saleId))->toThrow(PosException::class, 'payment_claim_unavailable');
    $this->store->rows('sales', $this->tenant->id)->where('id', $this->saleId)->update(['attempt_id' => null, 'expires_at' => now()->subSecond()]);
    expect(fn () => $this->claims->beginCollection($this->tenant->id, $this->saleId))->toThrow(PosException::class, 'payment_claim_unavailable');
    expect($this->claims->cancel($this->tenant->id, $this->saleId)->state)->toBe('cancelled');
    config(['pos_live.durable_start_claims_enabled' => false]);
    expect(fn () => $this->claims->cancel($this->tenant->id, $this->saleId))->toThrow(PosException::class, 'durable_start_claims_disabled');
});
