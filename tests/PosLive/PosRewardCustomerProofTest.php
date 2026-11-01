<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Pos\PosException;
use App\Services\Pos\PosRewardCustomerProof;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    config(['pos_live.runtime_id' => 'fixture_runtime', 'pos_live.in_person_rewards_enabled' => true]);
    $this->tenant = Tenant::create(['name' => 'Proof fixture', 'slug' => 'proof-'.Str::random(10), 'status' => 'active']);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Test']);
    $this->proof = app(PosRewardCustomerProof::class);
    $this->store = app(PosStore::class);
});

it('creates a short-lived opaque customer proof with no bearer token at rest', function (): void {
    $issued = $this->proof->issueForAuthenticatedProfile($this->profile);
    expect($issued['qr_payload'])->toStartWith('everbranch-reward:v1:')
        ->and($issued['expires_at'])->toBe(now()->addSeconds(120)->toIso8601String());
    $row = $this->proof->preview($this->tenant->id, $issued['qr_payload']);
    expect((int) $row->marketing_profile_id)->toBe($this->profile->id)
        ->and($row->token_hash)->toBe(hash('sha256', substr($issued['qr_payload'], strlen('everbranch-reward:v1:'))))
        ->and(json_encode($row))->not->toContain($issued['qr_payload']);
    expect(fn () => $this->proof->preview($this->tenant->id + 1, $issued['qr_payload']))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    expect(fn () => $this->proof->bind($this->tenant->id, $issued['qr_payload'], str_repeat('a', 32)))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
});

it('binds proof once to one original sale and permits only its retry', function (): void {
    $issued = $this->proof->issueForAuthenticatedProfile($this->profile);
    $sale = str_repeat('a', 32);
    $bound = DB::transaction(fn () => $this->proof->bind($this->tenant->id, $issued['qr_payload'], $sale));
    expect($bound->sale_id)->toBe($sale);
    expect(fn () => $this->proof->preview($this->tenant->id, $issued['qr_payload']))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    expect(fn () => DB::transaction(fn () => $this->proof->bind($this->tenant->id, $issued['qr_payload'], str_repeat('b', 32))))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    $this->travel(3)->minutes();
    $retry = DB::transaction(fn () => $this->proof->bind($this->tenant->id, $issued['qr_payload'], $sale));
    expect($retry->id)->toBe($bound->id);
});

it('rejects expired, malformed, disabled and noncanonical customers', function (): void {
    $issued = $this->proof->issueForAuthenticatedProfile($this->profile);
    $this->travel(2)->minutes();
    expect(fn () => $this->proof->preview($this->tenant->id, $issued['qr_payload']))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    expect(fn () => DB::transaction(fn () => $this->proof->bind($this->tenant->id, $issued['qr_payload'], str_repeat('a', 32))))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    expect(fn () => $this->proof->preview($this->tenant->id, 'everbranch-reward:v1:bad'))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    config(['pos_live.in_person_rewards_enabled' => false]);
    expect(fn () => $this->proof->issueForAuthenticatedProfile($this->profile))->toThrow(PosException::class, 'in_person_rewards_disabled');
});
