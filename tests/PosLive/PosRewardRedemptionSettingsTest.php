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
