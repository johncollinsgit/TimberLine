<?php

namespace App\Console\Commands;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use App\Support\Marketing\CandleCashMeasurement;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MarketingImportGrowaveOpeningBalances extends Command
{
    protected $signature = 'marketing:import-growave-opening-balances
        {--limit=500 : Maximum profiles to process}
        {--store= : Optional store_key filter}
        {--tenant-id= : Tenant owner for this backfill run (required when --store is omitted)}
        {--profile-id= : Optional marketing profile ID filter}
        {--dry-run : Preview import actions without writing}';

    protected $description = 'Import latest Growave points snapshots into Candle Cash as opening-balance ledger entries.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $store = $this->nullableString($this->option('store'));
        $providedTenantId = $this->integerOption('tenant-id');
        $profileId = $this->integerOption('profile-id');

        try {
            $tenantId = $this->resolveBackfillTenant($store, $providedTenantId);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $summary = [
            'candidates' => 0,
            'imported' => 0,
            'skipped_existing_import' => 0,
            'skipped_existing_transactions' => 0,
            'skipped_tenant_mismatch' => 0,
            'dry_run_would_import' => 0,
            'errors' => 0,
        ];

        $run = MarketingImportRun::query()->create([
            'tenant_id' => $tenantId,
            'type' => 'growave_opening_balance_backfill',
            'status' => 'running',
            'source_label' => $store !== null ? 'growave:' . $store : 'growave:tenant:' . $tenantId,
            'started_at' => now(),
            'summary' => [
                'store' => $store,
                'tenant_id' => $tenantId,
                'profile_id' => $profileId,
                'limit' => $limit,
                'dry_run' => $dryRun,
            ],
        ]);

        $query = $this->latestExternalSnapshotQuery($store, $profileId, $tenantId);

        try {
            foreach ($query->limit($limit)->get() as $external) {
                $summary['candidates']++;

                $externalTenantId = $this->positiveInt($external->tenant_id);
                $profileTenantId = $this->positiveInt($external->profile_tenant_id ?? null);
                if ($externalTenantId !== $tenantId || $profileTenantId !== $tenantId) {
                    $summary['skipped_tenant_mismatch']++;

                    continue;
                }

                $marketingProfileId = (int) ($external->marketing_profile_id ?? 0);
                if ($marketingProfileId <= 0) {
                    $summary['skipped_tenant_mismatch']++;

                    continue;
                }

                $hasOpeningImport = CandleCashTransaction::query()
                    ->where('marketing_profile_id', $marketingProfileId)
                    ->whereHas('profile', fn (Builder $query) => $query->forTenantId($tenantId))
                    ->where('type', 'import_opening_balance')
                    ->where('source', 'growave')
                    ->exists();
                if ($hasOpeningImport) {
                    $summary['skipped_existing_import']++;

                    continue;
                }

                $hasAnyTransactions = CandleCashTransaction::query()
                    ->where('marketing_profile_id', $marketingProfileId)
                    ->whereHas('profile', fn (Builder $query) => $query->forTenantId($tenantId))
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
                    $result = DB::transaction(function () use ($external, $marketingProfileId, $targetBalance, $legacyPoints, $tenantId): string {
                        $hasOpeningImport = CandleCashTransaction::query()
                            ->where('marketing_profile_id', $marketingProfileId)
                            ->whereHas('profile', fn (Builder $query) => $query->forTenantId($tenantId))
                            ->where('type', 'import_opening_balance')
                            ->where('source', 'growave')
                            ->lockForUpdate()
                            ->exists();
                        if ($hasOpeningImport) {
                            return 'skipped_existing_import';
                        }

                        $hasAnyTransactions = CandleCashTransaction::query()
                            ->where('marketing_profile_id', $marketingProfileId)
                            ->whereHas('profile', fn (Builder $query) => $query->forTenantId($tenantId))
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
        } catch (\Throwable $e) {
            $summary['errors']++;

            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $e->getMessage(),
            ])->save();

            throw $e;
        }

        $run->forceFill([
            'status' => ((int) ($summary['errors'] ?? 0)) > 0 ? 'partial' : 'completed',
            'finished_at' => now(),
            'summary' => $summary,
        ])->save();

        $this->line($dryRun ? 'mode=dry-run' : 'mode=live-import');
        foreach (['candidates', 'imported', 'skipped_existing_import', 'skipped_existing_transactions', 'skipped_tenant_mismatch', 'dry_run_would_import', 'errors'] as $key) {
            $this->line($key . '=' . (int) ($summary[$key] ?? 0));
        }

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function latestExternalSnapshotQuery(?string $store, ?int $profileId, int $tenantId): Builder
    {
        $latestByProfile = CustomerExternalProfile::query()
            ->selectRaw('MAX(customer_external_profiles.id) as id')
            ->join('marketing_profiles', 'marketing_profiles.id', '=', 'customer_external_profiles.marketing_profile_id')
            ->where('customer_external_profiles.integration', 'growave')
            ->whereNotNull('customer_external_profiles.marketing_profile_id')
            ->whereNotNull('customer_external_profiles.points_balance')
            ->where('customer_external_profiles.tenant_id', $tenantId)
            ->where('marketing_profiles.tenant_id', $tenantId)
            ->when($store !== null, fn (Builder $query) => $query->where('customer_external_profiles.store_key', $store))
            ->when($profileId !== null, fn (Builder $query) => $query->where('customer_external_profiles.marketing_profile_id', $profileId))
            ->groupBy('customer_external_profiles.marketing_profile_id');

        return CustomerExternalProfile::query()
            ->select('customer_external_profiles.*', 'marketing_profiles.tenant_id as profile_tenant_id')
            ->joinSub($latestByProfile, 'latest_external', function ($join): void {
                $join->on('latest_external.id', '=', 'customer_external_profiles.id');
            })
            ->join('marketing_profiles', 'marketing_profiles.id', '=', 'customer_external_profiles.marketing_profile_id')
            ->where('customer_external_profiles.tenant_id', $tenantId)
            ->where('marketing_profiles.tenant_id', $tenantId)
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

    protected function resolveBackfillTenant(?string $storeKey, ?int $tenantId): int
    {
        if ($tenantId !== null && $storeKey !== null) {
            $storeTenantId = $this->tenantIdFromStoreKey($storeKey);
            if ($storeTenantId !== $tenantId) {
                throw new RuntimeException('Growave opening balance backfill store owner conflicts with provided tenant context.');
            }
        }

        if ($tenantId !== null) {
            return $tenantId;
        }

        if ($storeKey === null) {
            throw new RuntimeException('Growave opening balance backfill requires --tenant-id or --store to prove tenant ownership.');
        }

        return $this->tenantIdFromStoreKey($storeKey);
    }

    protected function tenantIdFromStoreKey(string $storeKey): int
    {
        $normalized = strtolower(trim($storeKey));
        $store = ShopifyStores::find($normalized);
        if (! $store) {
            throw new RuntimeException("Unknown Shopify store key '{$normalized}'.");
        }

        $tenantId = $this->positiveInt($store['tenant_id'] ?? null);
        if ($tenantId === null) {
            throw new RuntimeException("Shopify store '{$normalized}' is not assigned to a tenant.");
        }

        return $tenantId;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
