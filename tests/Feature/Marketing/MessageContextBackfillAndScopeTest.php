<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageEngagementEvent;
use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Marketing\MarketingSmsExecutionService;
use App\Services\Marketing\MessageAnalyticsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('message analytics scope filter includes direct and campaign sms sends with default all', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Scope Tenant',
        'slug' => 'scope-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Scope',
        'last_name' => 'Tester',
        'phone' => '+15550000001',
        'normalized_phone' => '+15550000001',
        'email' => 'scope@example.com',
        'normalized_email' => 'scope@example.com',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $campaign = MarketingCampaign::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'name' => 'Campaign Scope',
        'status' => 'active',
        'channel' => 'sms',
        'source_label' => 'shopify_embedded_messaging_campaign',
    ]);

    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Variant A',
        'variant_key' => 'A',
        'message_text' => 'Campaign scope message',
        'status' => 'active',
        'weight' => 100,
        'is_control' => true,
    ]);

    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'variant_id' => $variant->id,
        'channel' => 'sms',
        'status' => 'sent',
    ]);

    $directDelivery = MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'direct-scope-batch',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => 'Direct scope',
        'channel' => 'sms',
        'provider' => 'twilio',
        'to_phone' => $profile->normalized_phone,
        'attempt_number' => 1,
        'rendered_message' => 'Direct scope body',
        'send_status' => 'sent',
        'sent_at' => now()->subHours(3),
    ]);

    $campaignDelivery = MarketingMessageDelivery::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_recipient_id' => $recipient->id,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'campaign-scope-batch',
        'source_label' => 'shopify_embedded_messaging_campaign',
        'message_subject' => 'Campaign scope',
        'channel' => 'sms',
        'provider' => 'twilio',
        'to_phone' => $profile->normalized_phone,
        'attempt_number' => 1,
        'rendered_message' => 'Campaign scope body',
        'send_status' => 'sent',
        'sent_at' => now()->subHours(2),
    ]);

    $service = app(MessageAnalyticsService::class);
    $baseFilters = [
        'date_from' => now()->subDays(2)->toDateString(),
        'date_to' => now()->toDateString(),
        'channel' => 'sms',
    ];

    $allPayload = $service->index($tenant->id, 'retail', $service->normalizeFilters($baseFilters));
    $directPayload = $service->index($tenant->id, 'retail', $service->normalizeFilters([...$baseFilters, 'scope' => 'direct']));
    $campaignPayload = $service->index($tenant->id, 'retail', $service->normalizeFilters([...$baseFilters, 'scope' => 'campaign']));

    $allRows = collect($allPayload['messages']->items());
    $directRows = collect($directPayload['messages']->items());
    $campaignRows = collect($campaignPayload['messages']->items());

    expect($allRows)->toHaveCount(2)
        ->and($directRows)->toHaveCount(1)
        ->and($campaignRows)->toHaveCount(1)
        ->and((array) data_get($directRows->first(), 'delivery_ids'))->toContain($directDelivery->id)
        ->and((array) data_get($campaignRows->first(), 'delivery_ids'))->toContain($campaignDelivery->id);
});

