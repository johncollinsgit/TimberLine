<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\Tenant;

function retailBirthdayAnalyticsApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

/**
 * @return array<int,array<string,string>>
 */
function parseBirthdayAnalyticsCsv(string $csv): array
{
    $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
    if ($lines === [] || count($lines) < 1) {
        return [];
    }

    $columns = str_getcsv((string) array_shift($lines));
    if ($columns === false || $columns === []) {
        return [];
    }

    $rows = [];
    foreach ($lines as $line) {
        if (trim((string) $line) === '') {
            continue;
        }

        $values = str_getcsv((string) $line);
        if ($values === false) {
            continue;
        }

        $combined = array_combine($columns, array_pad($values, count($columns), ''));
        if (is_array($combined)) {
            $rows[] = $combined;
        }
    }

    return $rows;
}

beforeEach(function () {
    $this->withoutVite();
    configureEmbeddedRetailStore();
});

test('shopify embedded birthday analytics api requires bearer token auth', function () {
    $this->getJson(route('shopify.app.api.rewards.birthdays.analytics'))
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth');
});

test('shopify embedded birthday analytics api returns tenant mapped analytics data', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics',
        'slug' => 'retail-birthday-analytics',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Avery',
        'email' => 'avery@embedded.test',
        'normalized_email' => 'avery@embedded.test',
        'accepts_email_marketing' => true,
    ]);

    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) now()->month,
        'birth_day' => (int) now()->day,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    $issuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'redeemed',
        'reward_code' => 'BDAY-EMBED-1',
        'issued_at' => now()->subDay(),
        'redeemed_at' => now(),
        'attributed_revenue' => 64.25,
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-EMBED-1',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profile->email,
        'status' => 'clicked',
        'sent_at' => now()->subHours(2),
        'delivered_at' => now()->subHours(2),
        'opened_at' => now()->subHours(2),
        'clicked_at' => now()->subHours(1),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuance->id,
            'coupon_code' => 'BDAY-EMBED-1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [],
    ]);

    $response = $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.birthdays.analytics', [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
            'provider' => 'sendgrid',
            'template_key' => 'birthday_email_primary',
            'status' => 'clicked',
        ], false));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.filters.tenant_id', $tenant->id)
        ->assertJsonPath('data.filters.comparison_mode', 'template')
        ->assertJsonPath('data.metrics.birthday_emails_attempted', 1)
        ->assertJsonPath('data.metrics.birthday_emails_sent_successfully', 1)
        ->assertJsonPath('data.metrics.coupons_redeemed', 1)
        ->assertJsonPath('data.metrics.attributed_revenue', 64.25)
        ->assertJsonPath('data.trend.bucket', 'day')
        ->assertJsonPath('data.comparison.mode', 'template');

    expect((array) data_get($response->json(), 'data.trend.daily', []))->not->toBeEmpty();
});

test('shopify embedded birthday analytics api returns tenant_not_mapped when store has no tenant mapping', function () {
    configureEmbeddedRetailStore(null);

    $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.birthdays.analytics', [], false))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'tenant_not_mapped');
});

test('shopify embedded birthday analytics api validates date range inputs', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Date Validation',
        'slug' => 'retail-birthday-analytics-date-validation',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.birthdays.analytics', [
            'date_from' => now()->subDays(500)->toDateString(),
            'date_to' => now()->toDateString(),
        ], false))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('errors.date_range.0', 'Date range must be 366 days or less.');
});

test('shopify embedded birthday analytics api validates comparison mode inputs', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Comparison Validation',
        'slug' => 'retail-birthday-analytics-comparison-validation',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.birthdays.analytics', [
            'comparison_mode' => 'bad_mode',
        ], false))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('errors.comparison_mode.0', 'The selected comparison mode is invalid.');
});

test('shopify embedded birthday analytics api validates period_view inputs', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Period View Validation',
        'slug' => 'retail-birthday-analytics-period-view-validation',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.birthdays.analytics', [
            'comparison_mode' => 'period',
            'period_view' => 'weekly',
        ], false))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('errors.period_view.0', 'The selected period view is invalid.');
});

