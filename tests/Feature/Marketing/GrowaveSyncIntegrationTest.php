<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingReviewHistory;
use App\Models\MarketingReviewSummary;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('marketing.growave.enabled', true);
    config()->set('marketing.growave.base_url', 'https://api.growave.io');
    config()->set('marketing.growave.client_id', 'test-client');
    config()->set('marketing.growave.client_secret', 'test-secret');
    config()->set('marketing.growave.scope', 'read_customer read_review read_reward');

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Retail',
        'last_name' => 'Customer',
        'email' => 'retail.customer@example.com',
        'normalized_email' => 'retail.customer@example.com',
        'phone' => '+1 (555) 600-1111',
        'normalized_phone' => '5556001111',
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:5001',
        'source_meta' => ['seed' => true],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '5001',
        'external_customer_gid' => 'gid://shopify/Customer/5001',
        'first_name' => 'Retail',
        'last_name' => 'Customer',
        'email' => 'retail.customer@example.com',
        'normalized_email' => 'retail.customer@example.com',
        'phone' => '+1 (555) 600-1111',
        'normalized_phone' => '5556001111',
        'source_channels' => ['shopify'],
        'raw_metafields' => [],
        'synced_at' => now()->subMinute(),
    ]);
});

test('growave sync imports external profile, review snapshots, and loyalty activities idempotently', function () {
    Http::fake([
        'https://api.growave.io/v2/oauth/getAccessToken' => Http::response([
            'accessToken' => 'growave-token',
            'tokenType' => 'Bearer',
            'expiresAt' => now()->addHour()->toIso8601String(),
        ], 200),
        'https://api.growave.io/v2/customers/getCustomer*' => Http::response([
            'customerId' => 5001,
            'email' => 'retail.customer@example.com',
            'phone' => '+15556001111',
            'firstName' => 'Retail',
            'lastName' => 'Customer',
            'birthday' => '05-28',
            'isRewardProgramAvailable' => true,
            'pointsBalance' => 140,
            'pointsExpiresAt' => now()->addMonth()->toIso8601String(),
            'isAllowedToEarnTier' => true,
            'currentTier' => [
                'id' => 4,
                'title' => 'Gold',
                'image' => null,
                'achievedAt' => now()->subMonth()->toIso8601String(),
            ],
            'isReferralProgramAvailable' => true,
            'referralLink' => 'https://growave.example.test/ref/5001',
            'acceptsEmailMarketing' => true,
            'acceptsSmsMarketing' => false,
        ], 200),
        'https://api.growave.io/v2/reviews/getReviews*' => Http::response([
            'totalCount' => 2,
            'currentOffset' => 0,
            'perPage' => 50,
            'items' => [
                [
                    'id' => 8001,
                    'title' => 'Great scent',
                    'body' => 'Loved it',
                    'rate' => 5,
                    'images' => ['https://imgs.example.test/review1.jpg'],
                    'votes' => 3,
                    'isPublished' => true,
                    'isPinned' => false,
                    'isVerifiedBuyer' => true,
                    'createdAt' => now()->subDays(2)->toIso8601String(),
                    'customer' => [
                        'shopifyCustomerId' => 5001,
                        'email' => 'retail.customer@example.com',
                        'phone' => '+15556001111',
                    ],
                    'product' => [
                        'shopifyProductId' => 901,
                        'title' => 'Nightfall Candle',
                    ],
                    'reply' => null,
                ],
                [
                    'id' => 8002,
                    'title' => 'Solid throw',
                    'body' => 'Would buy again',
                    'rate' => 4,
                    'images' => [],
                    'votes' => 1,
                    'isPublished' => true,
                    'isPinned' => false,
                    'isVerifiedBuyer' => true,
                    'createdAt' => now()->subDay()->toIso8601String(),
                    'customer' => [
                        'shopifyCustomerId' => 5001,
                        'email' => 'retail.customer@example.com',
                        'phone' => '+15556001111',
                    ],
                    'product' => [
                        'shopifyProductId' => 902,
                        'title' => 'Morning Dew Candle',
                    ],
                    'reply' => null,
                ],
            ],
        ], 200),
        'https://api.growave.io/v2/rewards/getActivityHistory*' => Http::response([
            'totalCount' => 3,
            'currentPage' => 1,
            'perPage' => 100,
            'activities' => [
                [
                    'id' => 7001,
                    'type' => 'reward',
                    'note' => 'Order points',
                    'createdAt' => now()->subDays(3)->toIso8601String(),
                    'reward' => [
                        'type' => 'points',
                        'points' => 20,
                    ],
                ],
                [
                    'id' => 7002,
                    'type' => 'redeem',
                    'note' => 'Redeemed reward',
                    'createdAt' => now()->subDays(2)->toIso8601String(),
                    'spentPoints' => 5,
                ],
                [
                    'id' => 7003,
                    'type' => 'manual',
                    'note' => 'Manual adjustment',
                    'createdAt' => now()->subDay()->toIso8601String(),
                    'points' => 3,
                ],
            ],
        ], 200),
    ]);

    $this->artisan('marketing:sync-growave --store=retail --limit=1')
        ->expectsOutputToContain('status=completed')
        ->expectsOutputToContain('processed=1')
        ->assertExitCode(0);

    $this->artisan('marketing:sync-growave --store=retail --limit=1')
        ->expectsOutputToContain('status=completed')
        ->assertExitCode(0);

    $external = CustomerExternalProfile::query()
        ->where('integration', 'growave')
        ->where('store_key', 'retail')
        ->where('external_customer_id', '5001')
        ->first();

    $firstLegacyTransaction = CandleCashTransaction::query()
        ->where('source', 'growave_activity')
        ->orderBy('id')
        ->first();

    expect($external)->not->toBeNull();

    $external = $external->refresh();

    expect((int) $external->points_balance)->toBe(140)
        ->and((string) $external->vip_tier)->toBe('Gold')
        ->and((string) $external->referral_link)->toBe('https://growave.example.test/ref/5001')
        ->and(collect((array) $external->raw_metafields)->contains(fn ($row): bool => (string) ($row['key'] ?? '') === 'birthday'))
        ->and(MarketingReviewSummary::query()->count())->toBe(1)
        ->and((int) MarketingReviewSummary::query()->value('review_count'))->toBe(2)
        ->and(MarketingReviewHistory::query()->count())->toBe(2)
        ->and(CandleCashTransaction::query()->where('source', 'growave_activity')->count())->toBe(3)
        ->and((float) CandleCashBalance::query()->where('marketing_profile_id', $external->marketing_profile_id)->value('balance'))->toBe(0.054)
        ->and((bool) $firstLegacyTransaction?->legacy_points_origin)->toBeTrue();
});

