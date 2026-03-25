<?php

use App\Models\BirthdayMessageEvent;
use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\Tenant;
use App\Models\TenantEmailSetting;
use App\Services\Marketing\BirthdayRewardEngineService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    MarketingSetting::query()->updateOrCreate(
        ['key' => 'birthday_reward_config'],
        ['value' => [
            'enabled' => true,
            'reward_type' => 'discount_code',
            'discount_code_prefix' => 'BDAY',
            'free_shipping_code_prefix' => 'BDAYSHIP',
            'claim_window_days_before' => 365,
            'claim_window_days_after' => 365,
        ]]
    );

    MarketingSetting::query()->updateOrCreate(
        ['key' => 'birthday_campaign_config'],
        ['value' => [
            'email_enabled' => true,
            'birthday_email_subject' => 'Happy Birthday {{ first_name }}',
            'birthday_email_body' => 'Use code {{ coupon_code }} today.',
        ]]
    );
});

test('birthday issuance sends through tenant email dispatch with sendgrid and persists canonical delivery metadata', function () {
    Http::fake([
        'https://api.sendgrid.com/v3/mail/send' => Http::response('', 202, [
            'X-Message-Id' => 'SG-BDAY-12345',
        ]),
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Birthday Tenant',
        'slug' => 'birthday-tenant-sendgrid',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => true,
        'from_name' => 'Birthday Team',
        'from_email' => 'hello@example.test',
        'reply_to_email' => 'support@example.test',
        'provider_status' => 'configured',
        'provider_config' => [
            'api_key' => 'SG.fake-api-key',
            'verified_sender_email' => 'verified@example.test',
            'verified_sender_name' => 'Birthday Team',
            'reply_to_email' => 'support@example.test',
            'tracking_enabled' => true,
        ],
        'analytics_enabled' => true,
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Avery',
        'email' => 'avery@example.test',
        'normalized_email' => 'avery@example.test',
        'accepts_email_marketing' => true,
    ]);

    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) now()->month,
        'birth_day' => (int) now()->day,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    $result = app(BirthdayRewardEngineService::class)->issueAnnualReward($birthday);

    $issuance = BirthdayRewardIssuance::query()->firstOrFail();
    $delivery = MarketingEmailDelivery::query()->where('campaign_type', 'birthday')->firstOrFail();
    $event = BirthdayMessageEvent::query()->firstOrFail();

    expect((bool) ($result['ok'] ?? false))->toBeTrue()
        ->and((bool) data_get($result, 'email_delivery.success'))->toBeTrue()
        ->and((string) data_get($result, 'email_delivery.provider'))->toBe('sendgrid')
        ->and((string) data_get($result, 'email_delivery.message_id'))->toBe('SG-BDAY-12345')
        ->and((int) $delivery->tenant_id)->toBe($tenant->id)
        ->and((string) $delivery->provider)->toBe('sendgrid')
        ->and((string) $delivery->provider_message_id)->toBe('SG-BDAY-12345')
        ->and((string) $delivery->status)->toBe('sent')
        ->and((string) $delivery->campaign_type)->toBe('birthday')
        ->and((string) $delivery->template_key)->toBe('birthday_email_primary')
        ->and((string) data_get($delivery->metadata, 'coupon_code'))->toBe((string) $issuance->reward_code)
        ->and((int) data_get($delivery->metadata, 'tenant_id'))->toBe($tenant->id)
        ->and((int) data_get($delivery->metadata, 'customer_id'))->toBe($profile->id)
        ->and((string) data_get($delivery->metadata, 'campaign_type'))->toBe('birthday')
        ->and((string) data_get($delivery->metadata, 'provider_resolution_source'))->toBe('tenant')
        ->and((string) data_get($delivery->metadata, 'provider_readiness_status'))->toBe('ready')
        ->and((bool) data_get($delivery->metadata, 'provider_using_fallback_config'))->toBeFalse()
        ->and((string) $event->provider)->toBe('sendgrid')
        ->and((string) $event->provider_message_id)->toBe('SG-BDAY-12345')
        ->and((string) $event->status)->toBe('sent')
        ->and((string) data_get($issuance->metadata, 'birthday_email.provider'))->toBe('sendgrid')
        ->and((int) data_get($issuance->metadata, 'birthday_email.delivery_id'))->toBe((int) $delivery->id);

    Http::assertSentCount(1);
});

