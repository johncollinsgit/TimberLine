<?php

namespace App\Console\Commands;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarketingReconcileCandleCashBalances extends Command
{
    protected $signature = 'marketing:reconcile-candle-cash-balances
        {--tenant-id= : Restrict reconciliation to a tenant id}
        {--profile-id= : Restrict reconciliation to a single marketing profile id}
        {--chunk=1000 : Chunk size for scoped profile scans}
        {--apply : Apply balance-table updates from ledger net sums}';

    protected $description = 'Preview or reconcile candle_cash_balances against canonical ledger net sums.';

    public function handle(): int
    {
        $tenantId = $this->positiveInt($this->option('tenant-id'));
        $profileId = $this->positiveInt($this->option('profile-id'));
        $chunk = max(1, (int) ($this->positiveInt($this->option('chunk')) ?? 1000));
        $apply = (bool) $this->option('apply');

        $scopeQuery = $this->scopeProfilesQuery($tenantId, $profileId);
        $scopeCount = (int) (clone $scopeQuery)->count();

        if ($scopeCount === 0) {
            $this->line('tenant_id=' . ($tenantId !== null ? (string) $tenantId : 'all'));
            $this->line('profile_id=' . ($profileId !== null ? (string) $profileId : 'all'));
            $this->line('mode=' . ($apply ? 'apply' : 'preview'));
            $this->line('scanned_profiles=0');
            $this->line('mismatches=0');
            $this->line('inserts=0');
            $this->line('updates=0');
            $this->line('unchanged=0');
            $this->line('post_mismatches=0');
            $this->line('ledger_balance=0.000');
            $this->line('balance_table=0.000');
            $this->line('difference=0.000');
            $this->line('reconciled=yes');

            return self::SUCCESS;
        }

        $summary = [
            'scanned_profiles' => 0,
            'mismatches' => 0,
            'inserts' => 0,
            'updates' => 0,
            'unchanged' => 0,
        ];

        $scopeQuery
            ->select('id')
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $rows) use ($apply, &$summary): void {
                $profileIds = $rows
                    ->pluck('id')
                    ->map(fn ($value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->values()
                    ->all();

                if ($profileIds === []) {
                    return;
                }

                $summary['scanned_profiles'] += count($profileIds);

                $ledgerByProfile = CandleCashTransaction::query()
                    ->whereIn('marketing_profile_id', $profileIds)
                    ->select('marketing_profile_id')
                    ->selectRaw('ROUND(COALESCE(SUM(candle_cash_delta), 0), 3) as ledger_balance')
                    ->groupBy('marketing_profile_id')
                    ->pluck('ledger_balance', 'marketing_profile_id');

                $balancesByProfile = CandleCashBalance::query()
                    ->whereIn('marketing_profile_id', $profileIds)
                    ->get(['marketing_profile_id', 'balance'])
                    ->keyBy('marketing_profile_id');

                DB::transaction(function () use ($apply, $profileIds, $ledgerByProfile, $balancesByProfile, &$summary): void {
                    foreach ($profileIds as $id) {
                        $target = CandleCashMeasurement::normalizeStoredAmount($ledgerByProfile[$id] ?? 0);
                        $existing = $balancesByProfile->get($id);

                        if (! $existing) {
                            if (abs($target) < 0.0005) {
                                $summary['unchanged']++;

                                continue;
                            }

                            $summary['mismatches']++;

                            if ($apply) {
                                CandleCashBalance::query()->create([
                                    'marketing_profile_id' => $id,
                                    'balance' => $target,
                                ]);
                                $summary['inserts']++;
                            }

                            continue;
                        }

                        $stored = CandleCashMeasurement::normalizeStoredAmount($existing->balance ?? 0);
                        if (abs($stored - $target) < 0.0005) {
                            $summary['unchanged']++;

                            continue;
                        }

                        $summary['mismatches']++;

                        if ($apply) {
                            CandleCashBalance::query()
                                ->where('marketing_profile_id', $id)
                                ->update([
                                    'balance' => $target,
                                    'updated_at' => now(),
                                ]);
                            $summary['updates']++;
                        }
                    }
                });
            });

        $postMismatches = $this->mismatchCount($tenantId, $profileId);
        $ledgerBalance = $this->ledgerBalance($tenantId, $profileId);
        $balanceTable = $this->balanceTable($tenantId, $profileId);
        $difference = CandleCashMeasurement::normalizeStoredAmount($ledgerBalance - $balanceTable);
        $reconciled = abs($difference) < 0.005;

        $this->line('tenant_id=' . ($tenantId !== null ? (string) $tenantId : 'all'));
        $this->line('profile_id=' . ($profileId !== null ? (string) $profileId : 'all'));
        $this->line('mode=' . ($apply ? 'apply' : 'preview'));
        $this->line('scanned_profiles=' . (int) $summary['scanned_profiles']);
        $this->line('mismatches=' . (int) $summary['mismatches']);
        $this->line('inserts=' . (int) $summary['inserts']);
        $this->line('updates=' . (int) $summary['updates']);
        $this->line('unchanged=' . (int) $summary['unchanged']);
        $this->line('post_mismatches=' . $postMismatches);
        $this->line('ledger_balance=' . $this->formatAmount($ledgerBalance));
        $this->line('balance_table=' . $this->formatAmount($balanceTable));
        $this->line('difference=' . $this->formatAmount($difference));
        $this->line('reconciled=' . ($reconciled ? 'yes' : 'no'));

        if (! $apply && (int) $summary['mismatches'] > 0) {
            $this->warn('Preview found drift. Re-run with --apply to upsert candle_cash_balances from ledger net sums.');
        }

        if ($apply && ! $reconciled) {
            $this->warn('Apply finished but drift remains. Review scoped rows for concurrent writes or out-of-scope profile filters.');
        }

        if ($apply) {
            return $reconciled ? self::SUCCESS : self::FAILURE;
        }

        return ((int) $summary['mismatches'] === 0) ? self::SUCCESS : self::FAILURE;
    }

