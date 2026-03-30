<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingEventSourceMapping;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\MarketingPerformanceAnalyticsService;

beforeEach(function () {
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.sms.dry_run', false);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.twilio.account_sid', 'AC_TEST');
    config()->set('marketing.twilio.auth_token', 'AUTH_TEST');
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');
    config()->set('services.twilio.account_sid', 'AC_TEST');
    config()->set('services.twilio.auth_token', 'AUTH_TEST');
    config()->set('services.twilio.messaging_service_sid', 'MG_TEST');
});

test('campaign index and show are tenant-scoped by ownership evidence', function () {
    [$tenantA, $tenantB, $user] = mt4cPass2TenantActors();

    $campaignA = mt4cPass2SmsCampaignWithRecipient($tenantA, 'Tenant A Campaign')['campaign'];
    $campaignB = mt4cPass2SmsCampaignWithRecipient($tenantB, 'Tenant B Campaign')['campaign'];

    $this->actingAs($user)
        ->get(route('marketing.campaigns'))
        ->assertOk()
        ->assertSeeText('Tenant A Campaign')
        ->assertDontSeeText('Tenant B Campaign');

    $this->actingAs($user)
        ->get(route('marketing.campaigns.show', $campaignA))
        ->assertOk()
        ->assertSeeText('Tenant A Campaign');

    $this->actingAs($user)
        ->get(route('marketing.campaigns.show', $campaignB))
        ->assertNotFound();
});

test('send selected sms fails closed when any selected recipient is outside tenant ownership', function () {
    [$tenantA, $tenantB, $user] = mt4cPass2TenantActors();

    $owned = mt4cPass2SmsCampaignWithRecipient($tenantA, 'Mixed Recipient Campaign');
    $campaign = $owned['campaign'];
    $ownedRecipient = $owned['recipient'];
    $foreignRecipient = mt4cPass2SmsCampaignWithRecipient($tenantB, 'Foreign Recipient Campaign')['recipient'];

    $this->actingAs($user)
        ->from(route('marketing.campaigns.show', $campaign))
        ->post(route('marketing.campaigns.send-selected-sms', $campaign), [
            'recipient_ids' => [$ownedRecipient->id, $foreignRecipient->id],
            'dry_run' => '1',
        ])
        ->assertRedirect(route('marketing.campaigns.show', $campaign))
        ->assertSessionHasErrors(['recipient_ids']);

    expect(MarketingMessageDelivery::query()
        ->where('campaign_recipient_id', $foreignRecipient->id)
        ->exists())->toBeFalse();
});

test('campaign performance summary excludes foreign tenant delivery rows at service layer', function () {
    [$tenantA, $tenantB] = mt4cPass2TenantsOnly();

    $owned = mt4cPass2SmsCampaignWithRecipient($tenantA, 'Service Filter Campaign');
    $campaign = $owned['campaign'];
    $ownedRecipient = $owned['recipient'];
    $ownedProfile = $owned['profile'];

    $foreignProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Foreign',
        'last_name' => 'Analytics',
        'phone' => '+15550005555',
        'normalized_phone' => '+15550005555',
        'accepts_sms_marketing' => true,
    ]);
    $foreignRecipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $foreignProfile->id,
        'variant_id' => $owned['variant']->id,
        'channel' => 'sms',
        'status' => 'sent',
        'scheduled_for' => now(),
        'sent_at' => now()->subHour(),
    ]);

    foreach ([
        ['recipient' => $ownedRecipient, 'profile' => $ownedProfile],
        ['recipient' => $foreignRecipient, 'profile' => $foreignProfile],
    ] as $row) {
        MarketingMessageDelivery::query()->create([
            'campaign_id' => $campaign->id,
            'campaign_recipient_id' => $row['recipient']->id,
            'marketing_profile_id' => $row['profile']->id,
            'channel' => 'sms',
            'provider' => 'twilio',
            'provider_message_id' => 'DRYRUN-' . $row['recipient']->id,
            'to_phone' => (string) $row['profile']->normalized_phone,
            'variant_id' => $owned['variant']->id,
            'attempt_number' => 1,
            'rendered_message' => 'Service filter test',
            'send_status' => 'delivered',
            'sent_at' => now()->subHour(),
            'delivered_at' => now()->subHour(),
        ]);
    }

    $summary = app(MarketingPerformanceAnalyticsService::class)->campaignSummary($campaign, 120, $tenantA->id);

    expect((int) ($summary['recipients'] ?? 0))->toBe(1)
        ->and((int) ($summary['sent'] ?? 0))->toBe(1)
        ->and((int) ($summary['delivered'] ?? 0))->toBe(1);
});

