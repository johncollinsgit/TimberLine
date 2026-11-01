<?php

use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SqlitePaymentJournal;
use App\Services\Pos\PosStore;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Fictional reward shop', 'slug' => 'reward-admin-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $this->actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    config(['pos_live.enabled' => true, 'pos_live.runtime_id' => 'fixture_runtime', 'pos_live.tenant_ids' => [$this->tenant->id],
        'pos_live.reward_policy_management_enabled' => false, 'pos_live.in_person_rewards_enabled' => false]);
    $this->store = app(PosStore::class);
    $this->register = str_repeat('a', 32);
    $this->store->rows('connections', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => str_repeat('c', 32), 'merchant_id' => 'fixture_merchant', 'provider_location_id' => 'fixture_location',
        'environment' => 'sandbox', 'credentials' => $this->store->encrypt([]), 'active' => true]));
    $this->store->rows('registers', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->register, 'name' => 'Fixture', 'location_id' => 'fixture', 'location_name' => 'Fixture',
        'connection_id' => str_repeat('c', 32), 'catalog_source' => 'native', 'active' => true, 'configuration' => $this->store->encrypt([])]));
    $this->store->rows('assignments', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'register_id' => $this->register, 'user_id' => $this->actor->id, 'device_id' => 'fixture_device_1234', 'role' => 'admin', 'active' => true]));
    if (DB::connection()->getDriverName() === 'sqlite') {
        $journal = new SqlitePaymentJournal(new PDO('sqlite::memory:'));
        $journal->installForTesting();
    } else {
        $journal = new \App\Services\PaymentsCore\MySqlPaymentJournal(DB::connection(), app('encrypter'));
    }
    app()->instance(PaymentJournal::class, $journal);
    $token = $this->actor->createToken('POS fixture', ['mobile:read', 'mobile:write', 'payments:read', 'payments:write'], now()->addDay())->plainTextToken;
    $this->withToken($token)->withHeader('X-Pos-Device', 'fixture_device_1234');
    $this->base = '/api/mobile/v1/workspaces/'.$this->tenant->slug.'/pos/registers/'.$this->register.'/reward-redemption';
    $this->draft = ['enabled' => true, 'max_minor' => 500, 'channel' => 'in_person', 'refund_policy' => 'restore_full_review_partial'];
});

function rewardAdminProgram($test): void
{
    foreach (['candle_cash_program_config' => ['rewardable_channels' => 'show_issued_online_redeemed'],
        'candle_cash_policy_config' => ['earning_rules' => ['event_rewards_enabled' => true]],
        'candle_cash_access_state' => ['launch_state' => 'published', 'test_mode' => false]] as $key => $value) {
        TenantMarketingSetting::create(['tenant_id' => $test->tenant->id, 'key' => $key, 'value' => $value]);
    }
}

function rewardPublishInput(array $status): array
{
    return ['draft_version' => $status['draft_version'], 'expected_published_version' => $status['published_version'],
        'expected_register_version' => $status['register_reviewed_version'], 'expected_program_version' => $status['program_version'], 'confirmed' => true];
}

it('keeps policy management and redemption off until separately enabled, with tenant and owner checks', function (): void {
    $initial = $this->getJson($this->base)->assertOk()->assertJsonPath('management_enabled', false)->assertJsonPath('redemption_enabled', false)->assertJsonPath('redemption_available', false)
        ->assertJsonPath('draft.enabled', false)->assertJsonPath('published', null)->json();
    $this->postJson($this->base.'/draft', ['draft' => $this->draft, 'expected_draft_version' => $initial['draft_version']])
        ->assertStatus(503)->assertJsonPath('error.code', 'reward_policy_management_disabled');
    config(['pos_live.reward_policy_management_enabled' => true]);
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    $this->getJson($this->base)->assertForbidden();
    $this->postJson($this->base.'/draft', ['draft' => $this->draft, 'expected_draft_version' => $initial['draft_version']])->assertForbidden();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'admin']);
    $this->withHeader('X-Pos-Device', 'wrong_device')->getJson($this->base)->assertNotFound();
    expect(TenantMarketingSetting::where('tenant_id', $this->tenant->id)->where('key', 'pos_candle_cash_redemption')->exists())->toBeFalse();
});

