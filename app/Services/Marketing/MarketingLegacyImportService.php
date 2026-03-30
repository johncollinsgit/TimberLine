<?php

namespace App\Services\Marketing;

use App\Models\MarketingExternalCampaignStat;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingImportRow;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MarketingLegacyImportService
{
    public function __construct(
        protected MarketingProfileSyncService $profileSyncService,
        protected MarketingConsentService $consentService,
        protected MarketingIdentityNormalizer $normalizer,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function importFile(
        UploadedFile $file,
        string $type,
        ?int $tenantId,
        ?int $createdBy = null,
        bool $dryRun = false
    ): array {
        $path = $file->getRealPath();
        if (! $path || ! file_exists($path)) {
            throw new \RuntimeException('Uploaded file could not be read.');
        }

        return $this->importCsvPath(
            path: $path,
            fileName: $file->getClientOriginalName(),
            type: $type,
            tenantId: $tenantId,
            createdBy: $createdBy,
            dryRun: $dryRun,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function importPath(
        string $path,
        string $type,
        ?int $tenantId,
        ?int $createdBy = null,
        bool $dryRun = false
    ): array {
        $absolutePath = realpath($path) ?: $path;
        if (! is_file($absolutePath)) {
            throw new \RuntimeException('Import file could not be read: ' . $path);
        }

        return $this->importCsvPath(
            path: $absolutePath,
            fileName: basename($absolutePath),
            type: $type,
            tenantId: $tenantId,
            createdBy: $createdBy,
            dryRun: $dryRun,
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function importCsvPath(
        string $path,
        string $fileName,
        string $type,
        ?int $tenantId,
        ?int $createdBy = null,
        bool $dryRun = false
    ): array {
        $normalizedType = $this->normalizeType($type);
        $resolvedTenantId = $this->requireTenantId($tenantId);

        $run = MarketingImportRun::query()->create([
            'type' => $normalizedType,
            'status' => 'running',
            'source_label' => $normalizedType === 'yotpo_contacts_import' ? 'yotpo' : 'square_marketing',
            'file_name' => $fileName,
            'started_at' => now(),
            'tenant_id' => $resolvedTenantId,
            'summary' => [
                'mode' => $dryRun ? 'dry-run' : 'import',
            ],
            'created_by' => $createdBy,
        ]);

        $summary = [
            'processed' => 0,
            'imported' => 0,
            'reviewed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'profiles_created' => 0,
            'profiles_updated' => 0,
            'links_created' => 0,
            'links_reused' => 0,
            'reviews_created' => 0,
            'records_skipped' => 0,
            'matched_existing' => 0,
            'sms_marketable' => 0,
            'email_marketable' => 0,
            'sms_suppressed' => 0,
            'email_suppressed' => 0,
        ];

        try {
            $rowNumber = 0;
            $handle = fopen($path, 'rb');
            if (! $handle) {
                throw new \RuntimeException('Unable to open CSV import file.');
            }

            $header = null;
            while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if ($header === null) {
                    $header = $this->normalizeHeader($data);
                    continue;
                }

                $rowNumber++;
                if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                $row = [];
                foreach ($header as $index => $key) {
                    $row[$key] = $data[$index] ?? null;
                }
                $row = $this->normalizeRow($normalizedType, $row);

                $summary['processed']++;
                $externalKey = $this->externalKeyForRow($normalizedType, $row, $rowNumber);

                try {
                    $result = $this->importRow($normalizedType, $row, $externalKey, $dryRun, $resolvedTenantId);
                    $summary[$result['status']]++;
                    foreach ([
                        'profiles_created',
                        'profiles_updated',
                        'links_created',
                        'links_reused',
                        'reviews_created',
                        'records_skipped',
                        'matched_existing',
                        'sms_marketable',
                        'email_marketable',
                        'sms_suppressed',
                        'email_suppressed',
                    ] as $key) {
                        $summary[$key] += (int) ($result[$key] ?? 0);
                    }

                    $this->writeRowLog($run, $rowNumber, $externalKey, $result['status'], $result['messages'], $row, $dryRun);
                } catch (\Throwable $e) {
                    $summary['failed']++;
                    $this->writeRowLog($run, $rowNumber, $externalKey, 'failed', [$e->getMessage()], $row, $dryRun);
                    Log::warning('marketing legacy import row failed', [
                        'type' => $normalizedType,
                        'row_number' => $rowNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            fclose($handle);

            $run->forceFill([
                'status' => $summary['failed'] > 0 ? 'partial' : 'completed',
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $dryRun ? 'Dry-run executed; no profile/link/stat writes were persisted.' : null,
            ])->save();
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $e->getMessage(),
            ])->save();

            throw $e;
        }

        return [
            'run_id' => $run->id,
            'status' => $run->status,
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *  status:string,
     *  messages:array<int,string>,
     *  profiles_created:int,
     *  profiles_updated:int,
     *  links_created:int,
     *  links_reused:int,
     *  reviews_created:int,
     *  records_skipped:int,
     *  matched_existing:int,
     *  sms_marketable:int,
     *  email_marketable:int,
     *  sms_suppressed:int,
     *  email_suppressed:int
     * }
     */
    protected function importRow(string $type, array $row, string $externalKey, bool $dryRun, int $tenantId): array
    {
        $channelSnapshot = $this->consentSnapshotForRow($type, $row);
        $identity = $this->identityPayloadForRow($type, $row, $externalKey, $tenantId);
        $syncResult = $this->profileSyncService->syncExternalIdentity($identity, [
            'dry_run' => $dryRun,
            'review_context' => [
                'source_label' => $type,
                'source_id' => $externalKey,
            ],
            'tenant_id' => $tenantId,
        ]);

        $messages = [];
        if (($syncResult['status'] ?? '') === 'review') {
            return [
                'status' => 'reviewed',
                'messages' => ['Identity conflict routed to review queue: ' . (string) ($syncResult['reason'] ?? 'unknown')],
                'profiles_created' => (int) ($syncResult['profiles_created'] ?? 0),
                'profiles_updated' => (int) ($syncResult['profiles_updated'] ?? 0),
                'links_created' => (int) ($syncResult['links_created'] ?? 0),
                'links_reused' => (int) ($syncResult['links_reused'] ?? 0),
                'reviews_created' => (int) ($syncResult['reviews_created'] ?? 0),
                'records_skipped' => (int) ($syncResult['records_skipped'] ?? 0),
                'matched_existing' => 0,
                'sms_marketable' => $this->isChannelMarketable($channelSnapshot['sms'], $row['phone_number'] ?? $row['phone'] ?? null) ? 1 : 0,
                'email_marketable' => $this->isChannelMarketable($channelSnapshot['email'], $row['email'] ?? $row['email_address'] ?? null) ? 1 : 0,
                'sms_suppressed' => (int) $channelSnapshot['sms']['suppressed'],
                'email_suppressed' => (int) $channelSnapshot['email']['suppressed'],
            ];
        }

        $profileId = (int) ($syncResult['profile_id'] ?? 0);
        if (! $dryRun && $profileId > 0) {
            /** @var MarketingProfile|null $profile */
            $profile = MarketingProfile::query()->find($profileId);
            if ($profile) {
                $this->applyConsent($profile, $row, $type, $externalKey);
                $this->recordConsentSnapshots($profile, $type, $externalKey, $channelSnapshot);
                $this->upsertCampaignStats($profile, $type, $row, $externalKey);
            }
        } elseif ($dryRun && $profileId > 0) {
            $messages[] = 'Dry-run: consent and campaign stats were not persisted.';
        }

        $status = ((int) ($syncResult['records_skipped'] ?? 0) > 0) ? 'skipped' : 'imported';
        if (($syncResult['reason'] ?? '') === 'missing_email_phone') {
            $messages[] = 'Row skipped because no usable email/phone was found.';
        }

        return [
            'status' => $status,
            'messages' => $messages,
            'profiles_created' => (int) ($syncResult['profiles_created'] ?? 0),
            'profiles_updated' => (int) ($syncResult['profiles_updated'] ?? 0),
            'links_created' => (int) ($syncResult['links_created'] ?? 0),
            'links_reused' => (int) ($syncResult['links_reused'] ?? 0),
            'reviews_created' => (int) ($syncResult['reviews_created'] ?? 0),
            'records_skipped' => (int) ($syncResult['records_skipped'] ?? 0),
            'matched_existing' => $profileId > 0 && (int) ($syncResult['profiles_created'] ?? 0) === 0 && (int) ($syncResult['records_skipped'] ?? 0) === 0 ? 1 : 0,
            'sms_marketable' => $this->isChannelMarketable($channelSnapshot['sms'], $row['phone_number'] ?? $row['phone'] ?? null) ? 1 : 0,
            'email_marketable' => $this->isChannelMarketable($channelSnapshot['email'], $row['email'] ?? $row['email_address'] ?? null) ? 1 : 0,
            'sms_suppressed' => (int) $channelSnapshot['sms']['suppressed'],
            'email_suppressed' => (int) $channelSnapshot['email']['suppressed'],
        ];
    }

    protected function applyConsent(MarketingProfile $profile, array $row, string $type, string $externalKey): void
    {
        $snapshot = $this->consentSnapshotForRow($type, $row);

        $this->consentService->applyToProfile($profile, [
            'accepts_email_marketing' => $snapshot['email']['state'],
            'accepts_sms_marketing' => $snapshot['sms']['state'],
            'email_opted_out_at' => $snapshot['email']['state'] === false ? $snapshot['email']['occurred_at'] : null,
            'sms_opted_out_at' => $snapshot['sms']['state'] === false ? $snapshot['sms']['occurred_at'] : null,
        ], [
            'source_type' => $type,
            'source_id' => $externalKey,
        ]);
    }

    /**
     * @param array<string,array<string,mixed>> $snapshot
     */
    protected function recordConsentSnapshots(MarketingProfile $profile, string $type, string $externalKey, array $snapshot): void
    {
        foreach (['email', 'sms'] as $channel) {
            $channelData = $snapshot[$channel];
            $state = $channelData['state'];
            $occurredAt = $channelData['occurred_at'] ?? null;
            $source = $channelData['source'] ?? null;
            $suppressed = (bool) ($channelData['suppressed'] ?? false);
            $rawStatus = $channelData['raw_status'] ?? null;

            if ($state === null && ! $suppressed && $source === null && $occurredAt === null) {
                continue;
            }

            $eventType = $state === false ? 'opted_out' : 'imported';
            $eventOccurredAt = $this->asDate($occurredAt) ?: now()->toImmutable();

            MarketingConsentEvent::query()->firstOrCreate(
                [
                    'marketing_profile_id' => $profile->id,
                    'channel' => $channel,
                    'event_type' => $eventType,
                    'source_type' => $type,
                    'source_id' => $externalKey,
                    'occurred_at' => $eventOccurredAt,
                ],
                [
                    'details' => array_filter([
                        'provider' => $type === 'yotpo_contacts_import' ? 'yotpo' : 'legacy_import',
                        'consent_source' => $source,
                        'suppressed' => $suppressed,
                        'raw_status' => $rawStatus,
                        'timestamp_origin' => $channelData['timestamp_origin'] ?? null,
                    ], static fn ($value) => $value !== null),
                ]
            );
        }
    }

    protected function upsertCampaignStats(MarketingProfile $profile, string $type, array $row, string $externalKey): void
    {
        $sourceType = $type === 'yotpo_contacts_import' ? 'yotpo' : 'square_marketing';
        $sends = $this->nullableInt($row['sends_count'] ?? $row['sent_count'] ?? $row['sends'] ?? null);
        $opens = $this->nullableInt($row['opens_count'] ?? $row['opens'] ?? null);
        $clicks = $this->nullableInt($row['clicks_count'] ?? $row['clicks'] ?? null);
        $lastEngaged = $this->nullableString($row['last_engaged_at'] ?? $row['last_opened_at'] ?? null);
        $unsubscribedAt = $this->nullableString($row['unsubscribed_at'] ?? $row['email_unsubscribed_at'] ?? null);

        if ($sends === null && $opens === null && $clicks === null && $lastEngaged === null && $unsubscribedAt === null) {
            return;
        }

        MarketingExternalCampaignStat::query()->updateOrCreate(
            [
                'marketing_profile_id' => $profile->id,
                'source_type' => $sourceType,
                'external_contact_id' => $externalKey,
            ],
            [
                'sends_count' => max(0, (int) ($sends ?? 0)),
                'opens_count' => max(0, (int) ($opens ?? 0)),
                'clicks_count' => max(0, (int) ($clicks ?? 0)),
                'last_engaged_at' => $this->asDate($lastEngaged),
                'unsubscribed_at' => $this->asDate($unsubscribedAt),
                'raw_payload' => $row,
            ]
        );
    }

    protected function writeRowLog(
        MarketingImportRun $run,
        int $rowNumber,
        string $externalKey,
        string $status,
        array $messages,
        array $row,
        bool $dryRun
    ): void {
        if ($dryRun && !config('marketing.imports.store_row_payloads')) {
            return;
        }

        MarketingImportRow::query()->create([
            'marketing_import_run_id' => $run->id,
            'row_number' => $rowNumber,
            'external_key' => $externalKey,
            'status' => $status,
            'messages' => $messages,
            'payload' => config('marketing.imports.store_row_payloads') ? $row : null,
        ]);
    }

    /**
     * @param array<int,mixed> $header
     * @return array<int,string>
     */
    protected function normalizeHeader(array $header): array
    {
        return array_map(function ($value): string {
            $normalized = Str::snake(trim((string) $value));
            $normalized = preg_replace('/(^|_)s_m_s(_|$)/', '$1sms$2', $normalized) ?? $normalized;
            return $normalized !== '' ? $normalized : 'col_' . Str::random(6);
        }, $header);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function identityPayloadForRow(string $type, array $row, string $externalKey, int $tenantId): array
    {
        $fullName = $this->nullableString($row['name'] ?? $row['full_name'] ?? null);
        [$splitFirst, $splitLast] = $this->normalizer->splitName($fullName);

        $sourceType = $type === 'yotpo_contacts_import' ? 'yotpo_contact' : 'square_marketing_contact';

        return [
            'first_name' => $this->nullableString($row['first_name'] ?? null) ?: $splitFirst,
            'last_name' => $this->nullableString($row['last_name'] ?? null) ?: $splitLast,
            'raw_email' => $this->nullableString($row['email'] ?? $row['email_address'] ?? $row['customer_email'] ?? null),
            'raw_phone' => $this->nullableString($row['phone'] ?? $row['phone_number'] ?? $row['sms_phone'] ?? null),
            'source_channels' => [$type === 'yotpo_contacts_import' ? 'yotpo' : 'legacy_square_marketing'],
            'source_links' => [[
                'source_type' => $sourceType,
                'source_id' => $externalKey,
                'source_meta' => [
                    'import_type' => $type,
                    'created_at' => $this->nullableString($row['source_created_at'] ?? null),
                ],
            ]],
            'primary_source' => [
                'source_type' => $sourceType,
                'source_id' => $externalKey,
            ],
            'tenant_id' => $tenantId,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    protected function externalKeyForRow(string $type, array $row, int $rowNumber): string
    {
        if ($type === 'yotpo_contacts_import') {
            $explicit = $this->firstNonNull([
                $this->nullableString($row['external_id'] ?? null),
                $this->nullableString($row['contact_id'] ?? null),
                $this->nullableString($row['customer_id'] ?? null),
                $this->nullableString($row['id'] ?? null),
            ]);
            if ($explicit !== null) {
                return $explicit;
            }

            $normalizedEmail = $this->normalizer->normalizeEmail($this->nullableString($row['email'] ?? $row['email_address'] ?? null));
            $normalizedPhone = $this->normalizer->normalizePhone($this->nullableString($row['phone_number'] ?? $row['phone'] ?? null));
            $fullName = $this->nullableString($row['name'] ?? null);
            $createdAt = $this->nullableString($row['source_created_at'] ?? null);

            if ($normalizedEmail !== null) {
                return 'yotpo-email:' . $normalizedEmail;
            }

            if ($normalizedPhone !== null) {
                return 'yotpo-phone:' . $normalizedPhone;
            }

            $fingerprint = implode('|', array_map(
                static fn (?string $value): string => $value ?? '',
                [$fullName, $createdAt]
            ));

            return 'yotpo-row:' . sha1($fingerprint !== '|' ? $fingerprint : 'row:' . $rowNumber);
        }

        $candidates = [
            $row['external_id'] ?? null,
            $row['contact_id'] ?? null,
            $row['customer_id'] ?? null,
            $row['id'] ?? null,
            $row['email'] ?? null,
            $row['phone'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = $this->nullableString($candidate);
            if ($value) {
                return $value;
            }
        }

        return ($type === 'yotpo_contacts_import' ? 'yotpo' : 'square-marketing') . '-row-' . $rowNumber;
    }

    protected function normalizeType(string $type): string
    {
        $normalized = trim(strtolower($type));
        return match ($normalized) {
            'yotpo', 'yotpo_contacts', 'yotpo_contacts_import' => 'yotpo_contacts_import',
            'square_marketing', 'square_marketing_contacts', 'square_marketing_import' => 'square_marketing_import',
            default => throw new \InvalidArgumentException('Unsupported import type: ' . $type),
        };
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);
        return $string !== '' ? $string : null;
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    protected function nullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $string = strtolower(trim((string) $value));
        if ($string === '') {
            return null;
        }

        if (in_array($string, ['true', 'yes', 'y', 'subscribed', 'opt_in', 'active'], true)) {
            return true;
        }
        if (in_array($string, ['false', 'no', 'n', 'unsubscribed', 'opt_out', 'inactive'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function normalizeRow(string $type, array $row): array
    {
        if ($type !== 'yotpo_contacts_import') {
            return $row;
        }

        $row['source_created_at'] = $this->combineDateAndTime(
            $this->nullableString($row['date_created'] ?? null),
            $this->nullableString($row['time_created'] ?? null),
        )?->toIso8601String();

        return $row;
    }

    protected function combineDateAndTime(?string $date, ?string $time): ?\Carbon\CarbonImmutable
    {
        $date = $this->nullableString($date);
        $time = $this->nullableString($time);

        if ($date === null && $time === null) {
            return null;
        }

        try {
            return \Carbon\CarbonImmutable::parse(trim(($date ?? '') . ', ' . ($time ?? '')));
        } catch (\Throwable) {
            try {
                return \Carbon\CarbonImmutable::parse((string) ($date ?? $time));
            } catch (\Throwable) {
                return null;
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,array<string,mixed>>
     */
    protected function consentSnapshotForRow(string $type, array $row): array
    {
        if ($type === 'yotpo_contacts_import') {
            return [
                'sms' => $this->yotpoChannelSnapshot($row, 'sms'),
                'email' => $this->yotpoChannelSnapshot($row, 'email'),
            ];
        }

        return [
            'sms' => $this->genericChannelSnapshot($row, 'sms'),
            'email' => $this->genericChannelSnapshot($row, 'email'),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function genericChannelSnapshot(array $row, string $channel): array
    {
        $state = $this->firstNonNull([
            $this->nullableBool($row['accepts_' . $channel . '_marketing'] ?? null),
            $this->nullableBool($row[$channel . '_subscribed'] ?? null),
            $this->nullableBool($row[$channel . '_opt_in'] ?? null),
            $this->nullableBool($row[$channel . '_consent'] ?? null),
        ]);

        return [
            'state' => $state,
            'suppressed' => false,
            'source' => null,
            'raw_status' => $state,
            'occurred_at' => $this->nullableString($row[$channel . '_consent_timestamp'] ?? $row[$channel . '_unsubscribed_at'] ?? $row['unsubscribed_at'] ?? null),
            'timestamp_origin' => $this->nullableString($row[$channel . '_consent_timestamp'] ?? null) ? 'explicit_timestamp' : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function yotpoChannelSnapshot(array $row, string $channel): array
    {
        $rawStatus = strtolower((string) ($row[$channel . '_marketing_consent'] ?? ''));
        $rawStatus = trim($rawStatus);
        $suppressedRaw = strtolower(trim((string) ($row[$channel . '_suppressed'] ?? '')));
        $suppressed = $suppressedRaw === 'suppressed';

        $state = match ($rawStatus) {
            'subscribed' => true,
            'unsubscribed' => false,
            'never subscribed' => null,
            default => $this->firstNonNull([
                $this->nullableBool($row[$channel . '_marketing_consent'] ?? null),
                $this->nullableBool($row[$channel . '_subscribed'] ?? null),
                $this->nullableBool($row['accepts_' . $channel . '_marketing'] ?? null),
                $this->nullableBool($row[$channel . '_opt_in'] ?? null),
                $this->nullableBool($row[$channel . '_consent'] ?? null),
            ]),
        };

        if ($suppressed) {
            $state = false;
        }

        $explicitTimestamp = $this->nullableString($row[$channel . '_consent_timestamp'] ?? null);
        $createdAtFallback = $this->nullableString($row['source_created_at'] ?? null);

        return [
            'state' => $state,
            'suppressed' => $suppressed,
            'source' => $this->nullableString($row[$channel . '_consent_source'] ?? null),
            'raw_status' => $this->nullableString($row[$channel . '_marketing_consent'] ?? null),
            'occurred_at' => $explicitTimestamp ?: $createdAtFallback,
            'timestamp_origin' => $explicitTimestamp ? 'consent_timestamp' : ($createdAtFallback ? 'created_at_fallback' : null),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    protected function isChannelMarketable(array $snapshot, mixed $contactValue): bool
    {
        return ($snapshot['state'] ?? null) === true && $this->nullableString($contactValue) !== null;
    }

    protected function asDate(?string $value): ?\Carbon\CarbonImmutable
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return \Carbon\CarbonImmutable::parse($string);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<int,mixed> $values
     */
    protected function firstNonNull(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function requireTenantId(?int $tenantId): int
    {
        if (! is_numeric($tenantId) || (int) $tenantId <= 0) {
            throw new \RuntimeException('Legacy marketing imports require an explicit tenant context.');
        }

        return (int) $tenantId;
    }
}
