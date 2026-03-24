<?php

use App\Models\BirthdayMessageEvent;
use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\BirthdayReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

function createBirthdayProfile(Tenant $tenant, string $firstName, string $email): array
{
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => $firstName,
        'email' => $email,
        'normalized_email' => strtolower($email),
        'accepts_email_marketing' => true,
    ]);

    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) now()->month,
        'birth_day' => (int) now()->day,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    return [$profile, $birthday];
}

function setModelCreatedAt(Model $model, string $createdAt, ?string $updatedAt = null): void
{
    $created = CarbonImmutable::parse($createdAt);
    $updated = $updatedAt !== null ? CarbonImmutable::parse($updatedAt) : $created;

    $model->forceFill([
        'created_at' => $created,
        'updated_at' => $updated,
    ])->save();
}

function seedBirthdayPeriodDelivery(
    Tenant $tenant,
    string $seedKey,
    string $deliveryCreatedAt,
    string $deliveryStatus = 'sent',
    string $provider = 'sendgrid',
    string $templateKey = 'birthday_email_primary',
    bool $redeemed = false,
    float $attributedRevenue = 0.0,
    bool $unsupported = false
): array {
    [$profile, $birthday] = createBirthdayProfile(
        $tenant,
        'Period' . $seedKey,
        'period-' . $seedKey . '@example.test'
    );

    $createdAt = CarbonImmutable::parse($deliveryCreatedAt);
    $issuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) $createdAt->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => $redeemed ? 'redeemed' : 'issued',
        'reward_code' => 'BDAY-' . strtoupper($seedKey),
        'issued_at' => $createdAt,
        'redeemed_at' => $redeemed ? $createdAt->addHours(6) : null,
        'attributed_revenue' => $redeemed ? $attributedRevenue : null,
    ]);
    setModelCreatedAt($issuance, $createdAt->toDateTimeString());

    $delivery = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'provider' => $provider,
        'provider_message_id' => strtoupper('MSG-' . $seedKey),
        'campaign_type' => 'birthday',
        'template_key' => $templateKey,
        'email' => $profile->email,
        'status' => $deliveryStatus,
        'sent_at' => in_array($deliveryStatus, ['sent', 'delivered', 'opened', 'clicked'], true) ? $createdAt->addMinutes(5) : null,
        'delivered_at' => in_array($deliveryStatus, ['delivered', 'opened', 'clicked'], true) ? $createdAt->addMinutes(10) : null,
        'opened_at' => in_array($deliveryStatus, ['opened', 'clicked'], true) ? $createdAt->addMinutes(20) : null,
        'clicked_at' => $deliveryStatus === 'clicked' ? $createdAt->addMinutes(25) : null,
        'failed_at' => $deliveryStatus === 'failed' ? $createdAt->addMinutes(10) : null,
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuance->id,
            'coupon_code' => 'BDAY-' . strtoupper($seedKey),
            'campaign_type' => 'birthday',
            'template_key' => $templateKey,
            'error_code' => $unsupported ? 'unsupported_provider_action' : null,
        ],
        'raw_payload' => [],
    ]);
    setModelCreatedAt($delivery, $createdAt->toDateTimeString());

    return [$profile, $birthday, $issuance, $delivery];
}

test('birthday analytics is tenant scoped and includes unsupported provider failures', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Birthday Analytics A',
        'slug' => 'birthday-analytics-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Birthday Analytics B',
        'slug' => 'birthday-analytics-b',
    ]);

    [$profileA1, $birthdayA1] = createBirthdayProfile($tenantA, 'Avery', 'avery@tenant-a.test');
    [$profileA2, $birthdayA2] = createBirthdayProfile($tenantA, 'Morgan', 'morgan@tenant-a.test');
    [$profileB1, $birthdayB1] = createBirthdayProfile($tenantB, 'Taylor', 'taylor@tenant-b.test');

    $issuanceA1 = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayA1->id,
        'marketing_profile_id' => $profileA1->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'redeemed',
        'reward_code' => 'BDAY-A1',
        'issued_at' => now()->subDay(),
        'redeemed_at' => now(),
        'attributed_revenue' => 88.50,
    ]);

    $issuanceA2 = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayA2->id,
        'marketing_profile_id' => $profileA2->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-A2',
        'issued_at' => now()->subDay(),
    ]);

    $issuanceB1 = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayB1->id,
        'marketing_profile_id' => $profileB1->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-B1',
        'issued_at' => now()->subDay(),
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profileA1->id,
        'tenant_id' => $tenantA->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-ANA-A1',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profileA1->email,
        'status' => 'clicked',
        'sent_at' => now()->subHours(2),
        'delivered_at' => now()->subHour(),
        'opened_at' => now()->subHour(),
        'clicked_at' => now()->subMinutes(45),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuanceA1->id,
            'coupon_code' => 'BDAY-A1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profileA2->id,
        'tenant_id' => $tenantA->id,
        'provider' => 'shopify_email',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profileA2->email,
        'status' => 'failed',
        'failed_at' => now()->subMinutes(30),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuanceA2->id,
            'coupon_code' => 'BDAY-A2',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
            'error_code' => 'unsupported_provider_action',
        ],
        'raw_payload' => [],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profileB1->id,
        'tenant_id' => $tenantB->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-ANA-B1',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profileB1->email,
        'status' => 'sent',
        'sent_at' => now()->subHour(),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuanceB1->id,
            'coupon_code' => 'BDAY-B1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [],
    ]);

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenantA->id,
        'date_from' => now()->subDays(7)->toDateString(),
        'date_to' => now()->toDateString(),
    ]);

    expect((bool) ($analytics['empty'] ?? true))->toBeFalse()
        ->and((int) data_get($analytics, 'metrics.rewards_issued'))->toBe(2)
        ->and((int) data_get($analytics, 'metrics.birthday_emails_attempted'))->toBe(2)
        ->and((int) data_get($analytics, 'metrics.birthday_emails_sent_successfully'))->toBe(1)
        ->and((int) data_get($analytics, 'metrics.birthday_emails_failed'))->toBe(1)
        ->and((int) data_get($analytics, 'metrics.coupons_redeemed'))->toBe(1)
        ->and((float) data_get($analytics, 'metrics.attributed_revenue'))->toBe(88.5)
        ->and((int) data_get(collect($analytics['status_breakdown'] ?? [])->firstWhere('status', 'unsupported'), 'count', 0))->toBe(1)
        ->and((int) data_get(collect($analytics['provider_breakdown'] ?? [])->firstWhere('provider', 'sendgrid'), 'attempted', 0))->toBe(1)
        ->and((int) data_get(collect($analytics['provider_breakdown'] ?? [])->firstWhere('provider', 'shopify_email'), 'attempted', 0))->toBe(1)
        ->and((int) data_get(collect($analytics['top_failure_reasons'] ?? [])->firstWhere('reason', 'unsupported_provider_action'), 'count', 0))->toBe(1);
});

