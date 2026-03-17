<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReferral;
use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTransaction;
use App\Models\CustomerBirthdayProfile;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use Carbon\CarbonImmutable;

function seedEmbeddedCustomerDetailFixture(): MarketingProfile
{
    $now = CarbonImmutable::parse('2026-03-05 14:00:00');

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Daria',
        'last_name' => 'Drift',
        'email' => 'daria@example.com',
        'normalized_email' => 'daria@example.com',
        'phone' => '555-234-9876',
        'normalized_phone' => '15552349876',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => false,
    ]);

    $profile->forceFill([
        'created_at' => $now->subDays(20),
        'updated_at' => $now->subDays(2),
    ])->save();

    MarketingConsentEvent::query()->create([
        'marketing_profile_id' => $profile->id,
        'channel' => 'email',
        'event_type' => 'confirmed',
        'source_type' => 'seed',
        'source_id' => 'seed',
        'occurred_at' => $now->subDays(9),
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 90,
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'points' => 40,
        'source' => 'admin',
        'source_id' => 'seed',
        'description' => 'Seed earn',
        'created_at' => $now->subDays(3),
        'updated_at' => $now->subDays(3),
    ]);

    $reward = CandleCashReward::query()->create([
        'name' => '$10 Candle Cash',
        'points_cost' => 100,
        'reward_type' => 'coupon',
        'reward_value' => '10USD',
        'is_active' => true,
    ]);

    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $reward->id,
        'points_spent' => 100,
        'platform' => 'shopify',
        'redemption_code' => 'TESTCODE',
        'status' => 'issued',
        'issued_at' => $now->subDays(4),
        'created_at' => $now->subDays(4),
        'updated_at' => $now->subDays(4),
    ]);

    $task = CandleCashTask::query()->firstOrCreate(
        ['handle' => 'candle-club-join'],
        [
            'title' => 'Candle Club Join',
            'task_type' => 'manual_submission',
            'reward_amount' => 1,
            'enabled' => true,
            'display_order' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    CandleCashTaskCompletion::query()->create([
        'candle_cash_task_id' => $task->id,
        'marketing_profile_id' => $profile->id,
        'status' => 'awarded',
        'reward_amount' => 1,
        'reward_points' => 10,
        'created_at' => $now->subDays(6),
        'updated_at' => $now->subDays(6),
        'awarded_at' => $now->subDays(6),
    ]);

    CandleCashReferral::query()->create([
        'referrer_marketing_profile_id' => $profile->id,
        'referral_code' => 'DARIA1',
        'status' => 'rewarded',
        'rewarded_at' => $now->subDays(5),
        'created_at' => $now->subDays(7),
        'updated_at' => $now->subDays(5),
    ]);

    CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => 8,
        'birth_day' => 12,
        'reward_last_issued_at' => $now->subDays(30),
        'created_at' => $now->subDays(40),
        'updated_at' => $now->subDays(30),
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'shopify',
        'store_key' => 'wholesale',
        'external_customer_id' => 'wholesale-123',
        'points_balance' => 120,
        'last_activity_at' => $now->subDay(),
        'synced_at' => $now->subDay(),
        'created_at' => $now->subDay(),
        'updated_at' => $now->subDay(),
    ]);

    return $profile;
}

function startEmbeddedCustomersDetailSession(\Illuminate\Foundation\Testing\TestCase $testCase): void
{
    $testCase->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();
}

test('customer detail renders identity and summary data', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $response = $this->get(route('shopify.embedded.customers.detail', ['marketingProfile' => $profile->id], false));

    $response->assertOk()
        ->assertSeeText('Customer Detail')
        ->assertSeeText($profile->email)
        ->assertSeeText('Candle Cash')
        ->assertSeeText('Recent Activity')
        ->assertSeeText('Consent');
});

test('customer detail alias route resolves with embedded context', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    $response = $this->get(route('shopify.app.customers.detail', array_merge([
        'marketingProfile' => $profile->id,
    ], retailEmbeddedSignedQuery())));

    $response->assertOk()
        ->assertSeeText($profile->email)
        ->assertSeeText('Candle Cash');
});

test('customer detail handles missing optional data gracefully', function () {
    configureEmbeddedRetailStore();
    $profile = MarketingProfile::query()->create([
        'first_name' => 'No',
        'last_name' => 'Extras',
        'email' => null,
        'normalized_email' => null,
    ]);
    startEmbeddedCustomersDetailSession($this);

    $response = $this->get(route('shopify.embedded.customers.detail', ['marketingProfile' => $profile->id], false));

    $response->assertOk()
        ->assertSeeText('Email not set')
        ->assertSeeText('No recent activity recorded yet.');
});

test('customer identity update persists safe fields', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->patch(
        route('shopify.embedded.customers.update', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => 'updated@example.com',
            'phone' => '555-111-2222',
        ]
    );

    $response->assertRedirect();

    $profile->refresh();

    expect($profile->first_name)->toBe('Updated')
        ->and($profile->last_name)->toBe('Name')
        ->and($profile->email)->toBe('updated@example.com')
        ->and($profile->normalized_email)->toBe('updated@example.com');
});

test('customer consent update succeeds and records events', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.update-consent', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'channel' => 'both',
            'consented' => true,
            'notes' => 'Consent granted by admin',
        ]
    );

    $response->assertRedirect();

    $profile->refresh();

    expect($profile->accepts_email_marketing)->toBeTrue()
        ->and($profile->accepts_sms_marketing)->toBeTrue();

    expect(MarketingConsentEvent::query()->where('marketing_profile_id', $profile->id)->count())
        ->toBeGreaterThanOrEqual(2);
});

test('invalid consent update is rejected', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.update-consent', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'channel' => 'invalid',
            'consented' => 'nope',
        ]
    );

    $response->assertSessionHasErrors(['channel', 'consented']);
});

test('consent update alias route resolves with embedded context', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.app.customers.update-consent', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'channel' => 'email',
            'consented' => false,
            'notes' => 'Opt-out requested',
        ]
    );

    $response->assertRedirect();
});
