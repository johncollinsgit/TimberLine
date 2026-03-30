<?php

use App\Models\BirthdayMessageEvent;
use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayAudit;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;

test('birthday pages render for admin users', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Birthday Tenant',
        'slug' => 'birthday-tenant',
    ]);
    $foreignTenant = Tenant::query()->create([
        'name' => 'Birthday Foreign Tenant',
        'slug' => 'birthday-foreign-tenant',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $admin->tenants()->syncWithoutDetaching([$tenant->id]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
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

    $foreignProfile = MarketingProfile::query()->create([
        'tenant_id' => $foreignTenant->id,
        'first_name' => 'Foreign',
        'last_name' => 'Birthday',
        'email' => 'foreign-birthday@example.com',
        'normalized_email' => 'foreign-birthday@example.com',
        'accepts_email_marketing' => true,
    ]);

    $foreignBirthdayProfile = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $foreignProfile->id,
        'birth_month' => 11,
        'birth_day' => 4,
        'birth_year' => 1991,
        'birthday_full_date' => '1991-11-04',
        'source' => 'birthday_import',
        'signup_source' => 'Foreign Birthday Club',
        'capture_date' => now()->subYear(),
        'email_subscribed' => true,
        'source_file' => 'foreign-birthday-import.csv',
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

    BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $foreignBirthdayProfile->id,
        'marketing_profile_id' => $foreignProfile->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Foreign Birthday Reward',
        'status' => 'redeemed',
        'reward_value' => 25,
        'reward_code' => 'BDAY-FOREIGN',
        'issued_at' => now()->subDay(),
        'claimed_at' => now()->subDay(),
        'redeemed_at' => now()->subDay(),
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
        'tenant_id' => $tenant->id,
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
        ->assertSee('Birthday Person')
        ->assertDontSee('Foreign Birthday');

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
        ->assertSee('BDAY-TEST')
        ->assertDontSee('BDAY-FOREIGN');

    $this->actingAs($admin)->get(route('birthdays.settings'))
        ->assertOk()
        ->assertSee('Save Capture Rules');

    $this->actingAs($admin)->get(route('birthdays.activity'))
        ->assertOk()
        ->assertSee('birthday-import.csv')
        ->assertSee('Birthday Imported')
        ->assertDontSee('Growave');
});

test('birthday pages fail closed without tenant context', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('birthdays.analytics'))
        ->assertForbidden();
});

test('birthday activity recent imports stay tenant-owned', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Birthday Tenant A',
        'slug' => 'birthday-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Birthday Tenant B',
        'slug' => 'birthday-tenant-b',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $admin->tenants()->syncWithoutDetaching([$tenantA->id]);

    MarketingImportRun::query()->create([
        'tenant_id' => $tenantA->id,
        'type' => 'birthday_customers_import',
        'status' => 'completed',
        'source_label' => 'birthday_import',
        'file_name' => 'tenant-a-birthday.csv',
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(5),
        'summary' => ['processed' => 2, 'imported' => 2],
    ]);

    MarketingImportRun::query()->create([
        'tenant_id' => $tenantB->id,
        'type' => 'birthday_customers_import',
        'status' => 'completed',
        'source_label' => 'birthday_import',
        'file_name' => 'tenant-b-birthday.csv',
        'started_at' => now()->subMinutes(9),
        'finished_at' => now()->subMinutes(4),
        'summary' => ['processed' => 1, 'imported' => 1],
    ]);

    $this->actingAs($admin)
        ->get(route('birthdays.activity', ['tenant' => $tenantA->slug]))
        ->assertOk()
        ->assertSee('tenant-a-birthday.csv')
        ->assertDontSee('tenant-b-birthday.csv');
});
