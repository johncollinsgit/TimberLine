<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingReviewHistory;
use App\Models\MarketingReviewSummary;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Marketing\CandleCashShopifyDiscountService;
use App\Services\Marketing\CandleCashService;

test('public rewards account lookup shows balance, referral, reviews, and transaction history', function () {
    $tenant = publicRewardsRetailTenant();
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
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
        'candle_cash_cost' => 100,
        'reward_type' => 'discount',
        'reward_value' => '5%',
        'is_active' => true,
    ]);

    $this->get(route('marketing.public.account-rewards', [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'store_key' => 'retail',
    ]))
        ->assertOk()
        ->assertSeeText('Rewards Account Lookup')
        ->assertSeeText('Transaction History')
        ->assertSeeText('Review Status')
        ->assertSeeText('https://refrr.app/example/12345');
});

test('public rewards account prefers native review and reward signals when available', function () {
    $tenant = publicRewardsRetailTenant();
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Native',
        'last_name' => 'Signals',
        'email' => 'native.signals@example.com',
        'normalized_email' => 'native.signals@example.com',
        'phone' => '5553037777',
        'normalized_phone' => '+15553037777',
    ]);

    app(CandleCashService::class)->addPoints($profile, 180, 'earn', 'admin', 'seed', 'Seed balance');

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'LEGACY-991',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'phone' => $profile->phone,
        'normalized_phone' => $profile->normalized_phone,
        'points_balance' => 180,
        'referral_link' => 'https://refrr.app/example/native-signals',
        'raw_metafields' => [],
        'synced_at' => now(),
    ]);

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'LEGACY-991',
        'external_customer_email' => $profile->email,
        'review_count' => 4,
        'published_review_count' => 4,
        'average_rating' => 4.75,
        'source_synced_at' => now(),
        'raw_payload' => [],
    ]);

    $task = CandleCashTask::query()->firstOrCreate(
        ['handle' => 'product-review'],
        [
            'title' => 'Write a product review',
            'description' => 'Leave a product review to earn Candle Cash.',
            'reward_amount' => 5.00,
            'enabled' => true,
            'display_order' => 10,
            'task_type' => 'auto_event',
            'verification_mode' => 'product_review_platform_event',
            'auto_award' => true,
            'max_completions_per_customer' => 1,
            'requires_manual_approval' => false,
            'requires_customer_submission' => false,
            'eligibility_type' => 'everyone',
            'visible_to_noneligible_customers' => false,
        ]
    );

    $completion = CandleCashTaskCompletion::query()->create([
        'candle_cash_task_id' => $task->id,
        'marketing_profile_id' => $profile->id,
        'status' => 'awarded',
        'completion_key' => 'task:product-review|profile:' . $profile->id . '|source:product_review_platform_event:native-test',
        'reward_amount' => 5.00,
        'reward_candle_cash' => 5,
        'source_type' => 'product_review_platform_event',
        'source_id' => 'product-review:native-test',
        'awarded_at' => now()->subMinutes(5),
        'reviewed_at' => now()->subMinutes(5),
        'submitted_at' => now()->subMinutes(5),
        'started_at' => now()->subMinutes(5),
    ]);

    MarketingReviewHistory::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'external_customer_id' => 'profile:' . $profile->id,
        'external_review_id' => 'native-review-991',
        'candle_cash_task_completion_id' => $completion->id,
        'rating' => 5,
        'title' => 'Native review',
        'body' => 'Native review data should be preferred over legacy projections.',
        'reviewer_name' => 'Native Signals',
        'reviewer_email' => $profile->email,
        'is_published' => true,
        'status' => 'approved',
        'submission_source' => 'native_storefront',
        'product_id' => '991',
        'product_handle' => 'native-signal-candle',
        'product_title' => 'Native Signal Candle',
        'submitted_at' => now()->subMinutes(6),
        'approved_at' => now()->subMinutes(5),
    ]);

    $this->get(route('marketing.public.account-rewards', [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'store_key' => 'retail',
    ]))
        ->assertOk()
        ->assertSeeText('Source:')
        ->assertSeeText('Native Backstage reviews')
        ->assertSeeText('Native: 1 reviews')
        ->assertSeeText('Legacy Growave (read-only): 4 reviews');
});

