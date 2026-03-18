<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingDeliveryEvent;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingRecommendation;
use App\Models\Order;
use App\Models\SquareOrder;
use App\Models\User;
use App\Services\Marketing\MarketingConversionAttributionService;
use App\Services\Marketing\MarketingRecommendationEngine;
use App\Services\Marketing\MarketingSmsExecutionService;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.sms.dry_run', false);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.twilio.account_sid', 'AC_TEST');
    config()->set('marketing.twilio.auth_token', 'AUTH_TEST');
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');
    config()->set('marketing.twilio.verify_signature', false);
});

test('invalid messaging service sid is rejected before provider request', function () {
    $authToken = '474946c5ae24a0aa1e115cde6764f2c5';
    config()->set('marketing.twilio.auth_token', $authToken);
    config()->set('marketing.twilio.messaging_service_sid', $authToken);
    config()->set('marketing.twilio.from_number', null);

    Http::fake();

    $result = app(TwilioSmsService::class)->sendSms('+15552229988', 'Test message');

    Http::assertNothingSent();

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('invalid_messaging_service_sid')
        ->and((string) $result['error_message'])->toContain('must start with MG')
        ->and($result['from_identifier'])->toBeNull();
});

test('missing sender identity fails before provider request', function () {
    config()->set('marketing.twilio.messaging_service_sid', null);
    config()->set('marketing.twilio.from_number', null);

    Http::fake();

    $result = app(TwilioSmsService::class)->sendSms('+15552229988', 'Test message');

    Http::assertNothingSent();

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('missing_sender_identity')
        ->and((string) $result['error_message'])->toContain('Configure at least one enabled Twilio sender');
});

test('disabled sender cannot be selected for live sends', function () {
    config()->set('marketing.twilio.messaging_service_sid', null);
    config()->set('marketing.twilio.from_number', null);
    config()->set('marketing.twilio.senders', [
        [
            'key' => 'toll_free',
            'label' => 'Toll-free',
            'type' => 'toll_free',
            'status' => 'active',
            'enabled' => true,
            'default' => true,
            'messaging_service_sid' => 'MG_TOLL_FREE',
        ],
        [
            'key' => 'local',
            'label' => 'Local',
            'type' => 'local',
            'status' => 'pending',
            'enabled' => false,
            'phone_number_sid' => 'PN_LOCAL',
            'from_number' => '+15554443333',
        ],
    ]);

    Http::fake();

    $result = app(TwilioSmsService::class)->sendSms('+15552229988', 'Test message', [
        'sender_key' => 'local',
    ]);

    Http::assertNothingSent();

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('sender_disabled')
        ->and($result['sender_label'])->toBe('Local');
});

test('enabled sender without a live twilio identity fails clearly', function () {
    config()->set('marketing.twilio.messaging_service_sid', null);
    config()->set('marketing.twilio.from_number', null);
    config()->set('marketing.twilio.senders', [
        [
            'key' => 'local',
            'label' => 'Local',
            'type' => 'local',
            'status' => 'active',
            'enabled' => true,
            'default' => true,
            'phone_number_sid' => 'PN_LOCAL',
        ],
    ]);

    Http::fake();

    $result = app(TwilioSmsService::class)->sendSms('+15552229988', 'Test message', [
        'sender_key' => 'local',
    ]);

    Http::assertNothingSent();

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('sender_not_ready')
        ->and($result['sender_label'])->toBe('Local')
        ->and((string) $result['error_message'])->toContain('does not have a live Twilio send identity');
});

