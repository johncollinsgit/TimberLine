<?php

namespace App\Services\Marketing;

use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyGrowaveCandleCashRehomeService
{
    protected const BALANCE_TOLERANCE = 0.0005;

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $tenantId = $this->positiveInt($options['tenant_id'] ?? null) ?? 1;
        $store = $this->normalizedStore($options['store'] ?? null) ?? 'retail';
        $includeWholesale = (bool) ($options['include_wholesale'] ?? false);
        $profileId = $this->positiveInt($options['profile_id'] ?? null);
        $chunk = max(1, (int) ($this->positiveInt($options['chunk'] ?? null) ?? 500));
        $sampleLimit = max(0, min(100, (int) ($this->positiveInt($options['sample'] ?? null) ?? 15)));
        $apply = (bool) ($options['apply'] ?? false);

        $rawRows = $this->rawMappingRows(
            tenantId: $tenantId,
            store: $store,
            profileId: $profileId
        );
        $collapsedPairs = $this->collapsePairs($rawRows);

        $wholesaleOldIds = $collapsedPairs
            ->filter(fn (array $pair): bool => (bool) ($pair['wholesale_touched'] ?? false))
            ->pluck('old_profile_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        $pairsAfterWholesale = $includeWholesale
            ? $collapsedPairs->values()
            : $collapsedPairs
                ->reject(fn (array $pair): bool => in_array((int) ($pair['old_profile_id'] ?? 0), $wholesaleOldIds->all(), true))
                ->values();

        $oldToTargets = $pairsAfterWholesale
            ->groupBy('old_profile_id')
            ->map(fn (Collection $rows): int => $rows->pluck('target_profile_id')->unique()->count());
        $targetToOlds = $pairsAfterWholesale
            ->groupBy('target_profile_id')
            ->map(fn (Collection $rows): int => $rows->pluck('old_profile_id')->unique()->count());

        $ambiguousOldIds = $oldToTargets
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();
        $ambiguousTargetIds = $targetToOlds
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        $ambiguousOldLookup = array_fill_keys($ambiguousOldIds->all(), true);
        $ambiguousTargetLookup = array_fill_keys($ambiguousTargetIds->all(), true);

        $eligiblePairs = $pairsAfterWholesale
            ->reject(function (array $pair) use ($ambiguousOldLookup, $ambiguousTargetLookup): bool {
                $oldId = (int) ($pair['old_profile_id'] ?? 0);
                $targetId = (int) ($pair['target_profile_id'] ?? 0);

                return isset($ambiguousOldLookup[$oldId]) || isset($ambiguousTargetLookup[$targetId]);
            })
            ->sortBy([
                ['old_profile_id', 'asc'],
                ['target_profile_id', 'asc'],
            ])
            ->values();

        $oldIds = $eligiblePairs
            ->pluck('old_profile_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
        $targetIds = $eligiblePairs
            ->pluck('target_profile_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();

        $rowsToMove = [
            'transactions' => $this->countRowsByColumn('candle_cash_transactions', 'marketing_profile_id', $oldIds),
            'redemptions' => $this->countRowsByColumn('candle_cash_redemptions', 'marketing_profile_id', $oldIds),
            'task_completions' => $this->countRowsByColumn('candle_cash_task_completions', 'marketing_profile_id', $oldIds),
            'task_events' => $this->countRowsByColumn('candle_cash_task_events', 'marketing_profile_id', $oldIds),
            'referrals_referrer' => $this->countRowsByColumn('candle_cash_referrals', 'referrer_marketing_profile_id', $oldIds),
            'referrals_referred' => $this->countRowsByColumn('candle_cash_referrals', 'referred_marketing_profile_id', $oldIds),
        ];

        $preTotals = [
            'old_balance_sum' => $this->sumBalances($oldIds),
            'target_balance_sum' => $this->sumBalances($targetIds),
            'old_ledger_sum' => $this->sumLedger($oldIds),
            'target_ledger_sum' => $this->sumLedger($targetIds),
        ];

        $samplePairs = $eligiblePairs->take($sampleLimit)->values()->all();

        $result = [
            'mode' => $apply ? 'apply' : 'preview',
            'tenant_id' => $tenantId,
            'store' => $store,
            'include_wholesale' => $includeWholesale,
            'profile_id' => $profileId,
            'chunk' => $chunk,
            'sample_limit' => $sampleLimit,
            'raw_pair_rows' => $rawRows->count(),
            'candidate_pairs' => $collapsedPairs->count(),
            'candidate_old_profiles' => $collapsedPairs->pluck('old_profile_id')->unique()->count(),
            'candidate_target_profiles' => $collapsedPairs->pluck('target_profile_id')->unique()->count(),
            'excluded_wholesale_profiles' => $includeWholesale ? 0 : $wholesaleOldIds->count(),
            'pairs_after_wholesale' => $pairsAfterWholesale->count(),
            'ambiguous_old_profiles' => $ambiguousOldIds->count(),
            'ambiguous_target_profiles' => $ambiguousTargetIds->count(),
            'eligible_pairs' => $eligiblePairs->count(),
            'eligible_old_profiles' => count($oldIds),
            'eligible_target_profiles' => count($targetIds),
            'rows_to_move' => $rowsToMove,
            'pre' => $preTotals,
            'applied' => [
                'rows_moved' => [
                    'transactions' => 0,
                    'redemptions' => 0,
                    'task_completions' => 0,
                    'task_events' => 0,
                    'referrals_referrer' => 0,
                    'referrals_referred' => 0,
                ],
                'balance_rows_deleted' => 0,
                'balance_rows_inserted' => 0,
                'balance_rows_updated' => 0,
                'balance_rows_unchanged' => 0,
            ],
            'post' => $this->postTotals($oldIds, $targetIds),
            'sample_pairs' => $samplePairs,
        ];

        if (! $apply || $oldIds === []) {
            return $result;
        }

        $map = [];
        foreach ($eligiblePairs as $pair) {
            $oldId = (int) ($pair['old_profile_id'] ?? 0);
            $targetId = (int) ($pair['target_profile_id'] ?? 0);
            if ($oldId > 0 && $targetId > 0) {
                $map[$oldId] = $targetId;
            }
        }

        if ($map === []) {
            return $result;
        }

        $applied = [
            'rows_moved' => [
                'transactions' => 0,
                'redemptions' => 0,
                'task_completions' => 0,
                'task_events' => 0,
                'referrals_referrer' => 0,
                'referrals_referred' => 0,
            ],
            'balance_rows_deleted' => 0,
            'balance_rows_inserted' => 0,
            'balance_rows_updated' => 0,
            'balance_rows_unchanged' => 0,
        ];

        DB::transaction(function () use ($map, $chunk, $oldIds, $targetIds, &$applied): void {
            foreach (array_chunk($map, $chunk, true) as $chunkMap) {
                $applied['rows_moved']['transactions'] += $this->updateMappedProfileColumn(
                    table: 'candle_cash_transactions',
                    column: 'marketing_profile_id',
                    map: $chunkMap
                );
                $applied['rows_moved']['redemptions'] += $this->updateMappedProfileColumn(
                    table: 'candle_cash_redemptions',
                    column: 'marketing_profile_id',
                    map: $chunkMap
                );
                $applied['rows_moved']['task_completions'] += $this->updateMappedProfileColumn(
                    table: 'candle_cash_task_completions',
                    column: 'marketing_profile_id',
                    map: $chunkMap
                );
                $applied['rows_moved']['task_events'] += $this->updateMappedProfileColumn(
                    table: 'candle_cash_task_events',
                    column: 'marketing_profile_id',
                    map: $chunkMap
                );
                $applied['rows_moved']['referrals_referrer'] += $this->updateMappedProfileColumn(
                    table: 'candle_cash_referrals',
                    column: 'referrer_marketing_profile_id',
                    map: $chunkMap
                );
                $applied['rows_moved']['referrals_referred'] += $this->updateMappedProfileColumn(
                    table: 'candle_cash_referrals',
                    column: 'referred_marketing_profile_id',
                    map: $chunkMap
                );
            }

            if (Schema::hasTable('candle_cash_balances') && $oldIds !== []) {
                $applied['balance_rows_deleted'] = DB::table('candle_cash_balances')
                    ->whereIn('marketing_profile_id', $oldIds)
                    ->delete();
            }

            $recomputed = $this->recomputeBalances(array_values(array_unique(array_merge($oldIds, $targetIds))));
            $applied['balance_rows_inserted'] = (int) ($recomputed['inserts'] ?? 0);
            $applied['balance_rows_updated'] = (int) ($recomputed['updates'] ?? 0);
            $applied['balance_rows_unchanged'] = (int) ($recomputed['unchanged'] ?? 0);
        });

        $result['applied'] = $applied;
        $result['post'] = $this->postTotals($oldIds, $targetIds);

        return $result;
    }

    /**
     * @return Collection<int,array{
     *   old_profile_id:int,
     *   target_profile_id:int,
     *   source_id:string,
     *   wholesale_touched:bool,
     *   source_id_count:int
     * }>
     */
    protected function collapsePairs(Collection $rawRows): Collection
    {
        return $rawRows
            ->map(function (object $row): array {
                return [
                    'old_profile_id' => (int) ($row->old_profile_id ?? 0),
                    'target_profile_id' => (int) ($row->target_profile_id ?? 0),
                    'source_id' => (string) ($row->source_id ?? ''),
                    'wholesale_touched' => ((int) ($row->has_wholesale_link ?? 0) > 0)
                        || ((int) ($row->has_wholesale_external ?? 0) > 0),
                ];
            })
            ->filter(fn (array $row): bool => ($row['old_profile_id'] ?? 0) > 0 && ($row['target_profile_id'] ?? 0) > 0)
            ->groupBy(fn (array $row): string => $row['old_profile_id'] . ':' . $row['target_profile_id'])
            ->map(function (Collection $rows): array {
                $sourceIds = $rows
                    ->pluck('source_id')
                    ->map(fn ($value): string => trim((string) $value))
                    ->filter(fn (string $value): bool => $value !== '')
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $first = $rows->first();
                $firstSource = $sourceIds[0] ?? ((string) ($first['source_id'] ?? ''));

                return [
                    'old_profile_id' => (int) ($first['old_profile_id'] ?? 0),
                    'target_profile_id' => (int) ($first['target_profile_id'] ?? 0),
                    'source_id' => $firstSource,
                    'source_id_count' => count($sourceIds),
                    'wholesale_touched' => $rows->contains(fn (array $row): bool => (bool) ($row['wholesale_touched'] ?? false)),
                ];
            })
            ->sortBy([
                ['old_profile_id', 'asc'],
                ['target_profile_id', 'asc'],
            ])
            ->values();
    }

    protected function countRowsByColumn(string $table, string $column, array $profileIds): int
    {
        $profileIds = array_values(array_unique(array_filter(array_map('intval', $profileIds), fn (int $value): bool => $value > 0)));
        if ($profileIds === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (int) DB::table($table)->whereIn($column, $profileIds)->count();
    }

    protected function sumBalances(array $profileIds): float
    {
        $profileIds = array_values(array_unique(array_filter(array_map('intval', $profileIds), fn (int $value): bool => $value > 0)));
        if ($profileIds === [] || ! Schema::hasTable('candle_cash_balances')) {
            return 0.0;
        }

        return CandleCashMeasurement::normalizeStoredAmount(
            DB::table('candle_cash_balances')
                ->whereIn('marketing_profile_id', $profileIds)
                ->sum('balance')
        );
    }

    protected function sumLedger(array $profileIds): float
    {
        $profileIds = array_values(array_unique(array_filter(array_map('intval', $profileIds), fn (int $value): bool => $value > 0)));
        if ($profileIds === [] || ! Schema::hasTable('candle_cash_transactions')) {
            return 0.0;
        }

        return CandleCashMeasurement::normalizeStoredAmount(
            DB::table('candle_cash_transactions')
                ->whereIn('marketing_profile_id', $profileIds)
                ->sum('candle_cash_delta')
        );
    }

    /**
     * @return array{old_balance_sum:float,target_balance_sum:float,old_ledger_sum:float,target_ledger_sum:float,difference:float,reconciled:bool}
     */
    protected function postTotals(array $oldIds, array $targetIds): array
    {
        $oldBalance = $this->sumBalances($oldIds);
        $targetBalance = $this->sumBalances($targetIds);
        $oldLedger = $this->sumLedger($oldIds);
        $targetLedger = $this->sumLedger($targetIds);

        $difference = CandleCashMeasurement::normalizeStoredAmount(
            ($oldLedger + $targetLedger) - ($oldBalance + $targetBalance)
        );

        return [
            'old_balance_sum' => $oldBalance,
            'target_balance_sum' => $targetBalance,
            'old_ledger_sum' => $oldLedger,
            'target_ledger_sum' => $targetLedger,
            'difference' => $difference,
            'reconciled' => abs($difference) < 0.005,
        ];
    }

    /**
     * @param  array<int,int>  $map
     */
    protected function updateMappedProfileColumn(string $table, string $column, array $map): int
    {
        if ($map === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', array_keys($map)), fn (int $value): bool => $value > 0)));
        if ($ids === []) {
            return 0;
        }

        $caseParts = [];
        foreach ($map as $oldId => $targetId) {
            $old = (int) $oldId;
            $target = (int) $targetId;
            if ($old <= 0 || $target <= 0) {
                continue;
            }
            $caseParts[] = sprintf('WHEN %d THEN %d', $old, $target);
        }

        if ($caseParts === []) {
            return 0;
        }

        $caseSql = sprintf(
            'CASE %s %s ELSE %s END',
            $column,
            implode(' ', $caseParts),
            $column
        );

        $updates = [
            $column => DB::raw($caseSql),
        ];

        if (Schema::hasColumn($table, 'updated_at')) {
            $updates['updated_at'] = now();
        }

        return DB::table($table)
            ->whereIn($column, $ids)
            ->update($updates);
    }

    /**
     * @param  array<int,int>  $profileIds
     * @return array{inserts:int,updates:int,unchanged:int}
     */
    protected function recomputeBalances(array $profileIds): array
    {
        $profileIds = array_values(array_unique(array_filter(array_map('intval', $profileIds), fn (int $value): bool => $value > 0)));
        if ($profileIds === [] || ! Schema::hasTable('candle_cash_balances')) {
            return ['inserts' => 0, 'updates' => 0, 'unchanged' => 0];
        }

        $ledgerByProfile = DB::table('candle_cash_transactions')
            ->select('marketing_profile_id')
            ->selectRaw('ROUND(COALESCE(SUM(candle_cash_delta), 0), 3) as ledger_balance')
            ->whereIn('marketing_profile_id', $profileIds)
            ->groupBy('marketing_profile_id')
            ->pluck('ledger_balance', 'marketing_profile_id');

        $balanceByProfile = DB::table('candle_cash_balances')
            ->whereIn('marketing_profile_id', $profileIds)
            ->pluck('balance', 'marketing_profile_id');

        $summary = ['inserts' => 0, 'updates' => 0, 'unchanged' => 0];

        foreach ($profileIds as $profileId) {
            $target = CandleCashMeasurement::normalizeStoredAmount($ledgerByProfile[$profileId] ?? 0);
            $hasExisting = $balanceByProfile->has($profileId);
            $existing = CandleCashMeasurement::normalizeStoredAmount($balanceByProfile[$profileId] ?? 0);

            if (! $hasExisting) {
                if (abs($target) < self::BALANCE_TOLERANCE) {
                    $summary['unchanged']++;

                    continue;
                }

                DB::table('candle_cash_balances')->insert([
                    'marketing_profile_id' => $profileId,
                    'balance' => $target,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $summary['inserts']++;

                continue;
            }

            if (abs($existing - $target) < self::BALANCE_TOLERANCE) {
                $summary['unchanged']++;

                continue;
            }

            DB::table('candle_cash_balances')
                ->where('marketing_profile_id', $profileId)
                ->update([
                    'balance' => $target,
                    'updated_at' => now(),
                ]);
            $summary['updates']++;
        }

        return $summary;
    }

    protected function rawMappingRows(int $tenantId, string $store, ?int $profileId = null): Collection
    {
        $storePattern = $store . ':%';

        return DB::table('marketing_profile_links as old_link')
            ->join('marketing_profiles as old_profile', 'old_profile.id', '=', 'old_link.marketing_profile_id')
            ->join('marketing_profile_links as target_link', function ($join) use ($tenantId): void {
                $join->on('target_link.source_type', '=', 'old_link.source_type')
                    ->on('target_link.source_id', '=', 'old_link.source_id')
                    ->where('target_link.source_type', 'shopify_customer')
                    ->where('target_link.tenant_id', $tenantId);
            })
            ->whereNull('old_profile.tenant_id')
            ->where('old_link.source_type', 'shopify_customer')
            ->whereNull('old_link.tenant_id')
            ->where('old_link.source_id', 'like', $storePattern)
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('candle_cash_transactions as legacy_tx')
                    ->whereColumn('legacy_tx.marketing_profile_id', 'old_profile.id')
                    ->where('legacy_tx.legacy_points_origin', true);
            })
            ->when(
                $profileId !== null,
                fn ($query) => $query->where('old_profile.id', $profileId)
            )
            ->select([
                'old_profile.id as old_profile_id',
                'target_link.marketing_profile_id as target_profile_id',
                'old_link.source_id',
            ])
            ->selectRaw(
                "EXISTS (
                    SELECT 1
                    FROM marketing_profile_links as old_wholesale_link
                    WHERE old_wholesale_link.marketing_profile_id = old_profile.id
                      AND old_wholesale_link.source_type = 'shopify_customer'
                      AND old_wholesale_link.source_id LIKE 'wholesale:%'
                ) as has_wholesale_link"
            )
            ->selectRaw(
                "EXISTS (
                    SELECT 1
                    FROM customer_external_profiles as old_external
                    WHERE old_external.marketing_profile_id = old_profile.id
                      AND old_external.provider = 'shopify'
                      AND old_external.store_key = 'wholesale'
                ) as has_wholesale_external"
            )
            ->distinct()
            ->orderBy('old_profile.id')
            ->get();
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    protected function normalizedStore(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
