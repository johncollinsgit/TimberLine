<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingOrderEventAttribution;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingRecommendation;
use App\Models\SquareOrder;
use App\Models\User;
use App\Models\EventInstance;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashShopifyDiscountService;
use App\Services\Marketing\MarketingEventOpportunityService;
use App\Services\Marketing\MarketingPerformanceAnalyticsService;
use App\Services\Marketing\MarketingRecommendationEngine;
use App\Services\Marketing\MarketingSegmentOpportunityService;
use App\Services\Marketing\MarketingTimingRecommendationService;

test('performance snapshots summarize delivery and conversion outcomes', function () {
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Stage7 Snapshot Campaign',
        'status' => 'active',
        'channel' => 'sms',
    ]);
    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Variant S7',
        'message_text' => 'Hi {{first_name}}',
        'status' => 'active',
    ]);
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Snapshot',
        'phone' => '5550001111',
        'normalized_phone' => '+15550001111',
        'accepts_sms_marketing' => true,
    ]);
    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'variant_id' => $variant->id,
        'channel' => 'sms',
        'status' => 'converted',
        'sent_at' => now()->subDay(),
    ]);
    MarketingMessageDelivery::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_recipient_id' => $recipient->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'provider' => 'twilio',
        'variant_id' => $variant->id,
        'send_status' => 'delivered',
        'rendered_message' => 'Snapshot delivery',
        'attempt_number' => 1,
        'sent_at' => now()->subDay(),
        'delivered_at' => now()->subDay(),
    ]);
    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'campaign_recipient_id' => $recipient->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => 's7-order-1',
        'converted_at' => now()->subHours(12),
        'order_total' => 45.00,
    ]);

    $summary = app(MarketingPerformanceAnalyticsService::class)->snapshotVariantPerformance([
        'campaign_id' => $campaign->id,
        'window_start' => now()->subDays(10),
        'window_end' => now(),
    ]);

    $row = collect((array) $summary['rows'])->first(fn (array $candidate): bool => (int) $candidate['variant_id'] === (int) $variant->id);

    expect($summary['processed'])->toBeGreaterThanOrEqual(1)
        ->and($row)->not->toBeNull()
        ->and((int) data_get($row, 'delivered_count'))->toBe(1)
        ->and((int) data_get($row, 'converted_count'))->toBe(1)
        ->and((float) data_get($row, 'attributed_revenue'))->toBe(45.0);
});