it('saves optimistic drafts but keeps publication unavailable and preserves the reviewed live policy', function (): void {
    config(['pos_live.reward_policy_management_enabled' => true]);
    rewardAdminProgram($this);
    $existing = ['enabled' => true, 'max_minor' => 700, 'refund_policy' => 'restore_full_review_partial',
        'reviewed_version' => str_repeat('e', 64), 'reviewed_by' => $this->actor->id, 'reviewed_at' => now()->toIso8601String()];
    TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => 'pos_candle_cash_redemption', 'value' => $existing]);
    $config = ['pos_candle_cash_reviewed_version' => str_repeat('e', 64)];
    $this->store->rows('registers', $this->tenant->id)->where('id', $this->register)->update(['configuration' => $this->store->encrypt($config)]);
    $initial = $this->getJson($this->base)->assertOk()->assertJsonPath('publication_available', false)->json();
    $status = $this->postJson($this->base.'/draft', ['draft' => $this->draft, 'expected_draft_version' => $initial['draft_version']])
        ->assertOk()->assertJsonPath('draft.max_minor', 500)->assertJsonPath('published.max_minor', 700)->json();
    $this->postJson($this->base.'/draft', ['draft' => $this->draft, 'expected_draft_version' => $initial['draft_version']])
        ->assertStatus(409)->assertJsonPath('error.code', 'reward_policy_changed');
    $this->postJson($this->base.'/publish', [...rewardPublishInput($status), 'confirmed' => false])->assertStatus(422);
    $this->postJson($this->base.'/publish', rewardPublishInput($status))->assertStatus(503)
        ->assertJsonPath('error.code', 'reward_policy_publication_unavailable');
    config(['pos_live.in_person_rewards_enabled' => true]);
    $this->getJson($this->base)->assertOk()->assertJsonPath('redemption_enabled', true)->assertJsonPath('redemption_available', false);
    $this->postJson($this->base.'/publish', rewardPublishInput($status))->assertStatus(503)
        ->assertJsonPath('error.code', 'reward_policy_publication_unavailable');
    config(['pos_live.in_person_rewards_enabled' => false]);
    $after = $this->getJson($this->base)->assertOk()->json();
    expect($after['published'])->toBe($status['published'])->and($after['published_version'])->toBe($status['published_version'])
        ->and($after['register_reviewed_version'])->toBe(str_repeat('e', 64))->and($after['redemption_enabled'])->toBeFalse();
    $disabled = [...$this->draft, 'enabled' => false];
    $this->postJson($this->base.'/draft', ['draft' => $disabled, 'expected_draft_version' => $status['draft_version']])
        ->assertOk()->assertJsonPath('draft.enabled', false)->assertJsonPath('published.enabled', true)
        ->assertJsonPath('register_reviewed_version', str_repeat('e', 64));
});

it('rejects unsupported channels, non-integer caps and cross-tenant access', function (): void {
    config(['pos_live.reward_policy_management_enabled' => true]);
    rewardAdminProgram($this);
    $initial = $this->getJson($this->base)->assertOk()->json();
    $this->postJson($this->base.'/draft', ['draft' => [...$this->draft, 'channel' => 'online'], 'expected_draft_version' => $initial['draft_version']])->assertStatus(422);
    $this->postJson($this->base.'/draft', ['draft' => [...$this->draft, 'max_minor' => '500'], 'expected_draft_version' => $initial['draft_version']])->assertStatus(422);
    $other = Tenant::create(['name' => 'Other tenant', 'slug' => 'other-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $otherBase = '/api/mobile/v1/workspaces/'.$other->slug.'/pos/registers/'.$this->register.'/reward-redemption';
    $this->getJson($otherBase)->assertStatus(404);
    expect(TenantMarketingSetting::query()->forTenantId($other->id)->where('key', 'pos_candle_cash_redemption_draft')->exists())->toBeFalse();
});
