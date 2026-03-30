<?php

namespace App\Services\Marketing;

use App\Models\BirthdayMessageEvent;
use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingImportRow;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class BirthdayCsvImportService
{
    public function __construct(
        protected MarketingProfileSyncService $profileSyncService,
        protected MarketingConsentService $consentService,
        protected BirthdayProfileService $birthdayProfileService,
        protected BirthdayRewardEngineService $birthdayRewardEngine,
        protected MarketingIdentityNormalizer $normalizer,
    ) {
    }

    /**
     * @return array{temp_path:string,file_name:string,headers:array<int,string>,preview_rows:array<int,array<string,mixed>>,mapping:array<string,string>}
     */
    public function storePreviewUpload(UploadedFile $file): array
    {
        $originalName = trim((string) $file->getClientOriginalName()) ?: 'birthday-import.csv';
        $fileName = now()->format('YmdHis') . '-' . Str::random(8) . '-' . Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $extension = strtolower(trim((string) $file->getClientOriginalExtension())) ?: 'csv';
        $storedPath = $file->storeAs('birthday-imports/tmp', $fileName . '.' . $extension);

        if (! $storedPath) {
            throw new \RuntimeException('Birthday import file could not be stored.');
        }

        return $this->previewStoredFile($storedPath, $originalName);
    }

    /**
     * @param array<string,string> $mapping
     * @return array{temp_path:string,file_name:string,headers:array<int,string>,preview_rows:array<int,array<string,mixed>>,mapping:array<string,string>}
     */
    public function previewStoredFile(string $storedPath, ?string $fileName = null, array $mapping = []): array
    {
        $absolutePath = Storage::disk('local')->path($storedPath);
        if (! is_file($absolutePath)) {
            throw new \RuntimeException('Stored birthday import file could not be found.');
        }

        $rows = $this->readCsvRows($absolutePath, 25);
        $headers = array_keys($rows[0] ?? []);
        $guessedMapping = $this->guessMapping($headers, $mapping);

        return [
            'temp_path' => $storedPath,
            'file_name' => $fileName ?: basename($absolutePath),
            'headers' => $headers,
            'preview_rows' => $rows,
            'mapping' => $guessedMapping,
        ];
    }

    /**
     * @param array<string,string> $mapping
     * @return array{run_id:int,status:string,summary:array<string,int>}
     */
    public function importStoredFile(
        string $storedPath,
        array $mapping,
        ?int $createdBy = null,
        bool $dryRun = false,
        ?int $tenantId = null
    ): array {
        $absolutePath = Storage::disk('local')->path($storedPath);
        if (! is_file($absolutePath)) {
            throw new \RuntimeException('Stored birthday import file could not be found.');
        }

        return $this->importPath($absolutePath, basename($absolutePath), $mapping, $createdBy, $dryRun, $tenantId);
    }

    /**
     * @param array<string,string> $mapping
     * @return array{run_id:int,status:string,summary:array<string,int>}
     */
    public function importPath(
        string $path,
        string $fileName,
        array $mapping,
        ?int $createdBy = null,
        bool $dryRun = false,
        ?int $tenantId = null
    ): array {
        if (! is_file($path)) {
            throw new \RuntimeException('Birthday import file could not be read: ' . $path);
        }

        $resolvedTenantId = $this->requireTenantId($tenantId);
        $run = MarketingImportRun::query()->create([
            'type' => 'birthday_customers_import',
            'status' => 'running',
            'source_label' => 'birthday_import',
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
            'birthday_profiles_created' => 0,
            'birthday_profiles_updated' => 0,
            'reward_issuances_created' => 0,
            'reward_issuances_updated' => 0,
            'message_events_created' => 0,
            'message_events_updated' => 0,
            'email_marketable' => 0,
            'sms_marketable' => 0,
            'email_suppressed' => 0,
            'sms_suppressed' => 0,
        ];

        try {
            $handle = fopen($path, 'rb');
            if (! $handle) {
                throw new \RuntimeException('Unable to open birthday import CSV.');
            }

            $header = null;
            $rowNumber = 0;
            while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if ($header === null) {
                    $header = $this->normalizeHeader($data);
                    continue;
                }

                if (count(array_filter($data, static fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }

                $rowNumber++;
                $row = [];
                foreach ($header as $index => $key) {
                    $row[$key] = $data[$index] ?? null;
                }

                $mappedRow = $this->mapRow($row, $mapping);
                $externalKey = $this->externalKeyForRow($mappedRow, $rowNumber);
                $summary['processed']++;

                try {
                    $result = $this->importRow(
                        row: $mappedRow,
                        rawRow: $row,
                        externalKey: $externalKey,
                        fileName: $fileName,
                        dryRun: $dryRun,
                        tenantId: $resolvedTenantId
                    );

                    $summary[$result['status']]++;
                    foreach ([
                        'profiles_created',
                        'profiles_updated',
                        'links_created',
                        'links_reused',
                        'reviews_created',
                        'records_skipped',
                        'matched_existing',
                        'birthday_profiles_created',
                        'birthday_profiles_updated',
                        'reward_issuances_created',
                        'reward_issuances_updated',
                        'message_events_created',
                        'message_events_updated',
                        'email_marketable',
                        'sms_marketable',
                        'email_suppressed',
                        'sms_suppressed',
                    ] as $key) {
                        $summary[$key] += (int) ($result[$key] ?? 0);
                    }

                    $this->writeRowLog($run, $rowNumber, $externalKey, $result['status'], $result['messages'], $row, $dryRun);
                } catch (\Throwable $e) {
                    $summary['failed']++;
                    $this->writeRowLog($run, $rowNumber, $externalKey, 'failed', [$e->getMessage()], $row, $dryRun);
                    Log::warning('birthday csv import row failed', [
                        'row_number' => $rowNumber,
                        'external_key' => $externalKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            fclose($handle);

            $run->forceFill([
                'status' => $summary['failed'] > 0 ? 'partial' : 'completed',
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $dryRun ? 'Dry-run executed; no birthday/profile writes were persisted.' : null,
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
            'run_id' => (int) $run->id,
            'status' => (string) $run->status,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string,string>
     */
    public function fieldOptions(): array
    {
        return [
            'ignore' => 'Ignore',
            'email' => 'Email',
            'phone' => 'Phone',
            'shopify_customer_id' => 'Shopify Customer ID',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'full_name' => 'Full Name',
            'birthday' => 'Birthday',
            'capture_date' => 'Capture Date',
            'signup_source' => 'Signup Source',
            'email_subscribed' => 'Email Subscribed',
            'sms_subscribed' => 'SMS Subscribed',
            'unsubscribed' => 'Unsubscribed',
            'email_sent_at' => 'Birthday Email Sent At',
            'email_opened_at' => 'Birthday Email Opened At',
            'email_clicked_at' => 'Birthday Email Clicked At',
            'discount_code' => 'Birthday Discount Code',
            'discount_code_used' => 'Birthday Discount Used',
        ];
    }

    /**
     * @param array<int,string> $headers
     * @param array<string,string> $overrides
     * @return array<string,string>
     */
    public function guessMapping(array $headers, array $overrides = []): array
    {
        $guesses = [];
        foreach ($headers as $header) {
            $guesses[$header] = match ($header) {
                'email' => 'email',
                'phone', 'phone_number', 'mobile', 'mobile_phone' => 'phone',
                'shopify_customer_id', 'customer_id' => 'shopify_customer_id',
                'first_name', 'first' => 'first_name',
                'last_name', 'last' => 'last_name',
                'name', 'full_name' => 'full_name',
                'birthday', 'birthdate', 'date_of_birth' => 'birthday',
                'capture_date', 'created_at', 'source_created_at' => 'capture_date',
                'signup_channel', 'signup_source', 'source', 'source_channel' => 'signup_source',
                'email_subscribed', 'email_marketing_consent' => 'email_subscribed',
                'sms_subscribed', 'sms_marketing_consent' => 'sms_subscribed',
                'unsubscribed' => 'unsubscribed',
                'this_year_email_sent', 'email_sent_at' => 'email_sent_at',
                'this_year_email_opened', 'email_opened_at' => 'email_opened_at',
                'this_year_email_clicked', 'email_clicked_at' => 'email_clicked_at',
                'this_year_email_discount_code', 'discount_code', 'coupon_code' => 'discount_code',
                'this_year_email_discount_code_used', 'discount_code_used', 'coupon_used' => 'discount_code_used',
                default => 'ignore',
            };
        }

        foreach ($overrides as $header => $field) {
            if (array_key_exists($header, $guesses)) {
                $guesses[$header] = $field;
            }
        }

        return $guesses;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function readCsvRows(string $path, int $limit): array
    {
        $rows = [];
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new \RuntimeException('Unable to open birthday import CSV.');
        }

        $header = null;
        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($header === null) {
                $header = $this->normalizeHeader($data);
                continue;
            }

            if (count(array_filter($data, static fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $row = [];
            foreach ($header as $index => $key) {
                $row[$key] = $data[$index] ?? null;
            }
            $rows[] = $row;

            if (count($rows) >= $limit) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $rawRow
     * @return array<string,mixed>
     */
    protected function importRow(
        array $row,
        array $rawRow,
        string $externalKey,
        string $fileName,
        bool $dryRun,
        int $tenantId
    ): array
    {
        $identity = $this->identityPayloadForRow($row, $externalKey, $tenantId);
        $syncResult = $this->profileSyncService->syncExternalIdentity($identity, [
            'dry_run' => $dryRun,
            'review_context' => [
                'source_label' => 'birthday_customers_import',
                'source_id' => $externalKey,
            ],
        ]);

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
                'birthday_profiles_created' => 0,
                'birthday_profiles_updated' => 0,
                'reward_issuances_created' => 0,
                'reward_issuances_updated' => 0,
                'message_events_created' => 0,
                'message_events_updated' => 0,
                'email_marketable' => $this->emailMarketable($row) ? 1 : 0,
                'sms_marketable' => $this->smsMarketable($row) ? 1 : 0,
                'email_suppressed' => $this->emailSuppressed($row) ? 1 : 0,
                'sms_suppressed' => $this->smsSuppressed($row) ? 1 : 0,
            ];
        }

        $profileId = (int) ($syncResult['profile_id'] ?? 0);
        $messages = [];
        $birthdayProfileCreated = 0;
        $birthdayProfileUpdated = 0;
        $rewardIssuancesCreated = 0;
        $rewardIssuancesUpdated = 0;
        $messageEventsCreated = 0;
        $messageEventsUpdated = 0;

        if (! $dryRun && $profileId > 0) {
            /** @var MarketingProfile|null $profile */
            $profile = MarketingProfile::query()->find($profileId);
            if ($profile) {
                [$birthdayProfile, $birthdayAction] = $this->upsertBirthdayProfile($profile, $row, $fileName);
                if ($birthdayProfile) {
                    $birthdayProfileCreated = $birthdayAction === 'created' ? 1 : 0;
                    $birthdayProfileUpdated = $birthdayAction === 'updated' ? 1 : 0;

                    $this->applyConsent($profile, $row, $externalKey);
                    $this->recordConsentSnapshot($profile, $row, $externalKey);

                    [$issuance, $issuanceAction] = $this->upsertImportedRewardIssuance($birthdayProfile, $row);
                    if ($issuanceAction === 'created') {
                        $rewardIssuancesCreated = 1;
                    } elseif ($issuanceAction === 'updated') {
                        $rewardIssuancesUpdated = 1;
                    }

                    [$event, $eventAction] = $this->upsertMessageEvent($birthdayProfile, $issuance, $row, $rawRow, $externalKey);
                    if ($eventAction === 'created') {
                        $messageEventsCreated = 1;
                    } elseif ($eventAction === 'updated') {
                        $messageEventsUpdated = 1;
                    }
                } else {
                    $messages[] = 'Row imported without a usable birthday value.';
                }
            }
        } elseif ($dryRun && $profileId > 0) {
            $messages[] = 'Dry-run: birthday profile, consent, reward, and event writes were not persisted.';
        }

        $status = ((int) ($syncResult['records_skipped'] ?? 0) > 0) ? 'skipped' : 'imported';
        if (($syncResult['reason'] ?? '') === 'missing_email_phone') {
            $messages[] = 'Row skipped because no usable identity or reusable birthday fingerprint was found.';
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
            'birthday_profiles_created' => $birthdayProfileCreated,
            'birthday_profiles_updated' => $birthdayProfileUpdated,
            'reward_issuances_created' => $rewardIssuancesCreated,
            'reward_issuances_updated' => $rewardIssuancesUpdated,
            'message_events_created' => $messageEventsCreated,
            'message_events_updated' => $messageEventsUpdated,
            'email_marketable' => $this->emailMarketable($row) ? 1 : 0,
            'sms_marketable' => $this->smsMarketable($row) ? 1 : 0,
            'email_suppressed' => $this->emailSuppressed($row) ? 1 : 0,
            'sms_suppressed' => $this->smsSuppressed($row) ? 1 : 0,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{0:?CustomerBirthdayProfile,1:'created'|'updated'|'skipped'}
     */
    protected function upsertBirthdayProfile(MarketingProfile $profile, array $row, string $fileName): array
    {
        $birthdayPayload = $this->birthdayPayload($row);
        if ($birthdayPayload === null) {
            return [null, 'skipped'];
        }

        $existing = CustomerBirthdayProfile::query()
            ->where('marketing_profile_id', $profile->id)
            ->first();

        $birthdayProfile = $this->birthdayProfileService->captureForProfile(
            $profile,
            $birthdayPayload,
            [
                'source' => 'birthday_import',
                'source_captured_at' => $this->asDate($row['capture_date'] ?? null) ?: now()->toImmutable(),
            ]
        );

        $birthdayProfile->forceFill([
            'signup_source' => $this->nullableString($row['signup_source'] ?? null),
            'capture_date' => $this->asDate($row['capture_date'] ?? null),
            'email_subscribed' => $this->emailSubscribedState($row),
            'sms_subscribed' => $this->smsSubscribedState($row),
            'unsubscribed' => $this->nullableBool($row['unsubscribed'] ?? null),
            'source_file' => $fileName,
            'metadata' => array_merge((array) ($birthdayProfile->metadata ?? []), [
                'import' => [
                    'source' => 'birthday_csv',
                    'file_name' => $fileName,
                    'signup_source' => $this->nullableString($row['signup_source'] ?? null),
                ],
            ]),
        ])->save();

        return [$birthdayProfile->fresh(), $existing ? 'updated' : 'created'];
    }

    protected function applyConsent(MarketingProfile $profile, array $row, string $externalKey): void
    {
        $emailSubscribed = $this->emailSubscribedState($row);
        $smsSubscribed = $this->smsSubscribedState($row);
        $captureDate = $this->asDate($row['capture_date'] ?? null);

        $this->consentService->applyToProfile($profile, [
            'accepts_email_marketing' => $emailSubscribed,
            'accepts_sms_marketing' => $smsSubscribed,
            'email_opted_out_at' => $emailSubscribed === false ? $captureDate : null,
            'sms_opted_out_at' => $smsSubscribed === false ? $captureDate : null,
        ], [
            'source_type' => 'birthday_customers_import',
            'source_id' => $externalKey,
            'occurred_at' => $captureDate,
            'details' => [
                'provider' => 'birthday_csv',
                'signup_source' => $this->nullableString($row['signup_source'] ?? null),
            ],
        ]);
    }

    protected function recordConsentSnapshot(MarketingProfile $profile, array $row, string $externalKey): void
    {
        foreach ([
            'email' => $this->emailSubscribedState($row),
            'sms' => $this->smsSubscribedState($row),
        ] as $channel => $state) {
            if ($state === null) {
                continue;
            }

            MarketingConsentEvent::query()->firstOrCreate(
                [
                    'marketing_profile_id' => $profile->id,
                    'channel' => $channel,
                    'event_type' => $state ? 'imported' : 'opted_out',
                    'source_type' => 'birthday_customers_import',
                    'source_id' => $externalKey,
                    'occurred_at' => $this->asDate($row['capture_date'] ?? null) ?: now()->toImmutable(),
                ],
                [
                    'details' => array_filter([
                        'provider' => 'birthday_csv',
                        'signup_source' => $this->nullableString($row['signup_source'] ?? null),
                        'file_origin' => 'birthday_csv',
                    ], static fn ($value) => $value !== null),
                ]
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array{0:?BirthdayRewardIssuance,1:'created'|'updated'|'skipped'}
     */
    protected function upsertImportedRewardIssuance(CustomerBirthdayProfile $birthdayProfile, array $row): array
    {
        $rewardCode = $this->nullableString($row['discount_code'] ?? null);
        $sentAt = $this->asDate($row['email_sent_at'] ?? null) ?: $this->asDate($row['capture_date'] ?? null);
        $used = $this->nullableBool($row['discount_code_used'] ?? null) === true;

        if ($rewardCode === null) {
            return [null, 'skipped'];
        }

        $config = $this->birthdayRewardEngine->rewardConfig();
        $cycleYear = (int) ($sentAt?->year ?: now()->year);
        $existing = BirthdayRewardIssuance::query()
            ->where('marketing_profile_id', $birthdayProfile->marketing_profile_id)
            ->where('cycle_year', $cycleYear)
            ->where('reward_type', 'discount_code')
            ->first();

        $issuance = BirthdayRewardIssuance::query()->updateOrCreate(
            [
                'marketing_profile_id' => $birthdayProfile->marketing_profile_id,
                'cycle_year' => $cycleYear,
                'reward_type' => 'discount_code',
            ],
            [
                'customer_birthday_profile_id' => $birthdayProfile->id,
                'reward_name' => (string) ($config['reward_name'] ?? 'Birthday Reward Credit'),
                'status' => $used ? 'redeemed' : 'claimed',
                'candle_cash_awarded' => null,
                'reward_value' => number_format((float) ($config['reward_value'] ?? 10), 2, '.', ''),
                'reward_code' => $rewardCode,
                'shopify_discount_id' => null,
                'claim_window_starts_at' => $sentAt,
                'claim_window_ends_at' => $this->expiryFromIssueDate($sentAt),
                'issued_at' => $sentAt,
                'claimed_at' => $sentAt,
                'expires_at' => $this->expiryFromIssueDate($sentAt),
                'redeemed_at' => $used ? $this->asDate($row['email_clicked_at'] ?? null) : null,
                'order_id' => null,
                'order_number' => null,
                'order_total' => null,
                'attributed_revenue' => null,
                'campaign_type' => 'birthday_email',
                'metadata' => [
                    'imported' => true,
                    'import_source' => 'birthday_csv',
                    'discount_code_used' => $used,
                ],
            ]
        );

        return [$issuance->fresh(), $existing ? 'updated' : 'created'];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $rawRow
     * @return array{0:?BirthdayMessageEvent,1:'created'|'updated'|'skipped'}
     */
    protected function upsertMessageEvent(
        CustomerBirthdayProfile $birthdayProfile,
        ?BirthdayRewardIssuance $issuance,
        array $row,
        array $rawRow,
        string $externalKey
    ): array {
        $sentAt = $this->asDate($row['email_sent_at'] ?? null);
        $openedAt = $this->asDate($row['email_opened_at'] ?? null);
        $clickedAt = $this->asDate($row['email_clicked_at'] ?? null);

        if (! $sentAt && ! $openedAt && ! $clickedAt) {
            return [null, 'skipped'];
        }

        $eventKey = 'birthday-email:' . $externalKey . ':' . sha1(json_encode([
            optional($sentAt)->toIso8601String(),
            optional($openedAt)->toIso8601String(),
            optional($clickedAt)->toIso8601String(),
            $this->nullableString($row['discount_code'] ?? null),
        ]));

        $status = $clickedAt ? 'clicked' : ($openedAt ? 'opened' : 'sent');
        $existing = BirthdayMessageEvent::query()->where('event_key', $eventKey)->first();

        $event = BirthdayMessageEvent::query()->updateOrCreate(
            ['event_key' => $eventKey],
            [
                'customer_birthday_profile_id' => $birthdayProfile->id,
                'marketing_profile_id' => $birthdayProfile->marketing_profile_id,
                'birthday_reward_issuance_id' => $issuance?->id,
                'campaign_type' => 'birthday_email',
                'channel' => 'email',
                'provider' => 'birthday_csv',
                'provider_message_id' => null,
                'status' => $status,
                'sent_at' => $sentAt,
                'delivered_at' => $sentAt,
                'opened_at' => $openedAt,
                'clicked_at' => $clickedAt,
                'conversion_at' => $this->nullableBool($row['discount_code_used'] ?? null) ? $clickedAt : null,
                'utm_campaign' => 'birthday-email',
                'utm_source' => 'birthday-import',
                'metadata' => [
                    'discount_code' => $this->nullableString($row['discount_code'] ?? null),
                    'discount_code_used' => $this->nullableBool($row['discount_code_used'] ?? null),
                    'raw_row' => $rawRow,
                ],
            ]
        );

        return [$event->fresh(), $existing ? 'updated' : 'created'];
    }

    /**
     * @param array<int,mixed> $header
     * @return array<int,string>
     */
    protected function normalizeHeader(array $header): array
    {
        return array_map(function ($value): string {
            return Str::snake(trim((string) $value));
        }, $header);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $mapping
     * @return array<string,mixed>
     */
    protected function mapRow(array $row, array $mapping): array
    {
        $mapped = [];
        foreach ($mapping as $header => $field) {
            if (($field ?? 'ignore') === 'ignore') {
                continue;
            }

            $mapped[$field] = $row[$header] ?? null;
        }

        return $mapped;
    }

    /**
     * @param array<string,mixed> $row
     */
    protected function identityPayloadForRow(array $row, string $externalKey, int $tenantId): array
    {
        $fullName = $this->nullableString($row['full_name'] ?? null);
        [$splitFirst, $splitLast] = $this->normalizer->splitName($fullName);

        return [
            'tenant_id' => $this->requireTenantId($tenantId),
            'first_name' => $this->nullableString($row['first_name'] ?? null) ?: $splitFirst,
            'last_name' => $this->nullableString($row['last_name'] ?? null) ?: $splitLast,
            'raw_email' => $this->nullableString($row['email'] ?? null),
            'raw_phone' => $this->nullableString($row['phone'] ?? null),
            'source_channels' => ['birthday_club'],
            'source_links' => [[
                'source_type' => 'birthday_customer',
                'source_id' => $externalKey,
                'source_meta' => array_filter([
                    'capture_date' => $this->nullableString($row['capture_date'] ?? null),
                    'signup_source' => $this->nullableString($row['signup_source'] ?? null),
                    'shopify_customer_id' => $this->nullableString($row['shopify_customer_id'] ?? null),
                ], static fn ($value) => $value !== null),
            ]],
            'primary_source' => [
                'source_type' => 'birthday_customer',
                'source_id' => $externalKey,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    protected function externalKeyForRow(array $row, int $rowNumber): string
    {
        $shopifyCustomerId = $this->nullableString($row['shopify_customer_id'] ?? null);
        if ($shopifyCustomerId !== null) {
            return 'birthday-shopify:' . $shopifyCustomerId;
        }

        $normalizedEmail = $this->normalizer->normalizeEmail($this->nullableString($row['email'] ?? null));
        if ($normalizedEmail !== null) {
            return 'birthday-email:' . $normalizedEmail;
        }

        $normalizedPhone = $this->normalizer->normalizePhone($this->nullableString($row['phone'] ?? null));
        if ($normalizedPhone !== null) {
            return 'birthday-phone:' . $normalizedPhone;
        }

        $birthdayPayload = $this->birthdayPayload($row);
        $fingerprint = implode('|', array_filter([
            strtolower((string) ($row['first_name'] ?? '')),
            strtolower((string) ($row['last_name'] ?? '')),
            $birthdayPayload['birthday_full_date'] ?? null,
            isset($birthdayPayload['birth_month'], $birthdayPayload['birth_day'])
                ? sprintf('%02d-%02d', (int) $birthdayPayload['birth_month'], (int) $birthdayPayload['birth_day'])
                : null,
        ], static fn ($value) => $value !== null && $value !== ''));

        if ($fingerprint !== '') {
            return 'birthday-fingerprint:' . sha1($fingerprint);
        }

        return 'birthday-row:' . $rowNumber;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{birth_month:?int,birth_day:?int,birth_year:?int,birthday_full_date:?string}|null
     */
    protected function birthdayPayload(array $row): ?array
    {
        $birthday = $this->nullableString($row['birthday'] ?? null);
        if ($birthday === null) {
            return null;
        }

        $parsed = $this->asDate($birthday);
        if ($parsed) {
            return $this->normalizeBirthdayPayload(
                month: (int) $parsed->month,
                day: (int) $parsed->day,
                year: (int) $parsed->year,
            );
        }

        $parts = preg_split('/[\/\-]/', $birthday) ?: [];
        if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            $month = (int) $parts[0];
            $day = (int) $parts[1];
            $year = isset($parts[2]) && is_numeric($parts[2]) ? (int) $parts[2] : null;

            return $this->normalizeBirthdayPayload($month, $day, $year);
        }

        return null;
    }

    /**
     * Imported birthday CSVs sometimes contain placeholder years like 0004 or 1058.
     * Preserve the valid month/day instead of rejecting the row when the year is unusable.
     *
     * @return array{birth_month:?int,birth_day:?int,birth_year:?int,birthday_full_date:?string}
     */
    protected function normalizeBirthdayPayload(int $month, int $day, ?int $year): array
    {
        try {
            return $this->birthdayProfileService->normalizeBirthday([
                'birth_month' => $month,
                'birth_day' => $day,
                'birth_year' => $year,
            ]);
        } catch (RuntimeException $e) {
            if ($year !== null && $e->getMessage() === 'Birthday year is outside the allowed range.') {
                return $this->birthdayProfileService->normalizeBirthday([
                    'birth_month' => $month,
                    'birth_day' => $day,
                    'birth_year' => null,
                ]);
            }

            throw $e;
        }
    }

    protected function emailSubscribedState(array $row): ?bool
    {
        $explicit = $this->nullableBool($row['email_subscribed'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $unsubscribed = $this->nullableBool($row['unsubscribed'] ?? null);
        if ($unsubscribed === null) {
            return $this->nullableString($row['email'] ?? null) ? true : null;
        }

        return ! $unsubscribed;
    }

    protected function smsSubscribedState(array $row): ?bool
    {
        $explicit = $this->nullableBool($row['sms_subscribed'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        return null;
    }

    protected function emailMarketable(array $row): bool
    {
        return $this->emailSubscribedState($row) === true
            && $this->nullableString($row['email'] ?? null) !== null;
    }

    protected function smsMarketable(array $row): bool
    {
        return $this->smsSubscribedState($row) === true
            && $this->nullableString($row['phone'] ?? null) !== null;
    }

    protected function emailSuppressed(array $row): bool
    {
        return $this->emailSubscribedState($row) === false;
    }

    protected function smsSuppressed(array $row): bool
    {
        return $this->smsSubscribedState($row) === false;
    }

    protected function expiryFromIssueDate(?CarbonImmutable $issuedAt): ?CarbonImmutable
    {
        if (! $issuedAt) {
            return null;
        }

        $config = $this->birthdayRewardEngine->rewardConfig();
        $days = max(1, (int) ($config['claim_window_days_after'] ?? 14));

        return $issuedAt->endOfDay()->addDays($days);
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
        if ($dryRun && ! config('marketing.imports.store_row_payloads')) {
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

    protected function requireTenantId(?int $tenantId): int
    {
        if (! is_numeric($tenantId) || (int) $tenantId <= 0) {
            throw new RuntimeException('Tenant context is required for birthday imports.');
        }

        return (int) $tenantId;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
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

        if (in_array($string, ['true', 'yes', 'y', 'subscribed', 'active', '1'], true)) {
            return true;
        }

        if (in_array($string, ['false', 'no', 'n', 'unsubscribed', 'inactive', '0'], true)) {
            return false;
        }

        return null;
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }
}
