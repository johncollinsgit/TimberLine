<?php

use App\Models\MarketingExternalCampaignStat;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingImportRow;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;

test('yotpo legacy import creates profiles, links, consent, and run logs', function () {
    $csv = implode("\n", [
        'contact_id,email,phone,first_name,last_name,email_subscribed,sms_subscribed,sends_count,opens_count,clicks_count,last_engaged_at',
        'yotpo-1,alice@example.com,5551112222,Alice,Legacy,1,0,12,4,1,2026-03-01',
        'yotpo-2,,,,,0,0,0,0,0,',
    ]);
    $file = UploadedFile::fake()->createWithContent('yotpo.csv', $csv);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $tenant = Tenant::query()->create([
        'name' => 'Legacy Import Tenant A',
        'slug' => 'legacy-import-tenant-a',
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id]);

    $this->actingAs($user)
        ->post(route('marketing.providers-integrations.import-legacy'), [
            'import_type' => 'yotpo_contacts_import',
            'file' => $file,
        ])
        ->assertRedirect(route('marketing.providers-integrations'));

    $profile = MarketingProfile::query()->where('normalized_email', 'alice@example.com')->firstOrFail();

    expect(MarketingImportRun::query()->where('type', 'yotpo_contacts_import')->exists())->toBeTrue()
        ->and(MarketingImportRow::query()->count())->toBeGreaterThanOrEqual(2)
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'yotpo_contact')
            ->where('source_id', 'yotpo-1')
            ->exists())->toBeTrue()
        ->and($profile->accepts_email_marketing)->toBeTrue()
        ->and($profile->accepts_sms_marketing)->toBeFalse()
        ->and(MarketingExternalCampaignStat::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'yotpo')
            ->where('sends_count', 12)
            ->exists())->toBeTrue();
});

test('square marketing legacy import updates existing profile by phone and sets consent', function () {
    $existing = MarketingProfile::query()->create([
        'first_name' => 'Existing',
        'phone' => '(555) 444-8888',
        'normalized_phone' => '+15554448888',
    ]);

    $csv = implode("\n", [
        'customer_id,phone,email,sms_subscribed,email_subscribed,sends_count,opens_count,clicks_count',
        'sq-marketing-1,5554448888,,1,0,8,2,0',
    ]);
    $file = UploadedFile::fake()->createWithContent('square-marketing.csv', $csv);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $tenant = Tenant::query()->create([
        'name' => 'Legacy Import Tenant B',
        'slug' => 'legacy-import-tenant-b',
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id]);
    $existing->forceFill(['tenant_id' => $tenant->id])->save();

    $this->actingAs($user)
        ->post(route('marketing.providers-integrations.import-legacy'), [
            'import_type' => 'square_marketing_import',
            'file' => $file,
        ])
        ->assertRedirect(route('marketing.providers-integrations'));

    $existing->refresh();

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $existing->id)
            ->where('source_type', 'square_marketing_contact')
            ->where('source_id', 'sq-marketing-1')
            ->exists())->toBeTrue()
        ->and($existing->accepts_sms_marketing)->toBeTrue()
        ->and($existing->accepts_email_marketing)->toBeFalse()
        ->and(MarketingImportRun::query()->where('type', 'square_marketing_import')->exists())->toBeTrue();
});