test('public rewards account redeem route issues and rejects redemptions with clear states', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('marketing.candle_cash.temporary_storefront_live_email_allowlist', [
        'sarahcollins0816@gmail.com',
        'rewards.short@example.com',
    ]);
    $tenant = publicRewardsRetailTenant('public-redeem-tenant', 'modernforestry.myshopify.com');

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Redeem',
        'last_name' => 'Customer',
        'email' => 'sarahcollins0816@gmail.com',
        'normalized_email' => 'sarahcollins0816@gmail.com',
        'phone' => '5553023333',
        'normalized_phone' => '+15553023333',
    ]);

    app(CandleCashService::class)->addPoints($profile, 300, 'earn', 'admin', 'seed', 'Seed Candle Cash');

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'PUBLIC-REDEEM-1',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'phone' => $profile->phone,
        'normalized_phone' => $profile->normalized_phone,
        'points_balance' => 300,
        'synced_at' => now(),
    ]);

    $storefrontReward = app(CandleCashService::class)->storefrontReward($tenant->id);
    expect($storefrontReward)->not->toBeNull();

    $nonStorefrontReward = CandleCashReward::query()->create([
        'name' => 'Large Gift Set',
        'description' => null,
        'candle_cash_cost' => 500,
        'reward_type' => 'product',
        'reward_value' => 'gift-set',
        'is_active' => true,
    ]);

    $discountSync = \Mockery::mock(CandleCashShopifyDiscountService::class);
    $discountSync->shouldReceive('ensureDiscountForRedemption')
        ->once()
        ->withArgs(function (CandleCashRedemption $redemption, ?string $preferredStoreKey) use ($profile, $storefrontReward): bool {
            return (int) $redemption->marketing_profile_id === (int) $profile->id
                && (int) $redemption->reward_id === (int) $storefrontReward->id
                && $preferredStoreKey === 'retail';
        })
        ->andReturn([
            'discount_id' => 'gid://shopify/DiscountCodeNode/public-redeem',
            'discount_node_id' => 'gid://shopify/DiscountCodeNode/public-redeem',
            'store_key' => 'retail',
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addDays(30)->toIso8601String(),
        ]);
    app()->instance(CandleCashShopifyDiscountService::class, $discountSync);

    $this->post(route('marketing.public.account-rewards.redeem'), [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $storefrontReward->id,
        'store_key' => 'retail',
    ])
        ->assertRedirect(route('marketing.public.rewards-lookup', [
            'email' => $profile->email,
            'phone' => $profile->phone,
            'store_key' => 'retail',
        ]))
        ->assertSessionHas('redeem_result', function (array $result): bool {
            return ($result['ok'] ?? false) === true
                && ($result['discount_sync_status'] ?? null) === 'synced'
                && filled($result['redemption_code'] ?? null);
        });

    expect(CandleCashRedemption::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('reward_id', $storefrontReward->id)
        ->exists())->toBeTrue()
        ->and((int) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(290);

    $insufficientProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Short',
        'last_name' => 'Balance',
        'email' => 'rewards.short@example.com',
        'normalized_email' => 'rewards.short@example.com',
        'phone' => '5553024444',
        'normalized_phone' => '+15553024444',
    ]);

    app(CandleCashService::class)->addPoints($insufficientProfile, 9, 'earn', 'admin', 'seed', 'Seed Candle Cash');

    $this->post(route('marketing.public.account-rewards.redeem'), [
        'email' => $insufficientProfile->email,
        'phone' => $insufficientProfile->phone,
        'reward_id' => $storefrontReward->id,
        'store_key' => 'retail',
    ])->assertRedirect(route('marketing.public.rewards-lookup', [
        'email' => $insufficientProfile->email,
        'phone' => $insufficientProfile->phone,
        'store_key' => 'retail',
    ]));

    expect(CandleCashRedemption::query()
        ->where('marketing_profile_id', $insufficientProfile->id)
        ->where('reward_id', $storefrontReward->id)
        ->exists())->toBeFalse()
        ->and((int) CandleCashBalance::query()->where('marketing_profile_id', $insufficientProfile->id)->value('balance'))->toBe(9);

    $this->post(route('marketing.public.account-rewards.redeem'), [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $nonStorefrontReward->id,
        'store_key' => 'retail',
    ])->assertRedirect(route('marketing.public.rewards-lookup', [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'store_key' => 'retail',
    ]));

    expect(CandleCashRedemption::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('reward_id', $nonStorefrontReward->id)
        ->exists())->toBeFalse();
});

test('public rewards account shows COMING SOON! and blocks non-allowlisted redemptions', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('marketing.candle_cash.temporary_storefront_live_email_allowlist', ['sarahcollins0816@gmail.com']);
    $tenant = publicRewardsRetailTenant('public-coming-soon-tenant', 'modernforestry.myshopify.com');

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Blocked',
        'last_name' => 'Customer',
        'email' => 'blocked.redeem@example.com',
        'normalized_email' => 'blocked.redeem@example.com',
        'phone' => '5553028888',
        'normalized_phone' => '+15553028888',
    ]);

    app(CandleCashService::class)->addPoints($profile, 300, 'earn', 'admin', 'seed', 'Seed Candle Cash');

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'PUBLIC-REDEEM-BLOCKED-1',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'phone' => $profile->phone,
        'normalized_phone' => $profile->normalized_phone,
        'points_balance' => 300,
        'synced_at' => now(),
    ]);

    $reward = app(CandleCashService::class)->storefrontReward($tenant->id);
    expect($reward)->not->toBeNull();

    $discountSync = \Mockery::mock(CandleCashShopifyDiscountService::class);
    $discountSync->shouldReceive('ensureDiscountForRedemption')->never();
    app()->instance(CandleCashShopifyDiscountService::class, $discountSync);

    $this->get(route('marketing.public.account-rewards', [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'store_key' => 'retail',
    ]))
        ->assertOk()
        ->assertSeeText('COMING SOON!');

    $this->post(route('marketing.public.account-rewards.redeem'), [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $reward->id,
        'store_key' => 'retail',
    ])
        ->assertRedirect(route('marketing.public.rewards-lookup', [
            'email' => $profile->email,
            'phone' => $profile->phone,
            'store_key' => 'retail',
        ]))
        ->assertSessionHas('redeem_result', function (array $result): bool {
            return ($result['ok'] ?? true) === false
                && ($result['state'] ?? null) === 'coming_soon';
        });

    expect(CandleCashRedemption::query()->where('marketing_profile_id', $profile->id)->exists())->toBeFalse()
        ->and((float) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(300.0);
});

