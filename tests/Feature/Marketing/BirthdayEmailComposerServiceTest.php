<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\BirthdayEmailComposerService;
use App\Services\Marketing\BirthdayEmailFollowupService;

test('birthday email composer saves a visual draft without replacing campaign settings and renders reward details', function () {
    $tenant = Tenant::query()->create(['name' => 'Birthday Test', 'slug' => 'birthday-test']);
    TenantMarketingSetting::query()->create([
        'tenant_id' => $tenant->id,
        'key' => 'birthday_campaign_config',
        'value' => ['email_enabled' => true, 'birthday_send_offset' => 0, 'reward_value' => 10],
    ]);
    $service = app(BirthdayEmailComposerService::class);
    $draft = $service->draft($tenant->id);

    expect($draft['sections'])->not->toBeEmpty()
        ->and($draft['rendered_html'])->toContain('birthday-mountain-candle-hero.png')
        ->toContain('cdn.shopify.com');

    $saved = $service->save($tenant->id, [
        'subject' => 'Happy birthday, {{ first_name }}!', 'sections' => $draft['sections'],
        'personalization' => ['first_name_token' => '{{ first_name }}'], 'revision' => $draft['revision'],
    ]);
    $profile = MarketingProfile::query()->create(['first_name' => 'Kelly', 'email' => 'kelly@example.com', 'normalized_email' => 'kelly@example.com']);
    $html = $service->renderForDelivery($saved['subject'], TenantMarketingSetting::query()->firstOrFail()->value, $profile, ['coupon_code' => 'BDAY10', 'reward_value' => '$10', 'reward_apply_url' => 'https://theforestrystudio.com/discount/BDAY10'])['html'];

    expect($saved['revision'])->toBe(2)->and($html)->toContain('Kelly')->toContain('BDAY10')->toContain('Unsubscribe');
    expect(TenantMarketingSetting::query()->firstOrFail()->value)->toMatchArray(['email_enabled' => true, 'birthday_send_offset' => 0]);
});

test('birthday email composer renders Candle Cash rewards with an account link and the ten dollar redemption rule', function () {
    $tenant = Tenant::query()->create(['name' => 'Birthday Cash Test', 'slug' => 'birthday-cash-test']);
    $profile = MarketingProfile::query()->create(['first_name' => 'Kari', 'email' => 'kari@example.com', 'normalized_email' => 'kari@example.com']);

    $html = app(BirthdayEmailComposerService::class)->renderForDelivery(
        'Happy Birthday from The Forestry Studio',
        [],
        $profile,
        [
            'reward_value' => '$50',
            'birthday_reward_message' => 'Your birthday gift of <strong>$50 in Candle Cash</strong> has been added to your account. Candle Cash applies in $10 increments at checkout.',
            'birthday_cta_label' => 'View your Candle Cash',
            'reward_apply_url' => 'https://theforestrystudio.com/account',
        ],
    )['html'];

    expect($tenant->id)->toBeGreaterThan(0)
        ->and($html)->toContain('$50 in Candle Cash')
        ->toContain('Candle Cash applies in $10 increments')
        ->toContain('https://theforestrystudio.com/account');
});

test('birthday follow-ups target only active, expiring code rewards for the selected tenant', function () {
    $tenant = Tenant::query()->create(['name' => 'Birthday Follow-up', 'slug' => 'birthday-follow-up']);
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Faith',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
        'accepts_email_marketing' => true,
    ]);
    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => 9,
        'birth_day' => 3,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);
    BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'status' => 'issued',
        'reward_code' => 'BDAY-FAITH',
        'reward_value' => 10,
        'expires_at' => now()->addDays(2),
    ]);
    BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthday->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'candle_cash',
        'status' => 'claimed',
        'candle_cash_awarded' => 50,
        'expires_at' => now()->addDays(2),
    ]);

    $summary = app(BirthdayEmailFollowupService::class)->dispatchDue($tenant->id, dryRun: true);

    expect($summary)->toMatchArray(['evaluated' => 1, 'sent' => 0, 'failed' => 0, 'disabled' => 0]);
});