test('shopify embedded birthday analytics api requires period_view to be used with period mode', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Period View Mode Validation',
        'slug' => 'retail-birthday-analytics-period-view-mode-validation',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.birthdays.analytics', [
            'comparison_mode' => 'template',
            'period_view' => 'per_day',
        ], false))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('errors.period_view.0', 'Period view is only supported when comparison_mode is period.');
});

test('shopify embedded birthday analytics api validates custom compare range requires both dates', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Compare Pair Validation',
        'slug' => 'retail-birthday-analytics-compare-pair-validation',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.birthdays.analytics', [
            'comparison_mode' => 'period',
            'compare_from' => '2026-02-01',
        ], false))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('errors.compare_range.0', 'Both compare_from and compare_to are required when using a custom comparison range.');
});

test('shopify embedded birthday analytics api validates custom compare range ordering', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Compare Order Validation',
        'slug' => 'retail-birthday-analytics-compare-order-validation',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.birthdays.analytics', [
            'comparison_mode' => 'period',
            'compare_from' => '2026-02-10',
            'compare_to' => '2026-02-01',
        ], false))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('errors.compare_from.0', 'Compare start date must be on or before compare end date.');
});

test('shopify embedded birthday analytics export requires bearer token auth', function () {
    $this->get(route('shopify.app.api.rewards.birthdays.analytics.export', [], false))
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth');
});

test('shopify embedded birthday analytics api supports provider comparison mode', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Provider Comparison',
        'slug' => 'retail-birthday-analytics-provider-comparison',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Harper',
        'email' => 'harper@embedded.test',
        'normalized_email' => 'harper@embedded.test',
        'accepts_email_marketing' => true,
    ]);

    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) now()->month,
        'birth_day' => (int) now()->day,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    $issuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-CMP-1',
        'issued_at' => now()->subDay(),
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-CMP-1',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profile->email,
        'status' => 'sent',
        'sent_at' => now()->subHours(2),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuance->id,
            'coupon_code' => 'BDAY-CMP-1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'provider' => 'shopify_email',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profile->email,
        'status' => 'failed',
        'failed_at' => now()->subHour(),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuance->id,
            'coupon_code' => 'BDAY-CMP-1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
            'error_code' => 'unsupported_provider_action',
        ],
        'raw_payload' => [],
    ]);

    $response = $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.birthdays.analytics', [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
            'comparison_mode' => 'provider',
        ], false));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.filters.comparison_mode', 'provider')
        ->assertJsonPath('data.comparison.mode', 'provider');

    $comparisonRows = collect(data_get($response->json(), 'data.comparison.rows', []))->keyBy('group_key');
    expect((int) data_get($comparisonRows->get('shopify_email') ?? [], 'unsupported_count', 0))->toBe(1);
});