test('yotpo export import maps consent sources timestamps and suppression safely', function () {
    $existing = MarketingProfile::query()->create([
        'email' => 'reused@example.com',
        'normalized_email' => 'reused@example.com',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => true,
    ]);

    $csv = implode("\n", [
        '"Name","Email","Phone Number","Date Created","Time Created","SMS marketing consent","Email marketing consent","SMS suppressed","Email suppressed","SMS consent source","SMS consent timestamp","Email consent source","Email consent timestamp"',
        '"Example Person","reused@example.com","+15551112222","Nov 28, 2022","9:32 AM","Subscribed","Subscribed","Not Suppressed","Suppressed","checkout","Dec 10, 2023, 11:53 AM","signup","Nov 28, 2022, 9:32 AM"',
        '"Phone Only","","+15553334444","Nov 29, 2022","10:15 AM","Subscribed","Never subscribed","Not Suppressed","Not Suppressed","shareable_link_14450","Nov 29, 2022, 10:15 AM","",""',
    ]);

    $file = UploadedFile::fake()->createWithContent('yotpo-export.csv', $csv);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $tenant = Tenant::query()->create([
        'name' => 'Legacy Import Tenant C',
        'slug' => 'legacy-import-tenant-c',
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id]);
    $existing->forceFill(['tenant_id' => $tenant->id])->save();

    $this->actingAs($user)
        ->post(route('marketing.providers-integrations.import-legacy'), [
            'import_type' => 'yotpo_contacts_import',
            'file' => $file,
        ])
        ->assertRedirect(route('marketing.providers-integrations'));

    $existing->refresh();
    $phoneOnly = MarketingProfile::query()->where('normalized_phone', '5553334444')->firstOrFail();

    expect($existing->accepts_sms_marketing)->toBeTrue()
        ->and($existing->accepts_email_marketing)->toBeFalse()
        ->and($phoneOnly->accepts_sms_marketing)->toBeTrue()
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $existing->id)
            ->where('source_type', 'yotpo_contact')
            ->where('source_id', 'yotpo-email:reused@example.com')
            ->exists())->toBeTrue()
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $phoneOnly->id)
            ->where('source_type', 'yotpo_contact')
            ->where('source_id', 'yotpo-phone:5553334444')
            ->exists())->toBeTrue()
        ->and(MarketingConsentEvent::query()
            ->where('marketing_profile_id', $existing->id)
            ->where('channel', 'email')
            ->where('source_type', 'yotpo_contacts_import')
            ->where('event_type', 'opted_out')
            ->exists())->toBeTrue()
        ->and(MarketingConsentEvent::query()
            ->where('marketing_profile_id', $existing->id)
            ->where('channel', 'sms')
            ->where('source_type', 'yotpo_contacts_import')
            ->where('event_type', 'imported')
            ->exists())->toBeTrue();

    $emailEvent = MarketingConsentEvent::query()
        ->where('marketing_profile_id', $existing->id)
        ->where('channel', 'email')
        ->where('source_type', 'yotpo_contacts_import')
        ->latest('id')
        ->firstOrFail();

    expect((array) $emailEvent->details)->toMatchArray([
        'provider' => 'yotpo',
        'suppressed' => true,
        'raw_status' => 'Subscribed',
        'consent_source' => 'signup',
    ]);

    $run = MarketingImportRun::query()->where('type', 'yotpo_contacts_import')->latest('id')->firstOrFail();
    expect(data_get($run->summary, 'matched_existing'))->toBe(1)
        ->and(data_get($run->summary, 'sms_marketable'))->toBe(2)
        ->and(data_get($run->summary, 'email_marketable'))->toBe(0)
        ->and(data_get($run->summary, 'email_suppressed'))->toBe(1);
});

test('marketing import legacy file command imports yotpo export from disk', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Legacy Import Command Tenant',
        'slug' => 'legacy-import-command-tenant',
    ]);

    $path = storage_path('framework/testing/yotpo-command.csv');
    file_put_contents($path, implode("\n", [
        '"Name","Email","Phone Number","Date Created","Time Created","SMS marketing consent","Email marketing consent","SMS suppressed","Email suppressed","SMS consent source","SMS consent timestamp","Email consent source","Email consent timestamp"',
        '"Cmd User","cmd@example.com","+15554445555","Nov 30, 2022","11:05 AM","Subscribed","Subscribed","Not Suppressed","Not Suppressed","checkout","Nov 30, 2022, 11:05 AM","signup","Nov 30, 2022, 11:05 AM"',
    ]));

    $this->artisan('marketing:import-legacy-file yotpo_contacts_import ' . escapeshellarg($path) . ' --tenant-id=' . $tenant->id)
        ->expectsOutputToContain('status=completed')
        ->expectsOutputToContain('processed=1')
        ->expectsOutputToContain('sms_marketable=1')
        ->expectsOutputToContain('email_marketable=1')
        ->assertExitCode(0);

    expect(MarketingProfile::query()->where('normalized_email', 'cmd@example.com')->exists())->toBeTrue();

    @unlink($path);
});

test('marketing import legacy file command fails closed without tenant ownership context', function () {
    $path = storage_path('framework/testing/yotpo-command-missing-tenant.csv');
    file_put_contents($path, implode("\n", [
        '"Name","Email","Phone Number"',
        '"No Tenant","no-tenant@example.com","+15554445556"',
    ]));

    $this->artisan('marketing:import-legacy-file yotpo_contacts_import ' . escapeshellarg($path))
        ->expectsOutputToContain('requires a tenant context')
        ->assertExitCode(1);

    expect(MarketingImportRun::query()
        ->where('type', 'yotpo_contacts_import')
        ->count())->toBe(0);

    @unlink($path);
});
