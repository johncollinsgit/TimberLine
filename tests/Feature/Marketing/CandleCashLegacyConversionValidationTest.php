<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Services\Marketing\CandleCashService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('legacy conversion validation command summarizes legacy, mixed, and modern sample profiles', function () {
    $legacyOnly = MarketingProfile::query()->create([
        'email' => 'legacy-only@example.com',
        'normalized_email' => 'legacy-only@example.com',
    ]);

    $mixed = MarketingProfile::query()->create([
        'email' => 'mixed@example.com',
        'normalized_email' => 'mixed@example.com',
    ]);

    $modernOnly = MarketingProfile::query()->create([
        'email' => 'modern-only@example.com',
        'normalized_email' => 'modern-only@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $legacyOnly->id,
        'type' => 'import_opening_balance',
        'points' => 100,
        'source' => 'growave',
        'source_id' => 'legacy-opening',
        'description' => 'Imported legacy balance',
    ]);
    CandleCashBalance::query()->create([
        'marketing_profile_id' => $legacyOnly->id,
        'balance' => 30.0,
    ]);

    app(CandleCashService::class)->addPoints($mixed, 5, 'earn', 'admin', 'mixed-admin', 'Modern admin earn');
    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $mixed->id,
        'type' => 'earn',
        'points' => 20,
        'source' => 'growave_activity',
        'source_id' => 'retail:mixed:7001',
        'description' => 'Imported Growave activity',
    ]);
    CandleCashBalance::query()->where('marketing_profile_id', $mixed->id)->update([
        'balance' => 11.0,
    ]);

    app(CandleCashService::class)->addPoints($modernOnly, 7, 'earn', 'admin', 'modern-seed', 'Modern seed');

    Artisan::call('marketing:validate-candle-cash-legacy-conversion', [
        '--json' => true,
        '--limit' => 5,
    ]);

    $summary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($summary, 'legacy.candidate_rows'))->toBe(2)
        ->and(data_get($summary, 'legacy.tagged_rows'))->toBe(2)
        ->and(data_get($summary, 'legacy.untagged_candidate_rows'))->toBe(0)
        ->and(data_get($summary, 'legacy.preview.legacy_rows_needing_correction'))->toBe(0)
        ->and(data_get($summary, 'legacy.expected_candle_cash_total'))->toBe(36)
        ->and(data_get($summary, 'legacy.actual_candle_cash_total'))->toBe(36)
        ->and(data_get($summary, 'balances.mismatch_count'))->toBe(0)
        ->and(data_get($summary, 'modern.row_count'))->toBe(2)
        ->and(data_get($summary, 'modern.fractional_row_count'))->toBe(0);

    expect(collect((array) data_get($summary, 'profiles.legacy_only'))->pluck('marketing_profile_id')->all())->toContain($legacyOnly->id)
        ->and(collect((array) data_get($summary, 'profiles.mixed'))->pluck('marketing_profile_id')->all())->toContain($mixed->id)
        ->and(collect((array) data_get($summary, 'profiles.modern_only'))->pluck('marketing_profile_id')->all())->toContain($modernOnly->id);
});

test('legacy conversion validation command flags untagged legacy rows, balance drift, and fractional modern rows', function () {
    $legacyProfile = MarketingProfile::query()->create([
        'email' => 'legacy-drift@example.com',
        'normalized_email' => 'legacy-drift@example.com',
    ]);

    $modernProfile = MarketingProfile::query()->create([
        'email' => 'modern-drift@example.com',
        'normalized_email' => 'modern-drift@example.com',
    ]);

    DB::table('candle_cash_transactions')->insert([
        'marketing_profile_id' => $legacyProfile->id,
        'type' => 'import_opening_balance',
        'points' => 100,
        'candle_cash_delta' => 100,
        'source' => 'growave',
        'source_id' => 'legacy-raw',
        'description' => 'Legacy row bypassing model normalization',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $legacyProfile->id,
        'balance' => 100,
    ]);

    app(CandleCashService::class)->addPoints($modernProfile, 10, 'earn', 'admin', 'modern-raw', 'Modern seed');
    DB::table('candle_cash_transactions')
        ->where('marketing_profile_id', $modernProfile->id)
        ->where('source', 'admin')
        ->update([
            'candle_cash_delta' => 0.03,
            'updated_at' => now(),
        ]);

    Artisan::call('marketing:validate-candle-cash-legacy-conversion', [
        '--json' => true,
        '--limit' => 5,
    ]);

    $summary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($summary, 'legacy.untagged_candidate_rows'))->toBe(1)
        ->and(data_get($summary, 'legacy.preview.legacy_rows_needing_correction'))->toBeGreaterThanOrEqual(1)
        ->and(data_get($summary, 'modern.fractional_row_count'))->toBe(1)
        ->and(data_get($summary, 'balances.mismatch_count'))->toBeGreaterThanOrEqual(1);

    expect(collect((array) data_get($summary, 'balances.sample_mismatches'))->pluck('marketing_profile_id')->all())->toContain($modernProfile->id)
        ->and(collect((array) data_get($summary, 'modern.sample_fractional_rows'))->pluck('marketing_profile_id')->all())->toContain($modernProfile->id);
});
