<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Services\Marketing\BirthdayRewardEngineService;

beforeEach(function () {
    MarketingSetting::query()->updateOrCreate(
        ['key' => 'birthday_reward_config'],
        ['value' => [
            'enabled' => true,
            'reward_type' => 'candle_cash',
            'candle_cash_amount' => 75,
            'discount_code_prefix' => 'BDAY',
            'free_shipping_code_prefix' => 'BDAYSHIP',
            'claim_window_days_before' => 365,
            'claim_window_days_after' => 365,
        ]]
    );
});

test('birthday reward engine enforces annual issuance guardrail for candle cash rewards', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Birthday',
        'email' => 'birthday-points@example.com',
        'normalized_email' => 'birthday-points@example.com',
    ]);

    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) now()->month,
        'birth_day' => (int) now()->day,
        'birth_year' => 1990,
        'birthday_full_date' => '1990-'.now()->format('m-d'),
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    $engine = app(BirthdayRewardEngineService::class);

    $first = $engine->issueAnnualReward($birthday);
    $second = $engine->issueAnnualReward($birthday);

    expect((bool) ($first['ok'] ?? false))->toBeTrue()
        ->and((string) ($first['state'] ?? ''))->toBe('already_claimed')
        ->and((bool) ($second['ok'] ?? false))->toBeTrue()
        ->and((string) ($second['state'] ?? ''))->toBe('already_claimed')
        ->and(BirthdayRewardIssuance::query()->count())->toBe(1)
        ->and(CandleCashTransaction::query()->where('source', 'birthday_reward')->count())->toBe(1)
        ->and(CustomerBirthdayProfile::query()->first()->reward_last_issued_year)->toBe((int) now()->year);
});

test('birthday reward engine supports discount code issuance and claim flow', function () {
    MarketingSetting::query()->updateOrCreate(
        ['key' => 'birthday_reward_config'],
        ['value' => [
            'enabled' => true,
            'reward_type' => 'discount_code',
            'candle_cash_amount' => 0,
            'discount_code_prefix' => 'BDAY',
            'free_shipping_code_prefix' => 'BDAYSHIP',
            'claim_window_days_before' => 365,
            'claim_window_days_after' => 365,
        ]]
    );

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Discount',
        'email' => 'birthday-discount@example.com',
        'normalized_email' => 'birthday-discount@example.com',
    ]);

    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) now()->month,
        'birth_day' => (int) now()->day,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    $engine = app(BirthdayRewardEngineService::class);

    $issued = $engine->issueAnnualReward($birthday);
    $claimed = $engine->claimIssuedReward($birthday);

    expect((bool) ($issued['ok'] ?? false))->toBeTrue()
        ->and((string) ($issued['state'] ?? ''))->toBe('birthday_reward_ready')
        ->and((string) ($issued['issuance']->status ?? ''))->toBe('issued')
        ->and((string) ($issued['issuance']->reward_code ?? ''))->not->toBe('')
        ->and((bool) ($claimed['ok'] ?? false))->toBeTrue()
        ->and((string) ($claimed['state'] ?? ''))->toBe('already_claimed')
        ->and((string) ($claimed['issuance']->status ?? ''))->toBe('claimed');
});

test('birthday reward engine blocks issuance outside claim window', function () {
    MarketingSetting::query()->updateOrCreate(
        ['key' => 'birthday_reward_config'],
        ['value' => [
            'enabled' => true,
            'reward_type' => 'free_shipping',
            'discount_code_prefix' => 'BDAY',
            'free_shipping_code_prefix' => 'BDAYSHIP',
            'claim_window_days_before' => 0,
            'claim_window_days_after' => 0,
        ]]
    );

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Window',
        'email' => 'birthday-window@example.com',
        'normalized_email' => 'birthday-window@example.com',
    ]);

    $birthdayDate = now()->addMonthsNoOverflow(5);
    $birthday = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) $birthdayDate->month,
        'birth_day' => (int) $birthdayDate->day,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);

    $engine = app(BirthdayRewardEngineService::class);

    $result = $engine->issueAnnualReward($birthday);

    expect((bool) ($result['ok'] ?? true))->toBeFalse()
        ->and((string) ($result['state'] ?? ''))->toBe('outside_claim_window')
        ->and(BirthdayRewardIssuance::query()->count())->toBe(0);
});
