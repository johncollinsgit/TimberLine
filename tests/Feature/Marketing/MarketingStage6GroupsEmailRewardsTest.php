<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingGroup;
use App\Models\MarketingGroupImportRow;
use App\Models\MarketingGroupImportRun;
use App\Models\MarketingGroupMember;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\User;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\MarketingCampaignAudienceBuilder;
use App\Services\Marketing\MarketingEmailExecutionService;
use Illuminate\Support\Facades\Http;

test('group can be created and member can be added', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Group',
        'last_name' => 'Member',
        'email' => 'group.member@example.com',
        'normalized_email' => 'group.member@example.com',
    ]);

    $this->actingAs($admin)
        ->post(route('marketing.groups.store'), [
            'name' => 'VIP Handpicked',
            'description' => 'Top curated profiles',
            'is_internal' => 0,
        ])
        ->assertRedirect();

    $group = MarketingGroup::query()->where('name', 'VIP Handpicked')->firstOrFail();
    expect($group->is_internal)->toBeFalse();

    $this->actingAs($admin)
        ->post(route('marketing.groups.members.add', $group), [
            'marketing_profile_id' => $profile->id,
        ])
        ->assertRedirect(route('marketing.groups.show', $group));

    expect(MarketingGroupMember::query()
        ->where('marketing_group_id', $group->id)
        ->where('marketing_profile_id', $profile->id)
        ->exists())->toBeTrue();
});

test('group csv import command creates profile links and membership', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $group = MarketingGroup::query()->create([
        'name' => 'CSV Group',
        'is_internal' => false,
        'created_by' => $admin->id,
    ]);

    $csvPath = tempnam(sys_get_temp_dir(), 'marketing_group_csv_');
    file_put_contents($csvPath, implode("\n", [
        'email,phone,first_name,last_name',
        'csv.person@example.com,5551230000,Csv,Person',
    ]));

    $this->artisan('marketing:import-group', [
        'group_id' => $group->id,
        'file' => $csvPath,
        '--created-by' => $admin->id,
    ])->assertExitCode(0);

    @unlink($csvPath);

    $profile = MarketingProfile::query()->where('normalized_email', 'csv.person@example.com')->first();
    expect($profile)->not->toBeNull()
        ->and(MarketingGroupMember::query()
            ->where('marketing_group_id', $group->id)
            ->where('marketing_profile_id', $profile->id)
            ->exists())->toBeTrue()
        ->and(MarketingGroupImportRun::query()->where('marketing_group_id', $group->id)->exists())->toBeTrue()
        ->and(MarketingGroupImportRow::query()->count())->toBeGreaterThan(0);
});

test('campaign audience builder unions segment group and manual recipients with dedupe', function () {
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Group Audience Campaign',
        'status' => 'draft',
        'channel' => 'sms',
    ]);

    MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Default',
        'message_text' => 'Hello {{first_name}}',
        'status' => 'active',
        'weight' => 100,
    ]);

    $group = MarketingGroup::query()->create([
        'name' => 'Group Audience',
        'is_internal' => false,
    ]);
    $campaign->groups()->sync([$group->id]);

    $eligible = MarketingProfile::query()->create([
        'first_name' => 'Eligible',
        'accepts_sms_marketing' => true,
        'phone' => '5550001000',
        'normalized_phone' => '+15550001000',
    ]);
    $ineligible = MarketingProfile::query()->create([
        'first_name' => 'Ineligible',
        'accepts_sms_marketing' => false,
        'phone' => '5550002000',
        'normalized_phone' => '+15550002000',
    ]);
    MarketingGroupMember::query()->create([
        'marketing_group_id' => $group->id,
        'marketing_profile_id' => $eligible->id,
    ]);
    MarketingGroupMember::query()->create([
        'marketing_group_id' => $group->id,
        'marketing_profile_id' => $ineligible->id,
    ]);

    $manual = MarketingProfile::query()->create([
        'first_name' => 'Manual',
        'accepts_sms_marketing' => true,
        'phone' => '5550003000',
        'normalized_phone' => '+15550003000',
    ]);
    MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $manual->id,
        'channel' => 'sms',
        'status' => 'pending',
        'reason_codes' => ['manual_add'],
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
    $manualRecipient = MarketingCampaignRecipient::query()
        ->where('campaign_id', $campaign->id)
        ->where('marketing_profile_id', $manual->id)
        ->firstOrFail();

    expect($summary['processed'])->toBe(3)
        ->and($eligibleRecipient->status)->toBe('queued_for_approval')
        ->and($ineligibleRecipient->status)->toBe('skipped')
        ->and((array) $ineligibleRecipient->reason_codes)->toContain('sms_not_consented')
        ->and($manualRecipient->status)->toBe('queued_for_approval')
        ->and(MarketingCampaignRecipient::query()->where('campaign_id', $campaign->id)->count())->toBe(3);
});

