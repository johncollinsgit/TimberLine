<?php

namespace App\Console\Commands;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Support\Facades\DB;

class MarketingImportGrowaveOpeningBalances extends Command
{
    protected $signature = 'marketing:import-growave-opening-balances
        {--limit=500 : Maximum profiles to process}
        {--store= : Optional store_key filter}
        {--profile-id= : Optional marketing profile ID filter}
        {--dry-run : Preview import actions without writing}';

    protected $description = 'Import latest Growave points snapshots into Candle Cash as opening-balance ledger entries.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $store = $this->nullableString($this->option('store'));
        $profileId = $this->integerOption('profile-id');

        $summary = [
            'candidates' => 0,
            'imported' => 0,
            'skipped_existing_import' => 0,
            'skipped_existing_transactions' => 0,
            'dry_run_would_import' => 0,
            'errors' => 0,
        ];

        $query = $this->latestExternalSnapshotQuery($store, $profileId);

        foreach ($query->limit($limit)->get() as $external) {
            $summary['candidates']++;

            $marketingProfileId = (int) ($external->marketing_profile_id ?? 0);
            if ($marketingProfileId <= 0) {
                continue;
            }

            $hasOpeningImport = CandleCashTransaction::query()
                ->where('marketing_profile_id', $marketingProfileId)
                ->where('type', 'import_opening_balance')
                ->where('source', 'growave')
                ->exists();
            if ($hasOpeningImport) {
                $summary['skipped_existing_import']++;

                continue;
            }

            $hasAnyTransactions = CandleCashTransaction::query()
                ->where('marketing_profile_id', $marketingProfileId)
                ->exists();
            if ($hasAnyTransactions) {
                $summary['skipped_existing_transactions']++;

                continue;
            }

            $legacyPoints = (int) ($external->points_balance ?? 0);
            $targetBalance = CandleCashMeasurement::legacyPointsToStartingCandleCash($legacyPoints);

            if ($dryRun) {
                $summary['dry_run_would_import']++;

                continue;
            }

            try {
                $result = DB::transaction(function () use ($external, $marketingProfileId, $targetBalance, $legacyPoints): string {
                    $hasOpeningImport = CandleCashTransaction::query()
                        ->where('marketing_profile_id', $marketingProfileId)
                        ->where('type', 'import_opening_balance')
                        ->where('source', 'growave')
                        ->lockForUpdate()
                        ->exists();
                    if ($hasOpeningImport) {
                        return 'skipped_existing_import';
                    }

                    $hasAnyTransactions = CandleCashTransaction::query()
                        ->where('marketing_profile_id', $marketingProfileId)
                        ->lockForUpdate()
                        ->exists();
                    if ($hasAnyTransactions) {
                        return 'skipped_existing_transactions';
                    }

                    $balance = CandleCashBalance::query()
                        ->lockForUpdate()
                        ->firstOrCreate(
                            ['marketing_profile_id' => $marketingProfileId],
                            ['balance' => 0]
                        );

                    $currentBalance = CandleCashMeasurement::normalizeStoredAmount($balance->balance);
                    $delta = CandleCashMeasurement::normalizeStoredAmount($targetBalance - $currentBalance);

                    $balance->forceFill(['balance' => $targetBalance])->save();

                    CandleCashTransaction::query()->create([
                        'marketing_profile_id' => $marketingProfileId,
                        'type' => 'import_opening_balance',
                        'points' => $legacyPoints,
                        'legacy_points_origin' => true,
                        'legacy_points_value' => $legacyPoints,
                        'candle_cash_delta' => $delta,
                        'source' => 'growave',
                        'source_id' => (string) $external->id,
                        'description' => 'Imported historical Growave starting balance from external snapshot #' . $external->id,
                    ]);

                    return 'imported';
                });

                $summary[$result]++;
            } catch (\Throwable $e) {
                $summary['errors']++;

                $this->warn('profile_id=' . $marketingProfileId . ' error=' . $e->getMessage());
            }
        }

        $this->line($dryRun ? 'mode=dry-run' : 'mode=live-import');
        foreach (['candidates', 'imported', 'skipped_existing_import', 'skipped_existing_transactions', 'dry_run_would_import', 'errors'] as $key) {
            $this->line($key . '=' . (int) ($summary[$key] ?? 0));
        }

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function latestExternalSnapshotQuery(?string $store, ?int $profileId): Builder
    {
        $latestByProfile = CustomerExternalProfile::query()
            ->selectRaw('MAX(id) as id')
            ->where('integration', 'growave')
            ->whereNotNull('marketing_profile_id')
            ->whereNotNull('points_balance')
            ->when($store !== null, fn (Builder $query) => $query->where('store_key', $store))
            ->when($profileId !== null, fn (Builder $query) => $query->where('marketing_profile_id', $profileId))
            ->groupBy('marketing_profile_id');

        return CustomerExternalProfile::query()
            ->select('customer_external_profiles.*')
            ->joinSub($latestByProfile, 'latest_external', function ($join): void {
                $join->on('latest_external.id', '=', 'customer_external_profiles.id');
            })
            ->orderBy('customer_external_profiles.id');
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function integerOption(string $key): ?int
    {
        $value = trim((string) $this->option($key));
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