test('default messaging service sender is used when available', function () {
    config()->set('marketing.twilio.messaging_service_sid', null);
    config()->set('marketing.twilio.from_number', null);
    config()->set('marketing.twilio.senders', [
        [
            'key' => 'toll_free',
            'label' => 'Toll-free',
            'type' => 'toll_free',
            'status' => 'active',
            'enabled' => true,
            'default' => true,
            'messaging_service_sid' => 'MG_TOLL_FREE',
        ],
        [
            'key' => 'local',
            'label' => 'Local',
            'type' => 'local',
            'status' => 'active',
            'enabled' => true,
            'from_number' => '+15554443333',
        ],
    ]);

    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'sid' => 'SM_MG_1',
            'status' => 'sent',
        ], 201),
    ]);

    $result = app(TwilioSmsService::class)->sendSms('+15552229988', 'Test message');

    Http::assertSent(function ($request) {
        return $request['MessagingServiceSid'] === 'MG_TOLL_FREE'
            && ($request['From'] ?? null) === null;
    });

    expect($result['success'])->toBeTrue()
        ->and($result['sender_key'])->toBe('toll_free')
        ->and($result['from_identifier'])->toBe('MG_TOLL_FREE');
});

test('selected multi-number sender is used for twilio request payload', function () {
    config()->set('marketing.twilio.messaging_service_sid', null);
    config()->set('marketing.twilio.from_number', null);
    config()->set('marketing.twilio.senders', [
        [
            'key' => 'toll_free',
            'label' => 'Toll-free',
            'type' => 'toll_free',
            'status' => 'active',
            'enabled' => true,
            'default' => true,
            'messaging_service_sid' => 'MG_TOLL_FREE',
        ],
        [
            'key' => 'local',
            'label' => 'Local',
            'type' => 'local',
            'status' => 'active',
            'enabled' => true,
            'from_number' => '+15554443333',
        ],
    ]);

    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'sid' => 'SM_MULTI_1',
            'status' => 'sent',
        ], 201),
    ]);

    $result = app(TwilioSmsService::class)->sendSms('+15552229988', 'Test message', [
        'sender_key' => 'local',
    ]);

    Http::assertSent(function ($request) {
        return $request['From'] === '+15554443333'
            && ($request['MessagingServiceSid'] ?? null) === null;
    });

    expect($result['success'])->toBeTrue()
        ->and($result['sender_key'])->toBe('local')
        ->and($result['from_identifier'])->toBe('+15554443333');
});

test('legacy fallback sender uses configured from number', function () {
    config()->set('marketing.twilio.senders', []);
    config()->set('marketing.twilio.messaging_service_sid', null);
    config()->set('marketing.twilio.from_number', '+15556667777');

    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'sid' => 'SM_LEGACY_1',
            'status' => 'sent',
        ], 201),
    ]);

    $result = app(TwilioSmsService::class)->sendSms('+15552229988', 'Legacy send');

    Http::assertSent(function ($request) {
        return $request['From'] === '+15556667777'
            && ($request['MessagingServiceSid'] ?? null) === null;
    });

    expect($result['success'])->toBeTrue()
        ->and($result['sender_key'])->toBe('legacy_default')
        ->and($result['sender_label'])->toBe('Primary SMS Number')
        ->and($result['from_identifier'])->toBe('+15556667777');
});

test('twilio provider errors redact sensitive configured values', function () {
    $authToken = '474946c5ae24a0aa1e115cde6764f2c5';
    config()->set('marketing.twilio.auth_token', $authToken);
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');
    config()->set('marketing.twilio.from_number', null);

    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'code' => 21705,
            'message' => "21705: The Messaging Service Sid {$authToken} is invalid.",
            'status' => 'failed',
        ], 400),
    ]);

    $result = app(TwilioSmsService::class)->sendSms('+15552229988', 'Test message');

    expect($result['success'])->toBeFalse()
        ->and((string) $result['error_code'])->toBe('21705')
        ->and((string) $result['error_message'])->not->toContain($authToken)
        ->and((string) $result['error_message'])->toContain('[REDACTED_AUTH_TOKEN]');
});

function makeSmsRecipient(array $overrides = []): MarketingCampaignRecipient
{
    $campaign = MarketingCampaign::query()->create([
        'name' => $overrides['campaign_name'] ?? 'Stage 5 Campaign',
        'status' => 'active',
        'channel' => 'sms',
        'attribution_window_days' => 14,
        'coupon_code' => $overrides['coupon_code'] ?? null,
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Taylor',
        'last_name' => 'Stage5',
        'email' => 'taylor.stage5@example.com',
        'normalized_email' => 'taylor.stage5@example.com',
        'phone' => '5552229988',
        'normalized_phone' => '+15552229988',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Variant A',
        'variant_key' => 'A',
        'message_text' => 'Hi {{first_name}}, this is your approved SMS.',
        'status' => 'active',
        'weight' => 100,
        'is_control' => true,
    ]);

    return MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'variant_id' => $variant->id,
        'channel' => 'sms',
        'status' => $overrides['status'] ?? 'approved',
        'reason_codes' => ['manual_add'],
    ]);
}