test('sendgrid email execution sends approved recipients and stores delivery metadata', function () {
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.dry_run', false);
    config()->set('marketing.email.from_email', 'marketing@example.com');
    config()->set('services.sendgrid.api_key', 'SG_TEST');

    Http::fake([
        'https://api.sendgrid.com/*' => Http::response([], 202, ['X-Message-Id' => 'SG_MSG_123']),
    ]);

    $campaign = MarketingCampaign::query()->create([
        'name' => 'Email Campaign',
        'status' => 'active',
        'channel' => 'email',
    ]);
    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Email Variant',
        'message_text' => 'Hi {{first_name}}',
        'status' => 'active',
    ]);
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Email',
        'email' => 'email.target@example.com',
        'normalized_email' => 'email.target@example.com',
        'accepts_email_marketing' => true,
    ]);
    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'variant_id' => $variant->id,
        'channel' => 'email',
        'status' => 'approved',
    ]);

    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient);

    $delivery = MarketingEmailDelivery::query()->where('marketing_campaign_recipient_id', $recipient->id)->firstOrFail();

    expect($result['outcome'])->toBe('sent')
        ->and($delivery->sendgrid_message_id)->toBe('SG_MSG_123')
        ->and($delivery->status)->toBe('sent')
        ->and($recipient->fresh()->status)->toBe('sent');
});

test('sendgrid webhook updates delivery states and handles duplicate callbacks idempotently', function () {
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Webhook Campaign',
        'status' => 'active',
        'channel' => 'email',
    ]);
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Webhook',
        'email' => 'webhook@example.com',
        'normalized_email' => 'webhook@example.com',
        'accepts_email_marketing' => true,
    ]);
    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'email',
        'status' => 'sent',
    ]);
    $delivery = MarketingEmailDelivery::query()->create([
        'marketing_campaign_recipient_id' => $recipient->id,
        'marketing_profile_id' => $profile->id,
        'sendgrid_message_id' => 'SG_EVT_1',
        'email' => $profile->email,
        'status' => 'sent',
        'sent_at' => now()->subMinute(),
        'raw_payload' => [],
    ]);

    $payload = [[
        'email' => 'webhook@example.com',
        'event' => 'delivered',
        'timestamp' => now()->timestamp,
        'sg_message_id' => 'SG_EVT_1',
        'custom_args' => [
            'marketing_email_delivery_id' => (string) $delivery->id,
        ],
    ]];

    $this->postJson(route('marketing.webhooks.sendgrid-events'), $payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'matched' => 1, 'updated' => 1]);

    $this->postJson(route('marketing.webhooks.sendgrid-events'), $payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'matched' => 1, 'duplicates' => 1]);

    $delivery->refresh();
    expect($delivery->status)->toBe('delivered')
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($recipient->fresh()->status)->toBe('delivered');
});

test('candle cash grant creates ledger transaction and updates balance', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $profile = MarketingProfile::query()->create(['first_name' => 'Rewards']);

    $this->actingAs($admin)
        ->post(route('marketing.customers.candle-cash.grant', $profile), [
            'type' => 'earn',
            'amount' => 80,
            'description' => 'Manual reward',
        ])
        ->assertRedirect(route('marketing.customers.show', $profile));

    expect((float) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(80.0)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('type', 'earn')
            ->where('candle_cash_delta', 80)
            ->exists())->toBeTrue();
});

test('candle cash redemption spends points and creates unique redemption code', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $profile = MarketingProfile::query()->create(['first_name' => 'Redeemer']);

    app(CandleCashService::class)->addPoints($profile, 400, 'earn', 'admin', 'seed', 'Seed balance');

    $reward = CandleCashReward::query()->where('is_active', true)->orderBy('candle_cash_cost')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('marketing.customers.candle-cash.redeem', $profile), [
            'reward_id' => $reward->id,
            'platform' => 'shopify',
        ])
        ->assertRedirect(route('marketing.customers.show', $profile));

    $redemption = CandleCashRedemption::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('reward_id', $reward->id)
        ->firstOrFail();

    $balance = CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance');
    expect($redemption->redemption_code)->toStartWith('CC-')
        ->and($redemption->platform)->toBe('shopify')
        ->and((int) $balance)->toBe(400 - (int) $reward->candle_cash_cost)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('type', 'redeem')
            ->exists())->toBeTrue();
});

test('internal group send route is restricted and supports admin and marketing_manager', function () {
    $internal = MarketingGroup::query()->create([
        'name' => 'Internal Ops',
        'is_internal' => true,
    ]);
    $external = MarketingGroup::query()->create([
        'name' => 'External List',
        'is_internal' => false,
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Internal Target',
        'phone' => '5550004444',
        'normalized_phone' => '+15550004444',
        'accepts_sms_marketing' => true,
    ]);
    MarketingGroupMember::query()->create([
        'marketing_group_id' => $internal->id,
        'marketing_profile_id' => $profile->id,
    ]);

    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $marketingManager = User::factory()->create(['role' => 'marketing_manager', 'email_verified_at' => now()]);
    $manager = User::factory()->create(['role' => 'manager', 'email_verified_at' => now()]);

    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.twilio.account_sid', 'AC_TEST');
    config()->set('marketing.twilio.auth_token', 'AUTH_TEST');
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');
    Http::fake([
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM_GROUP_1', 'status' => 'sent'], 201),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.groups.send', $internal))
        ->assertOk();

    $this->actingAs($marketingManager)
        ->get(route('marketing.groups.send', $internal))
        ->assertOk();

    $this->actingAs($manager)
        ->get(route('marketing.groups.send', $internal))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(route('marketing.groups.send', $external))
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('marketing.groups.send.execute', $internal), [
            'channel' => 'sms',
            'message' => 'Internal test message',
            'dry_run' => 1,
        ])
        ->assertRedirect(route('marketing.groups.send', $internal));

    expect(MarketingMessageDelivery::query()
        ->whereNull('campaign_id')
        ->where('marketing_profile_id', $profile->id)
        ->where('provider', 'twilio')
        ->exists())->toBeTrue();
});