test('message analytics scope filter includes direct and campaign email sends with default all', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Email Scope Tenant',
        'slug' => 'email-scope-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Email',
        'last_name' => 'Tester',
        'email' => 'email-scope@example.com',
        'normalized_email' => 'email-scope@example.com',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $campaign = MarketingCampaign::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'name' => 'Campaign Email Scope',
        'status' => 'active',
        'channel' => 'email',
        'source_label' => 'shopify_embedded_messaging_campaign',
    ]);

    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Variant A',
        'variant_key' => 'A',
        'message_subject' => 'Campaign subject',
        'message_text' => 'Campaign email body',
        'status' => 'active',
        'weight' => 100,
        'is_control' => true,
    ]);

    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'variant_id' => $variant->id,
        'channel' => 'email',
        'status' => 'sent',
    ]);

    $directDelivery = MarketingEmailDelivery::query()->create([
        'marketing_campaign_recipient_id' => null,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'direct-email-scope-batch',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => 'Direct Email Scope',
        'provider' => 'sendgrid',
        'provider_message_id' => 'direct-email-scope-msg',
        'campaign_type' => 'direct_message',
        'email' => $profile->normalized_email,
        'status' => 'sent',
        'sent_at' => now()->subHours(3),
    ]);

    $campaignDelivery = MarketingEmailDelivery::query()->create([
        'marketing_campaign_recipient_id' => $recipient->id,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'campaign-email-scope-batch',
        'source_label' => 'shopify_embedded_messaging_campaign',
        'message_subject' => 'Campaign Email Scope',
        'provider' => 'sendgrid',
        'provider_message_id' => 'campaign-email-scope-msg',
        'campaign_type' => 'campaign',
        'email' => $profile->normalized_email,
        'status' => 'sent',
        'sent_at' => now()->subHours(2),
    ]);

    $service = app(MessageAnalyticsService::class);
    $baseFilters = [
        'date_from' => now()->subDays(2)->toDateString(),
        'date_to' => now()->toDateString(),
        'channel' => 'email',
    ];

    $allPayload = $service->index($tenant->id, 'retail', $service->normalizeFilters($baseFilters));
    $directPayload = $service->index($tenant->id, 'retail', $service->normalizeFilters([...$baseFilters, 'scope' => 'direct']));
    $campaignPayload = $service->index($tenant->id, 'retail', $service->normalizeFilters([...$baseFilters, 'scope' => 'campaign']));

    $allRows = collect($allPayload['messages']->items());
    $directRows = collect($directPayload['messages']->items());
    $campaignRows = collect($campaignPayload['messages']->items());

    expect($allRows)->toHaveCount(2)
        ->and($directRows)->toHaveCount(1)
        ->and($campaignRows)->toHaveCount(1)
        ->and((array) data_get($directRows->first(), 'delivery_ids'))->toContain($directDelivery->id)
        ->and((array) data_get($campaignRows->first(), 'delivery_ids'))->toContain($campaignDelivery->id);
});

test('new tenant analytics starts empty and auto-populates after first direct send', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Fresh Tenant',
        'slug' => 'fresh-tenant',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'fresh-tenant.myshopify.com',
        'access_token' => 'token',
        'installed_at' => now(),
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Fresh',
        'last_name' => 'Customer',
        'phone' => '+15557778888',
        'normalized_phone' => '+15557778888',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    expect(MarketingCampaign::query()->where('tenant_id', $tenant->id)->count())->toBe(0);

    $service = app(MessageAnalyticsService::class);
    $filters = $service->normalizeFilters([
        'date_from' => now()->subDays(2)->toDateString(),
        'date_to' => now()->toDateString(),
        'channel' => 'sms',
    ]);

    $before = $service->index($tenant->id, 'retail', $filters);
    expect(collect($before['messages']->items()))->toHaveCount(0);

    MarketingMessageDelivery::query()->create([
        'campaign_id' => null,
        'campaign_recipient_id' => null,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'fresh-direct-batch',
        'source_label' => 'shopify_embedded_messaging_group',
        'message_subject' => 'Fresh direct',
        'channel' => 'sms',
        'provider' => 'twilio',
        'to_phone' => $profile->normalized_phone,
        'attempt_number' => 1,
        'rendered_message' => 'Fresh direct body',
        'send_status' => 'sent',
        'sent_at' => now()->subMinutes(5),
    ]);

    $after = $service->index($tenant->id, 'retail', $filters);
    expect(collect($after['messages']->items()))->toHaveCount(1);
});

test('sms campaign execution fails closed and alerts when tenant/store context cannot be resolved', function () {
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.twilio.account_sid', 'AC_TEST');
    config()->set('marketing.twilio.auth_token', 'AUTH_TEST');
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');

    // Ensure strict tenant mode is active while leaving campaign/profile ownership unresolved.
    Tenant::query()->create([
        'name' => 'Strict Tenant',
        'slug' => 'strict-tenant',
    ]);

    $campaign = MarketingCampaign::query()->create([
        'name' => 'No Context Campaign',
        'status' => 'active',
        'channel' => 'sms',
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Casey',
        'last_name' => 'Context',
        'phone' => '5552229988',
        'normalized_phone' => '+15552229988',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Variant A',
        'variant_key' => 'A',
        'message_text' => 'Context safety message.',
        'status' => 'active',
        'weight' => 100,
        'is_control' => true,
    ]);

    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'variant_id' => $variant->id,
        'channel' => 'sms',
        'status' => 'approved',
    ]);

    Http::fake();
    Log::spy();

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);

    Http::assertNothingSent();

    expect((string) ($result['outcome'] ?? ''))->toBe('failed')
        ->and((string) ($result['reason'] ?? ''))->toContain('context')
        ->and((string) $recipient->fresh()->status)->toBe('failed')
        ->and(MarketingMessageDelivery::query()->where('campaign_recipient_id', $recipient->id)->exists())->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'marketing.sms.send.context_unresolved'
                && (int) ($context['campaign_id'] ?? 0) > 0
                && (int) ($context['recipient_id'] ?? 0) > 0;
        })
        ->once();
});