test('birthday analytics supports provider template and status filtering with redemption attribution cohort', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Filters',
        'slug' => 'birthday-analytics-filters',
    ]);

    [$profileOne, $birthdayOne] = createBirthdayProfile($tenant, 'Jamie', 'jamie@example.test');
    [$profileTwo, $birthdayTwo] = createBirthdayProfile($tenant, 'Parker', 'parker@example.test');

    $issuanceOne = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayOne->id,
        'marketing_profile_id' => $profileOne->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'redeemed',
        'reward_code' => 'BDAY-FLT-1',
        'issued_at' => now()->subDay(),
        'redeemed_at' => now(),
        'attributed_revenue' => 42.00,
    ]);

    $issuanceTwo = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayTwo->id,
        'marketing_profile_id' => $profileTwo->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'redeemed',
        'reward_code' => 'BDAY-FLT-2',
        'issued_at' => now()->subDay(),
        'redeemed_at' => now(),
        'attributed_revenue' => 99.00,
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profileOne->id,
        'tenant_id' => $tenant->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-FLT-1',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profileOne->email,
        'status' => 'clicked',
        'sent_at' => now()->subHours(3),
        'delivered_at' => now()->subHours(2),
        'opened_at' => now()->subHours(2),
        'clicked_at' => now()->subHours(2),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuanceOne->id,
            'coupon_code' => 'BDAY-FLT-1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profileTwo->id,
        'tenant_id' => $tenant->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-FLT-2',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_followup',
        'email' => $profileTwo->email,
        'status' => 'sent',
        'sent_at' => now()->subHours(2),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuanceTwo->id,
            'coupon_code' => 'BDAY-FLT-2',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_followup',
        ],
        'raw_payload' => [],
    ]);

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => now()->subDays(7)->toDateString(),
        'date_to' => now()->toDateString(),
        'provider' => 'sendgrid',
        'template_key' => 'birthday_email_primary',
        'status' => 'clicked',
    ]);

    expect((int) data_get($analytics, 'metrics.birthday_emails_attempted'))->toBe(1)
        ->and((int) data_get($analytics, 'metrics.birthday_emails_sent_successfully'))->toBe(1)
        ->and((int) data_get($analytics, 'metrics.birthday_emails_failed'))->toBe(0)
        ->and((int) data_get($analytics, 'metrics.rewards_issued'))->toBe(1)
        ->and((int) data_get($analytics, 'metrics.coupons_redeemed'))->toBe(1)
        ->and((float) data_get($analytics, 'metrics.attributed_revenue'))->toBe(42.0)
        ->and((int) data_get($analytics, 'attribution.delivery_links.linked_count'))->toBe(1)
        ->and((int) data_get($analytics, 'attribution.delivery_links.linked_issuance_count'))->toBe(1)
        ->and((int) data_get($analytics, 'attribution.delivery_links.unlinked_count'))->toBe(0)
        ->and((array) ($analytics['notes'] ?? []))->not->toBeEmpty();
});

