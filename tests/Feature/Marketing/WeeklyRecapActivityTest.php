<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\TenantRewardsWishlistWeeklySummaryService;
use App\Services\Marketing\WeeklyRecapActivityService;
use Carbon\Carbon;

test('each recap link opens the matching tenant and exact calendar week activity', function (): void {
    $tenant = Tenant::create(['name' => 'Forestry Recap', 'slug' => 'recap-activity']);
    $other = Tenant::create(['name' => 'Other Recap', 'slug' => 'recap-activity-other']);
    $user = User::factory()->create(['role' => 'marketing_manager', 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'marketing_manager']);
    $start = Carbon::parse('2026-10-05 00:00:00', 'America/New_York')->utc();
    $end = Carbon::parse('2026-10-12 00:00:00', 'America/New_York')->utc();
    $profile = MarketingProfile::factory()->create(['tenant_id' => $tenant->id, 'email' => 'week@example.com', 'normalized_email' => 'week@example.com', 'created_at' => $start->copy()->addDay(), 'first_name' => 'Weekly', 'accepts_email_marketing' => true]);
    $duplicate = MarketingProfile::factory()->create(['tenant_id' => $tenant->id, 'email' => 'WEEK@example.com', 'normalized_email' => 'week@example.com', 'created_at' => $start->copy()->addDays(2)]);
    $otherProfile = MarketingProfile::factory()->create(['tenant_id' => $other->id, 'email' => 'private@example.com', 'created_at' => $start]);
    MarketingProfile::factory()->create(['tenant_id' => $tenant->id, 'email' => 'boundary@example.com', 'created_at' => $end]);
    $birthday = CustomerBirthdayProfile::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'birth_month' => 10, 'birth_day' => 6]);
    BirthdayRewardIssuance::create(['marketing_profile_id' => $profile->id, 'customer_birthday_profile_id' => $birthday->id, 'cycle_year' => 2026, 'reward_type' => 'discount_code', 'reward_code' => 'GIFT-WEEK', 'reward_value' => 10, 'status' => 'redeemed', 'issued_at' => $start, 'redeemed_at' => $end->copy()->subSecond()]);
    CandleCashTransaction::create(['marketing_profile_id' => $profile->id, 'type' => 'earn', 'candle_cash_delta' => 10, 'source' => 'test'])->forceFill(['created_at' => $start])->save();
    CandleCashRedemption::create(['marketing_profile_id' => $profile->id, 'reward_id' => CandleCashReward::firstOrFail()->id, 'redemption_code' => 'CASH-WEEK', 'candle_cash_spent' => 10, 'status' => 'redeemed', 'issued_at' => $start, 'redeemed_at' => $start->copy()->addDay()]);
    MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'email' => $profile->email, 'campaign_type' => 'birthday', 'message_subject' => 'Happy birthday!', 'status' => 'failed', 'sent_at' => $start, 'failed_at' => $start->copy()->addHour(), 'raw_payload' => ['last_event' => ['event' => 'bounce', 'reason' => '550 User unknown', 'status' => '5.1.1']]]);
    MarketingEmailDelivery::create(['tenant_id' => $other->id, 'marketing_profile_id' => $otherProfile->id, 'email' => $otherProfile->email, 'campaign_type' => 'birthday', 'status' => 'failed', 'sent_at' => $start, 'failed_at' => $start]);
    MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'email' => 'info@example.com', 'campaign_type' => 'operator_recap', 'status' => 'sent', 'sent_at' => $start]);
    foreach ([['widget_wishlist_add', 'ok'], ['reward_apply_failure', 'error']] as [$event, $status]) {
        MarketingStorefrontEvent::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'event_type' => $event, 'status' => $status, 'occurred_at' => $start]);
    }
    app(\App\Services\Marketing\MessagingContactChannelStateService::class)->markEmailStatus($tenant->id, $profile, $profile->email, 'unsubscribed', 'unsubscribe_link', 'everbranch', [], $start->copy()->addDay());
    $report = app(TenantRewardsWishlistWeeklySummaryService::class)->reportSnapshot($tenant, $end->copy()->addHours(8), 7, true);
    expect($report['recap']['customers_added'])->toBe(1)->and($report['recap']['emails_sent'])->toBe(1)->and($report['recap']['emails_failed'])->toBe(1);
    foreach ($report['links'] as $metric => $link) {
        $response = $this->actingAs($user)->get($link)->assertOk()->assertSee(WeeklyRecapActivityService::METRICS[$metric])->assertDontSee('private@example.com')->assertDontSee('boundary@example.com');
        expect($response->viewData('rows')->total())->toBe(1);
        if ($metric === 'birthday_issued') {
            $response->assertSee('GIFT-WEEK')->assertSee('$10.00');
        }
        if ($metric === 'emails_failed') {
            $response->assertSee('550 User unknown')->assertSee('Stopped')->assertSee('Happy birthday!');
        }
    }
    $html = view('emails.marketing.tenant-rewards-wishlist-weekly-summary', ['report' => $report])->render();
    $text = view('emails.marketing.tenant-rewards-wishlist-weekly-summary-text', ['report' => $report])->render();
    foreach ($report['links'] as $link) {
        expect($html)->toContain(e($link))->and($text)->toContain($link);
    }
});

