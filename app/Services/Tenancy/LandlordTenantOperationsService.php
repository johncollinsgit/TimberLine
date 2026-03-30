<?php

namespace App\Services\Tenancy;

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LandlordTenantOperationsService
{
    public const SNAPSHOT_ARTIFACT_TYPE = 'landlord_tenant_marketing_snapshot_v1';

    /**
     * @var array<int,string>
     */
    public const SNAPSHOT_TABLES = [
        'marketing_profiles',
        'marketing_profile_links',
        'customer_external_profiles',
        'marketing_profile_wishlist_items',
        'marketing_campaigns',
        'marketing_segments',
        'marketing_message_templates',
        'marketing_event_source_mappings',
        'marketing_order_event_attributions',
        'marketing_import_runs',
        'tenant_marketing_settings',
    ];

    /**
     * @var array<string,array<int,string>>
     */
    protected array $columnCache = [];

    public function __construct(
        protected MarketingIdentityNormalizer $identityNormalizer
    ) {
    }

    public function confirmationPhraseForTenant(Tenant $tenant): string
    {
        return 'confirm ' . strtolower(trim((string) $tenant->slug));
    }

    public function applyRestorePhraseForTenant(Tenant $tenant): string
    {
        return 'apply ' . strtolower(trim((string) $tenant->slug));
    }

    public function overwritePhraseForTenant(Tenant $tenant): string
    {
        return 'overwrite ' . strtolower(trim((string) $tenant->slug));
    }

    /**
     * @return array<int,string>
     */
    public function snapshotTables(): array
    {
        return self::SNAPSHOT_TABLES;
    }

    public function snapshotRetentionDays(): int
    {
        $days = (int) config('tenancy.landlord.tenant_ops.snapshot_retention_days', 14);

        return max(1, min(365, $days));
    }

    public function snapshotMaxBytes(): int
    {
        $bytes = (int) config('tenancy.landlord.tenant_ops.max_snapshot_bytes', 1024 * 1024 * 20);

        return max(1024 * 100, min(1024 * 1024 * 200, $bytes));
    }

    /**
     * @return Collection<int,MarketingProfile>
     */
    public function recentTenantCustomerRows(Tenant $tenant, int $limit = 20): Collection
    {
        $limit = max(1, min(100, $limit));

        return MarketingProfile::query()
            ->forTenantId((int) $tenant->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'tenant_id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'updated_at',
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function exportTenantSnapshot(Tenant $tenant, User $actor): array
    {
        $tenantId = (int) $tenant->id;
        $tableRows = [];
        $rowCounts = [];
        $skippedTables = [];

        foreach (self::SNAPSHOT_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                $skippedTables[$table] = 'table_or_tenant_column_missing';
                continue;
            }

            $rows = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();

            $tableRows[$table] = $rows;
            $rowCounts[$table] = count($rows);
        }

        $basePayload = [
            'artifact_type' => self::SNAPSHOT_ARTIFACT_TYPE,
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'tenant' => [
                'id' => $tenantId,
                'name' => (string) $tenant->name,
                'slug' => (string) $tenant->slug,
            ],
            'actor' => [
                'id' => (int) $actor->id,
                'email' => (string) $actor->email,
            ],
            'scope' => [
                'tables' => array_keys($tableRows),
                'skipped_tables' => $skippedTables,
            ],
            'row_counts' => $rowCounts,
            'data' => $tableRows,
        ];

        $checksum = $this->checksumForPayload($basePayload);
        $payload = $basePayload;
        $payload['checksum_sha256'] = $checksum;

        $artifactId = (string) Str::uuid();
        $timestamp = now()->format('Ymd_His');
        $slug = strtolower(trim((string) $tenant->slug));
        $safeSlug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? 'tenant-' . $tenantId;
        $safeSlug = trim($safeSlug, '-');
        if ($safeSlug === '') {
            $safeSlug = 'tenant-' . $tenantId;
        }

        $fileName = sprintf('%s-marketing-snapshot-%s-%s.json', $safeSlug, $timestamp, $artifactId);
        $path = sprintf('landlord/tenant-ops/tenant-%d/%s', $tenantId, $fileName);

        $encodedPayload = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (! is_string($encodedPayload) || $encodedPayload === '') {
            throw new RuntimeException('Failed to encode tenant snapshot payload.');
        }

        Storage::disk('local')->put($path, $encodedPayload);
        $expiresAt = now()->addDays($this->snapshotRetentionDays());

        return [
            'artifact_id' => $artifactId,
            'artifact_type' => self::SNAPSHOT_ARTIFACT_TYPE,
            'artifact_file_name' => $fileName,
            'artifact_path' => $path,
            'artifact_bytes' => strlen($encodedPayload),
            'generated_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'retention_days' => $this->snapshotRetentionDays(),
            'checksum_sha256' => $checksum,
            'row_counts' => $rowCounts,
            'total_rows' => array_sum($rowCounts),
            'scope_tables' => array_keys($tableRows),
            'skipped_tables' => $skippedTables,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function restoreTenantSnapshot(
        Tenant $tenant,
        UploadedFile $artifact,
        bool $allowOverwrite = false,
        bool $dryRun = false
    ): array
    {
        $maxBytes = $this->snapshotMaxBytes();
        $artifactBytes = (int) ($artifact->getSize() ?? 0);
        if ($artifactBytes > 0 && $artifactBytes > $maxBytes) {
            throw new RuntimeException(sprintf(
                'Snapshot restore blocked: artifact size %d bytes exceeds max allowed %d bytes.',
                $artifactBytes,
                $maxBytes
            ));
        }

        $contents = $artifact->get();
        if (! is_string($contents) || trim($contents) === '') {
            throw new RuntimeException('Uploaded snapshot artifact is empty.');
        }
        if (strlen($contents) > $maxBytes) {
            throw new RuntimeException(sprintf(
                'Snapshot restore blocked: artifact payload size exceeds max allowed %d bytes.',
                $maxBytes
            ));
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Uploaded snapshot artifact is not valid JSON.');
        }

        $artifactType = strtolower(trim((string) ($decoded['artifact_type'] ?? '')));
        if ($artifactType !== self::SNAPSHOT_ARTIFACT_TYPE) {
            throw new RuntimeException('Snapshot artifact type is unsupported.');
        }
        $schemaVersion = (int) ($decoded['schema_version'] ?? 0);
        if ($schemaVersion !== 1) {
            throw new RuntimeException(sprintf(
                'Snapshot restore blocked: unsupported schema version [%d].',
                $schemaVersion
            ));
        }

        $sourceTenantId = $this->positiveInt(data_get($decoded, 'tenant.id'));
        $tenantId = (int) $tenant->id;
        if ($sourceTenantId === null || $sourceTenantId !== $tenantId) {
            throw new RuntimeException('Snapshot restore blocked: source tenant does not match selected target tenant.');
        }
        $sourceTenantSlug = strtolower(trim((string) data_get($decoded, 'tenant.slug', '')));
        $targetTenantSlug = strtolower(trim((string) $tenant->slug));
        if ($sourceTenantSlug === '' || $sourceTenantSlug !== $targetTenantSlug) {
            throw new RuntimeException('Snapshot restore blocked: source tenant slug does not match selected target tenant.');
        }

        $generatedAtRaw = trim((string) ($decoded['generated_at'] ?? ''));
        if ($generatedAtRaw === '') {
            throw new RuntimeException('Snapshot restore blocked: generated_at metadata is missing.');
        }
        try {
            $generatedAt = Carbon::parse($generatedAtRaw);
        } catch (\Throwable) {
            throw new RuntimeException('Snapshot restore blocked: generated_at metadata is invalid.');
        }
        if ($generatedAt->isFuture()) {
            throw new RuntimeException('Snapshot restore blocked: generated_at metadata is in the future.');
        }

        $providedChecksum = strtolower(trim((string) ($decoded['checksum_sha256'] ?? '')));
        $checksumPayload = $decoded;
        unset($checksumPayload['checksum_sha256']);
        $computedChecksum = $this->checksumForPayload($checksumPayload);
        if ($providedChecksum === '' || $providedChecksum !== $computedChecksum) {
            throw new RuntimeException('Snapshot checksum validation failed.');
        }

        $tablePayload = is_array($decoded['data'] ?? null) ? (array) $decoded['data'] : [];
        if ($tablePayload === []) {
            throw new RuntimeException('Snapshot restore blocked: no table payload found.');
        }

        $scopeTables = $this->normalizedTableList(data_get($decoded, 'scope.tables', []));
        if ($scopeTables === []) {
            throw new RuntimeException('Snapshot restore blocked: scope table manifest is missing.');
        }
        $dataTables = $this->normalizedTableList(array_keys($tablePayload));
        if ($scopeTables !== $dataTables) {
            throw new RuntimeException('Snapshot restore blocked: scope table manifest does not match data table payload.');
        }

        foreach ($dataTables as $table) {
            if (! in_array($table, self::SNAPSHOT_TABLES, true)) {
                throw new RuntimeException(sprintf('Snapshot restore blocked: unsupported table payload [%s].', $table));
            }
        }

        $beforeCounts = [];
        $skippedTables = [];

        foreach ($tablePayload as $table => $rows) {
            if (! is_array($rows)) {
                throw new RuntimeException(sprintf('Snapshot restore blocked: invalid row payload for table [%s].', (string) $table));
            }

            if (! Schema::hasTable((string) $table) || ! Schema::hasColumn((string) $table, 'tenant_id')) {
                $skippedTables[(string) $table] = 'table_or_tenant_column_missing';
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new RuntimeException(sprintf('Snapshot restore blocked: malformed row payload for table [%s].', (string) $table));
                }

                $rowTenantId = $this->positiveInt($row['tenant_id'] ?? null);
                if ($rowTenantId === null || $rowTenantId !== $tenantId) {
                    throw new RuntimeException(sprintf('Snapshot restore blocked: row ownership mismatch for table [%s].', (string) $table));
                }
            }

            $beforeCounts[(string) $table] = (int) DB::table((string) $table)
                ->where('tenant_id', $tenantId)
                ->count();
        }

        $tableResults = [];

        if ($dryRun) {
            foreach ($tablePayload as $table => $rows) {
                $table = (string) $table;
                if (array_key_exists($table, $skippedTables)) {
                    continue;
                }

                $tableResults[$table] = [
                    'would_insert' => 0,
                    'would_update' => 0,
                    'skipped_existing' => 0,
                    'skipped_invalid' => 0,
                ];

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        $tableResults[$table]['skipped_invalid']++;
                        continue;
                    }

                    $rowId = $this->positiveInt($row['id'] ?? null);
                    if ($rowId === null) {
                        $tableResults[$table]['skipped_invalid']++;
                        continue;
                    }

                    $payload = $this->filterPayloadForTable($table, $row, $tenantId);
                    if ($payload === []) {
                        $tableResults[$table]['skipped_invalid']++;
                        continue;
                    }

                    $existing = DB::table($table)->where('id', $rowId)->first();
                    if ($existing) {
                        $existingTenantId = $this->positiveInt($existing->tenant_id ?? null);
                        if ($existingTenantId !== null && $existingTenantId !== $tenantId) {
                            throw new RuntimeException(sprintf('Snapshot restore blocked: row id %d in table [%s] belongs to another tenant.', $rowId, $table));
                        }

                        if ($allowOverwrite) {
                            $tableResults[$table]['would_update']++;
                        } else {
                            $tableResults[$table]['skipped_existing']++;
                        }
                        continue;
                    }

                    $tableResults[$table]['would_insert']++;
                }
            }
        } else {
            DB::transaction(function () use (
                $tablePayload,
                $skippedTables,
                $tenantId,
                $allowOverwrite,
                &$tableResults
            ): void {
                foreach ($tablePayload as $table => $rows) {
                    $table = (string) $table;
                    if (array_key_exists($table, $skippedTables)) {
                        continue;
                    }

                    $tableResults[$table] = [
                        'inserted' => 0,
                        'updated' => 0,
                        'skipped_existing' => 0,
                        'skipped_invalid' => 0,
                    ];

                    foreach ($rows as $row) {
                        if (! is_array($row)) {
                            $tableResults[$table]['skipped_invalid']++;
                            continue;
                        }

                        $rowId = $this->positiveInt($row['id'] ?? null);
                        if ($rowId === null) {
                            $tableResults[$table]['skipped_invalid']++;
                            continue;
                        }

                        $payload = $this->filterPayloadForTable($table, $row, $tenantId);
                        if ($payload === []) {
                            $tableResults[$table]['skipped_invalid']++;
                            continue;
                        }

                        $existing = DB::table($table)->where('id', $rowId)->first();
                        if ($existing) {
                            $existingTenantId = $this->positiveInt($existing->tenant_id ?? null);
                            if ($existingTenantId !== null && $existingTenantId !== $tenantId) {
                                throw new RuntimeException(sprintf('Snapshot restore blocked: row id %d in table [%s] belongs to another tenant.', $rowId, $table));
                            }

                            if (! $allowOverwrite) {
                                $tableResults[$table]['skipped_existing']++;
                                continue;
                            }

                            $updatePayload = $payload;
                            unset($updatePayload['id']);
                            DB::table($table)->where('id', $rowId)->update($updatePayload);
                            $tableResults[$table]['updated']++;
                            continue;
                        }

                        DB::table($table)->insert($payload);
                        $tableResults[$table]['inserted']++;
                    }
                }
            });
        }

        $afterCounts = [];
        foreach ($tablePayload as $table => $rows) {
            $table = (string) $table;
            if (array_key_exists($table, $skippedTables)) {
                continue;
            }

            if ($dryRun) {
                $afterCounts[$table] = (int) ($beforeCounts[$table] ?? 0) + (int) ($tableResults[$table]['would_insert'] ?? 0);
            } else {
                $afterCounts[$table] = (int) DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->count();
            }
        }

        return [
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'dry_run' => $dryRun,
            'applied' => ! $dryRun,
            'artifact_file_name' => $artifact->getClientOriginalName(),
            'artifact_bytes' => $artifactBytes,
            'schema_version' => $schemaVersion,
            'source_generated_at' => $generatedAt->toIso8601String(),
            'source_tenant' => [
                'id' => $sourceTenantId,
                'slug' => $sourceTenantSlug,
            ],
            'checksum_sha256' => $computedChecksum,
            'allow_overwrite' => $allowOverwrite,
            'table_results' => $tableResults,
            'before_counts' => $beforeCounts,
            'after_counts' => $afterCounts,
            'skipped_tables' => $skippedTables,
        ];
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public function modifyTenantCustomer(Tenant $tenant, int $profileId, array $changes): array
    {
        $profile = $this->tenantProfile($tenant, $profileId);
        $before = $this->profileSnapshot($profile);

        $updates = [];
        $changedFields = [];

        if (array_key_exists('first_name', $changes)) {
            $updates['first_name'] = $this->nullableString($changes['first_name'] ?? null, 120);
            $changedFields[] = 'first_name';
        }
        if (array_key_exists('last_name', $changes)) {
            $updates['last_name'] = $this->nullableString($changes['last_name'] ?? null, 120);
            $changedFields[] = 'last_name';
        }
        if (array_key_exists('email', $changes)) {
            $email = $this->nullableString($changes['email'] ?? null, 255);
            $updates['email'] = $email;
            $updates['normalized_email'] = $email ? $this->identityNormalizer->normalizeEmail($email) : null;
            $changedFields[] = 'email';
        }
        if (array_key_exists('phone', $changes)) {
            $phone = $this->nullableString($changes['phone'] ?? null, 40);
            $updates['phone'] = $phone;
            $updates['normalized_phone'] = $phone ? $this->identityNormalizer->normalizePhone($phone) : null;
            $changedFields[] = 'phone';
        }
        if (array_key_exists('notes', $changes)) {
            $updates['notes'] = $this->nullableString($changes['notes'] ?? null, 4000);
            $changedFields[] = 'notes';
        }
        if (array_key_exists('accepts_email_marketing', $changes)) {
            $enabled = (bool) $changes['accepts_email_marketing'];
            $updates['accepts_email_marketing'] = $enabled;
            $updates['email_opted_out_at'] = $enabled ? null : now();
            $changedFields[] = 'accepts_email_marketing';
        }
        if (array_key_exists('accepts_sms_marketing', $changes)) {
            $enabled = (bool) $changes['accepts_sms_marketing'];
            $updates['accepts_sms_marketing'] = $enabled;
            $updates['sms_opted_out_at'] = $enabled ? null : now();
            $changedFields[] = 'accepts_sms_marketing';
        }

        if ($updates === []) {
            throw new RuntimeException('No editable customer fields were supplied.');
        }

        $profile->forceFill($updates)->save();
        $profile->refresh();

        return [
            'status' => 'modified',
            'profile_id' => (int) $profile->id,
            'before' => $before,
            'after' => $this->profileSnapshot($profile),
            'changed_fields' => array_values(array_unique($changedFields)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function archiveTenantCustomerForDelete(Tenant $tenant, int $profileId, string $reason, ?int $actorUserId = null): array
    {
        $profile = $this->tenantProfile($tenant, $profileId);
        $before = $this->profileSnapshot($profile);

        $marker = '[landlord_operator_archive]';
        $existingNotes = trim((string) ($profile->notes ?? ''));
        $alreadyArchived = str_contains($existingNotes, $marker)
            && $profile->email === null
            && $profile->phone === null
            && ! (bool) ($profile->accepts_email_marketing ?? false)
            && ! (bool) ($profile->accepts_sms_marketing ?? false);

        if ($alreadyArchived) {
            return [
                'status' => 'already_archived',
                'profile_id' => (int) $profile->id,
                'before' => $before,
                'after' => $before,
            ];
        }

        $archiveNote = sprintf(
            '%s archived_at=%s actor_user_id=%s reason=%s',
            $marker,
            now()->toIso8601String(),
            $actorUserId !== null ? (string) $actorUserId : 'unknown',
            trim($reason)
        );
        $notes = trim(implode("\n", array_filter([$existingNotes, $archiveNote])));

        $sourceChannels = collect(is_array($profile->source_channels) ? $profile->source_channels : [])
            ->map(fn ($value): string => strtolower(trim((string) $value)))
            ->filter()
            ->push('landlord_archived')
            ->unique()
            ->values()
            ->all();

        $profile->forceFill([
            'first_name' => null,
            'last_name' => null,
            'email' => null,
            'normalized_email' => null,
            'phone' => null,
            'normalized_phone' => null,
            'address_line_1' => null,
            'address_line_2' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country' => null,
            'accepts_email_marketing' => false,
            'accepts_sms_marketing' => false,
            'email_opted_out_at' => now(),
            'sms_opted_out_at' => now(),
            'source_channels' => $sourceChannels,
            'notes' => $notes !== '' ? $notes : null,
        ])->save();

        $profile->refresh();

        return [
            'status' => 'archived',
            'profile_id' => (int) $profile->id,
            'before' => $before,
            'after' => $this->profileSnapshot($profile),
        ];
    }

    protected function tenantProfile(Tenant $tenant, int $profileId): MarketingProfile
    {
        $profile = MarketingProfile::query()
            ->forTenantId((int) $tenant->id)
            ->where('id', $profileId)
            ->first();

        if (! $profile) {
            throw new RuntimeException('Customer profile is outside the selected tenant scope.');
        }

        return $profile;
    }

    /**
     * @return array<string,mixed>
     */
    protected function profileSnapshot(MarketingProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'tenant_id' => (int) ($profile->tenant_id ?? 0),
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'email' => $profile->email,
            'phone' => $profile->phone,
            'accepts_email_marketing' => (bool) ($profile->accepts_email_marketing ?? false),
            'accepts_sms_marketing' => (bool) ($profile->accepts_sms_marketing ?? false),
            'notes' => $profile->notes,
            'updated_at' => optional($profile->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function filterPayloadForTable(string $table, array $row, int $tenantId): array
    {
        $columns = $this->tableColumns($table);
        if ($columns === []) {
            return [];
        }

        $columnMap = array_flip($columns);
        $payload = array_intersect_key($row, $columnMap);
        if ($payload === []) {
            return [];
        }

        $payload['tenant_id'] = $tenantId;

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    protected function tableColumns(string $table): array
    {
        if (array_key_exists($table, $this->columnCache)) {
            return $this->columnCache[$table];
        }

        if (! Schema::hasTable($table)) {
            $this->columnCache[$table] = [];

            return [];
        }

        $this->columnCache[$table] = Schema::getColumnListing($table);

        return $this->columnCache[$table];
    }

    /**
     * @param iterable<int,mixed> $tables
     * @return array<int,string>
     */
    protected function normalizedTableList(iterable $tables): array
    {
        return collect($tables)
            ->map(fn ($value): string => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $payload
     */
    protected function checksumForPayload(array $payload): string
    {
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to compute snapshot checksum.');
        }

        return hash('sha256', $encoded);
    }

    protected function nullableString(mixed $value, int $maxLength): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, $maxLength);
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $cast = (int) $value;

        return $cast > 0 ? $cast : null;
    }
}
