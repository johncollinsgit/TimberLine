<?php

use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Pos\PosCustomerRewards;
use App\Services\Pos\PosException;
use App\Services\Pos\PosRewardRedemptionSettings;
use App\Services\Pos\PosStore;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    config(['pos_live.runtime_id' => 'fixture_runtime', 'pos_live.in_person_rewards_enabled' => true]);
    $this->tenant = Tenant::create(['name' => 'Policy fixture', 'slug' => 'policy-'.Str::random(10), 'status' => 'active']);
    foreach (['candle_cash_program_config' => ['redeem_increment_dollars' => 10],
        'candle_cash_policy_config' => ['access_state' => ['launch_state' => 'published']]] as $key => $value) {
        TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => $key, 'value' => $value]);
    }
    $this->service = app(PosRewardRedemptionSettings::class);
    $this->store = app(PosStore::class);
    $this->programVersion = app(PosCustomerRewards::class)->policy($this->tenant->id)['version'];
    $this->raw = ['enabled' => true, 'max_minor' => 500, 'refund_policy' => 'restore_full_review_partial'];
    $this->version = $this->service->version($this->raw, $this->programVersion);
    TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => PosRewardRedemptionSettings::SETTING_KEY,
        'value' => [...$this->raw, 'reviewed_version' => $this->version, 'reviewed_by' => 1, 'reviewed_at' => now()->toIso8601String()]]);
});

it('requires an enabled, reviewed tenant policy and reviewed register', function (): void {
    $config = ['pos_candle_cash_reviewed_version' => $this->version];
    $scope = ['tenant_id' => $this->tenant->id, 'register' => (object) ['configuration' => $this->store->encrypt($config)]];
    expect($this->service->availableForTenant($this->tenant->id))->toBeTrue()
        ->and($this->service->forRegister($scope)['max_minor'])->toBe(500)
        ->and($this->service->availableForTenant($this->tenant->id + 100))->toBeFalse();
    $scope['register']->configuration = $this->store->encrypt([]);
    expect(fn () => $this->service->forRegister($scope))->toThrow(PosException::class, 'in_person_rewards_register_review_required');
    config(['pos_live.in_person_rewards_enabled' => false]);
    expect($this->service->availableForTenant($this->tenant->id))->toBeFalse();
});

it('invalidates review after a cap or rewards program change', function (): void {
    $setting = TenantMarketingSetting::query()->where('tenant_id', $this->tenant->id)->where('key', PosRewardRedemptionSettings::SETTING_KEY)->firstOrFail();
    $value = $setting->value;
    $value['max_minor'] = 600;
    $setting->update(['value' => $value]);
    expect(fn () => $this->service->tenant($this->tenant->id))->toThrow(PosException::class, 'in_person_rewards_review_required');
    $value['max_minor'] = 500;
    $setting->update(['value' => $value]);
    TenantMarketingSetting::query()->where('tenant_id', $this->tenant->id)->where('key', 'candle_cash_program_config')
        ->firstOrFail()->update(['value' => ['redeem_increment_dollars' => 20]]);
    expect(fn () => $this->service->tenant($this->tenant->id))->toThrow(PosException::class, 'in_person_rewards_review_required');
});