test('public rewards account prefers data-rich Growave projection when duplicate rows exist', function () {
    $tenant = publicRewardsRetailTenant();
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
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
        'store_key' => 'retail',
    ]))
        ->assertOk()
        ->assertSeeText('https://refrr.app/example/preferred-public')
        ->assertSeeText('4 reviews')
        ->assertSeeText('Avg 4.25')
        ->assertSeeText('GRO-RICH-8101');
});

test('public rewards account redeem restores balance when Shopify discount sync fails', function () {
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('marketing.candle_cash.temporary_storefront_live_email_allowlist', ['recover.balance@example.com']);
    $tenant = publicRewardsRetailTenant('public-sync-fail-tenant', 'modernforestry.myshopify.com');

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Recover',
        'last_name' => 'Balance',
        'email' => 'recover.balance@example.com',
        'normalized_email' => 'recover.balance@example.com',
        'phone' => '5553090000',
        'normalized_phone' => '+15553090000',
    ]);

    app(CandleCashService::class)->addPoints($profile, 300, 'earn', 'admin', 'seed', 'Seed Candle Cash');

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'PUBLIC-REDEEM-FAIL-1',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'phone' => $profile->phone,
        'normalized_phone' => $profile->normalized_phone,
        'points_balance' => 300,
        'synced_at' => now(),
    ]);

    $reward = app(CandleCashService::class)->storefrontReward($tenant->id);
    expect($reward)->not->toBeNull();

    $discountSync = \Mockery::mock(CandleCashShopifyDiscountService::class);
    $discountSync->shouldReceive('ensureDiscountForRedemption')
        ->once()
        ->andThrow(new RuntimeException('Shopify sync unavailable'));
    app()->instance(CandleCashShopifyDiscountService::class, $discountSync);

    $this->followingRedirects()->post(route('marketing.public.account-rewards.redeem'), [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $reward->id,
        'store_key' => 'retail',
    ])
        ->assertOk()
        ->assertSeeText('Redemption Failed')
        ->assertSeeText('Your reward balance is safe. We could not prepare the Shopify discount yet.')
        ->assertSeeText('Discount: SYNC_FAILED');

    $redemption = CandleCashRedemption::query()->where('marketing_profile_id', $profile->id)->latest('id')->first();

    expect($redemption)->not->toBeNull()
        ->and((string) $redemption->status)->toBe('canceled')
        ->and((float) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(300.0);
});

test('public rewards redeem fails closed when store context is missing', function () {
    $tenant = publicRewardsRetailTenant();

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Missing',
        'last_name' => 'Context',
        'email' => 'missing.context@example.com',
        'normalized_email' => 'missing.context@example.com',
        'phone' => '5553190000',
        'normalized_phone' => '+15553190000',
    ]);

    app(CandleCashService::class)->addPoints($profile, 300, 'earn', 'admin', 'seed', 'Seed balance');

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'PUBLIC-MISSING-CONTEXT-1',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'phone' => $profile->phone,
        'normalized_phone' => $profile->normalized_phone,
        'points_balance' => 300,
        'synced_at' => now(),
    ]);

    $reward = app(CandleCashService::class)->storefrontReward($tenant->id);
    expect($reward)->not->toBeNull();

    $this->post(route('marketing.public.account-rewards.redeem'), [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $reward->id,
    ])
        ->assertRedirect(route('marketing.public.rewards-lookup', [
            'email' => $profile->email,
            'phone' => $profile->phone,
        ]))
        ->assertSessionHas('redeem_result', function (array $result): bool {
            return ($result['ok'] ?? true) === false
                && ($result['state'] ?? null) === 'missing_tenant_context';
        });

    expect(CandleCashRedemption::query()->where('marketing_profile_id', $profile->id)->exists())->toBeFalse();
});

function publicRewardsRetailTenant(
    string $slug = 'public-rewards-retail',
    string $shopDomain = 'modernforestry.myshopify.com'
): Tenant {
    $tenant = Tenant::query()->create([
        'name' => str_replace('-', ' ', ucfirst($slug)),
        'slug' => $slug,
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'shop_domain' => $shopDomain,
            'access_token' => 'public-rewards-retail-token',
            'tenant_id' => $tenant->id,
            'installed_at' => now(),
        ]
    );

    return $tenant;
}