test('birthday analytics empty state returns zero metrics with empty flag', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Empty',
        'slug' => 'birthday-analytics-empty',
    ]);

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => now()->subDays(7)->toDateString(),
        'date_to' => now()->toDateString(),
    ]);

    expect((bool) ($analytics['empty'] ?? false))->toBeTrue()
        ->and((int) data_get($analytics, 'metrics.rewards_issued'))->toBe(0)
        ->and((int) data_get($analytics, 'metrics.birthday_emails_attempted'))->toBe(0)
        ->and((int) data_get($analytics, 'metrics.coupons_redeemed'))->toBe(0)
        ->and((float) data_get($analytics, 'metrics.attributed_revenue'))->toBe(0.0)
        ->and((bool) data_get($analytics, 'comparison.empty', false))->toBeTrue()
        ->and((string) data_get($analytics, 'comparison.recommendation.status', ''))->toBe('insufficient_data');
});

test('sendgrid webhook updates canonical birthday delivery status and propagates to birthday message event', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Webhook',
        'slug' => 'birthday-analytics-webhook',
    ]);

    [$profile, $birthday] = createBirthdayProfile($tenant, 'Rowan', 'rowan@example.test');

    $issuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-WEB-1',
        'issued_at' => now()->subDay(),
    ]);

    $delivery = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'provider' => 'sendgrid',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profile->email,
        'status' => 'sent',
        'sent_at' => now()->subMinutes(10),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuance->id,
            'coupon_code' => 'BDAY-WEB-1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [
            'event_key' => 'birthday-email:' . $issuance->id . ':birthday_email_primary',
        ],
    ]);

    $event = BirthdayMessageEvent::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'birthday_reward_issuance_id' => $issuance->id,
        'event_key' => 'birthday-email:' . $issuance->id . ':birthday_email_primary',
        'campaign_type' => 'birthday_email',
        'channel' => 'email',
        'provider' => 'sendgrid',
        'status' => 'sent',
        'sent_at' => now()->subMinutes(10),
        'metadata' => [],
    ]);

    $firstTimestamp = now()->timestamp;

    $this->postJson(route('marketing.webhooks.sendgrid-events'), [[
        'email' => $profile->email,
        'event' => 'open',
        'timestamp' => $firstTimestamp,
        'sg_message_id' => 'SG-BDAY-WEBHOOK.123',
        'custom_args' => [
            'marketing_email_delivery_id' => (string) $delivery->id,
        ],
    ]])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('matched', 1)
        ->assertJsonPath('updated', 1);

    $delivery->refresh();
    $event->refresh();

    $openedAt = optional($delivery->opened_at)?->toDateTimeString();

    expect((string) $delivery->status)->toBe('opened')
        ->and((string) $delivery->provider_message_id)->toBe('SG-BDAY-WEBHOOK')
        ->and($delivery->opened_at)->not->toBeNull()
        ->and((string) $event->status)->toBe('opened')
        ->and((string) $event->provider_message_id)->toBe('SG-BDAY-WEBHOOK')
        ->and($event->opened_at)->not->toBeNull()
        ->and((int) data_get($event->metadata, 'canonical_delivery_id'))->toBe((int) $delivery->id);

    $this->postJson(route('marketing.webhooks.sendgrid-events'), [[
        'email' => $profile->email,
        'event' => 'delivered',
        'timestamp' => $firstTimestamp - 3600,
        'sg_message_id' => 'SG-BDAY-WEBHOOK.123',
        'custom_args' => [
            'marketing_email_delivery_id' => (string) $delivery->id,
        ],
    ]])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('matched', 1)
        ->assertJsonPath('updated', 1);

    $delivery->refresh();
    $event->refresh();

    expect((string) $delivery->status)->toBe('opened')
        ->and(optional($delivery->opened_at)?->toDateTimeString())->toBe($openedAt)
        ->and((string) $event->status)->toBe('opened')
        ->and(optional($event->opened_at)?->toDateTimeString())->toBe($openedAt);
});

test('birthday analytics daily trend zero fills missing days and honors provider and template filters', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Trend Filters',
        'slug' => 'birthday-analytics-trend-filters',
    ]);

    [$profileOne, $birthdayOne] = createBirthdayProfile($tenant, 'Jordan', 'jordan@example.test');
    [$profileTwo, $birthdayTwo] = createBirthdayProfile($tenant, 'Quinn', 'quinn@example.test');

    $start = CarbonImmutable::parse('2026-03-01 09:00:00');
    $middle = CarbonImmutable::parse('2026-03-02 12:00:00');
    $end = CarbonImmutable::parse('2026-03-03 15:00:00');

    $issuanceOne = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayOne->id,
        'marketing_profile_id' => $profileOne->id,
        'cycle_year' => 2026,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-TREND-1',
        'issued_at' => $start,
    ]);
    setModelCreatedAt($issuanceOne, '2026-03-01 09:00:00');

    $issuanceTwo = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayTwo->id,
        'marketing_profile_id' => $profileTwo->id,
        'cycle_year' => 2026,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-TREND-2',
        'issued_at' => $middle,
    ]);
    setModelCreatedAt($issuanceTwo, '2026-03-02 10:00:00');

    $sendgridDelivery = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profileOne->id,
        'tenant_id' => $tenant->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-TREND-1',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profileOne->email,
        'status' => 'sent',
        'sent_at' => $start->addHour(),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuanceOne->id,
            'coupon_code' => 'BDAY-TREND-1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [],
    ]);
    setModelCreatedAt($sendgridDelivery, '2026-03-01 10:00:00');

    $otherDelivery = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profileTwo->id,
        'tenant_id' => $tenant->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-TREND-2',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_followup',
        'email' => $profileTwo->email,
        'status' => 'failed',
        'failed_at' => $end,
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuanceTwo->id,
            'coupon_code' => 'BDAY-TREND-2',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_followup',
            'error_code' => 'provider_down',
        ],
        'raw_payload' => [],
    ]);
    setModelCreatedAt($otherDelivery, '2026-03-03 10:00:00');

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-03',
        'provider' => 'sendgrid',
        'template_key' => 'birthday_email_primary',
    ]);

    $daily = collect(data_get($analytics, 'trend.daily', []))->keyBy('date');

    expect((array) data_get($analytics, 'trend.labels', []))->toHaveCount(3)
        ->and((int) data_get($analytics, 'metrics.birthday_emails_attempted'))->toBe(1)
        ->and((int) data_get($daily->get('2026-03-01'), 'birthday_emails_attempted', 0))->toBe(1)
        ->and((int) data_get($daily->get('2026-03-02'), 'birthday_emails_attempted', 0))->toBe(0)
        ->and((int) data_get($daily->get('2026-03-03'), 'birthday_emails_attempted', 0))->toBe(0)
        ->and((int) data_get($daily->get('2026-03-02'), 'birthday_emails_sent_successfully', 0))->toBe(0)
        ->and((int) data_get($daily->get('2026-03-02'), 'birthday_emails_failed', 0))->toBe(0);
});