test('copy improvement recommendation is generated from variant performance gap', function () {
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Stage7 Variant Gap',
        'status' => 'active',
        'channel' => 'sms',
        'send_window_json' => ['start' => '10:00', 'end' => '18:00'],
    ]);
    $variantA = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'A',
        'message_text' => 'Short copy',
        'status' => 'active',
    ]);
    $variantB = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'B',
        'message_text' => 'Long copy variant that underperforms heavily',
        'status' => 'active',
    ]);

    foreach (range(1, 3) as $index) {
        $profile = MarketingProfile::query()->create([
            'first_name' => 'A' . $index,
            'phone' => '55510010' . $index,
            'normalized_phone' => '+155510010' . $index,
            'accepts_sms_marketing' => true,
        ]);
        $recipient = MarketingCampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'marketing_profile_id' => $profile->id,
            'variant_id' => $variantA->id,
            'channel' => 'sms',
            'status' => $index <= 2 ? 'converted' : 'delivered',
            'sent_at' => now()->subHours(30 + $index),
        ]);
        MarketingMessageDelivery::query()->create([
            'campaign_id' => $campaign->id,
            'campaign_recipient_id' => $recipient->id,
            'marketing_profile_id' => $profile->id,
            'channel' => 'sms',
            'provider' => 'twilio',
            'variant_id' => $variantA->id,
            'send_status' => 'delivered',
            'rendered_message' => 'A delivery',
            'attempt_number' => 1,
            'sent_at' => now()->subHours(30 + $index),
            'delivered_at' => now()->subHours(30 + $index),
        ]);
        if ($index <= 2) {
            MarketingCampaignConversion::query()->create([
                'campaign_id' => $campaign->id,
                'marketing_profile_id' => $profile->id,
                'campaign_recipient_id' => $recipient->id,
                'attribution_type' => 'last_touch',
                'source_type' => 'order',
                'source_id' => 's7-copy-a-' . $index,
                'converted_at' => now()->subHours(20 + $index),
                'order_total' => 35.00,
            ]);
        }
    }

    foreach (range(1, 3) as $index) {
        $profile = MarketingProfile::query()->create([
            'first_name' => 'B' . $index,
            'phone' => '55510020' . $index,
            'normalized_phone' => '+155510020' . $index,
            'accepts_sms_marketing' => true,
        ]);
        $recipient = MarketingCampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'marketing_profile_id' => $profile->id,
            'variant_id' => $variantB->id,
            'channel' => 'sms',
            'status' => 'delivered',
            'sent_at' => now()->subHours(22 + $index),
        ]);
        MarketingMessageDelivery::query()->create([
            'campaign_id' => $campaign->id,
            'campaign_recipient_id' => $recipient->id,
            'marketing_profile_id' => $profile->id,
            'channel' => 'sms',
            'provider' => 'twilio',
            'variant_id' => $variantB->id,
            'send_status' => 'delivered',
            'rendered_message' => 'B delivery',
            'attempt_number' => 1,
            'sent_at' => now()->subHours(22 + $index),
            'delivered_at' => now()->subHours(22 + $index),
        ]);
    }

    app(MarketingRecommendationEngine::class)->generateForCampaign($campaign);

    expect(MarketingRecommendation::query()
        ->where('campaign_id', $campaign->id)
        ->where('type', 'copy_improvement')
        ->where('title', 'Favor stronger-performing variant copy')
        ->exists())->toBeTrue();
});

test('timing suggestion recommendation is generated from timing insights', function () {
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Stage7 Timing',
        'status' => 'active',
        'channel' => 'sms',
        'objective' => 'winback',
        'send_window_json' => ['start' => '10:00', 'end' => '18:00'],
    ]);
    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Timing Variant',
        'message_text' => 'Timing test',
        'status' => 'active',
    ]);

    foreach (range(1, 6) as $index) {
        $profile = MarketingProfile::query()->create([
            'first_name' => 'Timing' . $index,
            'phone' => '55520010' . $index,
            'normalized_phone' => '+155520010' . $index,
            'accepts_sms_marketing' => true,
        ]);
        MarketingCampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'marketing_profile_id' => $profile->id,
            'variant_id' => $variant->id,
            'channel' => 'sms',
            'status' => $index <= 4 ? 'converted' : 'sent',
            'sent_at' => now()->setTime(14, 0)->subDays(2)->addMinutes($index),
        ]);
    }

    app(MarketingTimingRecommendationService::class)->generateInsights([
        'campaign_id' => $campaign->id,
    ]);
    app(MarketingRecommendationEngine::class)->generateForCampaign($campaign);

    expect(MarketingRecommendation::query()
        ->where('campaign_id', $campaign->id)
        ->where('type', 'timing_suggestion')
        ->where('title', 'Use performance-backed send window')
        ->exists())->toBeTrue();
});

test('segment opportunity recommendation is generated from customer and reward patterns', function () {
    foreach (range(1, 6) as $index) {
        MarketingProfile::query()->create([
            'first_name' => 'Florida' . $index,
            'state' => $index % 2 === 0 ? 'FL' : 'Florida',
        ]);
    }

    $result = app(MarketingSegmentOpportunityService::class)->generate();

    expect($result['created'])->toBeGreaterThanOrEqual(1)
        ->and(MarketingRecommendation::query()
            ->where('type', 'segment_opportunity')
            ->where('title', 'Create a Florida customers segment')
            ->exists())->toBeTrue();
});

