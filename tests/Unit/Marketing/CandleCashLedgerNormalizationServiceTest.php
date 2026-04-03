<?php

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Services\Marketing\CandleCashLedgerNormalizationService;
use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('legacy converted opening balances are earned-limit exempt', function () {
    $profile = MarketingProfile::query()->create([
        'email' => 'legacy.exempt@example.com',
        'normalized_email' => 'legacy.exempt@example.com',
    ]);

    $legacyOpening = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'import_opening_balance',
        'points' => 100,
        'source' => 'growave',
        'source_id' => 'snapshot:legacy-exempt',
        'description' => 'Imported Growave opening balance',
    ]);

    $normalizer = app(CandleCashLedgerNormalizationService::class);

    expect($normalizer->isEarnedLimitExempt($legacyOpening))->toBeTrue()
        ->and($normalizer->isEarnedLimitEligible($legacyOpening))->toBeFalse();
});

test('program-earned transactions remain earned-limit eligible', function () {
    $profile = MarketingProfile::query()->create([
        'email' => 'program.eligible@example.com',
        'normalized_email' => 'program.eligible@example.com',
    ]);

    $programEarn = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'points' => 25,
        'source' => 'consent',
        'source_id' => 'sms-consent:program-eligible',
        'description' => 'Program earn',
    ]);

    $normalizer = app(CandleCashLedgerNormalizationService::class);

    expect($normalizer->isEarnedLimitExempt($programEarn))->toBeFalse()
        ->and($normalizer->isEarnedLimitEligible($programEarn))->toBeTrue();
});

test('mixed ledgers expose only program-earned credit as earned-limit eligible', function () {
    $profile = MarketingProfile::query()->create([
        'email' => 'mixed.ledger@example.com',
        'normalized_email' => 'mixed.ledger@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'import_opening_balance',
        'points' => 100,
        'source' => 'growave',
        'source_id' => 'snapshot:mixed-ledger',
        'description' => 'Imported Growave opening balance',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'points' => 50,
        'source' => 'consent',
        'source_id' => 'sms-consent:mixed-ledger',
        'description' => 'Program earn',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'redeem',
        'points' => -20,
        'source' => 'reward',
        'source_id' => 'redeem:mixed-ledger',
        'description' => 'Program redemption',
    ]);

    $normalizer = app(CandleCashLedgerNormalizationService::class);
    $transactions = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profile->id)
        ->orderBy('id')
        ->get();

    $eligibleEarnedCredit = CandleCashMeasurement::normalizeStoredAmount($transactions
        ->filter(fn (CandleCashTransaction $transaction): bool => $normalizer->isEarnedLimitEligible($transaction))
        ->sum('candle_cash_delta'));

    $exemptConvertedCredit = CandleCashMeasurement::normalizeStoredAmount($transactions
        ->filter(fn (CandleCashTransaction $transaction): bool => $normalizer->isEarnedLimitExempt($transaction))
        ->filter(fn (CandleCashTransaction $transaction): bool => CandleCashMeasurement::normalizeStoredAmount($transaction->candle_cash_delta) > 0)
        ->sum('candle_cash_delta'));

    expect($eligibleEarnedCredit)->toBe(50.0)
        ->and($exemptConvertedCredit)->toBe(30.0);
});
