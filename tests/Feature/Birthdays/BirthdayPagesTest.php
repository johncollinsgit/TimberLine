<?php

use App\Models\BirthdayMessageEvent;
use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayAudit;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\User;

test('birthday pages render for admin users', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Birthday',
        'last_name' => 'Person',
        'email' => 'birthday-person@example.com',
        'normalized_email' => 'birthday-person@example.com',
        'accepts_email_marketing' => true,
    ]);

    $birthdayProfile = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => 5,
        'birth_day' => 12,
        'birth_year' => 1993,
        'birthday_full_date' => '1993-05-12',
        'source' => 'birthday_import',
        'signup_source' => 'Legacy Birthday Club',
        'capture_date' => now()->subYear(),
        'email_subscribed' => true,
        'source_file' => 'birthday-import.csv',
        'source_captured_at' => now()->subYear(),
    ]);

    $issuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayProfile->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Candle Cash',
        'status' => 'claimed',
        'reward_value' => 10,
        'reward_code' => 'BDAY-TEST',
        'issued_at' => now()->subDay(),
        'claimed_at' => now()->subDay(),
        'claim_window_starts_at' => now()->subDay(),
        'claim_window_ends_at' => now()->addDays(14),
        'expires_at' => now()->addDays(14),
    ]);

    CustomerBirthdayAudit::query()->create([
        'customer_birthday_profile_id' => $birthdayProfile->id,
        'marketing_profile_id' => $profile->id,
        'action' => 'birthday_imported',
        'source' => 'birthday_csv',
        'is_uncertain' => false,
        'payload' => ['file_name' => 'birthday-import.csv'],
    ]);

    BirthdayMessageEvent::query()->create([
        'customer_birthday_profile_id' => $birthdayProfile->id,
        'marketing_profile_id' => $profile->id,
        'birthday_reward_issuance_id' => $issuance->id,
        'event_key' => 'birthday-event-1',
        'campaign_type' => 'birthday_email',
        'channel' => 'email',
        'provider' => 'birthday_csv',
        'status' => 'clicked',
        'sent_at' => now()->subDay(),
        'opened_at' => now()->subDay(),
        'clicked_at' => now()->subDay(),
    ]);

    MarketingImportRun::query()->create([
        'type' => 'birthday_customers_import',
        'status' => 'completed',
        'source_label' => 'birthday_import',
        'file_name' => 'birthday-import.csv',
        'started_at' => now()->subDay(),
        'finished_at' => now()->subDay(),
        'summary' => ['processed' => 1, 'imported' => 1],
    ]);

    $this->actingAs($admin)->get(route('birthdays.customers'))
        ->assertOk()
        ->assertSee('Birthday club customers')
        ->assertSee('Birthday Person');

    $this->actingAs($admin)->get(route('birthdays.analytics'))
        ->assertOk()
        ->assertSee('Last 30 days')
        ->assertSee('Campaign Overview');

    $this->actingAs($admin)->get(route('birthdays.campaigns'))
        ->assertOk()
        ->assertSee('Birthday Email');

    $this->actingAs($admin)->get(route('birthdays.rewards'))
        ->assertOk()
        ->assertSee('Birthday Candle Cash')
        ->assertSee('BDAY-TEST');

    $this->actingAs($admin)->get(route('birthdays.settings'))
        ->assertOk()
        ->assertSee('Save Capture Rules');

    $this->actingAs($admin)->get(route('birthdays.activity'))
        ->assertOk()
        ->assertSee('birthday-import.csv')
        ->assertSee('Birthday Imported');
});
