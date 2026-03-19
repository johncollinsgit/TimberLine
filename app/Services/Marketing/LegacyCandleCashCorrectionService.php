<?php

namespace App\Services\Marketing;

use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyCandleCashCorrectionService
{
    /**
     * @return array<string,mixed>
     */
    public function preview(): array
    {
        return $this->summarize();
    }

    /**
     * @return array<string,mixed>
     */
    public function apply(): array
    {
        $summary = $this->summarize();
        $profileIds = (array) ($summary['affected_profile_ids'] ?? []);

        if ($profileIds === []) {
            return $summary;
        }

        DB::transaction(function () use ($profileIds): void {
            $this->backfillLegacyOriginColumns();
            $this->correctLegacyOriginTransactions();
            $this->neutralizeLegacyRebaseTransactions();
            $this->recomputeBalances($profileIds);
        });

        return $this->summarize();
    }

    /**
     * @return array<string,mixed>
     */
    protected function summarize(): array
    {
        if (! Schema::hasTable('candle_cash_transactions')) {
            return [
                'affected_profile_ids' => [],
                'profiles' => 0,
                'legacy_transactions' => 0,
                'legacy_rebases' => 0,
                'legacy_points_total' => 0,
                'corrected_candle_cash_total' => 0.0,
                'legacy_rows_needing_correction' => 0,
                'legacy_rebases_needing_neutralization' => 0,
                'balances_requiring_recompute' => 0,
            ];
        }

        $legacyTransactions = $this->legacyOriginTransactionRows();
        $legacyRebases = $this->legacyRebaseRows();
        $affectedProfileIds = collect($legacyTransactions)
            ->pluck('marketing_profile_id')
            ->merge(collect($legacyRebases)->pluck('marketing_profile_id'))
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $legacyPointsTotal = collect($legacyTransactions)
            ->sum(fn (object $row): int => CandleCashMeasurement::legacyPointsValue($row) ?? 0);

        $correctedCandleCashTotal = CandleCashMeasurement::legacyPointsToStartingCandleCash($legacyPointsTotal);

        $legacyRowsNeedingCorrection = collect($legacyTransactions)
            ->filter(function (object $row): bool {
                $expected = CandleCashMeasurement::legacyPointsToStartingCandleCash(CandleCashMeasurement::legacyPointsValue($row) ?? 0);
                $current = CandleCashMeasurement::normalizeStoredAmount($row->candle_cash_delta ?? 0);

                return abs($expected - $current) >= 0.0005
                    || ! (bool) ($row->legacy_points_origin ?? false)
                    || (CandleCashMeasurement::legacyPointsValue($row) ?? null) === null
                    || (int) ($row->legacy_points_value ?? 0) !== (CandleCashMeasurement::legacyPointsValue($row) ?? 0);
            })
            ->count();

        $legacyRebasesNeedingNeutralization = collect($legacyRebases)
            ->filter(fn (object $row): bool => abs(CandleCashMeasurement::normalizeStoredAmount($row->candle_cash_delta ?? 0)) >= 0.0005)
            ->count();

        return [
            'affected_profile_ids' => $affectedProfileIds,
            'profiles' => count($affectedProfileIds),
            'legacy_transactions' => count($legacyTransactions),
            'legacy_rebases' => count($legacyRebases),
            'legacy_points_total' => (int) $legacyPointsTotal,
            'corrected_candle_cash_total' => CandleCashMeasurement::displayAmount($correctedCandleCashTotal),
            'legacy_rows_needing_correction' => (int) $legacyRowsNeedingCorrection,
            'legacy_rebases_needing_neutralization' => (int) $legacyRebasesNeedingNeutralization,
            'balances_requiring_recompute' => count($affectedProfileIds),
        ];
    }

    protected function backfillLegacyOriginColumns(): void
    {
        if (! Schema::hasColumn('candle_cash_transactions', 'legacy_points_origin')
            || ! Schema::hasColumn('candle_cash_transactions', 'legacy_points_value')) {
            return;
        }

        DB::table('candle_cash_transactions')
            ->where(function ($query): void {
                $query->where('source', 'growave_activity')
                    ->orWhere('source', 'growave')
                    ->orWhere('type', 'import_opening_balance');
            })
            ->update([
                'legacy_points_origin' => true,
                'legacy_points_value' => DB::raw('COALESCE(legacy_points_value, points)'),
                'updated_at' => now(),
            ]);
    }

    protected function correctLegacyOriginTransactions(): void
    {
        if (! Schema::hasColumn('candle_cash_transactions', 'legacy_points_origin')
            || ! Schema::hasColumn('candle_cash_transactions', 'legacy_points_value')) {
            return;
        }

        $rate = CandleCashMeasurement::LEGACY_STARTING_CANDLE_CASH_PER_POINT;

        $query = DB::table('candle_cash_transactions')
            ->where(function ($builder): void {
                $builder->where('source', 'growave_activity')
                    ->orWhere('source', 'growave')
                    ->orWhere('type', 'import_opening_balance');
            });
        $query->orWhere('legacy_points_origin', true);

        $query->update([
            'legacy_points_origin' => true,
            'legacy_points_value' => DB::raw('COALESCE(legacy_points_value, points)'),
            'candle_cash_delta' => DB::raw('ROUND(COALESCE(legacy_points_value, points, 0) * '.$rate.', 3)'),
            'updated_at' => now(),
        ]);
    }

    protected function neutralizeLegacyRebaseTransactions(): void
    {
        DB::table('candle_cash_transactions')
            ->where('source', 'legacy_rebase')
            ->update([
                'candle_cash_delta' => 0,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<int,int>  $profileIds
     */
    protected function recomputeBalances(array $profileIds): void
    {
        if (! Schema::hasTable('candle_cash_balances')) {
            return;
        }

        $profileIds = array_values(array_unique(array_filter(array_map('intval', $profileIds), fn (int $value): bool => $value > 0)));
        if ($profileIds === []) {
            return;
        }

        $totals = DB::table('candle_cash_transactions')
            ->select('marketing_profile_id', DB::raw('ROUND(COALESCE(SUM(candle_cash_delta), 0), 3) as corrected_balance'))
            ->whereIn('marketing_profile_id', $profileIds)
            ->groupBy('marketing_profile_id')
            ->pluck('corrected_balance', 'marketing_profile_id');

        $existingProfileIds = DB::table('candle_cash_balances')
            ->whereIn('marketing_profile_id', $profileIds)
            ->pluck('marketing_profile_id')
            ->map(fn ($value): int => (int) $value)
            ->all();

        foreach ($profileIds as $profileId) {
            $balance = CandleCashMeasurement::normalizeStoredAmount($totals[$profileId] ?? 0);

            if (in_array($profileId, $existingProfileIds, true)) {
                DB::table('candle_cash_balances')
                    ->where('marketing_profile_id', $profileId)
                    ->update([
                        'balance' => $balance,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('candle_cash_balances')->insert([
                'marketing_profile_id' => $profileId,
                'balance' => $balance,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array<int,object>
     */
    protected function legacyOriginTransactionRows(): array
    {
        $columns = [
            'id',
            'marketing_profile_id',
            'type',
            'source',
            'points',
            'candle_cash_delta',
        ];

        if (Schema::hasColumn('candle_cash_transactions', 'legacy_points_origin')) {
            $columns[] = 'legacy_points_origin';
        }

        if (Schema::hasColumn('candle_cash_transactions', 'legacy_points_value')) {
            $columns[] = 'legacy_points_value';
        }

        $query = DB::table('candle_cash_transactions')
            ->select($columns)
            ->where(function ($builder): void {
                $builder->where('source', 'growave_activity')
                    ->orWhere('source', 'growave')
                    ->orWhere('type', 'import_opening_balance');
            });

        if (Schema::hasColumn('candle_cash_transactions', 'legacy_points_origin')) {
            $query->orWhere('legacy_points_origin', true);
        }

        return $query->get()->all();
    }

    /**
     * @return array<int,object>
     */
    protected function legacyRebaseRows(): array
    {
        return DB::table('candle_cash_transactions')
            ->select(['id', 'marketing_profile_id', 'source', 'candle_cash_delta'])
            ->where('source', 'legacy_rebase')
            ->get()
            ->all();
    }
}