test('event reactivation recommendation is generated from event attribution history', function () {
    $event = EventInstance::query()->create([
        'title' => 'Flowertown Spring Market',
        'starts_at' => now()->subMonths(2),
    ]);
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Event Buyer',
        'phone' => '5553004444',
        'normalized_phone' => '+15553004444',
        'accepts_sms_marketing' => true,
        'source_channels' => ['event'],
    ]);
    $squareOrder = SquareOrder::query()->create([
        'square_order_id' => 'S7_FLOWERTOWN_1',
        'closed_at' => now()->subMonths(2),
    ]);
    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'square_order',
        'source_id' => $squareOrder->square_order_id,
        'match_method' => 'exact_phone',
    ]);
    MarketingOrderEventAttribution::query()->create([
        'source_type' => 'square_order',
        'source_id' => $squareOrder->square_order_id,
        'event_instance_id' => $event->id,
        'attribution_method' => 'mapping',
        'confidence' => 0.95,
    ]);

    $result = app(MarketingEventOpportunityService::class)->generate();

    expect($result['created'])->toBeGreaterThanOrEqual(1)
        ->and(MarketingRecommendation::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('title', 'Flowertown buyer has SMS consent but no follow-up sent')
            ->exists())->toBeTrue();
});

test('public event and rewards routes work without admin auth and do not expose admin surfaces', function () {
    EventInstance::query()->create([
        'title' => 'Florida Strawberry Festival',
        'starts_at' => now()->addWeek(),
    ]);

    $this->get(route('marketing.public.events.optin', ['eventSlug' => 'florida-strawberry-festival']))
        ->assertOk()
        ->assertSeeText('Event Opt-In')
        ->assertDontSeeText('Campaign Detail');

    $this->post(route('marketing.public.events.optin.store', ['eventSlug' => 'florida-strawberry-festival']), [
        'email' => 'public.event.customer@example.com',
        'phone' => '5554049999',
        'first_name' => 'Public',
        'consent_sms' => 1,
        'award_bonus' => 0,
    ])->assertRedirect();

    expect(MarketingProfile::query()->where('normalized_email', 'public.event.customer@example.com')->exists())->toBeTrue();

    $this->get(route('marketing.public.rewards-lookup', ['email' => 'public.event.customer@example.com']))
        ->assertOk()
        ->assertSeeText('Candle Cash Account Lookup');
});

