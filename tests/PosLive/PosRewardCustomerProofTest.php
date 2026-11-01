<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Pos\PosException;
use App\Services\Pos\PosRewardCustomerProof;
use App\Services\Pos\PosRewardRedemptionSettings;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    config(['pos_live.runtime_id' => 'fixture_runtime', 'pos_live.in_person_rewards_enabled' => true]);
    $this->tenant = Tenant::create(['name' => 'Proof fixture', 'slug' => 'proof-'.Str::random(10), 'status' => 'active']);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Test']);
    $this->accountLink = \Tests\Support\PosVerifiedAccountFixture::create($this->profile);
    foreach (['candle_cash_program_config' => ['redeem_increment_dollars' => 10],
        'candle_cash_policy_config' => ['access_state' => ['launch_state' => 'published']]] as $key => $value) {
        \App\Models\TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => $key, 'value' => $value]);
    }
    $programVersion = app(\App\Services\Pos\PosCustomerRewards::class)->policy($this->tenant->id)['version'];
    $policy = ['enabled' => true, 'max_minor' => 500, 'refund_policy' => 'restore_full_review_partial'];
    $version = app(PosRewardRedemptionSettings::class)->version($policy, $programVersion);
    \App\Models\TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => PosRewardRedemptionSettings::SETTING_KEY,
        'value' => [...$policy, 'reviewed_version' => $version, 'reviewed_by' => 1, 'reviewed_at' => now()->toIso8601String()]]);
    $this->accepted = ['max_minor' => 500, 'policy_version' => $version, 'disclosure_version' => PosRewardCustomerProof::DISCLOSURE_VERSION];
    $this->proof = app(PosRewardCustomerProof::class);
    $this->store = app(PosStore::class);
});

it('creates a short-lived opaque customer proof with no bearer token at rest', function (): void {
    $issued = $this->proof->issueForAuthenticatedProfile($this->profile, $this->accepted, $this->accountLink);
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
    $issued = $this->proof->issueForAuthenticatedProfile($this->profile, $this->accepted, $this->accountLink);
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
    $issued = $this->proof->issueForAuthenticatedProfile($this->profile, $this->accepted, $this->accountLink);
    $this->travel(2)->minutes();
    expect(fn () => $this->proof->preview($this->tenant->id, $issued['qr_payload']))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    expect(fn () => DB::transaction(fn () => $this->proof->bind($this->tenant->id, $issued['qr_payload'], str_repeat('a', 32))))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    expect(fn () => $this->proof->preview($this->tenant->id, 'everbranch-reward:v1:bad'))
        ->toThrow(PosException::class, 'reward_authorization_invalid');
    config(['pos_live.in_person_rewards_enabled' => false]);
    expect(fn () => $this->proof->issueForAuthenticatedProfile($this->profile, $this->accepted, $this->accountLink))->toThrow(PosException::class, 'in_person_rewards_disabled');
});

it('requires the customer to accept the exact capped-use disclosure and current policy', function (): void {
    foreach ([['max_minor' => 1000], ['policy_version' => str_repeat('b', 64)], ['disclosure_version' => 'old']] as $change) {
        expect(fn () => $this->proof->issueForAuthenticatedProfile($this->profile, [...$this->accepted, ...$change], $this->accountLink))
            ->toThrow(PosException::class, 'reward_disclosure_changed');
    }
    expect(DB::table('pos_reward_authorizations')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    $issued = $this->proof->issueForAuthenticatedProfile($this->profile, $this->accepted, $this->accountLink);
    $row = $this->proof->preview($this->tenant->id, $issued['qr_payload']);
    expect((int) $row->cap_minor)->toBe(500)->and($row->policy_version)->toBe($this->accepted['policy_version']);
});