test('birthday analytics daily trend buckets redemption revenue by redeemed_at date', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Trend Revenue',
        'slug' => 'birthday-analytics-trend-revenue',
    ]);

    [$profile, $birthday] = createBirthdayProfile($tenant, 'Reese', 'reese@example.test');

    $issuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => 2026,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'redeemed',
        'reward_code' => 'BDAY-REV-1',
        'issued_at' => CarbonImmutable::parse('2026-03-01 10:00:00'),
        'redeemed_at' => CarbonImmutable::parse('2026-03-03 19:00:00'),
        'attributed_revenue' => 75.35,
    ]);
    setModelCreatedAt($issuance, '2026-03-01 10:00:00');

    $delivery = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-REV-1',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profile->email,
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-03-01 10:30:00'),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuance->id,
            'coupon_code' => 'BDAY-REV-1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [],
    ]);
    setModelCreatedAt($delivery, '2026-03-01 10:00:00');

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-03',
    ]);

    $daily = collect(data_get($analytics, 'trend.daily', []))->keyBy('date');

    expect((float) data_get($daily->get('2026-03-01'), 'attributed_revenue', 0))->toBe(0.0)
        ->and((int) data_get($daily->get('2026-03-03'), 'coupons_redeemed', 0))->toBe(1)
        ->and((float) data_get($daily->get('2026-03-03'), 'attributed_revenue', 0))->toBe(75.35);
});

test('birthday analytics trend includes unsupported provider attempts in failed series', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Unsupported Trend',
        'slug' => 'birthday-analytics-unsupported-trend',
    ]);

    [$profile, $birthday] = createBirthdayProfile($tenant, 'Finley', 'finley@example.test');

    $issuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => 2026,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-UNSUP-1',
        'issued_at' => CarbonImmutable::parse('2026-03-02 10:00:00'),
    ]);
    setModelCreatedAt($issuance, '2026-03-02 10:00:00');

    $delivery = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'provider' => 'shopify_email',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profile->email,
        'status' => 'failed',
        'failed_at' => CarbonImmutable::parse('2026-03-02 11:00:00'),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuance->id,
            'coupon_code' => 'BDAY-UNSUP-1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
            'error_code' => 'unsupported_provider_action',
        ],
        'raw_payload' => [],
    ]);
    setModelCreatedAt($delivery, '2026-03-02 10:30:00');

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-03',
    ]);

    $daily = collect(data_get($analytics, 'trend.daily', []))->keyBy('date');

    expect((int) data_get($daily->get('2026-03-02'), 'birthday_emails_attempted', 0))->toBe(1)
        ->and((int) data_get($daily->get('2026-03-02'), 'birthday_emails_failed', 0))->toBe(1)
        ->and((int) data_get($analytics, 'trend.availability.unsupported_or_non_sendgrid_attempts', 0))->toBe(1)
        ->and((int) data_get(collect($analytics['status_breakdown'] ?? [])->firstWhere('status', 'unsupported'), 'count', 0))->toBe(1);
});