test('recap activity requires login and tenant membership and rejects invalid windows', function (): void {
    $tenant = Tenant::create(['name' => 'One', 'slug' => 'recap-one']);
    $other = Tenant::create(['name' => 'Two', 'slug' => 'recap-two']);
    $user = User::factory()->create(['role' => 'marketing_manager', 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id);
    $params = ['metric' => 'birthday_issued', 'tenant' => $other->id, 'started_at' => '2026-10-05T04:00:00Z', 'ended_at' => '2026-10-12T04:00:00Z'];
    $this->get(route('marketing.weekly-recap.activity', $params))->assertRedirect();
    $this->actingAs($user)->get(route('marketing.weekly-recap.activity', $params))->assertForbidden();
    $params['tenant'] = $tenant->id;
    $params['ended_at'] = '2025-10-01';
    $this->getJson(route('marketing.weekly-recap.activity', $params))->assertUnprocessable();
    $params['ended_at'] = '2027-10-01';
    $this->getJson(route('marketing.weekly-recap.activity', $params))->assertUnprocessable();
    $params['metric'] = 'unknown';
    $this->get(route('marketing.weekly-recap.activity', $params))->assertNotFound();
});

test('new customer activity excludes a freshly imported duplicate of a pre-existing customer', function (): void {
    $tenant = Tenant::create(['name' => 'Duplicates', 'slug' => 'recap-duplicates']);
    $start = Carbon::parse('2026-10-05');
    $end = Carbon::parse('2026-10-12');
    MarketingProfile::factory()->create(['tenant_id' => $tenant->id, 'email' => 'old@example.com', 'normalized_email' => null, 'created_at' => $start->copy()->subDay()]);
    MarketingProfile::factory()->create(['tenant_id' => $tenant->id, 'email' => ' OLD@example.com ', 'normalized_email' => 'old@example.com', 'created_at' => $start]);
    expect(app(WeeklyRecapActivityService::class)->newCustomers($tenant->id, $start, $end)->count())->toBe(0);
});

test('SMTP recap carries its retained delivery reference for automatic receipt matching', function (): void {
    $mail = new \App\Mail\TenantRewardsWishlistWeeklySummaryMail(['tenant' => ['id' => 12]], 34);
    $mail->build();
    $message = new \Symfony\Component\Mime\Email;
    foreach ($mail->callbacks as $callback) {
        $callback($message);
    }
    expect(json_decode($message->getHeaders()->get('X-SMTPAPI')->getBodyAsString(), true))->toBe(['unique_args' => ['marketing_email_delivery_id' => '34', 'tenant_id' => '12']]);
});
