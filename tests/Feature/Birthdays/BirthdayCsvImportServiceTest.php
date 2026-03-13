<?php

use App\Models\BirthdayMessageEvent;
use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Marketing\BirthdayCsvImportService;

test('birthday csv import creates canonical profiles, birthday rows, reward history, and message events', function () {
    $path = storage_path('framework/testing/birthday-import.csv');
    @mkdir(dirname($path), 0777, true);

    file_put_contents($path, implode("\n", [
        'email,birthday,"first name","last name",capture_date,signup_channel,unsubscribed,"this year email sent","this year email opened","this year email clicked","this year email discount code","this year email discount code used"',
        'alice@example.com,05/12/1993,Alice,One,"01/20/2018 12:31","Order Status Page (Legacy)",No,"2026-05-12 15:00:00","2026-05-12 15:15:00","2026-05-12 15:20:00",BDAY-ALICE,1',
        ',03/03/1990,Debra,Bunchman,"01/20/2018 12:31","Order Status Page (Legacy)",No,,,,,',
    ]));

    $service = app(BirthdayCsvImportService::class);

    $result = $service->importPath(
        path: $path,
        fileName: 'birthday-import.csv',
        mapping: $service->guessMapping([
            'email',
            'birthday',
            'first_name',
            'last_name',
            'capture_date',
            'signup_channel',
            'unsubscribed',
            'this_year_email_sent',
            'this_year_email_opened',
            'this_year_email_clicked',
            'this_year_email_discount_code',
            'this_year_email_discount_code_used',
        ]),
        createdBy: null,
        dryRun: false,
    );

    expect((string) $result['status'])->toBe('completed')
        ->and((int) data_get($result, 'summary.imported', 0))->toBe(2)
        ->and(CustomerBirthdayProfile::query()->count())->toBe(2)
        ->and(MarketingProfile::query()->count())->toBe(2)
        ->and(MarketingProfileLink::query()->where('source_type', 'birthday_customer')->count())->toBe(2)
        ->and(BirthdayRewardIssuance::query()->count())->toBe(1)
        ->and(BirthdayMessageEvent::query()->count())->toBe(1)
        ->and(MarketingImportRun::query()->where('type', 'birthday_customers_import')->exists())->toBeTrue();

    $alice = MarketingProfile::query()->where('email', 'alice@example.com')->firstOrFail();
    $aliceBirthday = CustomerBirthdayProfile::query()->where('marketing_profile_id', $alice->id)->firstOrFail();
    $issuance = BirthdayRewardIssuance::query()->where('marketing_profile_id', $alice->id)->firstOrFail();

    expect((bool) $alice->accepts_email_marketing)->toBeTrue()
        ->and((int) $aliceBirthday->birth_month)->toBe(5)
        ->and((int) $aliceBirthday->birth_day)->toBe(12)
        ->and((string) $issuance->reward_code)->toBe('BDAY-ALICE')
        ->and((string) $issuance->status)->toBe('redeemed')
        ->and(BirthdayMessageEvent::query()->first()->status)->toBe('clicked');
});