test('approved sms recipient sends successfully and stores delivery metadata', function () {
    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'sid' => 'SM123456789',
            'status' => 'sent',
        ], 201),
    ]);

    $recipient = makeSmsRecipient();

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);

    $recipient->refresh();
    $delivery = MarketingMessageDelivery::query()->where('campaign_recipient_id', $recipient->id)->firstOrFail();

    expect($result['outcome'])->toBe('sent')
        ->and($recipient->status)->toBe('sent')
        ->and($delivery->provider_message_id)->toBe('SM123456789')
        ->and($delivery->send_status)->toBe('sent')
        ->and($delivery->sent_at)->not->toBeNull()
        ->and(MarketingDeliveryEvent::query()->where('marketing_message_delivery_id', $delivery->id)->exists())->toBeTrue();
});

test('campaign approved send respects explicit sender key option', function () {
    config()->set('marketing.twilio.messaging_service_sid', null);
    config()->set('marketing.twilio.from_number', null);
    config()->set('marketing.twilio.senders', [
        [
            'key' => 'toll_free',
            'label' => 'Toll-free',
            'type' => 'toll_free',
            'status' => 'active',
            'enabled' => true,
            'default' => true,
            'messaging_service_sid' => 'MG_TOLL_FREE',
        ],
        [
            'key' => 'local',
            'label' => 'Local',
            'type' => 'local',
            'status' => 'active',
            'enabled' => true,
            'from_number' => '+15554443333',
        ],
    ]);

    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'sid' => 'SM_CAMPAIGN_LOCAL_1',
            'status' => 'sent',
        ], 201),
    ]);

    $recipient = makeSmsRecipient();

    $summary = app(MarketingSmsExecutionService::class)->sendApprovedForCampaign($recipient->campaign, [
        'limit' => 1,
        'sender_key' => 'local',
    ]);

    $delivery = MarketingMessageDelivery::query()
        ->where('campaign_recipient_id', $recipient->id)
        ->latest('id')
        ->firstOrFail();

    Http::assertSent(function ($request) {
        return $request['From'] === '+15554443333'
            && ($request['MessagingServiceSid'] ?? null) === null;
    });

    expect($summary['processed'])->toBe(1)
        ->and($summary['sent'])->toBe(1)
        ->and(data_get($delivery->provider_payload, 'sender_key'))->toBe('local')
        ->and($delivery->from_identifier)->toBe('+15554443333');
});

test('dry run mode does not call twilio provider', function () {
    Http::fake();

    $recipient = makeSmsRecipient();

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient, ['dry_run' => true]);

    $delivery = MarketingMessageDelivery::query()->where('campaign_recipient_id', $recipient->id)->firstOrFail();

    Http::assertNothingSent();

    expect($result['outcome'])->toBe('sent')
        ->and($result['dry_run'])->toBeTrue()
        ->and($delivery->provider_message_id)->toStartWith('DRYRUN-');
});

test('ineligible recipients are skipped when phone is missing or consent revoked', function () {
    $missingPhone = makeSmsRecipient();
    $missingPhone->profile->forceFill([
        'phone' => null,
        'normalized_phone' => null,
    ])->save();

    $revokedConsent = makeSmsRecipient(['campaign_name' => 'Consent Campaign']);
    $revokedConsent->profile->forceFill([
        'accepts_sms_marketing' => false,
    ])->save();

    $service = app(MarketingSmsExecutionService::class);

    $missingPhoneResult = $service->sendRecipient($missingPhone);
    $revokedResult = $service->sendRecipient($revokedConsent);

    expect($missingPhoneResult['outcome'])->toBe('skipped')
        ->and($missingPhoneResult['reason'])->toBe('missing_phone')
        ->and($missingPhone->fresh()->status)->toBe('skipped')
        ->and($revokedResult['outcome'])->toBe('skipped')
        ->and($revokedResult['reason'])->toBe('sms_not_consented')
        ->and($revokedConsent->fresh()->status)->toBe('skipped');
});

