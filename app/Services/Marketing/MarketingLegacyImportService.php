<?php

namespace App\Services\Marketing;

use App\Models\MarketingExternalCampaignStat;
use App\Models\MarketingImportRow;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MarketingLegacyImportService
{
    public function __construct(
        protected MarketingProfileSyncService $profileSyncService,
        protected MarketingConsentService $consentService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function importFile(
        UploadedFile $file,
        string $type,
        ?int $createdBy = null,
        bool $dryRun = false
    ): array {
        $normalizedType = $this->normalizeType($type);

        $run = MarketingImportRun::query()->create([
            'type' => $normalizedType,
            'status' => 'running',
            'source_label' => $normalizedType === 'yotpo_contacts_import' ? 'yotpo' : 'square_marketing',
            'file_name' => $file->getClientOriginalName(),
            'started_at' => now(),
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
        ];

        try {
            $path = $file->getRealPath();
            if (! $path || ! file_exists($path)) {
                throw new \RuntimeException('Uploaded file could not be read.');
            }

            $rowNumber = 0;
            $handle = fopen($path, 'rb');
            if (! $handle) {
                throw new \RuntimeException('Unable to open uploaded CSV.');
            }

            $header = null;
            while (($data = fgetcsv($handle)) !== false) {
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

                $summary['processed']++;
                $externalKey = $this->externalKeyForRow($normalizedType, $row, $rowNumber);

                try {
                    $result = $this->importRow($normalizedType, $row, $externalKey, $dryRun);
                    $summary[$result['status']]++;
                    foreach (['profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped'] as $key) {
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
     *  records_skipped:int
     * }
     */
    protected function importRow(string $type, array $row, string $externalKey, bool $dryRun): array
    {
        $identity = $this->identityPayloadForRow($type, $row, $externalKey);
        $syncResult = $this->profileSyncService->syncExternalIdentity($identity, [
            'dry_run' => $dryRun,
            'review_context' => [
                'source_label' => $type,
                'source_id' => $externalKey,
            ],
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
            ];
        }

        $profileId = (int) ($syncResult['profile_id'] ?? 0);
        if (! $dryRun && $profileId > 0) {
            /** @var MarketingProfile|null $profile */
            $profile = MarketingProfile::query()->find($profileId);
            if ($profile) {
                $this->applyConsent($profile, $row, $type, $externalKey);
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
        ];
    }

    protected function applyConsent(MarketingProfile $profile, array $row, string $type, string $externalKey): void
    {
        $this->consentService->applyToProfile($profile, [
            'accepts_email_marketing' => $this->firstNonNull([
                $this->nullableBool($row['accepts_email_marketing'] ?? null),
                $this->nullableBool($row['email_subscribed'] ?? null),
                $this->nullableBool($row['email_opt_in'] ?? null),
                $this->nullableBool($row['email_consent'] ?? null),
            ]),
            'accepts_sms_marketing' => $this->firstNonNull([
                $this->nullableBool($row['accepts_sms_marketing'] ?? null),
                $this->nullableBool($row['sms_subscribed'] ?? null),
                $this->nullableBool($row['sms_opt_in'] ?? null),
                $this->nullableBool($row['sms_consent'] ?? null),
            ]),
            'email_opted_out_at' => $this->nullableString($row['email_unsubscribed_at'] ?? $row['unsubscribed_at'] ?? null),
            'sms_opted_out_at' => $this->nullableString($row['sms_unsubscribed_at'] ?? null),
        ], [
            'source_type' => $type,
            'source_id' => $externalKey,
        ]);
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
            return $normalized !== '' ? $normalized : 'col_' . Str::random(6);
        }, $header);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function identityPayloadForRow(string $type, array $row, string $externalKey): array
    {
        $fullName = $this->nullableString($row['name'] ?? $row['full_name'] ?? null);
        [$splitFirst, $splitLast] = app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->splitName($fullName);

        $sourceType = $type === 'yotpo_contacts_import' ? 'yotpo_contact' : 'square_marketing_contact';

        return [
            'first_name' => $this->nullableString($row['first_name'] ?? null) ?: $splitFirst,
            'last_name' => $this->nullableString($row['last_name'] ?? null) ?: $splitLast,
            'raw_email' => $this->nullableString($row['email'] ?? $row['email_address'] ?? $row['customer_email'] ?? null),
            'raw_phone' => $this->nullableString($row['phone'] ?? $row['phone_number'] ?? $row['sms_phone'] ?? null),
            'source_channels' => [$type === 'yotpo_contacts_import' ? 'legacy_yotpo' : 'legacy_square_marketing'],
            'source_links' => [[
                'source_type' => $sourceType,
                'source_id' => $externalKey,
                'source_meta' => [
                    'import_type' => $type,
                ],
            ]],
            'primary_source' => [
                'source_type' => $sourceType,
                'source_id' => $externalKey,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    protected function externalKeyForRow(string $type, array $row, int $rowNumber): string
    {
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
}