test('birthday analytics comparison groups by template and returns conservative leader when samples are sufficient', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Comparison Template',
        'slug' => 'birthday-analytics-comparison-template',
    ]);

    foreach (range(1, 12) as $index) {
        [$profile, $birthday] = createBirthdayProfile(
            $tenant,
            'KaiA' . $index,
            'kai-a-' . $index . '@example.test'
        );

        $issuance = BirthdayRewardIssuance::query()->create([
            'customer_birthday_profile_id' => $birthday->id,
            'marketing_profile_id' => $profile->id,
            'cycle_year' => (int) now()->year,
            'reward_type' => 'discount_code',
            'reward_name' => 'Birthday Reward',
            'status' => $index <= 6 ? 'redeemed' : 'issued',
            'reward_code' => 'BDAY-TPL-A-' . $index,
            'issued_at' => now()->subDay(),
            'redeemed_at' => $index <= 6 ? now() : null,
            'attributed_revenue' => $index <= 6 ? 20.00 : null,
        ]);

        MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'tenant_id' => $tenant->id,
            'provider' => 'sendgrid',
            'provider_message_id' => 'SG-TPL-A-' . $index,
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
            'email' => $profile->email,
            'status' => 'sent',
            'sent_at' => now()->subHours(2),
            'metadata' => [
                'birthday_reward_issuance_id' => (int) $issuance->id,
                'coupon_code' => 'BDAY-TPL-A-' . $index,
                'campaign_type' => 'birthday',
                'template_key' => 'birthday_email_primary',
            ],
            'raw_payload' => [],
        ]);
    }

    foreach (range(1, 12) as $index) {
        [$profile, $birthday] = createBirthdayProfile(
            $tenant,
            'KaiB' . $index,
            'kai-b-' . $index . '@example.test'
        );

        $issuance = BirthdayRewardIssuance::query()->create([
            'customer_birthday_profile_id' => $birthday->id,
            'marketing_profile_id' => $profile->id,
            'cycle_year' => (int) now()->year,
            'reward_type' => 'discount_code',
            'reward_name' => 'Birthday Reward',
            'status' => $index <= 2 ? 'redeemed' : 'issued',
            'reward_code' => 'BDAY-TPL-B-' . $index,
            'issued_at' => now()->subDay(),
            'redeemed_at' => $index <= 2 ? now() : null,
            'attributed_revenue' => $index <= 2 ? 15.00 : null,
        ]);

        MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'tenant_id' => $tenant->id,
            'provider' => 'sendgrid',
            'provider_message_id' => 'SG-TPL-B-' . $index,
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_followup',
            'email' => $profile->email,
            'status' => 'sent',
            'sent_at' => now()->subHours(2),
            'metadata' => [
                'birthday_reward_issuance_id' => (int) $issuance->id,
                'coupon_code' => 'BDAY-TPL-B-' . $index,
                'campaign_type' => 'birthday',
                'template_key' => 'birthday_email_followup',
            ],
            'raw_payload' => [],
        ]);
    }

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => now()->subDays(7)->toDateString(),
        'date_to' => now()->toDateString(),
        'comparison_mode' => 'template',
    ]);

    $rows = collect(data_get($analytics, 'comparison.rows', []))->keyBy('group_key');
    $primaryRow = (array) ($rows->get('birthday_email_primary') ?? []);
    $followupRow = (array) ($rows->get('birthday_email_followup') ?? []);

    expect((string) data_get($analytics, 'comparison.mode'))->toBe('template')
        ->and((int) data_get($primaryRow, 'birthday_emails_attempted'))->toBe(12)
        ->and((int) data_get($primaryRow, 'coupons_redeemed'))->toBe(6)
        ->and((float) data_get($primaryRow, 'redemption_rate'))->toBe(50.0)
        ->and((int) data_get($followupRow, 'birthday_emails_attempted'))->toBe(12)
        ->and((int) data_get($followupRow, 'coupons_redeemed'))->toBe(2)
        ->and((string) data_get($analytics, 'comparison.recommendation.status'))->toBe('ranked')
        ->and((string) data_get($analytics, 'comparison.recommendation.winner_group_key'))->toBe('birthday_email_primary');
});

test('birthday analytics comparison groups by provider and keeps unsupported attempts visible with low-data guardrails', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Comparison Provider',
        'slug' => 'birthday-analytics-comparison-provider',
    ]);

    foreach (range(1, 3) as $index) {
        [$profile, $birthday] = createBirthdayProfile(
            $tenant,
            'Nico' . $index,
            'nico-' . $index . '@example.test'
        );

        $issuance = BirthdayRewardIssuance::query()->create([
            'customer_birthday_profile_id' => $birthday->id,
            'marketing_profile_id' => $profile->id,
            'cycle_year' => (int) now()->year,
            'reward_type' => 'discount_code',
            'reward_name' => 'Birthday Reward',
            'status' => 'issued',
            'reward_code' => 'BDAY-PROV-SG-' . $index,
            'issued_at' => now()->subDay(),
        ]);

        MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'tenant_id' => $tenant->id,
            'provider' => 'sendgrid',
            'provider_message_id' => 'SG-PROV-' . $index,
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
            'email' => $profile->email,
            'status' => 'sent',
            'sent_at' => now()->subHour(),
            'metadata' => [
                'birthday_reward_issuance_id' => (int) $issuance->id,
                'coupon_code' => 'BDAY-PROV-SG-' . $index,
                'campaign_type' => 'birthday',
                'template_key' => 'birthday_email_primary',
            ],
            'raw_payload' => [],
        ]);
    }

    [$unsupportedProfile, $unsupportedBirthday] = createBirthdayProfile($tenant, 'NicoU', 'nico-u@example.test');
    $unsupportedIssuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $unsupportedBirthday->id,
        'marketing_profile_id' => $unsupportedProfile->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-PROV-UNSUPPORTED',
        'issued_at' => now()->subDay(),
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $unsupportedProfile->id,
        'tenant_id' => $tenant->id,
        'provider' => 'shopify_email',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $unsupportedProfile->email,
        'status' => 'failed',
        'failed_at' => now()->subMinutes(15),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $unsupportedIssuance->id,
            'coupon_code' => 'BDAY-PROV-UNSUPPORTED',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
            'error_code' => 'unsupported_provider_action',
        ],
        'raw_payload' => [],
    ]);

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => now()->subDays(7)->toDateString(),
        'date_to' => now()->toDateString(),
        'comparison_mode' => 'provider',
    ]);

    $rows = collect(data_get($analytics, 'comparison.rows', []))->keyBy('group_key');
    $unsupported = (array) ($rows->get('shopify_email') ?? []);

    expect((string) data_get($analytics, 'comparison.mode'))->toBe('provider')
        ->and((int) data_get($unsupported, 'birthday_emails_failed'))->toBe(1)
        ->and((int) data_get($unsupported, 'unsupported_count'))->toBe(1)
        ->and((bool) data_get($unsupported, 'low_sample_size'))->toBeTrue()
        ->and((string) data_get($analytics, 'comparison.recommendation.status'))->toBe('insufficient_data');
});