test('shopify embedded birthday analytics api supports period comparison mode with prior range labels', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Period Comparison',
        'slug' => 'retail-birthday-analytics-period-comparison',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    foreach (range(1, 10) as $index) {
        $profile = MarketingProfile::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'PeriodCurrent' . $index,
            'email' => 'period-current-' . $index . '@embedded.test',
            'normalized_email' => 'period-current-' . $index . '@embedded.test',
            'accepts_email_marketing' => true,
        ]);
        $birthday = CustomerBirthdayProfile::query()->create([
            'marketing_profile_id' => $profile->id,
            'birth_month' => 3,
            'birth_day' => 6,
            'source' => 'test',
            'source_captured_at' => now(),
        ]);
        $issuance = BirthdayRewardIssuance::query()->create([
            'customer_birthday_profile_id' => $birthday->id,
            'marketing_profile_id' => $profile->id,
            'cycle_year' => 2026,
            'reward_type' => 'discount_code',
            'reward_name' => 'Birthday Reward',
            'status' => 'issued',
            'reward_code' => 'BDAY-PC-' . $index,
            'issued_at' => '2026-03-06 10:00:00',
        ]);
        $issuance->forceFill([
            'created_at' => '2026-03-06 10:00:00',
            'updated_at' => '2026-03-06 10:00:00',
        ])->save();

        $delivery = MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'tenant_id' => $tenant->id,
            'provider' => 'sendgrid',
            'provider_message_id' => 'SG-PC-' . $index,
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
            'email' => $profile->email,
            'status' => 'sent',
            'sent_at' => '2026-03-06 11:00:00',
            'metadata' => [
                'birthday_reward_issuance_id' => (int) $issuance->id,
                'coupon_code' => 'BDAY-PC-' . $index,
                'campaign_type' => 'birthday',
                'template_key' => 'birthday_email_primary',
            ],
            'raw_payload' => [],
        ]);
        $delivery->forceFill([
            'created_at' => '2026-03-06 10:00:00',
            'updated_at' => '2026-03-06 10:00:00',
        ])->save();
    }

    $response = $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.birthdays.analytics', [
            'date_from' => '2026-03-05',
            'date_to' => '2026-03-07',
            'comparison_mode' => 'period',
        ], false));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.filters.comparison_mode', 'period')
        ->assertJsonPath('data.comparison.mode', 'period')
        ->assertJsonPath('data.comparison.current_period.date_from', '2026-03-05')
        ->assertJsonPath('data.comparison.prior_period.date_to', '2026-03-04')
        ->assertJsonPath('data.comparison.period_resolution_mode', 'auto_prior_period');
});

test('shopify embedded birthday analytics api supports custom period comparison override', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Period Override',
        'slug' => 'retail-birthday-analytics-period-override',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    foreach (range(1, 10) as $index) {
        $profile = MarketingProfile::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'PeriodOverrideCurrent' . $index,
            'email' => 'period-override-current-' . $index . '@embedded.test',
            'normalized_email' => 'period-override-current-' . $index . '@embedded.test',
            'accepts_email_marketing' => true,
        ]);
        $birthday = CustomerBirthdayProfile::query()->create([
            'marketing_profile_id' => $profile->id,
            'birth_month' => 3,
            'birth_day' => 6,
            'source' => 'test',
            'source_captured_at' => now(),
        ]);

        $currentIssuance = BirthdayRewardIssuance::query()->create([
            'customer_birthday_profile_id' => $birthday->id,
            'marketing_profile_id' => $profile->id,
            'cycle_year' => 2026,
            'reward_type' => 'discount_code',
            'reward_name' => 'Birthday Reward',
            'status' => 'issued',
            'reward_code' => 'BDAY-OV-C-' . $index,
            'issued_at' => '2026-03-06 10:00:00',
        ]);
        $currentIssuance->forceFill([
            'created_at' => '2026-03-06 10:00:00',
            'updated_at' => '2026-03-06 10:00:00',
        ])->save();

        $currentDelivery = MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'tenant_id' => $tenant->id,
            'provider' => 'sendgrid',
            'provider_message_id' => 'SG-OV-C-' . $index,
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
            'email' => $profile->email,
            'status' => 'sent',
            'sent_at' => '2026-03-06 11:00:00',
            'metadata' => [
                'birthday_reward_issuance_id' => (int) $currentIssuance->id,
                'coupon_code' => 'BDAY-OV-C-' . $index,
                'campaign_type' => 'birthday',
                'template_key' => 'birthday_email_primary',
            ],
            'raw_payload' => [],
        ]);
        $currentDelivery->forceFill([
            'created_at' => '2026-03-06 10:00:00',
            'updated_at' => '2026-03-06 10:00:00',
        ])->save();

        $comparisonIssuance = BirthdayRewardIssuance::query()->create([
            'customer_birthday_profile_id' => $birthday->id,
            'marketing_profile_id' => $profile->id,
            'cycle_year' => 2025,
            'reward_type' => 'discount_code',
            'reward_name' => 'Birthday Reward',
            'status' => 'issued',
            'reward_code' => 'BDAY-OV-P-' . $index,
            'issued_at' => '2025-12-15 10:00:00',
        ]);
        $comparisonIssuance->forceFill([
            'created_at' => '2025-12-15 10:00:00',
            'updated_at' => '2025-12-15 10:00:00',
        ])->save();

        $comparisonDelivery = MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'tenant_id' => $tenant->id,
            'provider' => 'sendgrid',
            'provider_message_id' => 'SG-OV-P-' . $index,
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
            'email' => $profile->email,
            'status' => 'sent',
            'sent_at' => '2025-12-15 11:00:00',
            'metadata' => [
                'birthday_reward_issuance_id' => (int) $comparisonIssuance->id,
                'coupon_code' => 'BDAY-OV-P-' . $index,
                'campaign_type' => 'birthday',
                'template_key' => 'birthday_email_primary',
            ],
            'raw_payload' => [],
        ]);
        $comparisonDelivery->forceFill([
            'created_at' => '2025-12-15 10:00:00',
            'updated_at' => '2025-12-15 10:00:00',
        ])->save();
    }

    $response = $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.birthdays.analytics', [
            'date_from' => '2026-03-05',
            'date_to' => '2026-03-07',
            'comparison_mode' => 'period',
            'period_view' => 'per_day',
            'compare_from' => '2025-12-14',
            'compare_to' => '2025-12-16',
        ], false));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.filters.period_view', 'per_day')
        ->assertJsonPath('data.filters.compare_from', '2025-12-14')
        ->assertJsonPath('data.filters.compare_to', '2025-12-16')
        ->assertJsonPath('data.comparison.view_mode', 'per_day')
        ->assertJsonPath('data.comparison.period_resolution_mode', 'custom_comparison_period')
        ->assertJsonPath('data.comparison.custom_range_override', true)
        ->assertJsonPath('data.comparison.comparison_period.date_from', '2025-12-14');
});