it('uses separate tenant member caps only with an active authoritative subscription and withdraws the higher cap when paused', function (): void {
    $profile = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Member fixture', 'source_channels' => ['candle_club']]);
    $raw = [...$this->raw, 'member_max_minor' => 1000];
    $version = $this->service->version($raw, $this->programVersion);
    expect($version)->not->toBe($this->version);
    TenantMarketingSetting::query()->forTenantId($this->tenant->id)->where('key', PosRewardRedemptionSettings::SETTING_KEY)
        ->firstOrFail()->update(['value' => [...$raw, 'reviewed_version' => $version, 'reviewed_by' => 1, 'reviewed_at' => now()->toIso8601String()]]);
    expect($this->service->forProfile($profile)['max_minor'])->toBe(500);
    $shop = \App\Models\ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'membership-'.$this->tenant->id,
        'shop_domain' => 'membership-'.$this->tenant->id.'.myshopify.com', 'access_token' => 'fictional']);
    $member = \App\Models\SubscriptionPilotMember::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id,
        'shopify_store_id' => $shop->id, 'recharge_customer_id' => 'fixture_member', 'shopify_customer_id' => '123',
        'billing_owner' => 'everbranch', 'status' => 'active', 'target_date' => now()->toDateString(), 'currency' => 'USD', 'details' => [], 'prepared_by' => 1]);
    $agreement = \App\Models\SubscriptionBillingAgreement::create(['tenant_id' => $this->tenant->id, 'member_id' => $member->id,
        'processor' => 'stripe', 'status' => 'active', 'terms' => [], 'terms_hash' => str_repeat('a', 64), 'method_binding' => [],
        'authorization_evidence' => [], 'prepared_by' => 1, 'billing_anchor_date' => now()->toDateString(), 'method_verified_at' => now(), 'activated_at' => now()]);
    $effective = $this->service->forProfile($profile);
    expect($effective['max_minor'])->toBe(1000)->and($effective['membership_evidence']['id'])->toBe($agreement->public_id);
    $agreement->update(['status' => 'paused']);
    expect($this->service->forProfile($profile)['max_minor'])->toBe(500);
    expect(app(\App\Services\Pos\PosMembershipEvidence::class)->history($profile)['coverage_complete'])->toBeFalse();
    $generation = (string) \Illuminate\Support\Str::uuid();
    $connection = \App\Models\SubscriptionRechargeConnection::create(['tenant_id' => $this->tenant->id, 'shopify_store_id' => $shop->id,
        'provider_store_id' => 'fictional', 'platform' => 'shopify', 'identifier' => 'fictional.myshopify.com', 'status' => 'verified',
        'last_complete_sync_at' => now(), 'sync_state' => ['last_complete' => ['generation_id' => $generation]]]);
    $credential = \App\Models\SubscriptionRechargeCredentialRevision::create(['tenant_id' => $this->tenant->id,
        'connection_id' => $connection->id, 'revision' => 1, 'credentials' => [], 'token_id' => 'fixture',
        'credential_fingerprint' => str_repeat('c', 64), 'digest_key_id' => str_repeat('d', 16)]);
    $operation = \App\Models\SubscriptionRechargeOperation::create(['tenant_id' => $this->tenant->id, 'connection_id' => $connection->id,
        'credential_revision_id' => $credential->id, 'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
        'kind' => 'shadow_sync', 'request' => [], 'request_fingerprint' => str_repeat('e', 64), 'digest_key_id' => str_repeat('d', 16)]);
    $originalJoin = now()->subYear()->toIso8601String();
    $source = \App\Models\SubscriptionRechargeResource::create(['tenant_id' => $this->tenant->id, 'connection_id' => $connection->id,
        'sync_operation_id' => $operation->id, 'generation_id' => $generation, 'resource_type' => 'subscription',
        'provider_id' => 'fixture_subscription', 'customer_provider_id' => $member->recharge_customer_id, 'completeness' => 'complete',
        'canonical_digest' => str_repeat('f', 64), 'digest_key_id' => str_repeat('d', 16), 'observed_at' => now(),
        'snapshot' => ['id' => 'fixture_subscription', 'created_at' => $originalJoin]]);
    $history = app(\App\Services\Pos\PosMembershipEvidence::class)->history($profile);
    expect($history['coverage_complete'])->toBeTrue()->and($history['joined_at'])->toBe([$originalJoin]);
    $source->update(['stale_reason' => 'source_changed']);
    expect(app(\App\Services\Pos\PosMembershipEvidence::class)->history($profile))->toBe(['joined_at' => [], 'coverage_complete' => false]);
});
