<?php

namespace App\Services\Marketing;

use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CandleCashLegacyConversionValidationService
{
    public function __construct(
        protected LegacyCandleCashCorrectionService $correctionService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(int $limit = 5): array
    {
        if (! Schema::hasTable('candle_cash_transactions')) {
            return [
                'status' => 'missing_ledger_table',
                'legacy' => [],
                'balances' => [],
                'modern' => [],
                'profiles' => [],
            ];
        }

        if (! Schema::hasColumn('candle_cash_transactions', 'candle_cash_delta')
            || ! Schema::hasTable('candle_cash_balances')
            || ! Schema::hasColumn('candle_cash_balances', 'balance')) {
            return [
                'status' => 'requires_canonical_migration',
                'message' => 'Run the canonical Candle Cash correction migration before using this validation report.',
                'legacy' => [],
                'balances' => [],
                'modern' => [],
                'profiles' => [],
            ];
        }

        $limit = max(1, min($limit, 25));

        $legacyCandidateRows = $this->legacyCandidateRowQuery();
        $legacyTaggedRows = $this->legacyTaggedRowQuery();
        $modernRows = $this->modernRowQuery();

        return [
            'status' => 'ready',
            'legacy' => [
                'preview' => $this->correctionService->preview(),
                'candidate_rows' => (int) $legacyCandidateRows->count(),
                'tagged_rows' => (int) $legacyTaggedRows->count(),
                'untagged_candidate_rows' => (int) $this->legacyCandidateRowQuery()
                    ->where(function ($query): void {
                        $query->whereNull('legacy_points_origin')
                            ->orWhere('legacy_points_origin', false);
                    })
                    ->count(),
                'missing_legacy_points_value_rows' => (int) $this->legacyTaggedRowQuery()
                    ->whereNull('legacy_points_value')
                    ->count(),
                'actual_candle_cash_total' => CandleCashMeasurement::displayAmount($legacyTaggedRows->sum('candle_cash_delta')),
                'expected_candle_cash_total' => CandleCashMeasurement::displayAmount($this->legacyTaggedRowsExpectedTotal()),
                'sample_rows' => $this->legacyRowSamples($limit),
            ],
            'balances' => [
                'mismatch_count' => count($this->balanceMismatchRows($limit, sampleOnly: false)),
                'sample_mismatches' => $this->balanceMismatchRows($limit),
            ],
            'modern' => [
                'row_count' => (int) $modernRows->count(),
                'fractional_row_count' => (int) $this->modernRowQuery()
                    ->whereRaw('ABS(COALESCE(candle_cash_delta, 0) - ROUND(COALESCE(candle_cash_delta, 0), 0)) >= 0.0005')
                    ->count(),
                'positive_total' => CandleCashMeasurement::displayAmount($this->modernRowQuery()->where('candle_cash_delta', '>', 0)->sum('candle_cash_delta')),
                'negative_total' => CandleCashMeasurement::displayAmount(abs((float) $this->modernRowQuery()->where('candle_cash_delta', '<', 0)->sum('candle_cash_delta'))),
                'sample_fractional_rows' => $this->modernFractionalRowSamples($limit),
            ],
            'profiles' => [
                'legacy_only' => $this->profileCompositionSamples('legacy_only', $limit),
                'mixed' => $this->profileCompositionSamples('mixed', $limit),
                'modern_only' => $this->profileCompositionSamples('modern_only', $limit),
            ],
        ];
    }

    protected function legacyTaggedRowsExpectedTotal(): float
    {
        $expression = Schema::hasColumn('candle_cash_transactions', 'legacy_points_value')
            ? 'ROUND(COALESCE(legacy_points_value, points, 0) * '.CandleCashMeasurement::LEGACY_STARTING_CANDLE_CASH_PER_POINT.', 3)'
            : 'ROUND(COALESCE(points, 0) * '.CandleCashMeasurement::LEGACY_STARTING_CANDLE_CASH_PER_POINT.', 3)';

        return CandleCashMeasurement::normalizeStoredAmount(
            $this->legacyTaggedRowQuery()->sum(DB::raw($expression))
        );
    }

    protected function legacyRowSamples(int $limit): array
    {
        $hasTenantId = Schema::hasColumn('marketing_profiles', 'tenant_id');
        $columns = [
            'candle_cash_transactions.id',
            'candle_cash_transactions.marketing_profile_id',
            'mp.email',
            'candle_cash_transactions.source',
            'candle_cash_transactions.type',
            'candle_cash_transactions.candle_cash_delta',
        ];

        if ($hasTenantId) {
            $columns[] = 'mp.tenant_id';
        }

        if (Schema::hasColumn('candle_cash_transactions', 'legacy_points_value')) {
            $columns[] = 'candle_cash_transactions.legacy_points_value';
        } else {
            $columns[] = 'candle_cash_transactions.points as legacy_points_value';
        }

        return $this->legacyTaggedRowQuery()
            ->leftJoin('marketing_profiles as mp', 'mp.id', '=', 'candle_cash_transactions.marketing_profile_id')
            ->orderBy('candle_cash_transactions.id')
            ->limit($limit)
            ->get($columns)
            ->map(fn ($row): array => [
                'transaction_id' => (int) $row->id,
                'marketing_profile_id' => (int) $row->marketing_profile_id,
                'email' => $row->email ? (string) $row->email : null,
                'tenant_id' => $hasTenantId && $row->tenant_id !== null ? (int) $row->tenant_id : null,
                'source' => (string) $row->source,
                'type' => (string) $row->type,
                'legacy_points_value' => (int) ($row->legacy_points_value ?? 0),
                'candle_cash_delta' => CandleCashMeasurement::normalizeStoredAmount($row->candle_cash_delta ?? 0),
            ])
            ->all();
    }

    protected function balanceMismatchRows(int $limit, bool $sampleOnly = true): array
    {
        $hasTenantId = Schema::hasColumn('marketing_profiles', 'tenant_id');
        $ledgerTotals = DB::table('candle_cash_transactions')
            ->select('marketing_profile_id', DB::raw('ROUND(COALESCE(SUM(candle_cash_delta), 0), 3) as ledger_balance'))
            ->groupBy('marketing_profile_id');

        $query = DB::table('marketing_profiles as mp')
            ->leftJoin('candle_cash_balances as cb', 'cb.marketing_profile_id', '=', 'mp.id')
            ->leftJoinSub($ledgerTotals, 'ledger', function ($join): void {
                $join->on('ledger.marketing_profile_id', '=', 'mp.id');
            })
            ->whereRaw('ABS(COALESCE(cb.balance, 0) - COALESCE(ledger.ledger_balance, 0)) >= 0.0005')
            ->orderByDesc(DB::raw('ABS(COALESCE(cb.balance, 0) - COALESCE(ledger.ledger_balance, 0))'))
            ->orderBy('mp.id');

        if ($sampleOnly) {
            $query->limit($limit);
        }

        $columns = [
            'mp.id',
            'mp.email',
            'cb.balance as stored_balance',
            'ledger.ledger_balance',
        ];

        if ($hasTenantId) {
            $columns[] = 'mp.tenant_id';
        }

        return $query
            ->get($columns)
            ->map(fn ($row): array => [
                'marketing_profile_id' => (int) $row->id,
                'email' => $row->email ? (string) $row->email : null,
                'tenant_id' => $hasTenantId && $row->tenant_id !== null ? (int) $row->tenant_id : null,
                'stored_balance' => CandleCashMeasurement::normalizeStoredAmount($row->stored_balance ?? 0),
                'ledger_balance' => CandleCashMeasurement::normalizeStoredAmount($row->ledger_balance ?? 0),
            ])
            ->all();
    }

    protected function modernFractionalRowSamples(int $limit): array
    {
        $hasTenantId = Schema::hasColumn('marketing_profiles', 'tenant_id');
        return $this->modernRowQuery()
            ->leftJoin('marketing_profiles as mp', 'mp.id', '=', 'candle_cash_transactions.marketing_profile_id')
            ->whereRaw('ABS(COALESCE(candle_cash_delta, 0) - ROUND(COALESCE(candle_cash_delta, 0), 0)) >= 0.0005')
            ->orderBy('candle_cash_transactions.id')
            ->limit($limit)
            ->get(array_values(array_filter([
                'candle_cash_transactions.id',
                'candle_cash_transactions.marketing_profile_id',
                'mp.email',
                $hasTenantId ? 'mp.tenant_id' : null,
                'candle_cash_transactions.source',
                'candle_cash_transactions.type',
                'candle_cash_transactions.candle_cash_delta',
            ])))
            ->map(fn ($row): array => [
                'transaction_id' => (int) $row->id,
                'marketing_profile_id' => (int) $row->marketing_profile_id,
                'email' => $row->email ? (string) $row->email : null,
                'tenant_id' => $hasTenantId && $row->tenant_id !== null ? (int) $row->tenant_id : null,
                'source' => (string) $row->source,
                'type' => (string) $row->type,
                'candle_cash_delta' => CandleCashMeasurement::normalizeStoredAmount($row->candle_cash_delta ?? 0),
            ])
            ->all();
    }

    protected function profileCompositionSamples(string $kind, int $limit): array
    {
        $hasTenantId = Schema::hasColumn('marketing_profiles', 'tenant_id');
        $aggregates = DB::table('candle_cash_transactions')
            ->select('marketing_profile_id')
            ->selectRaw('ROUND(COALESCE(SUM(candle_cash_delta), 0), 3) as ledger_balance');

        if (Schema::hasColumn('candle_cash_transactions', 'legacy_points_origin')) {
            $aggregates
                ->selectRaw('SUM(CASE WHEN legacy_points_origin = 1 THEN 1 ELSE 0 END) as legacy_row_count')
                ->selectRaw("SUM(CASE WHEN (legacy_points_origin IS NULL OR legacy_points_origin = 0) AND source != 'legacy_rebase' THEN 1 ELSE 0 END) as modern_row_count");
        } else {
            $aggregates
                ->selectRaw("SUM(CASE WHEN source IN ('growave_activity', 'growave') OR type = 'import_opening_balance' THEN 1 ELSE 0 END) as legacy_row_count")
                ->selectRaw("SUM(CASE WHEN source NOT IN ('growave_activity', 'growave', 'legacy_rebase') AND type != 'import_opening_balance' THEN 1 ELSE 0 END) as modern_row_count");
        }

        $aggregates->groupBy('marketing_profile_id');

        $query = DB::table('marketing_profiles as mp')
            ->joinSub($aggregates, 'agg', function ($join): void {
                $join->on('agg.marketing_profile_id', '=', 'mp.id');
            })
            ->leftJoin('candle_cash_balances as cb', 'cb.marketing_profile_id', '=', 'mp.id');

        match ($kind) {
            'legacy_only' => $query->where('agg.legacy_row_count', '>', 0)->where('agg.modern_row_count', 0),
            'mixed' => $query->where('agg.legacy_row_count', '>', 0)->where('agg.modern_row_count', '>', 0),
            'modern_only' => $query->where('agg.legacy_row_count', 0)->where('agg.modern_row_count', '>', 0),
            default => null,
        };

        return $query
            ->orderBy('mp.id')
            ->limit($limit)
            ->get(array_values(array_filter([
                'mp.id',
                'mp.email',
                $hasTenantId ? 'mp.tenant_id' : null,
                'agg.legacy_row_count',
                'agg.modern_row_count',
                'agg.ledger_balance',
                'cb.balance as stored_balance',
            ])))
            ->map(fn ($row): array => [
                'marketing_profile_id' => (int) $row->id,
                'email' => $row->email ? (string) $row->email : null,
                'tenant_id' => $hasTenantId && $row->tenant_id !== null ? (int) $row->tenant_id : null,
                'legacy_row_count' => (int) $row->legacy_row_count,
                'modern_row_count' => (int) $row->modern_row_count,
                'ledger_balance' => CandleCashMeasurement::normalizeStoredAmount($row->ledger_balance ?? 0),
                'stored_balance' => CandleCashMeasurement::normalizeStoredAmount($row->stored_balance ?? 0),
            ])
            ->all();
    }

    protected function legacyCandidateRowQuery()
    {
        return DB::table('candle_cash_transactions')
            ->where(function ($query): void {
                $query->where('source', 'growave_activity')
                    ->orWhere('source', 'growave')
                    ->orWhere('type', 'import_opening_balance');
            });
    }

    protected function legacyTaggedRowQuery()
    {
        if (Schema::hasColumn('candle_cash_transactions', 'legacy_points_origin')) {
            return DB::table('candle_cash_transactions')->where('legacy_points_origin', true);
        }

        return $this->legacyCandidateRowQuery();
    }

    protected function modernRowQuery()
    {
        $query = DB::table('candle_cash_transactions')
            ->where('source', '!=', 'legacy_rebase');

        if (Schema::hasColumn('candle_cash_transactions', 'legacy_points_origin')) {
            $query->where(function ($builder): void {
                $builder->whereNull('legacy_points_origin')
                    ->orWhere('legacy_points_origin', false);
            });
        } else {
            $query->where(function ($builder): void {
                $builder->where('source', '!=', 'growave_activity')
                    ->where('source', '!=', 'growave')
                    ->where('type', '!=', 'import_opening_balance');
            });
        }

        return $query;
    }
}