test('failed sends capture error metadata and retry creates new attempt history', function () {
    $recipient = makeSmsRecipient();

    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'code' => 30007,
            'message' => 'Carrier violation',
            'status' => 'failed',
        ], 400),
    ]);

    $service = app(MarketingSmsExecutionService::class);
    $first = $service->sendRecipient($recipient);

    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'sid' => 'SM_RETRY_OK',
            'status' => 'sent',
        ], 201),
    ]);

    $second = $service->retryRecipient($recipient->fresh(), ['dry_run' => true]);

    $recipient->refresh();
    $deliveries = MarketingMessageDelivery::query()
        ->where('campaign_recipient_id', $recipient->id)
        ->orderBy('id')
        ->get();

    expect($first['outcome'])->toBe('failed')
        ->and($second['outcome'])->toBe('sent')
        ->and($deliveries)->toHaveCount(2)
        ->and((int) $deliveries[0]->attempt_number)->toBe(1)
        ->and((int) $deliveries[1]->attempt_number)->toBe(2)
        ->and($deliveries[0]->error_code)->not->toBeNull()
        ->and($recipient->status)->toBe('sent')
        ->and((int) $recipient->send_attempt_count)->toBe(2);
});

test('twilio callbacks update delivery status and are idempotent on duplicates', function () {
    $recipient = makeSmsRecipient();
    $delivery = MarketingMessageDelivery::query()->create([
        'campaign_id' => $recipient->campaign_id,
        'campaign_recipient_id' => $recipient->id,
        'marketing_profile_id' => $recipient->marketing_profile_id,
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_CALLBACK_1',
        'to_phone' => '+15552229988',
        'variant_id' => $recipient->variant_id,
        'attempt_number' => 1,
        'rendered_message' => 'Test message',
        'send_status' => 'sent',
        'sent_at' => now()->subMinute(),
    ]);

    $recipient->forceFill(['status' => 'sent', 'sent_at' => now()->subMinute()])->save();

    $payload = [
        'MessageSid' => 'SM_CALLBACK_1',
        'MessageStatus' => 'delivered',
        'ErrorCode' => '',
        'ErrorMessage' => '',
    ];

    $this->post(route('marketing.webhooks.twilio-status'), $payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'matched' => true, 'status' => 'delivered']);

    $this->post(route('marketing.webhooks.twilio-status'), $payload)
        ->assertOk();

    expect($delivery->fresh()->send_status)->toBe('delivered')
        ->and($recipient->fresh()->status)->toBe('delivered')
        ->and(MarketingDeliveryEvent::query()
            ->where('marketing_message_delivery_id', $delivery->id)
            ->count())->toBe(2);
});

test('unknown twilio callback message sid is handled safely', function () {
    $this->post(route('marketing.webhooks.twilio-status'), [
        'MessageSid' => 'SM_UNKNOWN_SID',
        'MessageStatus' => 'delivered',
    ])
        ->assertOk()
        ->assertJson(['ok' => true, 'matched' => false]);

    expect(MarketingDeliveryEvent::query()
        ->where('provider_message_id', 'SM_UNKNOWN_SID')
        ->exists())->toBeTrue();
});