test('birthday analytics comparison remains tenant scoped', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Birthday Comparison Tenant A',
        'slug' => 'birthday-comparison-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Birthday Comparison Tenant B',
        'slug' => 'birthday-comparison-tenant-b',
    ]);

    [$profileA, $birthdayA] = createBirthdayProfile($tenantA, 'Ari', 'ari@tenant-a.test');
    [$profileB, $birthdayB] = createBirthdayProfile($tenantB, 'Rory', 'rory@tenant-b.test');

    $issuanceA = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayA->id,
        'marketing_profile_id' => $profileA->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-TENANT-A',
        'issued_at' => now()->subDay(),
    ]);

    BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayB->id,
        'marketing_profile_id' => $profileB->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-TENANT-B',
        'issued_at' => now()->subDay(),
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profileA->id,
        'tenant_id' => $tenantA->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-TENANT-A',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profileA->email,
        'status' => 'sent',
        'sent_at' => now()->subHour(),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuanceA->id,
            'coupon_code' => 'BDAY-TENANT-A',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profileB->id,
        'tenant_id' => $tenantB->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-TENANT-B',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_followup',
        'email' => $profileB->email,
        'status' => 'sent',
        'sent_at' => now()->subHour(),
        'metadata' => [
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_followup',
        ],
        'raw_payload' => [],
    ]);

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenantA->id,
        'date_from' => now()->subDays(7)->toDateString(),
        'date_to' => now()->toDateString(),
        'comparison_mode' => 'template',
    ]);

    $groupKeys = collect(data_get($analytics, 'comparison.rows', []))
        ->pluck('group_key')
        ->all();

    expect($groupKeys)->toContain('birthday_email_primary')
        ->and($groupKeys)->not->toContain('birthday_email_followup');
});

test('birthday analytics period comparison calculates prior equal-length range and directional deltas', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Period Delta',
        'slug' => 'birthday-analytics-period-delta',
    ]);

    foreach (range(1, 12) as $index) {
        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'curr-' . $index,
            deliveryCreatedAt: '2026-03-06 10:00:00',
            deliveryStatus: 'sent',
            provider: 'sendgrid',
            templateKey: 'birthday_email_primary',
            redeemed: $index <= 6,
            attributedRevenue: 25.00
        );

        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'prev-' . $index,
            deliveryCreatedAt: '2026-03-03 10:00:00',
            deliveryStatus: 'sent',
            provider: 'sendgrid',
            templateKey: 'birthday_email_primary',
            redeemed: $index <= 3,
            attributedRevenue: 20.00
        );
    }

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => '2026-03-05',
        'date_to' => '2026-03-07',
        'comparison_mode' => 'period',
    ]);

    $deltas = (array) data_get($analytics, 'comparison.metric_deltas', []);

    expect((string) data_get($analytics, 'comparison.mode'))->toBe('period')
        ->and((string) data_get($analytics, 'filters.period_view'))->toBe('raw')
        ->and((string) data_get($analytics, 'comparison.view_mode'))->toBe('raw')
        ->and((string) data_get($analytics, 'comparison.current_period.date_from'))->toBe('2026-03-05')
        ->and((string) data_get($analytics, 'comparison.current_period.date_to'))->toBe('2026-03-07')
        ->and((string) data_get($analytics, 'comparison.prior_period.date_from'))->toBe('2026-03-02')
        ->and((string) data_get($analytics, 'comparison.prior_period.date_to'))->toBe('2026-03-04')
        ->and((string) data_get($analytics, 'comparison.period_resolution_mode'))->toBe('auto_prior_period')
        ->and((bool) data_get($analytics, 'comparison.custom_range_override', true))->toBeFalse()
        ->and((bool) data_get($analytics, 'comparison.range_diagnostics.range_length_mismatch', true))->toBeFalse()
        ->and((float) data_get($deltas, 'redemption_rate.absolute_delta', 0))->toBe(25.0)
        ->and((string) data_get($analytics, 'comparison.recommendation.status'))->toBe('up');
});

