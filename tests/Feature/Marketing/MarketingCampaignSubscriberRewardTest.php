<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingProfile;
use App\Models\MarketingSegment;
use App\Models\MessagingContactChannelState;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\MarketingCampaignAudienceBuilder;
use App\Services\Marketing\MarketingCampaignRewardIssuanceService;
use App\Services\Marketing\MarketingSmsExecutionService;

function campaignAdminUser(): User
{
    return User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
}

function campaignTestTenant(): Tenant
{
    return Tenant::query()->create([
        'name' => 'Campaign Tenant',
        'slug' => 'campaign-tenant-' . str()->lower(str()->random(8)),
    ]);
}

test('campaign audience preparation skips profiles with STOP suppression state', function () {
    $tenant = campaignTestTenant();

    $segment = MarketingSegment::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Subscriber Segment',
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
        'tenant_id' => $tenant->id,
        'name' => 'Text Subscriber Thank-You Reward',
        'status' => 'draft',
        'channel' => 'sms',
        'segment_id' => $segment->id,
        'objective' => 'reward_issuance',
    ]);

    MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Control',
        'status' => 'active',
        'message_text' => 'Thanks for subscribing',
        'weight' => 100,
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Suppressed',
        'source_channels' => ['online'],
        'accepts_sms_marketing' => true,
        'phone' => '5551112200',
        'normalized_phone' => '5551112200',
    ]);

    MessagingContactChannelState::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'phone' => '+15551112200',
        'sms_status' => 'unsubscribed',
        'sms_status_reason' => 'stop',
    ]);

    $summary = app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($campaign);

    $recipient = MarketingCampaignRecipient::query()
        ->where('campaign_id', $campaign->id)
        ->where('marketing_profile_id', $profile->id)
        ->firstOrFail();

    expect($summary['processed'])->toBe(1)
        ->and($summary['skipped'])->toBe(1)
        ->and($recipient->status)->toBe('skipped')
        ->and((array) $recipient->reason_codes)->toContain('sms_stop_suppressed');
});

test('sms execution re-validates suppression and skips STOP-suppressed recipients', function () {
    $tenant = campaignTestTenant();

    $campaign = MarketingCampaign::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Suppression Guardrail',
        'status' => 'active',
        'channel' => 'sms',
        'objective' => 'reward_issuance',
    ]);

    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'A',
        'status' => 'active',
        'message_text' => 'Hello from Modern Forestry',
        'weight' => 100,
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Suppressed',
        'accepts_sms_marketing' => true,
        'phone' => '5552223300',
        'normalized_phone' => '5552223300',
    ]);

    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'variant_id' => $variant->id,
        'channel' => 'sms',
        'status' => 'approved',
        'reason_codes' => [],
    ]);

    MessagingContactChannelState::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'phone' => '+15552223300',
        'sms_status' => 'unsubscribed',
        'sms_status_reason' => 'stop',
    ]);

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient, ['dry_run' => true]);

    expect($result['outcome'])->toBe('skipped')
        ->and($result['reason'])->toBe('sms_stop_suppressed')
        ->and((string) $recipient->fresh()->status)->toBe('skipped')
        ->and(CandleCashTransaction::query()->count())->toBe(0)
        ->and($campaign->deliveries()->count())->toBe(0);
});

test('campaign subscriber reward action is idempotent and only awards eligible sms recipients', function () {
    $admin = campaignAdminUser();
    $tenant = campaignTestTenant();
    $admin->tenants()->attach($tenant->id);

    $campaign = MarketingCampaign::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Text Subscriber Thank-You Reward',
        'status' => 'active',
        'channel' => 'sms',
        'objective' => 'reward_issuance',
        'slug' => 'text-subscriber-thank-you-reward',
    ]);

    $eligible = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Eligible',
        'accepts_sms_marketing' => true,
        'phone' => '5553334400',
        'normalized_phone' => '5553334400',
    ]);

    $suppressed = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Suppressed',
        'accepts_sms_marketing' => true,
        'phone' => '5553335500',
        'normalized_phone' => '5553335500',
    ]);

    MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $eligible->id,
        'channel' => 'sms',
        'status' => 'approved',
        'reason_codes' => [],
    ]);

    MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $suppressed->id,
        'channel' => 'sms',
        'status' => 'approved',
        'reason_codes' => [],
    ]);

    MessagingContactChannelState::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $suppressed->id,
        'phone' => '+15553335500',
        'sms_status' => 'unsubscribed',
        'sms_status_reason' => 'stop',
    ]);

    $this->actingAs($admin)
        ->post(route('marketing.campaigns.issue-subscriber-reward', $campaign), [
            'amount' => 5,
        ])
        ->assertRedirect(route('marketing.campaigns.show', $campaign));

    $sourceId = app(MarketingCampaignRewardIssuanceService::class)->sourceIdForCampaign($campaign, 5);

    $eligibleTransactions = CandleCashTransaction::query()
        ->where('marketing_profile_id', $eligible->id)
        ->where('source', 'campaign_reward')
        ->where('source_id', $sourceId)
        ->get();

    expect($eligibleTransactions)->toHaveCount(1)
        ->and((string) $eligibleTransactions->first()->type)->toBe('gift')
        ->and((float) $eligibleTransactions->first()->candle_cash_delta)->toBe(5.0)
        ->and((float) CandleCashBalance::query()->findOrFail($eligible->id)->balance)->toBe(5.0)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $suppressed->id)
            ->where('source', 'campaign_reward')
            ->count())->toBe(0);

    $this->actingAs($admin)
        ->post(route('marketing.campaigns.issue-subscriber-reward', $campaign), [
            'amount' => 5,
        ])
        ->assertRedirect(route('marketing.campaigns.show', $campaign));

    expect(CandleCashTransaction::query()
        ->where('marketing_profile_id', $eligible->id)
        ->where('source', 'campaign_reward')
        ->where('source_id', $sourceId)
        ->count())->toBe(1);

    $this->actingAs($admin)
        ->get(route('marketing.campaigns.show', $campaign))
        ->assertOk()
        ->assertSee($sourceId, false)
        ->assertSee('Campaign Rewards', false);
});
