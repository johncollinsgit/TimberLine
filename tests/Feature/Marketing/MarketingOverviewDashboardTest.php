<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingGroup;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingReviewSummary;
use App\Models\MarketingSegment;
use App\Models\ShopifyImportRun;
use App\Models\SquareCustomer;
use App\Models\User;

test('marketing overview shows real imported-system metrics and grouped navigation', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $shopifyOnly = MarketingProfile::query()->create([
        'first_name' => 'Shopify',
        'last_name' => 'Only',
        'email' => 'shopify-only@example.com',
        'normalized_email' => 'shopify-only@example.com',
        'source_channels' => ['shopify'],
    ]);
    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $shopifyOnly->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'SHOP-1',
        'match_method' => 'exact_shopify_customer',
        'confidence' => 1,
    ]);
    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $shopifyOnly->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => 'SHOP-1',
        'email' => 'shopify-only@example.com',
        'normalized_email' => 'shopify-only@example.com',
        'synced_at' => now(),
    ]);

    $squareOnlyMissing = MarketingProfile::query()->create([
        'first_name' => 'Square',
        'last_name' => 'Only',
        'source_channels' => ['square'],
    ]);
    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $squareOnlyMissing->id,
        'source_type' => 'square_customer',
        'source_id' => 'SQ-1',
        'match_method' => 'exact_square_customer',
        'confidence' => 1,
    ]);
    SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-1',
        'given_name' => 'Square',
        'family_name' => 'Only',
        'synced_at' => now(),
        'raw_payload' => [],
    ]);

    $allThree = MarketingProfile::query()->create([
        'first_name' => 'Tri',
        'last_name' => 'Channel',
        'email' => 'tri@example.com',
        'normalized_email' => 'tri@example.com',
        'phone' => '5554442222',
        'normalized_phone' => '+15554442222',
        'source_channels' => ['shopify', 'square', 'growave'],
    ]);

    foreach ([
        ['source_type' => 'shopify_customer', 'source_id' => 'SHOP-TRI'],
        ['source_type' => 'square_customer', 'source_id' => 'SQ-TRI'],
        ['source_type' => 'growave_customer', 'source_id' => 'GR-TRI'],
    ] as $link) {
        MarketingProfileLink::query()->create([
            'marketing_profile_id' => $allThree->id,
            'source_type' => $link['source_type'],
            'source_id' => $link['source_id'],
            'match_method' => 'exact_match',
            'confidence' => 1,
        ]);
    }

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $allThree->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GR-TRI',
        'points_balance' => 240,
        'referral_link' => 'https://example.test/ref/tri',
        'synced_at' => now(),
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $allThree->id,
        'balance' => 240,
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $allThree->id,
        'source' => 'growave_activity',
        'source_id' => 'retail:GR-TRI:activity-1',
        'type' => 'earned',
        'points' => 40,
    ]);

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $allThree->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GR-TRI',
        'review_count' => 2,
        'published_review_count' => 2,
        'average_rating' => 5,
        'source_synced_at' => now(),
    ]);

    MarketingGroup::query()->create([
        'name' => 'Launch Crew',
        'created_by' => $user->id,
    ]);

    $segment = MarketingSegment::query()->create([
        'name' => 'VIP Core',
        'slug' => 'vip-core',
        'status' => 'active',
        'rules_json' => [],
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $campaign = MarketingCampaign::query()->create([
        'name' => 'Core Winback',
        'slug' => 'core-winback',
        'status' => 'draft',
        'channel' => 'sms',
        'segment_id' => $segment->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $allThree->id,
        'channel' => 'sms',
        'status' => 'queued_for_approval',
    ]);

    MarketingMessageTemplate::query()->create([
        'name' => 'Winback SMS',
        'channel' => 'sms',
        'template_text' => 'Come back soon',
        'variables_json' => [],
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    MarketingIdentityReview::query()->create([
        'status' => 'pending',
        'source_type' => 'square_customer',
        'source_id' => 'SQ-CONFLICT',
    ]);

    MarketingImportRun::query()->create([
        'type' => 'square_customers_sync',
        'status' => 'completed',
        'source_label' => 'square:customers',
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(5),
        'summary' => ['processed' => 30015, 'errors' => 0],
        'created_by' => $user->id,
    ]);

    ShopifyImportRun::query()->create([
        'store_key' => 'retail',
        'source' => 'customer_metafields',
        'imported_count' => 16466,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subMinutes(30),
    ]);

    $response = $this->actingAs($user)->get(route('marketing.overview'));

    $response
        ->assertOk()
        ->assertSeeText('What is actually resident in the marketing system')
        ->assertSeeText('Home')
        ->assertSeeText('Lists & Sends')
        ->assertSeeText('Rewards')
        ->assertSeeText('Setup')
        ->assertSeeText('Canonical Profiles')
        ->assertSeeText('Cross-channel Core')
        ->assertSeeText('Capture Square buyer contact info')
        ->assertSeeText('Latest Shopify import')
        ->assertDontSeeText('Current Rollout Status')
        ->assertDontSeeText('Stage 1 foundation map');

    $response->assertViewHas('overviewDashboard', function (array $dashboard): bool {
        return data_get($dashboard, 'hero_metrics.0.value') === 3
            && data_get($dashboard, 'hero_metrics.2.value') === 1
            && data_get($dashboard, 'hero_metrics.3.value') === 1
            && data_get($dashboard, 'source_cards.0.profiles') === 2
            && data_get($dashboard, 'source_cards.1.profiles') === 2
            && data_get($dashboard, 'source_cards.2.profiles') === 1
            && data_get($dashboard, 'system_cards.1.primary_value') === '1'
            && data_get($dashboard, 'recent_import_runs.0.source_label') === 'square:customers';
    });
});