test('event source mapping edits are tenant-owned and fail closed for foreign or unresolved rows', function () {
    [$tenantA, , $user] = mt4cPass2TenantActors();
    $tenantB = Tenant::query()->create([
        'name' => 'MT4C Pass2 Tenant C',
        'slug' => 'mt4c-pass2-tenant-c',
    ]);

    $ownedMapping = MarketingEventSourceMapping::query()->create([
        'tenant_id' => $tenantA->id,
        'source_system' => 'square_tax_name',
        'raw_value' => 'tenant-a-tax',
        'normalized_value' => 'tenant-a-tax',
        'is_active' => true,
    ]);
    $foreignMapping = MarketingEventSourceMapping::query()->create([
        'tenant_id' => $tenantB->id,
        'source_system' => 'square_tax_name',
        'raw_value' => 'tenant-b-tax',
        'normalized_value' => 'tenant-b-tax',
        'is_active' => true,
    ]);
    $unresolvedMapping = MarketingEventSourceMapping::query()->create([
        'source_system' => 'square_tax_name',
        'raw_value' => 'unresolved-tax',
        'normalized_value' => 'unresolved-tax',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations'))
        ->assertOk()
        ->assertSeeText('tenant-a-tax')
        ->assertDontSeeText('tenant-b-tax')
        ->assertDontSeeText('unresolved-tax');

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations.mappings.create'))
        ->assertOk();

    $this->actingAs($user)
        ->post(route('marketing.providers-integrations.mappings.store'), [
            'source_system' => 'square_tax_name',
            'raw_value' => 'tenant-a-new-tax',
            'is_active' => true,
        ])
        ->assertRedirect(route('marketing.providers-integrations'));

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations.mappings.edit', $ownedMapping))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations.mappings.edit', $foreignMapping))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations.mappings.edit', $unresolvedMapping))
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(route('marketing.providers-integrations.mappings.update', $ownedMapping), [
            'source_system' => 'square_tax_name',
            'raw_value' => 'tenant-a-tax-updated',
            'is_active' => true,
        ])
        ->assertRedirect(route('marketing.providers-integrations'));

    $this->actingAs($user)
        ->patch(route('marketing.providers-integrations.mappings.update', $foreignMapping), [
            'source_system' => 'square_tax_name',
            'raw_value' => 'tenant-b-tax-updated',
            'is_active' => true,
        ])
        ->assertNotFound();

    expect(MarketingEventSourceMapping::query()
        ->where('raw_value', 'tenant-a-new-tax')
        ->value('tenant_id'))->toBe($tenantA->id);
});

test('send approved sms command requires tenant context in strict mode and blocks foreign campaign ids', function () {
    [$tenantA, $tenantB] = mt4cPass2TenantsOnly();

    $campaignA = mt4cPass2SmsCampaignWithRecipient($tenantA, 'Command Tenant A Campaign')['campaign'];
    $campaignB = mt4cPass2SmsCampaignWithRecipient($tenantB, 'Command Tenant B Campaign')['campaign'];

    $this->artisan('marketing:send-approved-sms', [
        '--campaign-id' => $campaignA->id,
        '--dry-run' => true,
    ])->expectsOutputToContain('--tenant-id')
        ->assertExitCode(1);

    $this->artisan('marketing:send-approved-sms', [
        '--tenant-id' => $tenantA->id,
        '--campaign-id' => $campaignB->id,
        '--dry-run' => true,
    ])->expectsOutputToContain('ownership scope')
        ->assertExitCode(1);

    $this->artisan('marketing:send-approved-sms', [
        '--tenant-id' => $tenantA->id,
        '--campaign-id' => $campaignA->id,
        '--dry-run' => true,
    ])->expectsOutputToContain('processed=')
        ->assertExitCode(0);
});

test('generate recommendations command requires tenant context in strict mode and blocks foreign campaigns', function () {
    [$tenantA, $tenantB] = mt4cPass2TenantsOnly();

    $campaignA = mt4cPass2SmsCampaignWithRecipient($tenantA, 'Recommendation Tenant A Campaign')['campaign'];
    $campaignB = mt4cPass2SmsCampaignWithRecipient($tenantB, 'Recommendation Tenant B Campaign')['campaign'];

    $this->artisan('marketing:generate-recommendations', [
        '--campaign-id' => $campaignA->id,
        '--dry-run' => true,
    ])->expectsOutputToContain('--tenant-id')
        ->assertExitCode(1);

    $this->artisan('marketing:generate-recommendations', [
        '--tenant-id' => $tenantA->id,
        '--campaign-id' => $campaignB->id,
        '--dry-run' => true,
    ])->expectsOutputToContain('ownership scope')
        ->assertExitCode(1);

    $this->artisan('marketing:generate-recommendations', [
        '--tenant-id' => $tenantA->id,
        '--campaign-id' => $campaignA->id,
        '--dry-run' => true,
    ])->expectsOutputToContain('campaign_id=' . $campaignA->id)
        ->assertExitCode(0);
});

/**
 * @return array{0:Tenant,1:Tenant,2:User}
 */
function mt4cPass2TenantActors(): array
{
    [$tenantA, $tenantB] = mt4cPass2TenantsOnly();

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenantA->id]);

    return [$tenantA, $tenantB, $user];
}

/**
 * @return array{0:Tenant,1:Tenant}
 */
function mt4cPass2TenantsOnly(): array
{
    $tenantA = Tenant::query()->create([
        'name' => 'MT4C Pass2 Tenant A',
        'slug' => 'mt4c-pass2-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'MT4C Pass2 Tenant B',
        'slug' => 'mt4c-pass2-tenant-b',
    ]);

    return [$tenantA, $tenantB];
}

/**
 * @return array{campaign:MarketingCampaign,profile:MarketingProfile,recipient:MarketingCampaignRecipient,variant:MarketingCampaignVariant}
 */
function mt4cPass2SmsCampaignWithRecipient(Tenant $tenant, string $campaignName): array
{
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => $tenant->slug,
        'last_name' => 'Customer',
        'phone' => '+1555000' . str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT),
        'normalized_phone' => '+1555000' . str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT),
        'accepts_sms_marketing' => true,
        'source_channels' => ['shopify'],
    ]);

    $campaign = MarketingCampaign::query()->create([
        'tenant_id' => $tenant->id,
        'name' => $campaignName,
        'status' => 'draft',
        'channel' => 'sms',
    ]);

    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Primary Variant',
        'variant_key' => 'A',
        'message_text' => 'Hi {{first_name}}, this is a tenancy test.',
        'status' => 'active',
        'weight' => 100,
    ]);

    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'variant_id' => $variant->id,
        'channel' => 'sms',
        'status' => 'approved',
        'scheduled_for' => now(),
    ]);

    return [
        'campaign' => $campaign,
        'profile' => $profile,
        'recipient' => $recipient,
        'variant' => $variant,
    ];
}