test('birthday issuance fails honestly when tenant provider is unsupported and still writes delivery records', function () {
    Http::fake();

    $tenant = Tenant::query()->create([
        'name' => 'Birthday Tenant Unsupported',
        'slug' => 'birthday-tenant-unsupported',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'shopify_email',
        'email_enabled' => true,
        'from_name' => 'Birthday Team',
        'from_email' => 'hello@example.test',
        'reply_to_email' => 'support@example.test',
        'provider_status' => 'configured',
        'provider_config' => [
            'use_shopify_native_email' => true,
            'supports_app_sends' => false,
        ],
        'analytics_enabled' => true,
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Morgan',
        'email' => 'morgan@example.test',
        'normalized_email' => 'morgan@example.test',
        'accepts_email_marketing' => true,
    ]);

    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) now()->month,
        'birth_day' => (int) now()->day,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    $result = app(BirthdayRewardEngineService::class)->issueAnnualReward($birthday);

    $delivery = MarketingEmailDelivery::query()->where('campaign_type', 'birthday')->firstOrFail();
    $event = BirthdayMessageEvent::query()->firstOrFail();

    expect((bool) ($result['ok'] ?? false))->toBeTrue()
        ->and((bool) data_get($result, 'email_delivery.success'))->toBeFalse()
        ->and((string) data_get($result, 'email_delivery.error_code'))->toBe('unsupported_provider_action')
        ->and((string) $delivery->provider)->toBe('shopify_email')
        ->and($delivery->provider_message_id)->toBeNull()
        ->and((string) $delivery->status)->toBe('failed')
        ->and((string) data_get($delivery->metadata, 'provider_resolution_source'))->toBe('tenant')
        ->and((string) data_get($delivery->metadata, 'provider_readiness_status'))->toBe('unsupported')
        ->and((bool) data_get($delivery->metadata, 'provider_using_fallback_config'))->toBeFalse()
        ->and((string) data_get($delivery->metadata, 'campaign_type'))->toBe('birthday')
        ->and((string) data_get($delivery->metadata, 'coupon_code'))->not->toBe('')
        ->and((int) $delivery->tenant_id)->toBe($tenant->id)
        ->and((string) $event->provider)->toBe('shopify_email')
        ->and($event->provider_message_id)->toBeNull()
        ->and((string) $event->status)->toBe('failed')
        ->and((string) data_get($event->metadata, 'error_code'))->toBe('unsupported_provider_action');

    Http::assertNothingSent();
});

test('birthday issuance writes failed delivery when tenant email sending is disabled', function () {
    Http::fake();

    $tenant = Tenant::query()->create([
        'name' => 'Birthday Tenant Misconfigured',
        'slug' => 'birthday-tenant-misconfigured',
    ]);

    TenantEmailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'email_provider' => 'sendgrid',
        'email_enabled' => false,
        'from_name' => null,
        'from_email' => null,
        'reply_to_email' => null,
        'provider_status' => 'not_configured',
        'provider_config' => [
            'api_key' => 'SG.fake-api-key',
            'verified_sender_email' => null,
            'verified_sender_name' => null,
        ],
        'analytics_enabled' => true,
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Casey',
        'email' => 'casey@example.test',
        'normalized_email' => 'casey@example.test',
        'accepts_email_marketing' => true,
    ]);

    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) now()->month,
        'birth_day' => (int) now()->day,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    $result = app(BirthdayRewardEngineService::class)->issueAnnualReward($birthday);

    $delivery = MarketingEmailDelivery::query()->where('campaign_type', 'birthday')->firstOrFail();

    expect((bool) ($result['ok'] ?? false))->toBeTrue()
        ->and((bool) data_get($result, 'email_delivery.success'))->toBeFalse()
        ->and((string) data_get($result, 'email_delivery.provider'))->toBe('sendgrid')
        ->and((string) data_get($result, 'email_delivery.error_code'))->toBe('email_disabled')
        ->and((string) $delivery->provider)->toBe('sendgrid')
        ->and((string) $delivery->status)->toBe('failed')
        ->and((string) data_get($delivery->metadata, 'provider_resolution_source'))->toBe('tenant')
        ->and((string) data_get($delivery->metadata, 'provider_readiness_status'))->toBe('not_configured')
        ->and((bool) data_get($delivery->metadata, 'provider_using_fallback_config'))->toBeFalse()
        ->and($delivery->provider_message_id)->toBeNull()
        ->and((int) $delivery->tenant_id)->toBe($tenant->id);

    Http::assertNothingSent();
});
