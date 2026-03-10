<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Models\MarketingRecommendation;
use App\Models\MarketingSegment;
use App\Models\User;
use App\Services\Marketing\MarketingCampaignAudienceBuilder;
use App\Services\Marketing\MarketingRecommendationEngine;
use App\Services\Marketing\MarketingTemplateRenderer;

test('campaign can be created and edited with linked segment', function () {
    $segment = MarketingSegment::query()->create([
        'name' => 'Campaign Segment',
        'status' => 'active',
        'rules_json' => [
            'logic' => 'and',
            'conditions' => [
                ['field' => 'has_sms_consent', 'operator' => 'eq', 'value' => true],
            ],
            'groups' => [],
        ],
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('marketing.campaigns.store'), [
            'name' => 'Winback A',
            'status' => 'draft',
            'channel' => 'sms',
            'segment_id' => $segment->id,
            'objective' => 'winback',
            'attribution_window_days' => 9,
            'coupon_code' => 'SAVE10',
        ])
        ->assertRedirect();

    $campaign = MarketingCampaign::query()
        ->where('name', 'Winback A')
        ->firstOrFail();

    expect((int) $campaign->segment_id)->toBe((int) $segment->id)
        ->and($campaign->name)->toBe('Winback A');

    $this->actingAs($admin)
        ->patch(route('marketing.campaigns.update', $campaign), [
            'name' => 'Winback B',
            'status' => 'ready_for_review',
            'channel' => 'sms',
            'segment_id' => $segment->id,
            'objective' => 'repeat_purchase',
            'attribution_window_days' => 7,
        ])
        ->assertRedirect(route('marketing.campaigns.show', $campaign));

    $campaign->refresh();
    expect($campaign->name)->toBe('Winback B')
        ->and($campaign->objective)->toBe('repeat_purchase')
        ->and($campaign->status)->toBe('ready_for_review');
});

test('recipient materialization applies consent gating and assigns default variant', function () {
    $segment = MarketingSegment::query()->create([
        'name' => 'Online Buyers Segment',
        'status' => 'active',
        'rules_json' => [
            'logic' => 'and',
            'conditions' => [
                ['field' => 'source_channel', 'operator' => 'contains', 'value' => 'online'],
            ],
            'groups' => [],
        ],
    ]);

    $campaign = MarketingCampaign::query()->create([
        'name' => 'Audience Prep Campaign',
        'status' => 'draft',
        'channel' => 'sms',
        'segment_id' => $segment->id,
    ]);

    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Variant A',
        'variant_key' => 'A',
        'message_text' => 'Hi first_name',
        'status' => 'active',
        'weight' => 100,
    ]);

    $eligible = MarketingProfile::query()->create([
        'first_name' => 'Eligible',
        'source_channels' => ['online'],
        'accepts_sms_marketing' => true,
        'phone' => '5551112222',
        'normalized_phone' => '+15551112222',
    ]);
    $ineligible = MarketingProfile::query()->create([
        'first_name' => 'Ineligible',
        'source_channels' => ['online'],
        'accepts_sms_marketing' => false,
        'phone' => '5551113333',
        'normalized_phone' => '+15551113333',
    ]);

    $summary = app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($campaign);

    $eligibleRecipient = MarketingCampaignRecipient::query()
        ->where('campaign_id', $campaign->id)
        ->where('marketing_profile_id', $eligible->id)
        ->firstOrFail();
    $ineligibleRecipient = MarketingCampaignRecipient::query()
        ->where('campaign_id', $campaign->id)
        ->where('marketing_profile_id', $ineligible->id)
        ->firstOrFail();

    expect($summary['processed'])->toBe(2)
        ->and($eligibleRecipient->status)->toBe('queued_for_approval')
        ->and((int) $eligibleRecipient->variant_id)->toBe((int) $variant->id)
        ->and($ineligibleRecipient->status)->toBe('skipped')
        ->and((array) $ineligibleRecipient->reason_codes)->toContain('sms_not_consented');
});

test('template renderer safely renders variables for profile context', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Taylor',
        'email' => 'taylor@example.com',
        'normalized_email' => 'taylor@example.com',
    ]);
    $template = MarketingMessageTemplate::query()->create([
        'name' => 'Renderer Template',
        'channel' => 'sms',
        'template_text' => 'Hi {{first_name}}, coupon {{coupon_code}}.',
        'is_active' => true,
    ]);

    $rendered = app(MarketingTemplateRenderer::class)->renderTemplate($template, $profile, [
        'coupon_code' => 'SAVE5',
    ]);

    expect($rendered)->toBe('Hi Taylor, coupon SAVE5.');
});

test('recommendation engine generates send and campaign recommendations with details', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Lapsed',
        'accepts_sms_marketing' => true,
        'phone' => '5552229999',
        'normalized_phone' => '+15552229999',
    ]);

    $campaign = MarketingCampaign::query()->create([
        'name' => 'Event Followup Campaign',
        'status' => 'draft',
        'channel' => 'sms',
        'objective' => 'event_followup',
    ]);
    MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Only Variant',
        'status' => 'active',
        'message_text' => 'Come back soon',
        'weight' => 100,
    ]);

    $engine = app(MarketingRecommendationEngine::class);

    $sendResult = $engine->generateSendSuggestionForProfile($profile, $campaign);
    $campaignResult = $engine->generateForCampaign($campaign);

    $sendRecommendation = MarketingRecommendation::query()
        ->where('type', 'send_suggestion')
        ->where('marketing_profile_id', $profile->id)
        ->firstOrFail();

    expect($sendResult['created'])->toBe(1)
        ->and($campaignResult['created'])->toBeGreaterThanOrEqual(2)
        ->and($sendRecommendation->status)->toBe('pending')
        ->and((array) $sendRecommendation->details_json)->toHaveKey('suggested_channel')
        ->and(MarketingRecommendation::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('type', ['copy_improvement', 'timing_suggestion'])
            ->count())->toBeGreaterThanOrEqual(2);
});
