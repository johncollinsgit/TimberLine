<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashReward;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingReviewSummary;
use App\Services\Marketing\CandleCashService;

test('public rewards account lookup shows balance, referral, reviews, and transaction history', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Rewards',
        'last_name' => 'Customer',
        'email' => 'rewards.lookup@example.com',
        'normalized_email' => 'rewards.lookup@example.com',
        'phone' => '5553012222',
        'normalized_phone' => '+15553012222',
    ]);

    app(CandleCashService::class)->addPoints($profile, 120, 'earn', 'admin', 'seed', 'Seed balance');

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '12345',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'phone' => $profile->phone,
        'normalized_phone' => $profile->normalized_phone,
        'points_balance' => 120,
        'referral_link' => 'https://refrr.app/example/12345',
        'raw_metafields' => [],
        'synced_at' => now(),
    ]);

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '12345',
        'external_customer_email' => $profile->email,
        'review_count' => 2,
        'published_review_count' => 2,
        'average_rating' => 4.50,
        'source_synced_at' => now(),
        'raw_payload' => [],
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'points' => 10,
        'source' => 'growave_activity',
        'source_id' => 'retail:12345:111',
        'description' => 'Imported Growave activity #111 (reward): review reward',
    ]);

    CandleCashReward::query()->create([
        'name' => '5% Off',
        'description' => 'Public lookup reward',
        'points_cost' => 100,
        'reward_type' => 'discount',
        'reward_value' => '5%',
        'is_active' => true,
    ]);

    $this->get(route('marketing.public.account-rewards', [
        'email' => $profile->email,
        'phone' => $profile->phone,
    ]))
        ->assertOk()
        ->assertSeeText('Rewards Lookup')
        ->assertSeeText('Transaction History')
        ->assertSeeText('Review Status')
        ->assertSeeText('https://refrr.app/example/12345');
});

test('public rewards account redeem route issues and rejects redemptions with clear states', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Redeem',
        'last_name' => 'Customer',
        'email' => 'rewards.redeem@example.com',
        'normalized_email' => 'rewards.redeem@example.com',
        'phone' => '5553023333',
        'normalized_phone' => '+15553023333',
    ]);

    app(CandleCashService::class)->addPoints($profile, 90, 'earn', 'admin', 'seed', 'Seed points');

    $rewardAffordable = CandleCashReward::query()->create([
        'name' => 'Free Wick Trimmer',
        'description' => null,
        'points_cost' => 50,
        'reward_type' => 'product',
        'reward_value' => 'wick-trimmer',
        'is_active' => true,
    ]);

    $rewardTooExpensive = CandleCashReward::query()->create([
        'name' => 'Large Gift Set',
        'description' => null,
        'points_cost' => 500,
        'reward_type' => 'product',
        'reward_value' => 'gift-set',
        'is_active' => true,
    ]);

    $this->post(route('marketing.public.account-rewards.redeem'), [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $rewardAffordable->id,
    ])->assertRedirect(route('marketing.public.rewards-lookup', [
        'email' => $profile->email,
        'phone' => $profile->phone,
    ]));

    expect(CandleCashRedemption::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('reward_id', $rewardAffordable->id)
        ->exists())->toBeTrue()
        ->and((int) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(40);

    $this->post(route('marketing.public.account-rewards.redeem'), [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $rewardTooExpensive->id,
    ])->assertRedirect(route('marketing.public.rewards-lookup', [
        'email' => $profile->email,
        'phone' => $profile->phone,
    ]));

    expect(CandleCashRedemption::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('reward_id', $rewardTooExpensive->id)
        ->exists())->toBeFalse()
        ->and((int) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(40);
});

test('public rewards account prefers data-rich Growave projection when duplicate rows exist', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Duplicate',
        'last_name' => 'Projection',
        'email' => 'duplicate.projection@example.com',
        'normalized_email' => 'duplicate.projection@example.com',
        'phone' => '5553034444',
        'normalized_phone' => '+15553034444',
    ]);

    app(CandleCashService::class)->addPoints($profile, 210, 'earn', 'admin', 'seed', 'Seed points');

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-RICH-8101',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'phone' => $profile->phone,
        'normalized_phone' => $profile->normalized_phone,
        'points_balance' => 210,
        'referral_link' => 'https://refrr.app/example/preferred-public',
        'raw_metafields' => [
            ['namespace' => 'growave', 'key' => 'review_count', 'value' => '4', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'published_review_count', 'value' => '4', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'activity_total', 'value' => '8', 'type' => 'number_integer'],
        ],
        'synced_at' => now()->subHour(),
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-EMPTY-8102',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'phone' => $profile->phone,
        'normalized_phone' => $profile->normalized_phone,
        'points_balance' => 0,
        'referral_link' => null,
        'raw_metafields' => [
            ['namespace' => 'growave', 'key' => 'review_count', 'value' => '0', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'published_review_count', 'value' => '0', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'activity_total', 'value' => '0', 'type' => 'number_integer'],
        ],
        'synced_at' => now(),
    ]);

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-RICH-8101',
        'external_customer_email' => $profile->email,
        'review_count' => 4,
        'published_review_count' => 4,
        'average_rating' => 4.25,
        'source_synced_at' => now()->subHour(),
        'raw_payload' => [],
    ]);

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-EMPTY-8102',
        'external_customer_email' => $profile->email,
        'review_count' => 0,
        'published_review_count' => 0,
        'average_rating' => null,
        'source_synced_at' => now(),
        'raw_payload' => [],
    ]);

    $this->get(route('marketing.public.account-rewards', [
        'email' => $profile->email,
        'phone' => $profile->phone,
    ]))
        ->assertOk()
        ->assertSeeText('https://refrr.app/example/preferred-public')
        ->assertSeeText('4 reviews')
        ->assertSeeText('Avg 4.25')
        ->assertSeeText('GRO-RICH-8101');
});
