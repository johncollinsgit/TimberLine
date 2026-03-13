<?php

use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Services\Marketing\BirthdayProfileService;

it('captures month-day birthday in year-optional mode', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'MonthDay',
        'email' => 'monthday@example.com',
        'normalized_email' => 'monthday@example.com',
    ]);

    $service = app(BirthdayProfileService::class);

    $birthday = $service->captureForProfile($profile, [
        'birth_month' => 7,
        'birth_day' => 14,
        'source' => 'shopify_widget',
    ], [
        'source' => 'shopify_widget',
    ]);

    expect((int) $birthday->birth_month)->toBe(7)
        ->and((int) $birthday->birth_day)->toBe(14)
        ->and($birthday->birth_year)->toBeNull()
        ->and($birthday->birthday_full_date)->toBeNull()
        ->and((string) $birthday->source)->toBe('shopify_widget');
});

it('parses full date capture into normalized birthday fields', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'FullDate',
        'email' => 'fulldate@example.com',
        'normalized_email' => 'fulldate@example.com',
    ]);

    $service = app(BirthdayProfileService::class);

    $birthday = $service->captureForProfile($profile, [
        'birthday_full_date' => '1988-02-29',
        'source' => 'admin_backstage',
    ], [
        'source' => 'admin_backstage',
    ]);

    expect((int) $birthday->birth_month)->toBe(2)
        ->and((int) $birthday->birth_day)->toBe(29)
        ->and((int) $birthday->birth_year)->toBe(1988)
        ->and(optional($birthday->birthday_full_date)->toDateString())->toBe('1988-02-29');
});

it('rejects invalid birthday combinations', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Invalid',
        'email' => 'invalid@example.com',
        'normalized_email' => 'invalid@example.com',
    ]);

    $service = app(BirthdayProfileService::class);

    expect(fn () => $service->captureForProfile($profile, [
        'birth_month' => 2,
        'birth_day' => 31,
        'source' => 'test',
    ], [
        'source' => 'test',
    ]))->toThrow(\RuntimeException::class);

    expect(CustomerBirthdayProfile::query()->count())->toBe(0);
});
