<?php

namespace App\Console\Commands;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileWishlistItem;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SplFileObject;

class MarketingImportWishlistCsv extends Command
{
    protected $signature = 'marketing:import-wishlist-csv
        {file : Absolute or relative CSV path}
        {--dry-run : Validate and simulate import without writing}
        {--profile-email= : Optional customer email filter for targeted QA imports}
        {--batch=250 : Maximum ready rows to process per database transaction batch}';

    protected $description = 'Import normalized wishlist CSV rows into canonical marketing_profile_wishlist_items.';

    public function handle(MarketingIdentityNormalizer $normalizer): int
    {
        $path = $this->resolvePath((string) $this->argument('file'));
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('CSV file is missing or unreadable: ' . $path);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batchSize = max(1, (int) $this->option('batch'));
        $profileEmailFilter = $this->normalizedFilterEmail($this->option('profile-email'), $normalizer);

        $summary = [
            'total_rows' => 0,
            'imported' => 0,
            'skipped_missing_profile' => 0,
            'skipped_missing_product' => 0,
            'skipped_guest_rows' => 0,
            'errors' => 0,
            'bad_row_errors' => 0,
        ];

        $profileIdCache = [];
        $profileTenantCache = [];
        $batch = [];

        try {
            $file = new SplFileObject($path);
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        } catch (\Throwable $e) {
            $this->error('Unable to open CSV file: ' . $e->getMessage());

            return self::FAILURE;
        }

        $headers = null;
        $rowNumber = 0;

        foreach ($file as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->isEmptyCsvRow($row)) {
                continue;
            }

            $rowNumber++;

            if ($headers === null) {
                $headers = $this->normalizeHeaders($row);

                continue;
            }

            $summary['total_rows']++;

            try {
                $mapped = $this->mapRow($headers, $row);
                $prepared = $this->prepareRow(
                    mapped: $mapped,
                    rowNumber: $rowNumber,
                    normalizer: $normalizer,
                    profileIdCache: $profileIdCache,
                    profileTenantCache: $profileTenantCache,
                    profileEmailFilter: $profileEmailFilter,
                    summary: $summary
                );
            } catch (\Throwable $e) {
                $summary['errors']++;
                $summary['bad_row_errors']++;

                Log::warning('wishlist csv import row processing failed', [
                    'row_number' => $rowNumber,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($prepared === null) {
                continue;
            }

            $batch[] = $prepared;

            if (count($batch) >= $batchSize) {
                $this->flushBatch($batch, $dryRun, $summary);
            }
        }

        $this->flushBatch($batch, $dryRun, $summary);

        $this->line($dryRun ? 'mode=dry-run' : 'mode=live-import');
        foreach ([
            'total_rows',
            'imported',
            'skipped_missing_profile',
            'skipped_missing_product',
            'skipped_guest_rows',
        ] as $key) {
            $this->line($key . '=' . (int) ($summary[$key] ?? 0));
        }
        $this->line('errors=' . (int) ($summary['errors'] ?? 0));
        $this->line('reason_breakdown:');
        $this->line('missing_profile=' . (int) ($summary['skipped_missing_profile'] ?? 0));
        $this->line('missing_product=' . (int) ($summary['skipped_missing_product'] ?? 0));
        $this->line('guest_rows=' . (int) ($summary['skipped_guest_rows'] ?? 0));
        $this->line('bad_row_errors=' . (int) ($summary['bad_row_errors'] ?? 0));

        return self::SUCCESS;
    }

    /**
     * @param array<int,array<string,mixed>> $batch
     * @param array<string,int> $summary
     */
    protected function flushBatch(array &$batch, bool $dryRun, array &$summary): void
    {
        if ($batch === []) {
            return;
        }

        if ($dryRun) {
            $summary['imported'] += count($batch);
            $batch = [];

            return;
        }

        DB::transaction(function () use (&$summary, &$batch): void {
            foreach ($batch as $payload) {
                try {
                    $this->upsertWishlistRow($payload);
                    $summary['imported']++;
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    $summary['bad_row_errors']++;

                    Log::warning('wishlist csv import upsert failed', [
                        'row_number' => $payload['_row_number'] ?? null,
                        'marketing_profile_id' => $payload['marketing_profile_id'] ?? null,
                        'store_key' => $payload['store_key'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $batch = [];
    }

    /**
     * @param array<string,mixed> $mapped
     * @param array<string,?int> $profileIdCache
     * @param array<int,?int> $profileTenantCache
     * @param ?string $profileEmailFilter
     * @param array<string,int> $summary
     * @return array<string,mixed>|null
     */
    protected function prepareRow(
        array $mapped,
        int $rowNumber,
        MarketingIdentityNormalizer $normalizer,
        array &$profileIdCache,
        array &$profileTenantCache,
        ?string $profileEmailFilter,
        array &$summary
    ): ?array {
        $importStatus = strtolower((string) ($mapped['import_status'] ?? ''));
        if ($importStatus !== 'ready') {
            if ($importStatus === 'needs_customer_mapping') {
                $summary['skipped_guest_rows']++;
                $this->logSkippedRow($rowNumber, 'needs_customer_mapping', $mapped);
            }

            return null;
        }

        $customerEmail = $this->nullableString($mapped['customer_email'] ?? null);
        if ($customerEmail === null) {
            $summary['skipped_missing_profile']++;
            $this->logSkippedRow($rowNumber, 'missing_profile', $mapped);

            return null;
        }

        $normalizedRowEmail = $this->normalizedFilterEmail($customerEmail, $normalizer);
        if ($profileEmailFilter !== null && $normalizedRowEmail !== $profileEmailFilter) {
            return null;
        }

        $marketingProfileId = $this->resolveProfileId($customerEmail, $normalizer, $profileIdCache);
        if ($marketingProfileId === null) {
            $summary['skipped_missing_profile']++;
            $this->logSkippedRow($rowNumber, 'missing_profile', $mapped);

            return null;
        }

        $storeKey = $this->nullableString($mapped['store_key'] ?? null);
        $productId = $this->nullableString($mapped['product_id'] ?? null);
        $productHandle = $this->nullableString($mapped['product_handle'] ?? null);
        $resolvedProductId = $productId ?? $productHandle;

        if ($storeKey === null || $resolvedProductId === null) {
            $summary['skipped_missing_product']++;
            $this->logSkippedRow($rowNumber, 'missing_product', $mapped);

            return null;
        }

        if (! array_key_exists($marketingProfileId, $profileTenantCache)) {
            $profile = MarketingProfile::query()
                ->select(['id', 'tenant_id'])
                ->whereKey($marketingProfileId)
                ->first();

            if (! $profile) {
                $summary['skipped_missing_profile']++;
                $this->logSkippedRow($rowNumber, 'missing_profile', $mapped);

                return null;
            }

            $profileTenantCache[$marketingProfileId] = $profile->tenant_id ? (int) $profile->tenant_id : null;
        }

        $addedAt = $this->parseDateTime($mapped['added_at'] ?? null) ?? now();
        $sourceSyncedAt = $this->parseDateTime($mapped['source_synced_at'] ?? null);

        return [
            '_row_number' => $rowNumber,
            'tenant_id' => is_numeric($profileTenantCache[$marketingProfileId] ?? null)
                ? (int) $profileTenantCache[$marketingProfileId]
                : null,
            'marketing_profile_id' => $marketingProfileId,
            'store_key' => $storeKey,
            'product_id' => $resolvedProductId,
            'product_variant_id' => $this->nullableString($mapped['product_variant_id'] ?? null),
            'product_handle' => $productHandle,
            'product_title' => $this->nullableString($mapped['product_title'] ?? null),
            'product_url' => $this->nullableString($mapped['product_url'] ?? null),
            'provider' => $this->nullableString($mapped['provider'] ?? null) ?? 'growave',
            'integration' => $this->nullableString($mapped['integration'] ?? null) ?? 'csv_import',
            'source' => $this->nullableString($mapped['source'] ?? null) ?? 'wishlist_csv_import',
            'source_surface' => $this->nullableString($mapped['source_surface'] ?? null),
            'source_ref' => $this->nullableString($mapped['source_ref'] ?? null),
            'raw_payload' => $this->decodeRawPayload($mapped['raw_payload_json'] ?? null),
            'added_at' => $addedAt,
            'last_added_at' => $addedAt,
            'source_synced_at' => $sourceSyncedAt,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    protected function upsertWishlistRow(array $payload): void
    {
        $existing = MarketingProfileWishlistItem::query()
            ->where('marketing_profile_id', $payload['marketing_profile_id'])
            ->where('store_key', $payload['store_key'])
            ->where('product_id', $payload['product_id'])
            ->lockForUpdate()
            ->first();

        if (! $existing) {
            MarketingProfileWishlistItem::query()->create([
                'tenant_id' => $payload['tenant_id'],
                'marketing_profile_id' => $payload['marketing_profile_id'],
                'provider' => $payload['provider'],
                'integration' => $payload['integration'],
                'store_key' => $payload['store_key'],
                'product_id' => $payload['product_id'],
                'product_variant_id' => $payload['product_variant_id'],
                'product_handle' => $payload['product_handle'],
                'product_title' => $payload['product_title'],
                'product_url' => $payload['product_url'],
                'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
                'source' => $payload['source'],
                'source_surface' => $payload['source_surface'],
                'source_ref' => $payload['source_ref'],
                'added_at' => $payload['added_at'],
                'last_added_at' => $payload['last_added_at'],
                'removed_at' => null,
                'source_synced_at' => $payload['source_synced_at'],
                'raw_payload' => $payload['raw_payload'],
            ]);

            return;
        }

        $existing->forceFill([
            'tenant_id' => $payload['tenant_id'],
            'provider' => $payload['provider'],
            'integration' => $payload['integration'],
            'product_variant_id' => $payload['product_variant_id'],
            'product_handle' => $payload['product_handle'],
            'product_title' => $payload['product_title'],
            'product_url' => $payload['product_url'],
            'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
            'source' => $payload['source'],
            'source_surface' => $payload['source_surface'],
            'source_ref' => $payload['source_ref'],
            'last_added_at' => $payload['last_added_at'],
            'removed_at' => null,
            'source_synced_at' => $payload['source_synced_at'],
            'raw_payload' => $payload['raw_payload'],
        ]);

        if ($existing->added_at === null) {
            $existing->added_at = $payload['added_at'];
        }

        if ($existing->isDirty()) {
            $existing->save();
        }
    }

    /**
     * @param array<int,string> $headers
     * @param array<int,mixed> $row
     * @return array<string,mixed>
     */
    protected function mapRow(array $headers, array $row): array
    {
        $mapped = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $mapped[$header] = $row[$index] ?? null;
        }

        return $mapped;
    }

    /**
     * @param array<int,mixed> $row
     * @return array<int,string>
     */
    protected function normalizeHeaders(array $row): array
    {
        return array_map(function (mixed $value): string {
            $header = strtolower(trim((string) $value));
            $header = str_replace("\xEF\xBB\xBF", '', $header);

            return $header;
        }, $row);
    }

    /**
     * @param array<int,mixed> $row
     */
    protected function isEmptyCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function parseDateTime(mixed $value): ?CarbonImmutable
    {
        $string = $this->nullableString($value);
        if ($string === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,?int> $cache
     */
    protected function resolveProfileId(string $email, MarketingIdentityNormalizer $normalizer, array &$cache): ?int
    {
        $normalizedEmail = $normalizer->normalizeEmail($email);
        $cacheKey = $normalizedEmail ?: strtolower(trim($email));
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $lookupEmail = strtolower(trim($email));

        $matches = MarketingProfile::query()
            ->when($normalizedEmail !== null, function (Builder $query) use ($normalizedEmail): void {
                $query->where('normalized_email', $normalizedEmail);
            }, function (Builder $query) use ($lookupEmail): void {
                $query->whereRaw('LOWER(email) = ?', [$lookupEmail]);
            })
            ->limit(2)
            ->pluck('id');

        if ($matches->count() !== 1) {
            $cache[$cacheKey] = null;

            return null;
        }

        $resolved = (int) $matches->first();
        $cache[$cacheKey] = $resolved > 0 ? $resolved : null;

        return $cache[$cacheKey];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function decodeRawPayload(mixed $value): ?array
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['raw_payload_json' => $raw];
    }

    /**
     * @param array<string,mixed> $mapped
     */
    protected function logSkippedRow(int $rowNumber, string $reason, array $mapped): void
    {
        Log::info('wishlist csv import row skipped', [
            'row_number' => $rowNumber,
            'reason' => $reason,
            'import_status' => $mapped['import_status'] ?? null,
            'customer_email' => $mapped['customer_email'] ?? null,
            'store_key' => $mapped['store_key'] ?? null,
            'product_id' => $mapped['product_id'] ?? null,
            'product_handle' => $mapped['product_handle'] ?? null,
        ]);
    }

    protected function normalizedFilterEmail(mixed $value, MarketingIdentityNormalizer $normalizer): ?string
    {
        $email = $this->nullableString($value);
        if ($email === null) {
            return null;
        }

        return $normalizer->normalizeEmail($email) ?? strtolower($email);
    }

    protected function resolvePath(string $candidate): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return $candidate;
        }

        if ($this->isAbsolutePath($candidate)) {
            return $candidate;
        }

        return base_path($candidate);
    }

    protected function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