test('birthday analytics period comparison supports custom comparison range override and surfaces mismatch diagnostics', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Period Custom Override',
        'slug' => 'birthday-analytics-period-custom-override',
    ]);

    foreach (range(1, 12) as $index) {
        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'cust-cur-' . $index,
            deliveryCreatedAt: '2026-03-06 10:00:00',
            deliveryStatus: 'sent',
            provider: 'sendgrid',
            templateKey: 'birthday_email_primary',
            redeemed: $index <= 6,
            attributedRevenue: 25.00
        );
        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'cust-prior-' . $index,
            deliveryCreatedAt: '2026-02-20 10:00:00',
            deliveryStatus: 'sent',
            provider: 'sendgrid',
            templateKey: 'birthday_email_primary',
            redeemed: $index <= 4,
            attributedRevenue: 22.00
        );
    }

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => '2026-03-05',
        'date_to' => '2026-03-07',
        'comparison_mode' => 'period',
        'compare_from' => '2026-02-19',
        'compare_to' => '2026-02-21',
    ]);

    expect((string) data_get($analytics, 'comparison.period_resolution_mode'))->toBe('custom_comparison_period')
        ->and((bool) data_get($analytics, 'comparison.custom_range_override', false))->toBeTrue()
        ->and((string) data_get($analytics, 'comparison.comparison_period.date_from'))->toBe('2026-02-19')
        ->and((int) data_get($analytics, 'comparison.range_diagnostics.current_period_days', 0))->toBe(3)
        ->and((int) data_get($analytics, 'comparison.range_diagnostics.comparison_period_days', 0))->toBe(3)
        ->and((bool) data_get($analytics, 'comparison.range_diagnostics.range_length_mismatch', true))->toBeFalse();

    $mismatch = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => '2026-03-05',
        'date_to' => '2026-03-07',
        'comparison_mode' => 'period',
        'compare_from' => '2026-02-16',
        'compare_to' => '2026-02-21',
    ]);

    expect((bool) data_get($mismatch, 'comparison.range_diagnostics.range_length_mismatch', false))->toBeTrue()
        ->and((string) data_get($mismatch, 'comparison.recommendation.status'))->toBe('insufficient_data')
        ->and((array) data_get($mismatch, 'comparison.notes', []))->not->toBeEmpty();
});

test('birthday analytics period per-day view normalizes eligible metrics and keeps ratio metrics unchanged', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Period Per Day',
        'slug' => 'birthday-analytics-period-per-day',
    ]);

    foreach (range(1, 12) as $index) {
        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'per-day-cur-' . $index,
            deliveryCreatedAt: '2026-03-06 10:00:00',
            deliveryStatus: 'sent',
            provider: 'sendgrid',
            templateKey: 'birthday_email_primary',
            redeemed: $index <= 6,
            attributedRevenue: 24.00
        );
        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'per-day-prior-' . $index,
            deliveryCreatedAt: '2026-02-18 10:00:00',
            deliveryStatus: 'sent',
            provider: 'sendgrid',
            templateKey: 'birthday_email_primary',
            redeemed: $index <= 4,
            attributedRevenue: 20.00
        );
    }

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => '2026-03-05',
        'date_to' => '2026-03-07',
        'comparison_mode' => 'period',
        'period_view' => 'per_day',
        'compare_from' => '2026-02-16',
        'compare_to' => '2026-02-21',
    ]);

    $normalizedRows = collect((array) data_get($analytics, 'comparison.summary_rows_normalized', []))->keyBy('key');

    expect((string) data_get($analytics, 'comparison.view_mode'))->toBe('per_day')
        ->and((string) data_get($analytics, 'comparison.period_resolution_mode'))->toBe('custom_comparison_period')
        ->and((bool) data_get($analytics, 'comparison.range_diagnostics.range_length_mismatch', false))->toBeTrue()
        ->and((float) data_get($analytics, 'comparison.current_period.normalized_metrics.birthday_emails_attempted', 0))->toBe(4.0)
        ->and((float) data_get($analytics, 'comparison.prior_period.normalized_metrics.birthday_emails_attempted', 0))->toBe(2.0)
        ->and((float) data_get($analytics, 'comparison.metric_deltas.birthday_emails_attempted.absolute_delta', 0))->toBe(0.0)
        ->and((float) data_get($analytics, 'comparison.metric_deltas_normalized.birthday_emails_attempted.absolute_delta', 0))->toBe(2.0)
        ->and((float) data_get($analytics, 'comparison.metric_deltas_normalized.birthday_emails_attempted.percent_delta', 0))->toBe(100.0)
        ->and((bool) data_get($analytics, 'comparison.metric_deltas_normalized.birthday_emails_attempted.normalized_per_day', false))->toBeTrue()
        ->and((bool) data_get($analytics, 'comparison.metric_deltas_normalized.redemption_rate.normalized_per_day', true))->toBeFalse()
        ->and((bool) data_get($analytics, 'comparison.metric_deltas_normalized.redemption_rate.ratio_metric_not_re_normalized', false))->toBeTrue()
        ->and((float) data_get($analytics, 'comparison.metric_deltas_normalized.redemption_rate.current_value', 0))->toBe(
            (float) data_get($analytics, 'comparison.metric_deltas.redemption_rate.current_value', 0)
        )
        ->and((float) data_get($normalizedRows->get('attributed_revenue') ?? [], 'current_value', 0))->toBe(48.0)
        ->and((array) data_get($analytics, 'comparison.normalization_notes', []))->not->toBeEmpty();
});

