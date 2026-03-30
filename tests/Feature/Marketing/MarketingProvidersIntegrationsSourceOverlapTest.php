<?php

use App\Models\CandleCashBalance;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingReviewSummary;
use App\Models\Tenant;
use App\Models\User;

test('providers integrations page renders source overlap summary and filters all-source profiles', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $tenantA = Tenant::query()->create([
        'name' => 'Providers Tenant A',
        'slug' => 'providers-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Providers Tenant B',
        'slug' => 'providers-tenant-b',
    ]);
    $user->tenants()->syncWithoutDetaching([$tenantA->id]);

    $shopifyOnly = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Shopify',
        'last_name' => 'Only',
        'email' => 'shopify-only@example.com',
        'normalized_email' => 'shopify-only@example.com',
        'source_channels' => ['shopify'],
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenantA->id,
        'marketing_profile_id' => $shopifyOnly->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'SHOP-CUST-1',
        'match_method' => 'exact_shopify_customer',
        'confidence' => 1,
    ]);

    $squareOnlyMissingContact = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Square',
        'last_name' => 'Only',
        'source_channels' => ['square'],
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenantA->id,
        'marketing_profile_id' => $squareOnlyMissingContact->id,
        'source_type' => 'square_customer',
        'source_id' => 'SQ-CUST-1',
        'match_method' => 'exact_square_customer',
        'confidence' => 1,
    ]);

    $growaveOnly = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Growave',
        'last_name' => 'Only',
        'email' => 'growave-only@example.com',
        'normalized_email' => 'growave-only@example.com',
        'source_channels' => ['growave'],
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenantA->id,
        'marketing_profile_id' => $growaveOnly->id,
        'source_type' => 'growave_customer',
        'source_id' => 'GR-CUST-1',
        'match_method' => 'exact_growave_customer',
        'confidence' => 1,
    ]);

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $growaveOnly->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GR-CUST-1',
        'review_count' => 3,
        'published_review_count' => 3,
        'average_rating' => 5,
        'source_synced_at' => now(),
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $growaveOnly->id,
        'balance' => 120,
    ]);

    $allThree = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Tri',
        'last_name' => 'Channel',
        'email' => 'tri-channel@example.com',
        'normalized_email' => 'tri-channel@example.com',
        'phone' => '5554442222',
        'normalized_phone' => '+15554442222',
        'source_channels' => ['shopify', 'square', 'growave'],
    ]);

    foreach ([
        ['source_type' => 'shopify_customer', 'source_id' => 'SHOP-CUST-TRI'],
        ['source_type' => 'square_customer', 'source_id' => 'SQ-CUST-TRI'],
        ['source_type' => 'growave_customer', 'source_id' => 'GR-CUST-TRI'],
    ] as $link) {
        MarketingProfileLink::query()->create([
            'tenant_id' => $tenantA->id,
            'marketing_profile_id' => $allThree->id,
            'source_type' => $link['source_type'],
            'source_id' => $link['source_id'],
            'match_method' => 'exact_match',
            'confidence' => 1,
        ]);
    }

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $allThree->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GR-CUST-TRI',
        'review_count' => 2,
        'published_review_count' => 2,
        'average_rating' => 4.5,
        'source_synced_at' => now(),
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $allThree->id,
        'balance' => 25,
    ]);

    $foreignProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Foreign',
        'last_name' => 'Channel',
        'email' => 'foreign-channel@example.com',
        'normalized_email' => 'foreign-channel@example.com',
        'phone' => '5552229999',
        'normalized_phone' => '+15552229999',
        'source_channels' => ['shopify', 'square', 'growave'],
    ]);
    foreach ([
        ['source_type' => 'shopify_customer', 'source_id' => 'SHOP-CUST-FOREIGN'],
        ['source_type' => 'square_customer', 'source_id' => 'SQ-CUST-FOREIGN'],
        ['source_type' => 'growave_customer', 'source_id' => 'GR-CUST-FOREIGN'],
    ] as $link) {
        MarketingProfileLink::query()->create([
            'tenant_id' => $tenantB->id,
            'marketing_profile_id' => $foreignProfile->id,
            'source_type' => $link['source_type'],
            'source_id' => $link['source_id'],
            'match_method' => 'exact_match',
            'confidence' => 1,
        ]);
    }
    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $foreignProfile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'foreign',
        'external_customer_id' => 'GR-CUST-FOREIGN',
        'review_count' => 7,
        'published_review_count' => 7,
        'average_rating' => 4.2,
        'source_synced_at' => now(),
    ]);
    CandleCashBalance::query()->create([
        'marketing_profile_id' => $foreignProfile->id,
        'balance' => 999,
    ]);

    $unlinked = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Manual',
        'last_name' => 'Only',
        'source_channels' => ['manual'],
    ]);

    $response = $this->actingAs($user)
        ->get(route('marketing.providers-integrations', [
            'overlap_filter' => 'all_three',
        ]));

    $response
        ->assertOk()
        ->assertSeeText('Customer Source Overlap')
        ->assertSeeText('Shopify + Square + Growave')
        ->assertSeeText('tri-channel@example.com')
        ->assertDontSeeText('shopify-only@example.com')
        ->assertDontSeeText('growave-only@example.com')
        ->assertDontSeeText('foreign-channel@example.com');

    $response->assertViewHas('sourceOverlap', function (array $overlap) use ($allThree): bool {
        $profiles = $overlap['profiles'];
        $items = $profiles->items();

        return data_get($overlap, 'summary.shopify_only.profile_count') === 1
            && data_get($overlap, 'summary.square_only.profile_count') === 1
            && data_get($overlap, 'summary.growave_only.profile_count') === 1
            && data_get($overlap, 'summary.shopify_square_growave.profile_count') === 1
            && data_get($overlap, 'summary.unlinked_or_other.profile_count') === 1
            && data_get($overlap, 'summary.growave_only.total_candle_cash_balance') === 120
            && data_get($overlap, 'summary.shopify_square_growave.total_candle_cash_balance') === 25
            && data_get($overlap, 'summary.growave_only.total_review_count') === 3
            && data_get($overlap, 'summary.shopify_square_growave.total_review_count') === 2
            && $profiles->total() === 1
            && count($items) === 1
            && (int) ($items[0]->id ?? 0) === $allThree->id
            && data_get($overlap, 'total_profiles') === 5;
    });
});
