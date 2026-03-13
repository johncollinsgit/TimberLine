<?php

namespace App\Console\Commands;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingReviewHistory;
use App\Models\MarketingReviewSummary;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketingMergeGrowaveSnapshot extends Command
{
    protected $signature = 'marketing:merge-growave-snapshot
        {snapshot : Path to the donor SQLite database}
        {--store= : Optional store_key filter}
        {--chunk=500 : Chunk size for donor reads}
        {--dry-run : Preview actions without writing to the target database}';

    protected $description = 'Merge Growave-derived snapshot rows from a donor SQLite database into the current database using provider-stable keys.';

    public function __construct(
        protected MarketingIdentityNormalizer $normalizer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $snapshotPath = $this->resolveSnapshotPath((string) $this->argument('snapshot'));
        if ($snapshotPath === null) {
            $this->error('snapshot database not found');

            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $store = $this->nullableString($this->option('store'));
        $dryRun = (bool) $this->option('dry-run');

        $sourceConnection = $this->configureSourceConnection($snapshotPath);
        $this->ensureSourceTables($sourceConnection);

        $context = $this->buildContext($store, $dryRun);
        $summary = [
            'snapshot' => $snapshotPath,
            'store' => $store,
            'externals_created' => 0,
            'externals_updated' => 0,
            'review_summaries_created' => 0,
            'review_summaries_updated' => 0,
            'review_histories_created' => 0,
            'review_histories_updated' => 0,
            'transactions_created' => 0,
            'transactions_updated' => 0,
            'transactions_skipped_no_profile' => 0,
            'balances_recomputed' => 0,
        ];

        $this->importExternalProfiles($sourceConnection, $context, $summary, $chunkSize);
        $this->importReviewSummaries($sourceConnection, $context, $summary, $chunkSize);
        $this->importReviewHistories($sourceConnection, $context, $summary, $chunkSize);
        $this->importTransactions($sourceConnection, $context, $summary, $chunkSize);

        if (! $dryRun) {
            $summary['balances_recomputed'] = $this->refreshBalances(array_keys($context['touched_profile_ids']));
        }

        DB::purge($sourceConnection);

        $this->line('mode=' . ($dryRun ? 'dry-run' : 'merge'));
        foreach ($summary as $key => $value) {
            $this->line($key . '=' . (is_scalar($value) || $value === null ? (string) $value : json_encode($value)));
        }

        return self::SUCCESS;
    }

    protected function resolveSnapshotPath(string $path): ?string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return null;
        }

        if (is_file($trimmed)) {
            return realpath($trimmed) ?: $trimmed;
        }

        $candidate = base_path($trimmed);
        if (is_file($candidate)) {
            return realpath($candidate) ?: $candidate;
        }

        return null;
    }

    protected function configureSourceConnection(string $snapshotPath): string
    {
        $connection = 'growave_snapshot_merge';

        Config::set('database.connections.' . $connection, [
            'driver' => 'sqlite',
            'database' => $snapshotPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge($connection);

        return $connection;
    }

    protected function ensureSourceTables(string $connection): void
    {
        foreach ([
            'customer_external_profiles',
            'candle_cash_transactions',
            'marketing_review_summaries',
            'marketing_review_histories',
        ] as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                throw new \RuntimeException('missing donor table: ' . $table);
            }
        }
    }

    /**
     * @return array{
     *   store:?string,
     *   dry_run:bool,
     *   shopify_profile_ids_by_external_key:array<string,int>,
     *   growave_profile_ids_by_external_key:array<string,int>,
     *   marketing_profile_ids_by_email:array<string,int>,
     *   marketing_profile_ids_by_phone:array<string,int>,
     *   summary_ids_by_external_key:array<string,int>,
     *   touched_profile_ids:array<int,bool>
     * }
     */
    protected function buildContext(?string $store, bool $dryRun): array
    {
        $shopifyProfileIdsByExternalKey = [];
        CustomerExternalProfile::query()
            ->where('integration', 'shopify_customer')
            ->when($store !== null, fn ($query) => $query->where('store_key', $store))
            ->get(['store_key', 'external_customer_id', 'marketing_profile_id'])
            ->each(function (CustomerExternalProfile $row) use (&$shopifyProfileIdsByExternalKey): void {
                $profileId = (int) ($row->marketing_profile_id ?? 0);
                if ($profileId <= 0) {
                    return;
                }

                $shopifyProfileIdsByExternalKey[$this->externalKey($row->store_key, $row->external_customer_id)] = $profileId;
            });

        $growaveProfileIdsByExternalKey = [];
        CustomerExternalProfile::query()
            ->where('integration', 'growave')
            ->when($store !== null, fn ($query) => $query->where('store_key', $store))
            ->get(['store_key', 'external_customer_id', 'marketing_profile_id'])
            ->each(function (CustomerExternalProfile $row) use (&$growaveProfileIdsByExternalKey): void {
                $profileId = (int) ($row->marketing_profile_id ?? 0);
                if ($profileId <= 0) {
                    return;
                }

                $growaveProfileIdsByExternalKey[$this->externalKey($row->store_key, $row->external_customer_id)] = $profileId;
            });

        $marketingProfileIdsByEmail = $this->buildUniqueIdentityMap(
            MarketingProfile::query()
                ->whereNotNull('normalized_email')
                ->get(['id', 'normalized_email']),
            'normalized_email'
        );

        $marketingProfileIdsByPhone = $this->buildUniqueIdentityMap(
            MarketingProfile::query()
                ->whereNotNull('normalized_phone')
                ->get(['id', 'normalized_phone']),
            'normalized_phone'
        );

        $summaryIdsByExternalKey = [];
        if (! $dryRun) {
            MarketingReviewSummary::query()
                ->where('integration', 'growave')
                ->when($store !== null, fn ($query) => $query->where('store_key', $store))
                ->get(['id', 'store_key', 'external_customer_id'])
                ->each(function (MarketingReviewSummary $summary) use (&$summaryIdsByExternalKey): void {
                    $summaryIdsByExternalKey[$this->externalKey($summary->store_key, $summary->external_customer_id)] = (int) $summary->id;
                });
        }

        return [
            'store' => $store,
            'dry_run' => $dryRun,
            'shopify_profile_ids_by_external_key' => $shopifyProfileIdsByExternalKey,
            'growave_profile_ids_by_external_key' => $growaveProfileIdsByExternalKey,
            'marketing_profile_ids_by_email' => $marketingProfileIdsByEmail,
            'marketing_profile_ids_by_phone' => $marketingProfileIdsByPhone,
            'summary_ids_by_external_key' => $summaryIdsByExternalKey,
            'touched_profile_ids' => [],
        ];
    }

    /**
     * @param iterable<int,\Illuminate\Database\Eloquent\Model|object> $rows
     * @return array<string,int>
     */
    protected function buildUniqueIdentityMap(iterable $rows, string $column): array
    {
        $counts = [];
        $profileIds = [];

        foreach ($rows as $row) {
            $identity = $this->nullableString($row->{$column} ?? null);
            $profileId = $row->id ?? null;
            if ($identity === null || ! is_numeric($profileId)) {
                continue;
            }

            $counts[$identity] = ($counts[$identity] ?? 0) + 1;
            $profileIds[$identity] = (int) $profileId;
        }

        $unique = [];
        foreach ($profileIds as $identity => $profileId) {
            if (($counts[$identity] ?? 0) !== 1) {
                continue;
            }

            $unique[$identity] = (int) $profileId;
        }

        return $unique;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $summary
     */
    protected function importExternalProfiles(
        string $sourceConnection,
        array &$context,
        array &$summary,
        int $chunkSize
    ): void {
        DB::connection($sourceConnection)
            ->table('customer_external_profiles')
            ->where('integration', 'growave')
            ->when($context['store'] !== null, fn ($query) => $query->where('store_key', $context['store']))
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use (&$context, &$summary): void {
                foreach ($rows as $row) {
                    $storeKey = $this->nullableString($row->store_key ?? null);
                    $customerId = $this->nullableString($row->external_customer_id ?? null);
                    if ($customerId === null) {
                        continue;
                    }

                    $lookup = [
                        'provider' => $this->nullableString($row->provider ?? null) ?: 'shopify',
                        'integration' => 'growave',
                        'store_key' => $storeKey,
                        'external_customer_id' => $customerId,
                    ];

                    $existing = CustomerExternalProfile::query()->where($lookup)->first();
                    $marketingProfileId = $this->resolveTargetMarketingProfileId(
                        $storeKey,
                        $customerId,
                        $this->nullableString($row->email ?? null),
                        $this->nullableString($row->normalized_email ?? null),
                        $this->nullableString($row->phone ?? null),
                        $this->nullableString($row->normalized_phone ?? null),
                        $context
                    ) ?: (int) ($existing?->marketing_profile_id ?? 0);

                    if (! $context['dry_run']) {
                        $external = $existing ?: new CustomerExternalProfile();
                        $this->forceFillAndSave($external, array_merge($lookup, [
                        'marketing_profile_id' => $marketingProfileId > 0 ? $marketingProfileId : null,
                        'external_customer_gid' => $this->nullableString($row->external_customer_gid ?? null),
                        'first_name' => $this->nullableString($row->first_name ?? null),
                        'last_name' => $this->nullableString($row->last_name ?? null),
                        'full_name' => $this->nullableString($row->full_name ?? null),
                        'email' => $this->nullableString($row->email ?? null),
                        'normalized_email' => $this->nullableString($row->normalized_email ?? null)
                            ?: $this->normalizer->normalizeEmail($this->nullableString($row->email ?? null)),
                        'phone' => $this->nullableString($row->phone ?? null),
                        'normalized_phone' => $this->nullableString($row->normalized_phone ?? null)
                            ?: $this->normalizer->normalizePhone($this->nullableString($row->phone ?? null)),
                        'accepts_marketing' => $this->nullableBool($row->accepts_marketing ?? null),
                        'order_count' => is_numeric($row->order_count ?? null) ? (int) $row->order_count : null,
                        'last_order_at' => $this->nullableString($row->last_order_at ?? null),
                        'last_activity_at' => $this->nullableString($row->last_activity_at ?? null),
                        'source_channels' => $this->jsonArray($row->source_channels ?? null),
                        'raw_metafields' => $this->jsonArray($row->raw_metafields ?? null),
                        'points_balance' => is_numeric($row->points_balance ?? null) ? (int) $row->points_balance : null,
                        'vip_tier' => $this->nullableString($row->vip_tier ?? null),
                        'referral_link' => $this->nullableString($row->referral_link ?? null),
                        'synced_at' => $this->nullableString($row->synced_at ?? null),
                        'created_at' => $this->nullableString($row->created_at ?? null) ?: now(),
                        'updated_at' => $this->nullableString($row->updated_at ?? null) ?: now(),
                        ]));
                    }

                    if ($marketingProfileId > 0) {
                        $context['growave_profile_ids_by_external_key'][$this->externalKey($storeKey, $customerId)] = $marketingProfileId;
                    }

                    $summary[$existing ? 'externals_updated' : 'externals_created']++;
                }
            }, 'id');
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $summary
     */
    protected function importReviewSummaries(
        string $sourceConnection,
        array &$context,
        array &$summary,
        int $chunkSize
    ): void {
        DB::connection($sourceConnection)
            ->table('marketing_review_summaries')
            ->where('integration', 'growave')
            ->when($context['store'] !== null, fn ($query) => $query->where('store_key', $context['store']))
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use (&$context, &$summary): void {
                foreach ($rows as $row) {
                    $storeKey = $this->nullableString($row->store_key ?? null);
                    $customerId = $this->nullableString($row->external_customer_id ?? null);
                    if ($customerId === null) {
                        continue;
                    }

                    $lookup = [
                        'provider' => $this->nullableString($row->provider ?? null) ?: 'growave',
                        'integration' => 'growave',
                        'store_key' => $storeKey,
                        'external_customer_id' => $customerId,
                    ];

                    $existing = MarketingReviewSummary::query()->where($lookup)->first();
                    $marketingProfileId = $this->resolveTargetMarketingProfileId(
                        $storeKey,
                        $customerId,
                        $this->nullableString($row->external_customer_email ?? null),
                        null,
                        null,
                        null,
                        $context
                    ) ?: (int) ($existing?->marketing_profile_id ?? 0);

                    if (! $context['dry_run']) {
                        $summaryRow = $existing ?: new MarketingReviewSummary();
                        $this->forceFillAndSave($summaryRow, array_merge($lookup, [
                        'marketing_profile_id' => $marketingProfileId > 0 ? $marketingProfileId : null,
                        'external_customer_email' => $this->nullableString($row->external_customer_email ?? null),
                        'review_count' => is_numeric($row->review_count ?? null) ? (int) $row->review_count : 0,
                        'published_review_count' => is_numeric($row->published_review_count ?? null) ? (int) $row->published_review_count : 0,
                        'average_rating' => is_numeric($row->average_rating ?? null) ? (float) $row->average_rating : null,
                        'last_reviewed_at' => $this->nullableString($row->last_reviewed_at ?? null),
                        'source_synced_at' => $this->nullableString($row->source_synced_at ?? null),
                        'raw_payload' => $this->jsonArray($row->raw_payload ?? null),
                        'created_at' => $this->nullableString($row->created_at ?? null) ?: now(),
                        'updated_at' => $this->nullableString($row->updated_at ?? null) ?: now(),
                        ]));

                        $context['summary_ids_by_external_key'][$this->externalKey($storeKey, $customerId)] = (int) $summaryRow->id;
                    }
                    $summary[$existing ? 'review_summaries_updated' : 'review_summaries_created']++;
                }
            }, 'id');
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $summary
     */
    protected function importReviewHistories(
        string $sourceConnection,
        array &$context,
        array &$summary,
        int $chunkSize
    ): void {
        DB::connection($sourceConnection)
            ->table('marketing_review_histories')
            ->where('integration', 'growave')
            ->when($context['store'] !== null, fn ($query) => $query->where('store_key', $context['store']))
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use (&$context, &$summary): void {
                foreach ($rows as $row) {
                    $storeKey = $this->nullableString($row->store_key ?? null);
                    $customerId = $this->nullableString($row->external_customer_id ?? null);
                    $reviewId = $this->nullableString($row->external_review_id ?? null);
                    if ($customerId === null || $reviewId === null) {
                        continue;
                    }

                    $lookup = [
                        'provider' => $this->nullableString($row->provider ?? null) ?: 'growave',
                        'integration' => 'growave',
                        'store_key' => $storeKey,
                        'external_review_id' => $reviewId,
                    ];

                    $existing = MarketingReviewHistory::query()->where($lookup)->first();
                    $marketingProfileId = $this->resolveTargetMarketingProfileId(
                        $storeKey,
                        $customerId,
                        null,
                        null,
                        null,
                        null,
                        $context
                    ) ?: (int) ($existing?->marketing_profile_id ?? 0);

                    $summaryId = $context['summary_ids_by_external_key'][$this->externalKey($storeKey, $customerId)] ?? null;
                    if (! $summaryId) {
                        $summaryId = MarketingReviewSummary::query()
                            ->where('provider', 'growave')
                            ->where('integration', 'growave')
                            ->where('store_key', $storeKey)
                            ->where('external_customer_id', $customerId)
                            ->value('id');
                    }

                    if (! $context['dry_run']) {
                        $history = $existing ?: new MarketingReviewHistory();
                        $this->forceFillAndSave($history, array_merge($lookup, [
                        'marketing_profile_id' => $marketingProfileId > 0 ? $marketingProfileId : null,
                        'marketing_review_summary_id' => is_numeric($summaryId) ? (int) $summaryId : null,
                        'external_customer_id' => $customerId,
                        'rating' => is_numeric($row->rating ?? null) ? (int) $row->rating : null,
                        'title' => $this->nullableString($row->title ?? null),
                        'body' => $this->nullableString($row->body ?? null),
                        'is_published' => $this->nullableBool($row->is_published ?? null),
                        'is_pinned' => $this->nullableBool($row->is_pinned ?? null),
                        'is_verified_buyer' => $this->nullableBool($row->is_verified_buyer ?? null),
                        'votes' => is_numeric($row->votes ?? null) ? (int) $row->votes : null,
                        'has_media' => $this->nullableBool($row->has_media ?? null) ?? false,
                        'media_count' => is_numeric($row->media_count ?? null) ? (int) $row->media_count : 0,
                        'product_id' => $this->nullableString($row->product_id ?? null),
                        'product_title' => $this->nullableString($row->product_title ?? null),
                        'reviewed_at' => $this->nullableString($row->reviewed_at ?? null),
                        'source_synced_at' => $this->nullableString($row->source_synced_at ?? null),
                        'raw_payload' => $this->jsonArray($row->raw_payload ?? null),
                        'created_at' => $this->nullableString($row->created_at ?? null) ?: now(),
                        'updated_at' => $this->nullableString($row->updated_at ?? null) ?: now(),
                        ]));
                    }

                    $summary[$existing ? 'review_histories_updated' : 'review_histories_created']++;
                }
            }, 'id');
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $summary
     */
    protected function importTransactions(
        string $sourceConnection,
        array &$context,
        array &$summary,
        int $chunkSize
    ): void {
        DB::connection($sourceConnection)
            ->table('candle_cash_transactions')
            ->where('source', 'growave_activity')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use (&$context, &$summary): void {
                foreach ($rows as $row) {
                    $sourceId = $this->nullableString($row->source_id ?? null);
                    if ($sourceId === null) {
                        continue;
                    }

                    $activity = $this->parseGrowaveActivitySourceId($sourceId);
                    if ($activity === null) {
                        $summary['transactions_skipped_no_profile']++;

                        continue;
                    }

                    if ($context['store'] !== null && $context['store'] !== $activity['store_key']) {
                        continue;
                    }

                    $marketingProfileId = $this->resolveTargetMarketingProfileId(
                        $activity['store_key'],
                        $activity['external_customer_id'],
                        null,
                        null,
                        null,
                        null,
                        $context
                    );

                    if (! $marketingProfileId || $marketingProfileId <= 0) {
                        $summary['transactions_skipped_no_profile']++;

                        continue;
                    }

                    $existing = CandleCashTransaction::query()
                        ->where('source', 'growave_activity')
                        ->where('source_id', $sourceId)
                        ->first();

                    if (! $context['dry_run']) {
                        $transaction = $existing ?: new CandleCashTransaction();
                        $this->forceFillAndSave($transaction, [
                        'marketing_profile_id' => $marketingProfileId,
                        'type' => $this->nullableString($row->type ?? null) ?: 'earn',
                        'points' => is_numeric($row->points ?? null) ? (int) $row->points : 0,
                        'source' => 'growave_activity',
                        'source_id' => $sourceId,
                        'description' => $this->nullableString($row->description ?? null),
                        'created_at' => $this->nullableString($row->created_at ?? null) ?: now(),
                        'updated_at' => $this->nullableString($row->updated_at ?? null) ?: now(),
                        ]);
                    }

                    $context['touched_profile_ids'][$marketingProfileId] = true;
                    $summary[$existing ? 'transactions_updated' : 'transactions_created']++;
                }
            }, 'id');
    }

    /**
     * @param array<int,int> $profileIds
     */
    protected function refreshBalances(array $profileIds): int
    {
        $profileIds = array_values(array_unique(array_filter(array_map('intval', $profileIds), fn (int $id): bool => $id > 0)));
        $count = 0;

        foreach ($profileIds as $profileId) {
            $balanceValue = (int) CandleCashTransaction::query()
                ->where('marketing_profile_id', $profileId)
                ->sum('points');

            $balance = CandleCashBalance::query()->find($profileId) ?: new CandleCashBalance();
            $this->forceFillAndSave($balance, [
                'marketing_profile_id' => $profileId,
                'balance' => $balanceValue,
                'created_at' => $balance->exists ? ($balance->created_at ?: now()) : now(),
                'updated_at' => now(),
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function resolveTargetMarketingProfileId(
        ?string $storeKey,
        ?string $externalCustomerId,
        ?string $email,
        ?string $normalizedEmail,
        ?string $phone,
        ?string $normalizedPhone,
        array $context
    ): ?int {
        if ($externalCustomerId !== null) {
            $externalKey = $this->externalKey($storeKey, $externalCustomerId);
            $shopifyProfileId = $context['shopify_profile_ids_by_external_key'][$externalKey] ?? null;
            if (is_numeric($shopifyProfileId) && (int) $shopifyProfileId > 0) {
                return (int) $shopifyProfileId;
            }

            $growaveProfileId = $context['growave_profile_ids_by_external_key'][$externalKey] ?? null;
            if (is_numeric($growaveProfileId) && (int) $growaveProfileId > 0) {
                return (int) $growaveProfileId;
            }
        }

        $normalizedEmail = $normalizedEmail ?: $this->normalizer->normalizeEmail($email);
        if ($normalizedEmail !== null) {
            $profileId = $context['marketing_profile_ids_by_email'][$normalizedEmail] ?? null;
            if (is_numeric($profileId) && (int) $profileId > 0) {
                return (int) $profileId;
            }
        }

        $normalizedPhone = $normalizedPhone ?: $this->normalizer->normalizePhone($phone);
        if ($normalizedPhone !== null) {
            $profileId = $context['marketing_profile_ids_by_phone'][$normalizedPhone] ?? null;
            if (is_numeric($profileId) && (int) $profileId > 0) {
                return (int) $profileId;
            }
        }

        return null;
    }

    protected function externalKey(?string $storeKey, ?string $externalCustomerId): string
    {
        return ($storeKey ?? '') . '|' . ($externalCustomerId ?? '');
    }

    /**
     * @return array{store_key:?string,external_customer_id:string,activity_id:string}|null
     */
    protected function parseGrowaveActivitySourceId(string $sourceId): ?array
    {
        $parts = array_values(array_filter(explode(':', trim($sourceId)), static fn (string $part): bool => $part !== ''));
        if (count($parts) === 3) {
            return [
                'store_key' => $parts[0],
                'external_customer_id' => $parts[1],
                'activity_id' => $parts[2],
            ];
        }

        if (count($parts) === 2) {
            return [
                'store_key' => null,
                'external_customer_id' => $parts[0],
                'activity_id' => $parts[1],
            ];
        }

        return null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }

    /**
     * @return array<int|string,mixed>|null
     */
    protected function jsonArray(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    protected function forceFillAndSave(Model $model, array $attributes): void
    {
        $timestamps = $model->timestamps;
        $model->timestamps = false;
        $model->forceFill($attributes);
        $model->save();
        $model->timestamps = $timestamps;
    }
}