test('growave sync falls back to email lookup when the Shopify customer id is not found', function () {
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/v2/oauth/getAccessToken')) {
            return Http::response([
                'accessToken' => 'growave-token',
                'tokenType' => 'Bearer',
                'expiresAt' => now()->addHour()->toIso8601String(),
            ], 200);
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $customerIdentifier = (string) ($query['customerIdentifier'] ?? '');

        if (str_contains($url, '/v2/customers/getCustomer')) {
            if ($customerIdentifier === '5001') {
                return Http::response([], 404);
            }

            if ($customerIdentifier === 'retail.customer@example.com') {
                return Http::response([
                    'customerId' => null,
                    'email' => 'retail.customer@example.com',
                    'phone' => '+15556001111',
                    'firstName' => 'Retail',
                    'lastName' => 'Customer',
                    'pointsBalance' => 55,
                    'referralLink' => 'https://growave.example.test/ref/email-fallback',
                    'isRewardProgramAvailable' => false,
                    'isReferralProgramAvailable' => false,
                ], 200);
            }
        }

        if (str_contains($url, '/v2/reviews/getReviews')) {
            expect($customerIdentifier)->toBe('retail.customer@example.com');

            return Http::response([
                'totalCount' => 1,
                'currentOffset' => 0,
                'perPage' => 50,
                'items' => [
                    [
                        'id' => 9101,
                        'title' => 'Email fallback review',
                        'body' => 'Still lands correctly',
                        'rate' => 5,
                        'images' => [],
                        'votes' => 0,
                        'isPublished' => true,
                        'isPinned' => false,
                        'isVerifiedBuyer' => true,
                        'createdAt' => now()->subDay()->toIso8601String(),
                        'customer' => [
                            'email' => 'retail.customer@example.com',
                        ],
                        'product' => [
                            'shopifyProductId' => 991,
                            'title' => 'Fallback Candle',
                        ],
                        'reply' => null,
                    ],
                ],
            ], 200);
        }

        if (str_contains($url, '/v2/rewards/getActivityHistory')) {
            expect($customerIdentifier)->toBe('retail.customer@example.com');

            return Http::response([
                'totalCount' => 0,
                'currentPage' => 1,
                'perPage' => 100,
                'activities' => [],
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->artisan('marketing:sync-growave --store=retail --limit=1')
        ->expectsOutputToContain('status=completed')
        ->expectsOutputToContain('growave_found=1')
        ->assertExitCode(0);

    $external = CustomerExternalProfile::query()
        ->where('integration', 'growave')
        ->where('store_key', 'retail')
        ->where('external_customer_id', '5001')
        ->first();

    expect($external)->not->toBeNull()
        ->and((int) $external->points_balance)->toBe(55)
        ->and((string) $external->referral_link)->toBe('https://growave.example.test/ref/email-fallback')
        ->and((int) MarketingReviewSummary::query()->value('review_count'))->toBe(1)
        ->and(MarketingReviewHistory::query()->count())->toBe(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/customers/getCustomer')
        && str_contains($request->url(), 'customerIdentifier=5001'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/customers/getCustomer')
        && str_contains($request->url(), 'customerIdentifier=retail.customer%40example.com'));
});

test('growave sync without limit processes all Shopify-linked customer candidates', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Second',
        'last_name' => 'Retail',
        'email' => 'retail.second@example.com',
        'normalized_email' => 'retail.second@example.com',
        'phone' => '+1 (555) 600-2222',
        'normalized_phone' => '5556002222',
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:5002',
        'source_meta' => ['seed' => true],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '5002',
        'external_customer_gid' => 'gid://shopify/Customer/5002',
        'first_name' => 'Second',
        'last_name' => 'Retail',
        'email' => 'retail.second@example.com',
        'normalized_email' => 'retail.second@example.com',
        'phone' => '+1 (555) 600-2222',
        'normalized_phone' => '5556002222',
        'source_channels' => ['shopify'],
        'raw_metafields' => [],
        'synced_at' => now()->subMinute(),
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/v2/oauth/getAccessToken')) {
            return Http::response([
                'accessToken' => 'growave-token',
                'tokenType' => 'Bearer',
                'expiresAt' => now()->addHour()->toIso8601String(),
            ], 200);
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $customerIdentifier = (string) ($query['customerIdentifier'] ?? '0');

        if (str_contains($url, '/v2/customers/getCustomer')) {
            return Http::response([
                'customerId' => (int) $customerIdentifier,
                'email' => 'customer.' . $customerIdentifier . '@example.com',
                'phone' => '+1555600' . $customerIdentifier,
                'firstName' => 'Retail',
                'lastName' => 'Customer',
                'pointsBalance' => 120,
                'currentTier' => [
                    'title' => 'Silver',
                ],
                'referralLink' => 'https://growave.example.test/ref/' . $customerIdentifier,
            ], 200);
        }

        if (str_contains($url, '/v2/reviews/getReviews')) {
            return Http::response([
                'totalCount' => 0,
                'currentOffset' => 0,
                'perPage' => 50,
                'items' => [],
            ], 200);
        }

        if (str_contains($url, '/v2/rewards/getActivityHistory')) {
            return Http::response([
                'totalCount' => 0,
                'currentPage' => 1,
                'perPage' => 100,
                'activities' => [],
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->artisan('marketing:sync-growave --store=retail')
        ->expectsOutputToContain('status=completed')
        ->expectsOutputToContain('processed=2')
        ->assertExitCode(0);

    $run = \App\Models\MarketingImportRun::query()
        ->where('type', 'growave_customer_sync')
        ->latest('id')
        ->first();

    expect(CustomerExternalProfile::query()
        ->where('provider', 'shopify')
        ->where('integration', 'growave')
        ->where('store_key', 'retail')
        ->count())->toBe(2)
        ->and(data_get($run?->summary, 'limit'))->toBeNull();
});

test('growave sync only-missing mode skips Shopify candidates that already have Growave rows', function () {
    $profileId = (int) MarketingProfile::query()->value('id');

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profileId > 0 ? $profileId : null,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '5001',
        'email' => 'retail.customer@example.com',
        'normalized_email' => 'retail.customer@example.com',
        'phone' => '+1 (555) 600-1111',
        'normalized_phone' => '5556001111',
        'source_channels' => ['shopify', 'growave'],
        'raw_metafields' => [],
        'synced_at' => now(),
    ]);

    Http::fake([
        'https://api.growave.io/v2/oauth/getAccessToken' => Http::response([
            'accessToken' => 'growave-token',
            'tokenType' => 'Bearer',
            'expiresAt' => now()->addHour()->toIso8601String(),
        ], 200),
    ]);

    $this->artisan('marketing:sync-growave --store=retail --only-missing')
        ->expectsOutputToContain('status=completed')
        ->expectsOutputToContain('processed=0')
        ->assertExitCode(0);

    Http::assertSentCount(0);
});
