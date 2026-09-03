<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\BirthdayEmailComposerService;

test('birthday email composer saves a visual draft without replacing campaign settings and renders reward details', function () {
    $tenant = Tenant::query()->create(['name' => 'Birthday Test', 'slug' => 'birthday-test']);
    TenantMarketingSetting::query()->create([
        'tenant_id' => $tenant->id,
        'key' => 'birthday_campaign_config',
        'value' => ['email_enabled' => true, 'birthday_send_offset' => 0, 'reward_value' => 10],
    ]);
    $service = app(BirthdayEmailComposerService::class);
    $draft = $service->draft($tenant->id);

    expect($draft['sections'])->not->toBeEmpty()->and($draft['rendered_html'])->toContain('cdn.shopify.com');

    $saved = $service->save($tenant->id, [
        'subject' => 'Happy birthday, {{ first_name }}!', 'sections' => $draft['sections'],
        'personalization' => ['first_name_token' => '{{ first_name }}'], 'revision' => $draft['revision'],
    ]);
    $profile = MarketingProfile::query()->create(['first_name' => 'Kelly', 'email' => 'kelly@example.com', 'normalized_email' => 'kelly@example.com']);
    $html = $service->renderForDelivery($saved['subject'], TenantMarketingSetting::query()->firstOrFail()->value, $profile, ['coupon_code' => 'BDAY10', 'reward_value' => '$10', 'reward_apply_url' => 'https://theforestrystudio.com/discount/BDAY10'])['html'];

    expect($saved['revision'])->toBe(2)->and($html)->toContain('Kelly')->toContain('BDAY10')->toContain('Unsubscribe');
    expect(TenantMarketingSetting::query()->firstOrFail()->value)->toMatchArray(['email_enabled' => true, 'birthday_send_offset' => 0]);
});
