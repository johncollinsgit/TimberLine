<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\User;
use App\Services\Marketing\MarketingCampaignDeliveryDiagnostics;

function createEmailRecipient(MarketingCampaign $campaign, string $email): MarketingCampaignRecipient
{
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Casey',
        'last_name' => 'River',
        'email' => $email,
        'normalized_email' => strtolower($email),
        'accepts_email_marketing' => true,
    ]);

    return MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'email',
        'status' => 'approved',
    ]);
}

test('delivery diagnostics derives readiness states from smoke-test and webhook data', function () {
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Diagnostics Campaign',
        'status' => 'draft',
        'channel' => 'email',
    ]);

    $recipient = createEmailRecipient($campaign, 'smoke@example.com');

    MarketingEmailDelivery::query()->create([
        'marketing_campaign_recipient_id' => $recipient->id,
        'marketing_profile_id' => $recipient->marketing_profile_id,
        'email' => 'smoke@example.com',
        'status' => 'sent',
        'sendgrid_message_id' => 'SG-awaiting',
        'sent_at' => now()->subMinutes(20),
        'raw_payload' => [
            'request' => ['id' => 'awaiting'],
        ],
    ]);

    $service = app(MarketingCampaignDeliveryDiagnostics::class);

    $missingConfig = $service->summarize($campaign, [
        'smoke_test_recipient_email' => '',
        'status' => 'ready_for_live_send',
    ], MarketingEmailDelivery::query()->where('marketing_campaign_recipient_id', $recipient->id)->get());
    expect($missingConfig['overall_status'])->toBe('needs_config');

    $awaiting = $service->summarize($campaign, [
        'smoke_test_recipient_email' => 'smoke@example.com',
        'status' => 'ready_for_live_send',
    ], MarketingEmailDelivery::query()->where('marketing_campaign_recipient_id', $recipient->id)->get());
    expect($awaiting['overall_status'])->toBe('awaiting_webhook')
        ->and(data_get($awaiting, 'webhook_health.indicator'))->toBe('missing_events');

    $delivery = MarketingEmailDelivery::query()->where('marketing_campaign_recipient_id', $recipient->id)->latest('id')->firstOrFail();
    $delivery->forceFill([
        'status' => 'delivered',
        'raw_payload' => [
            'events' => [
                ['event' => 'processed', 'at' => now()->subMinutes(6)->toIso8601String()],
                ['event' => 'delivered', 'at' => now()->subMinutes(4)->toIso8601String()],
            ],
        ],
    ])->save();

    $received = $service->summarize($campaign, [
        'smoke_test_recipient_email' => 'smoke@example.com',
        'status' => 'ready_for_live_send',
    ], MarketingEmailDelivery::query()->where('marketing_campaign_recipient_id', $recipient->id)->get());
    expect($received['overall_status'])->toBe('webhook_received')
        ->and((string) ($received['last_smoke_test_webhook_event'] ?? ''))->toBe('delivered');

    $delivery->forceFill([
        'status' => 'failed',
        'raw_payload' => [
            'events' => [
                ['event' => 'bounce', 'at' => now()->subMinutes(2)->toIso8601String()],
            ],
        ],
    ])->save();

    $failed = $service->summarize($campaign, [
        'smoke_test_recipient_email' => 'smoke@example.com',
        'status' => 'ready_for_live_send',
    ], MarketingEmailDelivery::query()->where('marketing_campaign_recipient_id', $recipient->id)->get());
    expect($failed['overall_status'])->toBe('error');
});