test('shopify embedded birthday analytics export returns csv that matches active filters', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Export',
        'slug' => 'retail-birthday-analytics-export',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Dakota',
        'email' => 'dakota@embedded.test',
        'normalized_email' => 'dakota@embedded.test',
        'accepts_email_marketing' => true,
    ]);

    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) now()->month,
        'birth_day' => (int) now()->day,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    $issuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-EXPORT-1',
        'issued_at' => now()->subDay(),
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-EXPORT-1',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profile->email,
        'status' => 'sent',
        'sent_at' => now()->subHours(2),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuance->id,
            'coupon_code' => 'BDAY-EXPORT-1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'provider' => 'shopify_email',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profile->email,
        'status' => 'failed',
        'failed_at' => now()->subHour(),
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $issuance->id,
            'coupon_code' => 'BDAY-EXPORT-1',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
            'error_code' => 'unsupported_provider_action',
        ],
        'raw_payload' => [],
    ]);

    $response = $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->get(route('shopify.app.api.rewards.birthdays.analytics.export', [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
            'provider' => 'sendgrid',
            'template_key' => 'birthday_email_primary',
            'comparison_mode' => 'provider',
        ], false));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $rows = collect(parseBirthdayAnalyticsCsv($response->streamedContent()));

    expect((int) $rows->where('record_type', 'daily')->count())->toBeGreaterThan(0)
        ->and((string) data_get($rows->firstWhere('record_type', 'filter') ?? [], 'record_type'))->toBe('filter')
        ->and((string) data_get($rows->firstWhere('key', 'provider') ?? [], 'value'))->toBe('sendgrid')
        ->and((string) data_get($rows->firstWhere('key', 'template_key') ?? [], 'value'))->toBe('birthday_email_primary')
        ->and((string) data_get($rows->firstWhere('key', 'comparison_mode') ?? [], 'value'))->toBe('provider')
        ->and((int) $rows->where('record_type', 'comparison_row')->count())->toBeGreaterThan(0)
        ->and((string) data_get($rows->firstWhere('record_type', 'summary_metric') ?? [], 'record_type'))->toBe('summary_metric')
        ->and((string) data_get($rows->firstWhere('provider', 'shopify_email') ?? [], 'record_type', ''))->toBe('');
});