test('shopify integration endpoints return reward and consent responses with signed storefront auth', function () {
    config()->set('marketing.shopify.signing_secret', 'stage7-secret');
    config()->set('marketing.consent_bonus_points.sms', 5);
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage7-retail-client');

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Shopify',
        'email' => 'shopify.customer@example.com',
        'normalized_email' => 'shopify.customer@example.com',
        'phone' => '5558882222',
        'normalized_phone' => '+15558882222',
        'accepts_email_marketing' => true,
    ]);
    app(CandleCashService::class)->addPoints($profile, 400, 'earn', 'admin', 'seed', 'Seed');
    $reward = app(CandleCashService::class)->storefrontReward();
    expect($reward)->not->toBeNull();

    $discountSync = \Mockery::mock(CandleCashShopifyDiscountService::class);
    $discountSync->shouldReceive('ensureDiscountForRedemption')
        ->once()
        ->withArgs(function (\App\Models\CandleCashRedemption $redemption, ?string $preferredStoreKey): bool {
            return $preferredStoreKey === 'retail'
                && data_get($redemption->redemption_context, 'shopify_store_key') === 'retail';
        })
        ->andReturn([
            'discount_id' => 'gid://shopify/DiscountCodeNode/stage7',
            'discount_node_id' => 'gid://shopify/DiscountCodeNode/stage7',
            'store_key' => 'retail',
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addDays(30)->toIso8601String(),
        ]);
    app()->instance(CandleCashShopifyDiscountService::class, $discountSync);

    $signedHeaders = static function (string $method, string $path, array $query = [], array $payload = []): array {
        ksort($query);
        $canonicalQuery = collect($query)
            ->map(function ($value, $key): string {
                return rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
            })
            ->implode('&');
        $timestamp = (string) time();
        $body = $payload === [] ? '' : json_encode($payload, JSON_THROW_ON_ERROR);
        $payloadToSign = implode("\n", [
            $timestamp,
            strtoupper($method),
            $path,
            $canonicalQuery,
            hash('sha256', $body),
        ]);

        return [
            'X-Marketing-Timestamp' => $timestamp,
            'X-Marketing-Signature' => hash_hmac('sha256', $payloadToSign, 'stage7-secret'),
        ];
    };

    $balanceQuery = [
        'email' => $profile->email,
        'shop' => 'modernforestry.myshopify.com',
    ];

    $this->withHeaders($signedHeaders('GET', '/shopify/marketing/rewards/balance', $balanceQuery))
        ->getJson(route('marketing.shopify.rewards.balance', $balanceQuery))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.profile_id', $profile->id);

    $this->withHeaders($signedHeaders('GET', '/shopify/marketing/rewards/available'))
        ->getJson(route('marketing.shopify.rewards.available'))
        ->assertOk()
        ->assertJsonPath('ok', true);

    $consentPayload = [
        'email' => 'shopify.new@example.com',
        'phone' => '5551114444',
        'consent_sms' => true,
        'consent_email' => true,
        'award_bonus' => true,
    ];

    $this->withHeaders($signedHeaders('POST', '/shopify/marketing/consent/optin', [], $consentPayload))
        ->postJson(route('marketing.shopify.consent.optin'), $consentPayload)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.accepts_sms_marketing', true);

    $redeemPayload = [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $reward->id,
        'shop' => 'modernforestry.myshopify.com',
    ];

    $this->withHeaders($signedHeaders('POST', '/shopify/marketing/rewards/redeem', [], $redeemPayload))
        ->postJson(route('marketing.shopify.rewards.redeem'), $redeemPayload)
        ->assertOk()
        ->assertJsonPath('ok', true);

    $this->withHeaders(['X-Marketing-Token' => 'stage7-token'])
        ->getJson(route('marketing.shopify.rewards.balance', $balanceQuery))
        ->assertStatus(401);

    $this->withHeaders(['X-Marketing-Signature' => 'invalid-signature', 'X-Marketing-Timestamp' => (string) time()])
        ->getJson(route('marketing.shopify.rewards.balance', $balanceQuery))
        ->assertStatus(401);
});

test('customer detail shows next best action card for qualifying profile', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $profile = MarketingProfile::query()->create([
        'first_name' => 'NBA',
        'email' => 'nba@example.com',
        'normalized_email' => 'nba@example.com',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => false,
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Next Best Action')
        ->assertSeeText('Invite profile to SMS consent');
});

test('recommendation generation does not auto-send or auto-apply recipients', function () {
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Stage7 Integrity Campaign',
        'status' => 'active',
        'channel' => 'sms',
    ]);
    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Integrity Variant',
        'message_text' => 'No auto send',
        'status' => 'active',
    ]);
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Integrity',
        'phone' => '5558889999',
        'normalized_phone' => '+15558889999',
        'accepts_sms_marketing' => true,
    ]);
    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'variant_id' => $variant->id,
        'channel' => 'sms',
        'status' => 'approved',
    ]);

    $initialDeliveryCount = (int) MarketingMessageDelivery::query()->count();

    app(MarketingRecommendationEngine::class)->generateGlobal();

    expect((int) MarketingMessageDelivery::query()->count())->toBe($initialDeliveryCount)
        ->and($recipient->fresh()->status)->toBe('approved');
});
