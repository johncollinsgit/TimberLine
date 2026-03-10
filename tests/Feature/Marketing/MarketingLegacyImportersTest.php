<?php

use App\Models\MarketingExternalCampaignStat;
use App\Models\MarketingImportRow;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
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