test('shopify embedded birthday analytics export returns zero-filled daily rows for empty datasets', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Export Empty',
        'slug' => 'retail-birthday-analytics-export-empty',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $dateFrom = now()->subDays(2)->toDateString();
    $dateTo = now()->toDateString();

    $response = $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->get(route('shopify.app.api.rewards.birthdays.analytics.export', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ], false));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $rows = collect(parseBirthdayAnalyticsCsv($response->streamedContent()));
    $dailyRows = $rows->where('record_type', 'daily')->values();
    $metricAttempted = $rows->firstWhere('key', 'birthday_emails_attempted');
    $comparisonRecommendation = $rows->firstWhere('record_type', 'comparison_recommendation');

    expect((int) $dailyRows->count())->toBe(3)
        ->and((int) data_get($dailyRows->first(), 'birthday_emails_attempted', 999))->toBe(0)
        ->and((int) data_get($dailyRows->first(), 'rewards_issued', 999))->toBe(0)
        ->and((string) data_get($metricAttempted, 'value'))->toBe('0')
        ->and((string) data_get($comparisonRecommendation, 'recommendation_status'))->toBe('insufficient_data');
});

test('shopify embedded birthday analytics export includes period comparison rows when requested', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Export Period',
        'slug' => 'retail-birthday-analytics-export-period',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    foreach (range(1, 10) as $index) {
        $profile = MarketingProfile::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'PeriodExport' . $index,
            'email' => 'period-export-' . $index . '@embedded.test',
            'normalized_email' => 'period-export-' . $index . '@embedded.test',
            'accepts_email_marketing' => true,
        ]);
        $birthday = CustomerBirthdayProfile::query()->create([
            'marketing_profile_id' => $profile->id,
            'birth_month' => 3,
            'birth_day' => 6,
            'source' => 'test',
            'source_captured_at' => now(),
        ]);
        $issuance = BirthdayRewardIssuance::query()->create([
            'customer_birthday_profile_id' => $birthday->id,
            'marketing_profile_id' => $profile->id,
            'cycle_year' => 2026,
            'reward_type' => 'discount_code',
            'reward_name' => 'Birthday Reward',
            'status' => 'issued',
            'reward_code' => 'BDAY-PE-' . $index,
            'issued_at' => '2026-03-06 10:00:00',
        ]);
        $issuance->forceFill([
            'created_at' => '2026-03-06 10:00:00',
            'updated_at' => '2026-03-06 10:00:00',
        ])->save();

        $delivery = MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'tenant_id' => $tenant->id,
            'provider' => 'sendgrid',
            'provider_message_id' => 'SG-PE-' . $index,
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
            'email' => $profile->email,
            'status' => 'sent',
            'sent_at' => '2026-03-06 11:00:00',
            'metadata' => [
                'birthday_reward_issuance_id' => (int) $issuance->id,
                'coupon_code' => 'BDAY-PE-' . $index,
                'campaign_type' => 'birthday',
                'template_key' => 'birthday_email_primary',
            ],
            'raw_payload' => [],
        ]);
        $delivery->forceFill([
            'created_at' => '2026-03-06 10:00:00',
            'updated_at' => '2026-03-06 10:00:00',
        ])->save();
    }

    $response = $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->get(route('shopify.app.api.rewards.birthdays.analytics.export', [
            'date_from' => '2026-03-05',
            'date_to' => '2026-03-07',
            'comparison_mode' => 'period',
            'period_view' => 'per_day',
            'compare_from' => '2026-03-01',
            'compare_to' => '2026-03-03',
        ], false));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $rows = collect(parseBirthdayAnalyticsCsv($response->streamedContent()));

    expect((string) data_get($rows->firstWhere('key', 'comparison_mode') ?? [], 'value'))->toBe('period')
        ->and((string) data_get($rows->firstWhere('key', 'period_view') ?? [], 'value'))->toBe('per_day')
        ->and((string) data_get($rows->firstWhere('key', 'compare_from') ?? [], 'value'))->toBe('2026-03-01')
        ->and((string) data_get($rows->firstWhere('key', 'compare_to') ?? [], 'value'))->toBe('2026-03-03')
        ->and((string) data_get($rows->firstWhere('key', 'period_resolution_mode') ?? [], 'value'))->toBe('custom_comparison_period')
        ->and((int) $rows->where('record_type', 'comparison_period_metric')->count())->toBeGreaterThan(0)
        ->and((int) $rows->where('record_type', 'comparison_delta')->count())->toBeGreaterThan(0)
        ->and((int) $rows->where('record_type', 'comparison_period_metric_normalized')->count())->toBeGreaterThan(0)
        ->and((int) $rows->where('record_type', 'comparison_delta_normalized')->count())->toBeGreaterThan(0);
});