test('conversion attribution records code based conversion when coupon signal matches', function () {
    $recipient = makeSmsRecipient(['coupon_code' => 'SAVE10']);

    MarketingMessageDelivery::query()->create([
        'campaign_id' => $recipient->campaign_id,
        'campaign_recipient_id' => $recipient->id,
        'marketing_profile_id' => $recipient->marketing_profile_id,
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_CODE_MATCH',
        'to_phone' => '+15552229988',
        'variant_id' => $recipient->variant_id,
        'attempt_number' => 1,
        'rendered_message' => 'Code match',
        'send_status' => 'delivered',
        'sent_at' => now()->subDays(1),
        'delivered_at' => now()->subDays(1),
    ]);

    $squareOrder = SquareOrder::query()->create([
        'square_order_id' => 'SQ_CODE_1',
        'closed_at' => now(),
        'total_money_amount' => 4500,
        'raw_payload' => [
            'metadata' => [
                'coupon_code' => 'SAVE10',
            ],
        ],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $recipient->marketing_profile_id,
        'source_type' => 'square_order',
        'source_id' => 'SQ_CODE_1',
        'match_method' => 'exact_email',
    ]);

    app(MarketingConversionAttributionService::class)->attributeForSquareOrder($squareOrder);

    expect(MarketingCampaignConversion::query()
        ->where('campaign_id', $recipient->campaign_id)
        ->where('marketing_profile_id', $recipient->marketing_profile_id)
        ->where('source_type', 'square_order')
        ->where('source_id', 'SQ_CODE_1')
        ->where('attribution_type', 'code_based')
        ->exists())->toBeTrue();
});

test('last touch and assisted conversions are created without duplication on rerun', function () {
    $recipientA = makeSmsRecipient(['campaign_name' => 'Campaign A']);
    $recipientB = makeSmsRecipient(['campaign_name' => 'Campaign B']);

    // Re-point recipient B to same profile as recipient A.
    $recipientB->forceFill(['marketing_profile_id' => $recipientA->marketing_profile_id])->save();

    MarketingMessageDelivery::query()->create([
        'campaign_id' => $recipientA->campaign_id,
        'campaign_recipient_id' => $recipientA->id,
        'marketing_profile_id' => $recipientA->marketing_profile_id,
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_TOUCH_A',
        'to_phone' => '+15552229988',
        'variant_id' => $recipientA->variant_id,
        'attempt_number' => 1,
        'rendered_message' => 'Touch A',
        'send_status' => 'delivered',
        'sent_at' => now()->subDays(2),
        'delivered_at' => now()->subDays(2),
    ]);

    MarketingMessageDelivery::query()->create([
        'campaign_id' => $recipientB->campaign_id,
        'campaign_recipient_id' => $recipientB->id,
        'marketing_profile_id' => $recipientB->marketing_profile_id,
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_TOUCH_B',
        'to_phone' => '+15552229988',
        'variant_id' => $recipientB->variant_id,
        'attempt_number' => 1,
        'rendered_message' => 'Touch B',
        'send_status' => 'delivered',
        'sent_at' => now()->subDay(),
        'delivered_at' => now()->subDay(),
    ]);

    $squareOrder = SquareOrder::query()->create([
        'square_order_id' => 'SQ_TOUCH_1',
        'closed_at' => now(),
        'total_money_amount' => 7600,
        'raw_payload' => [],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $recipientA->marketing_profile_id,
        'source_type' => 'square_order',
        'source_id' => 'SQ_TOUCH_1',
        'match_method' => 'exact_email',
    ]);

    $service = app(MarketingConversionAttributionService::class);
    $service->attributeForSquareOrder($squareOrder);
    $service->attributeForSquareOrder($squareOrder);

    $conversions = MarketingCampaignConversion::query()
        ->where('source_type', 'square_order')
        ->where('source_id', 'SQ_TOUCH_1')
        ->orderBy('attribution_type')
        ->get();

    expect($conversions)->toHaveCount(2)
        ->and($conversions->pluck('attribution_type')->all())->toBe(['assisted', 'last_touch']);
});

test('campaign send/retry pages and actions are role-gated with delivery visibility', function () {
    $recipient = makeSmsRecipient();

    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $marketingManager = User::factory()->create(['role' => 'marketing_manager', 'email_verified_at' => now()]);
    $manager = User::factory()->create(['role' => 'manager', 'email_verified_at' => now()]);

    foreach ([$admin, $marketingManager] as $user) {
        $this->actingAs($user)
            ->get(route('marketing.campaigns.show', $recipient->campaign))
            ->assertOk()
            ->assertSeeText('SMS Delivery Log')
            ->assertSeeText('Conversion Summary');

        $this->actingAs($user)
            ->post(route('marketing.campaigns.send-approved-sms', $recipient->campaign), [
                'dry_run' => 1,
            ])
            ->assertRedirect(route('marketing.campaigns.show', $recipient->campaign));
    }

    $recipient->forceFill(['status' => 'failed'])->save();

    $this->actingAs($manager)
        ->get(route('marketing.campaigns.show', $recipient->campaign))
        ->assertForbidden();

    $this->actingAs($manager)
        ->post(route('marketing.campaigns.recipients.retry-sms', [$recipient->campaign, $recipient]))
        ->assertForbidden();
});