    protected function scopeProfilesQuery(?int $tenantId, ?int $profileId): EloquentBuilder
    {
        return MarketingProfile::query()
            ->when(
                $tenantId !== null,
                fn (EloquentBuilder $query): EloquentBuilder => $query->where('tenant_id', $tenantId)
            )
            ->when(
                $profileId !== null,
                fn (EloquentBuilder $query): EloquentBuilder => $query->where('id', $profileId)
            );
    }

    protected function mismatchCount(?int $tenantId, ?int $profileId): int
    {
        $ledgerSubquery = CandleCashTransaction::query()
            ->select('marketing_profile_id')
            ->selectRaw('ROUND(COALESCE(SUM(candle_cash_delta), 0), 3) as ledger_balance')
            ->groupBy('marketing_profile_id');

        return (int) MarketingProfile::query()
            ->leftJoin('candle_cash_balances as cb', 'cb.marketing_profile_id', '=', 'marketing_profiles.id')
            ->leftJoinSub($ledgerSubquery, 'ledger', function ($join): void {
                $join->on('ledger.marketing_profile_id', '=', 'marketing_profiles.id');
            })
            ->when(
                $tenantId !== null,
                fn ($query) => $query->where('marketing_profiles.tenant_id', $tenantId)
            )
            ->when(
                $profileId !== null,
                fn ($query) => $query->where('marketing_profiles.id', $profileId)
            )
            ->whereRaw('ABS(COALESCE(cb.balance, 0) - COALESCE(ledger.ledger_balance, 0)) >= 0.0005')
            ->count();
    }

    protected function ledgerBalance(?int $tenantId, ?int $profileId): float
    {
        return CandleCashMeasurement::normalizeStoredAmount(
            MarketingProfile::query()
                ->join('candle_cash_transactions as cct', 'cct.marketing_profile_id', '=', 'marketing_profiles.id')
                ->when(
                    $tenantId !== null,
                    fn (EloquentBuilder $query): EloquentBuilder => $query->where('marketing_profiles.tenant_id', $tenantId)
                )
                ->when(
                    $profileId !== null,
                    fn (EloquentBuilder $query): EloquentBuilder => $query->where('marketing_profiles.id', $profileId)
                )
                ->sum('cct.candle_cash_delta')
        );
    }

    protected function balanceTable(?int $tenantId, ?int $profileId): float
    {
        return CandleCashMeasurement::normalizeStoredAmount(
            MarketingProfile::query()
                ->leftJoin('candle_cash_balances as ccb', 'ccb.marketing_profile_id', '=', 'marketing_profiles.id')
                ->when(
                    $tenantId !== null,
                    fn (EloquentBuilder $query): EloquentBuilder => $query->where('marketing_profiles.tenant_id', $tenantId)
                )
                ->when(
                    $profileId !== null,
                    fn (EloquentBuilder $query): EloquentBuilder => $query->where('marketing_profiles.id', $profileId)
                )
                ->sum(DB::raw('COALESCE(ccb.balance, 0)'))
        );
    }

    protected function formatAmount(float $value): string
    {
        return number_format(CandleCashMeasurement::normalizeStoredAmount($value), 3, '.', '');
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}