test('birthday analytics period normalized helper fails safely when period days are invalid', function () {
    $service = app(BirthdayReportingService::class);
    $method = new ReflectionMethod(BirthdayReportingService::class, 'normalizedPerDayValue');
    $method->setAccessible(true);

    expect($method->invoke($service, 12.0, 0))->toBeNull()
        ->and($method->invoke($service, 12.0, -2))->toBeNull()
        ->and($method->invoke($service, 12.0, 3))->toBe(4.0);
});

test('birthday analytics period comparison applies provider and template filters to both periods', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Period Filters',
        'slug' => 'birthday-analytics-period-filters',
    ]);

    foreach (range(1, 12) as $index) {
        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'flt-cur-match-' . $index,
            deliveryCreatedAt: '2026-03-06 10:00:00',
            deliveryStatus: 'sent',
            provider: 'sendgrid',
            templateKey: 'birthday_email_primary',
            redeemed: false
        );

        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'flt-prv-match-' . $index,
            deliveryCreatedAt: '2026-03-03 10:00:00',
            deliveryStatus: 'sent',
            provider: 'sendgrid',
            templateKey: 'birthday_email_primary',
            redeemed: false
        );

        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'flt-cur-other-' . $index,
            deliveryCreatedAt: '2026-03-06 11:00:00',
            deliveryStatus: 'sent',
            provider: 'shopify_email',
            templateKey: 'birthday_email_followup',
            redeemed: false
        );
    }

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => '2026-03-05',
        'date_to' => '2026-03-07',
        'comparison_mode' => 'period',
        'provider' => 'sendgrid',
        'template_key' => 'birthday_email_primary',
        'status' => 'sent',
    ]);

    expect((int) data_get($analytics, 'comparison.current_period.metrics.birthday_emails_attempted', 0))->toBe(12)
        ->and((int) data_get($analytics, 'comparison.prior_period.metrics.birthday_emails_attempted', 0))->toBe(12)
        ->and((int) data_get($analytics, 'comparison.current_period.unsupported_attempts', 0))->toBe(0);
});

test('birthday analytics period comparison returns insufficient baseline when prior period is empty', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Period Baseline',
        'slug' => 'birthday-analytics-period-baseline',
    ]);

    foreach (range(1, 12) as $index) {
        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'base-cur-' . $index,
            deliveryCreatedAt: '2026-03-06 10:00:00',
            deliveryStatus: 'sent',
            provider: 'sendgrid',
            templateKey: 'birthday_email_primary',
            redeemed: $index <= 4,
            attributedRevenue: 30.00
        );
    }

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => '2026-03-05',
        'date_to' => '2026-03-07',
        'comparison_mode' => 'period',
    ]);

    expect((string) data_get($analytics, 'comparison.recommendation.status'))->toBe('insufficient_data')
        ->and((bool) data_get($analytics, 'comparison.metric_deltas.birthday_emails_attempted.insufficient_baseline', false))->toBeTrue()
        ->and(data_get($analytics, 'comparison.metric_deltas.birthday_emails_attempted.percent_delta'))->toBeNull();
});

test('birthday analytics period comparison keeps unsupported attempts visible in period snapshots', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Analytics Period Unsupported',
        'slug' => 'birthday-analytics-period-unsupported',
    ]);

    foreach (range(1, 10) as $index) {
        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'uns-cur-sg-' . $index,
            deliveryCreatedAt: '2026-03-06 10:00:00',
            deliveryStatus: 'sent',
            provider: 'sendgrid',
            templateKey: 'birthday_email_primary',
            redeemed: false
        );
        seedBirthdayPeriodDelivery(
            tenant: $tenant,
            seedKey: 'uns-prv-sg-' . $index,
            deliveryCreatedAt: '2026-03-03 10:00:00',
            deliveryStatus: 'sent',
            provider: 'sendgrid',
            templateKey: 'birthday_email_primary',
            redeemed: false
        );
    }

    seedBirthdayPeriodDelivery(
        tenant: $tenant,
        seedKey: 'uns-cur-unsupported',
        deliveryCreatedAt: '2026-03-06 12:00:00',
        deliveryStatus: 'failed',
        provider: 'shopify_email',
        templateKey: 'birthday_email_primary',
        redeemed: false,
        attributedRevenue: 0.0,
        unsupported: true
    );

    $analytics = app(BirthdayReportingService::class)->birthdayAnalytics([
        'tenant_id' => $tenant->id,
        'date_from' => '2026-03-05',
        'date_to' => '2026-03-07',
        'comparison_mode' => 'period',
    ]);

    expect((int) data_get($analytics, 'comparison.current_period.unsupported_attempts', 0))->toBe(1)
        ->and((array) data_get($analytics, 'comparison.notes', []))->not->toBeEmpty();
});