test('consent capture recommendation is generated and manual consent update writes event', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Consent',
        'last_name' => 'Target',
        'email' => 'consent.target@example.com',
        'normalized_email' => 'consent.target@example.com',
        'phone' => '5554449988',
        'normalized_phone' => '+15554449988',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => false,
    ]);

    $order = Order::query()->create([
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'order_number' => 'R-1001',
        'ordered_at' => now()->subDays(15),
    ]);
    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'match_method' => 'exact_email',
    ]);

    $recommendationResult = app(MarketingRecommendationEngine::class)
        ->generateConsentCaptureSuggestionForProfile($profile);

    $recommendation = MarketingRecommendation::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('summary', 'Consent-capture outreach: profile has email consent but no SMS consent.')
        ->first();

    expect($recommendationResult['created'])->toBe(1)
        ->and($recommendation)->not->toBeNull();

    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

    $this->actingAs($admin)
        ->post(route('marketing.customers.update-consent', $profile), [
            'channel' => 'sms',
            'consented' => 1,
            'notes' => 'Customer confirmed opt-in via support call.',
        ])
        ->assertRedirect(route('marketing.customers.show', $profile));

    expect($profile->fresh()->accepts_sms_marketing)->toBeTrue()
        ->and(MarketingConsentEvent::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('channel', 'sms')
            ->where('event_type', 'confirmed')
            ->where('source_type', 'admin_manual')
            ->exists())->toBeTrue();
});

test('marketing send approved sms command runs and reports summary counts', function () {
    config()->set('marketing.sms.dry_run', true);

    makeSmsRecipient();

    $this->artisan('marketing:send-approved-sms --limit=5 --dry-run')
        ->assertSuccessful()
        ->expectsOutputToContain('processed=')
        ->expectsOutputToContain('sent=')
        ->expectsOutputToContain('failed=')
        ->expectsOutputToContain('skipped=');
});

test('marketing conversion attribution command runs and reports summary counts', function () {
    $recipient = makeSmsRecipient(['coupon_code' => 'SAVE10']);

    MarketingMessageDelivery::query()->create([
        'campaign_id' => $recipient->campaign_id,
        'campaign_recipient_id' => $recipient->id,
        'marketing_profile_id' => $recipient->marketing_profile_id,
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_COMMAND_ATTR',
        'to_phone' => '+15552229988',
        'variant_id' => $recipient->variant_id,
        'attempt_number' => 1,
        'rendered_message' => 'Command attribution',
        'send_status' => 'delivered',
        'sent_at' => now()->subDay(),
        'delivered_at' => now()->subDay(),
    ]);

    $squareOrder = SquareOrder::query()->create([
        'square_order_id' => 'SQ_CMD_ATTR',
        'closed_at' => now(),
        'total_money_amount' => 5500,
        'raw_payload' => ['metadata' => ['coupon_code' => 'SAVE10']],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $recipient->marketing_profile_id,
        'source_type' => 'square_order',
        'source_id' => 'SQ_CMD_ATTR',
        'match_method' => 'exact_email',
    ]);

    $this->artisan('marketing:attribute-conversions --square-order-id=SQ_CMD_ATTR')
        ->assertSuccessful()
        ->expectsOutputToContain('sources_processed=')
        ->expectsOutputToContain('conversions_created=')
        ->expectsOutputToContain('conversions_updated=');

    expect(MarketingCampaignConversion::query()
        ->where('source_type', 'square_order')
        ->where('source_id', 'SQ_CMD_ATTR')
        ->exists())->toBeTrue();
});