test('delivery diagnostics builds recipient-level tracking and smoke/live mode rows', function () {
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Recipient Metrics Campaign',
        'status' => 'draft',
        'channel' => 'email',
    ]);

    $smokeRecipient = createEmailRecipient($campaign, 'smoke@example.com');
    $liveRecipient = createEmailRecipient($campaign, 'buyer@example.com');
    $unsubscribeRecipient = createEmailRecipient($campaign, 'unsub@example.com');

    MarketingEmailDelivery::query()->create([
        'marketing_campaign_recipient_id' => $smokeRecipient->id,
        'marketing_profile_id' => $smokeRecipient->marketing_profile_id,
        'email' => 'smoke@example.com',
        'status' => 'clicked',
        'sendgrid_message_id' => 'SG-SMOKE-CLICK',
        'sent_at' => now()->subMinutes(15),
        'raw_payload' => [
            'events' => [
                ['event' => 'processed', 'at' => now()->subMinutes(14)->toIso8601String()],
                ['event' => 'delivered', 'at' => now()->subMinutes(13)->toIso8601String()],
                ['event' => 'open', 'at' => now()->subMinutes(12)->toIso8601String()],
                ['event' => 'click', 'at' => now()->subMinutes(11)->toIso8601String()],
            ],
        ],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_campaign_recipient_id' => $liveRecipient->id,
        'marketing_profile_id' => $liveRecipient->marketing_profile_id,
        'email' => 'buyer@example.com',
        'status' => 'failed',
        'sendgrid_message_id' => 'SG-LIVE-FAIL',
        'sent_at' => now()->subMinutes(18),
        'raw_payload' => [
            'events' => [
                ['event' => 'dropped', 'at' => now()->subMinutes(17)->toIso8601String()],
            ],
        ],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_campaign_recipient_id' => $unsubscribeRecipient->id,
        'marketing_profile_id' => $unsubscribeRecipient->marketing_profile_id,
        'email' => 'unsub@example.com',
        'status' => 'sent',
        'sendgrid_message_id' => 'DRYRUN-SG-UNSUB',
        'sent_at' => now()->subMinutes(10),
        'raw_payload' => [
            'dry_run' => true,
            'events' => [
                ['event' => 'unsubscribe', 'at' => now()->subMinutes(8)->toIso8601String()],
            ],
        ],
    ]);

    $summary = app(MarketingCampaignDeliveryDiagnostics::class)->summarize($campaign, [
        'smoke_test_recipient_email' => 'smoke@example.com',
        'status' => 'ready_for_live_send',
    ], MarketingEmailDelivery::query()->whereIn('marketing_campaign_recipient_id', [
        $smokeRecipient->id,
        $liveRecipient->id,
        $unsubscribeRecipient->id,
    ])->get());

    expect(data_get($summary, 'recipient_tracking.total_deliveries'))->toBe(3)
        ->and(data_get($summary, 'recipient_tracking.delivered_count'))->toBe(1)
        ->and(data_get($summary, 'recipient_tracking.open_count'))->toBe(1)
        ->and(data_get($summary, 'recipient_tracking.click_count'))->toBe(1)
        ->and(data_get($summary, 'recipient_tracking.failure_count'))->toBe(1)
        ->and(data_get($summary, 'recipient_tracking.bounce_drop_deferred_count'))->toBe(1)
        ->and(data_get($summary, 'recipient_tracking.unsubscribe_count'))->toBe(1)
        ->and(data_get($summary, 'webhook_health.indicator'))->toBe('failures_detected');

    $rows = collect((array) ($summary['deliveries'] ?? []));
    expect($rows->where('is_smoke_test', true)->count())->toBe(1)
        ->and($rows->where('mode', 'dry_run')->count())->toBe(1)
        ->and($rows->where('mode', 'live')->count())->toBe(1);
});

test('campaign detail renders the upgraded delivery diagnostics section', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.dry_run', false);
    config()->set('marketing.email.from_email', 'marketing@example.com');
    config()->set('marketing.email.from_name', 'Modern Forestry');
    config()->set('marketing.email.smoke_test_recipient_email', 'smoke@example.com');
    config()->set('services.sendgrid.api_key', 'SG_TEST');

    $campaign = MarketingCampaign::query()->create([
        'name' => 'Diagnostics UI Campaign',
        'status' => 'draft',
        'channel' => 'email',
    ]);

    $recipient = createEmailRecipient($campaign, 'smoke@example.com');
    MarketingEmailDelivery::query()->create([
        'marketing_campaign_recipient_id' => $recipient->id,
        'marketing_profile_id' => $recipient->marketing_profile_id,
        'email' => 'smoke@example.com',
        'status' => 'sent',
        'sendgrid_message_id' => 'SG-DIAG-UI',
        'sent_at' => now()->subMinutes(7),
        'raw_payload' => [
            'events' => [
                ['event' => 'processed', 'at' => now()->subMinutes(6)->toIso8601String()],
            ],
        ],
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.campaigns.show', $campaign))
        ->assertOk()
        ->assertSeeText('Delivery Diagnostics')
        ->assertSeeText('Recipient-level Tracking')
        ->assertSeeText('Webhook Health')
        ->assertSeeText('Send Smoke Test');
});