test('shopify embedded birthday analytics export keeps raw period mode behavior when period_view is omitted', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Birthday Analytics Export Period Raw',
        'slug' => 'retail-birthday-analytics-export-period-raw',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'PeriodRaw',
        'email' => 'period-raw@embedded.test',
        'normalized_email' => 'period-raw@embedded.test',
        'accepts_email_marketing' => true,
    ]);
    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => 3,
        'birth_day' => 6,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    $currentIssuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => 2026,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-PR-CURRENT',
        'issued_at' => '2026-03-06 10:00:00',
    ]);
    $currentIssuance->forceFill([
        'created_at' => '2026-03-06 10:00:00',
        'updated_at' => '2026-03-06 10:00:00',
    ])->save();

    $currentDelivery = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-PR-CURRENT',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profile->email,
        'status' => 'sent',
        'sent_at' => '2026-03-06 11:00:00',
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $currentIssuance->id,
            'coupon_code' => 'BDAY-PR-CURRENT',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [],
    ]);
    $currentDelivery->forceFill([
        'created_at' => '2026-03-06 10:00:00',
        'updated_at' => '2026-03-06 10:00:00',
    ])->save();

    $priorIssuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => 2025,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_code' => 'BDAY-PR-PRIOR',
        'issued_at' => '2026-03-03 10:00:00',
    ]);
    $priorIssuance->forceFill([
        'created_at' => '2026-03-03 10:00:00',
        'updated_at' => '2026-03-03 10:00:00',
    ])->save();

    $priorDelivery = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'provider' => 'sendgrid',
        'provider_message_id' => 'SG-PR-PRIOR',
        'campaign_type' => 'birthday',
        'template_key' => 'birthday_email_primary',
        'email' => $profile->email,
        'status' => 'sent',
        'sent_at' => '2026-03-03 11:00:00',
        'metadata' => [
            'birthday_reward_issuance_id' => (int) $priorIssuance->id,
            'coupon_code' => 'BDAY-PR-PRIOR',
            'campaign_type' => 'birthday',
            'template_key' => 'birthday_email_primary',
        ],
        'raw_payload' => [],
    ]);
    $priorDelivery->forceFill([
        'created_at' => '2026-03-03 10:00:00',
        'updated_at' => '2026-03-03 10:00:00',
    ])->save();

    $response = $this
        ->withHeaders(retailBirthdayAnalyticsApiHeaders())
        ->get(route('shopify.app.api.rewards.birthdays.analytics.export', [
            'date_from' => '2026-03-05',
            'date_to' => '2026-03-07',
            'comparison_mode' => 'period',
        ], false));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $rows = collect(parseBirthdayAnalyticsCsv($response->streamedContent()));

    expect((string) data_get($rows->firstWhere('key', 'period_view') ?? [], 'value'))->toBe('raw')
        ->and((int) $rows->where('record_type', 'comparison_period_metric')->count())->toBeGreaterThan(0)
        ->and((int) $rows->where('record_type', 'comparison_delta')->count())->toBeGreaterThan(0)
        ->and((int) $rows->where('record_type', 'comparison_period_metric_normalized')->count())->toBe(0)
        ->and((int) $rows->where('record_type', 'comparison_delta_normalized')->count())->toBe(0);
});