test('message context backfill command repairs campaign delivery and event context and is idempotent', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Backfill Tenant',
        'slug' => 'backfill-tenant',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'backfill-tenant.myshopify.com',
        'access_token' => 'token',
        'installed_at' => now(),
    ]);

    $campaign = MarketingCampaign::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'name' => 'Backfill Campaign',
        'status' => 'active',
        'channel' => 'sms',
        'source_label' => 'shopify_embedded_messaging_campaign',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Backfill',
        'last_name' => 'Profile',
        'phone' => '+15556667777',
        'normalized_phone' => '+15556667777',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $variant = MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Variant A',
        'variant_key' => 'A',
        'message_text' => 'Backfill body',
        'status' => 'active',
        'weight' => 100,
        'is_control' => true,
    ]);

    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'variant_id' => $variant->id,
        'channel' => 'sms',
        'status' => 'sent',
    ]);

    $delivery = MarketingMessageDelivery::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_recipient_id' => $recipient->id,
        'marketing_profile_id' => $profile->id,
        'tenant_id' => null,
        'store_key' => null,
        'batch_id' => null,
        'source_label' => null,
        'message_subject' => 'Legacy campaign send',
        'channel' => 'sms',
        'provider' => 'twilio',
        'to_phone' => $profile->normalized_phone,
        'attempt_number' => 1,
        'rendered_message' => 'Legacy campaign body',
        'send_status' => 'delivered',
        'sent_at' => now()->subDays(1),
    ]);

    $event = MarketingMessageEngagementEvent::query()->create([
        'tenant_id' => null,
        'store_key' => null,
        'marketing_email_delivery_id' => null,
        'marketing_message_delivery_id' => $delivery->id,
        'marketing_profile_id' => null,
        'channel' => 'sms',
        'event_type' => 'click',
        'event_hash' => hash('sha256', 'legacy-context-event'),
        'provider' => 'short_link',
        'url' => 'https://theforestrystudio.com/pages/rewards',
        'normalized_url' => 'https://theforestrystudio.com/pages/rewards',
        'url_domain' => 'theforestrystudio.com',
        'occurred_at' => now()->subDay(),
        'payload' => ['event' => 'click'],
    ]);

    $this->artisan('marketing:backfill-message-context', [
        '--tenant-id' => $tenant->id,
        '--store' => 'retail',
        '--skip-attribution-sync' => true,
    ])->assertExitCode(0);

    $delivery->refresh();
    $event->refresh();

    expect((int) ($delivery->tenant_id ?? 0))->toBe($tenant->id)
        ->and((string) ($delivery->store_key ?? ''))->toBe('retail')
        ->and((string) ($delivery->source_label ?? ''))->toBe('shopify_embedded_messaging_campaign')
        ->and((string) ($delivery->batch_id ?? ''))->not->toBe('')
        ->and((int) ($event->tenant_id ?? 0))->toBe($tenant->id)
        ->and((string) ($event->store_key ?? ''))->toBe('retail')
        ->and((int) ($event->marketing_profile_id ?? 0))->toBe($profile->id)
        ->and((string) ($event->channel ?? ''))->toBe('sms');

    $batchId = (string) $delivery->batch_id;

    $this->artisan('marketing:backfill-message-context', [
        '--tenant-id' => $tenant->id,
        '--store' => 'retail',
        '--skip-attribution-sync' => true,
    ])->assertExitCode(0);

    expect((string) $delivery->fresh()->batch_id)->toBe($batchId);
});
