<?php

namespace App\Console\Commands;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Marketing\MarketingConsentService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MarketingValidateSquareExport extends Command
{
    protected $signature = 'marketing:validate-square-export
        {file : Absolute or relative CSV path}
        {--tenant-id= : Tenant ID to validate against (required)}
        {--limit= : Optional max CSV rows to process}
        {--apply : Apply email opt-in for safe subscribed matches only}
        {--report= : Optional path to write a profile-level validation report CSV}';

    protected $description = 'Validate a Square export against existing tenant marketing profiles without creating duplicates.';

    /**
     * @var array<string,array<int,int>>
     */
    protected array $profileIdsByEmail = [];

    /**
     * @var array<string,array<int,int>>
     */
    protected array $profileIdsByPhone = [];

    /**
     * @var array<string,array<int,int>>
     */
    protected array $profileIdsBySquareId = [];

    public function handle(
        MarketingIdentityNormalizer $normalizer,
        MarketingConsentService $consentService
    ): int {
        $tenantId = $this->tenantIdOption();
        if ($tenantId === null) {
            $this->error('Missing required --tenant-id.');

            return self::FAILURE;
        }

        $path = $this->resolvedFilePath((string) $this->argument('file'));
        if ($path === null) {
            $this->error('CSV file could not be read.');

            return self::FAILURE;
        }

        $limit = $this->optionalPositiveInt($this->option('limit'));
        $apply = (bool) $this->option('apply');
        $reportPath = $this->resolvedReportPath($this->option('report'));

        $profiles = MarketingProfile::query()
            ->forTenantId($tenantId)
            ->get([
                'id',
                'email',
                'normalized_email',
                'phone',
                'normalized_phone',
                'accepts_email_marketing',
                'email_opted_out_at',
            ]);
        $profilesById = $profiles->keyBy(fn (MarketingProfile $profile): int => (int) $profile->id);

        $this->buildIdentityIndexes($profiles, $normalizer);
        $this->buildSquareIdIndex($tenantId);

        $summary = [
            'rows_processed' => 0,
            'rows_skipped_blank' => 0,
            'rows_unmatched' => 0,
            'rows_ambiguous' => 0,
            'rows_matched' => 0,
            'rows_subscribed' => 0,
            'rows_negative' => 0,
            'rows_unknown' => 0,
            'matched_profiles' => 0,
            'profiles_can_enable' => 0,
            'profiles_already_enabled' => 0,
            'profiles_blocked_export_negative' => 0,
            'profiles_blocked_existing_opt_out' => 0,
            'profiles_no_subscribed_signal' => 0,
            'applied_email_enabled' => 0,
            'apply_attempted' => $apply ? 1 : 0,
        ];

        /** @var array<int,array<string,mixed>> $profileDecisions */
        $profileDecisions = [];

        $handle = fopen($path, 'rb');
        if (! $handle) {
            $this->error('Unable to open CSV file for reading.');

            return self::FAILURE;
        }

        try {
            $header = null;
            $rowNumber = 0;

            while (($raw = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if ($header === null) {
                    $header = $this->normalizeHeader($raw);
                    continue;
                }

                $rowNumber++;
                if ($limit !== null && $summary['rows_processed'] >= $limit) {
                    break;
                }

                if ($this->isBlankRow($raw)) {
                    $summary['rows_skipped_blank']++;
                    continue;
                }

                $summary['rows_processed']++;

                $row = $this->rowFromHeader($header, $raw);
                $emailStatus = $this->emailStatusClass($row);
                if ($emailStatus === 'subscribed') {
                    $summary['rows_subscribed']++;
                } elseif ($emailStatus === 'negative') {
                    $summary['rows_negative']++;
                } else {
                    $summary['rows_unknown']++;
                }

                $normalizedEmail = $normalizer->normalizeEmail(
                    $this->firstNonEmpty([
                        $row['email_address'] ?? null,
                        $row['email'] ?? null,
                        $row['customer_email'] ?? null,
                    ])
                );
                $normalizedPhone = $normalizer->normalizePhone(
                    $this->firstNonEmpty([
                        $row['phone_number'] ?? null,
                        $row['phone'] ?? null,
                        $row['sms_phone'] ?? null,
                    ])
                );
                $squareCustomerId = $this->normalizeSquareCustomerId(
                    $this->firstNonEmpty([
                        $row['square_customer_id'] ?? null,
                        $row['customer_id'] ?? null,
                    ])
                );

                $resolution = $this->resolveProfile(
                    squareCustomerId: $squareCustomerId,
                    normalizedEmail: $normalizedEmail,
                    normalizedPhone: $normalizedPhone
                );

                if (($resolution['status'] ?? '') !== 'matched') {
                    if (($resolution['status'] ?? '') === 'ambiguous') {
                        $summary['rows_ambiguous']++;
                    } else {
                        $summary['rows_unmatched']++;
                    }

                    continue;
                }

                $summary['rows_matched']++;

                $profileId = (int) ($resolution['profile_id'] ?? 0);
                if ($profileId <= 0) {
                    continue;
                }

                if (! isset($profileDecisions[$profileId])) {
                    $profileDecisions[$profileId] = [
                        'profile_id' => $profileId,
                        'methods' => [],
                        'row_numbers' => [],
                        'square_customer_ids' => [],
                        'subscribed_count' => 0,
                        'negative_count' => 0,
                        'unknown_count' => 0,
                        'decision' => 'pending',
                    ];
                }

                $profileDecisions[$profileId]['methods'][(string) ($resolution['method'] ?? 'unknown')] = true;
                if (count((array) $profileDecisions[$profileId]['row_numbers']) < 12) {
                    $profileDecisions[$profileId]['row_numbers'][] = $rowNumber;
                }
                if ($squareCustomerId !== null) {
                    $profileDecisions[$profileId]['square_customer_ids'][$squareCustomerId] = true;
                }

                if ($emailStatus === 'subscribed') {
                    $profileDecisions[$profileId]['subscribed_count']++;
                } elseif ($emailStatus === 'negative') {
                    $profileDecisions[$profileId]['negative_count']++;
                } else {
                    $profileDecisions[$profileId]['unknown_count']++;
                }
            }
        } finally {
            fclose($handle);
        }

        $summary['matched_profiles'] = count($profileDecisions);

        foreach ($profileDecisions as $profileId => &$decision) {
            /** @var MarketingProfile|null $profile */
            $profile = $profilesById->get($profileId);
            if (! $profile instanceof MarketingProfile) {
                $decision['decision'] = 'profile_missing';
                continue;
            }

            $hasNegative = (int) ($decision['negative_count'] ?? 0) > 0;
            $hasSubscribed = (int) ($decision['subscribed_count'] ?? 0) > 0;

            if ($hasNegative) {
                $decision['decision'] = 'blocked_export_negative_status';
                $summary['profiles_blocked_export_negative']++;
                continue;
            }

            if (! $hasSubscribed) {
                $decision['decision'] = 'no_subscribed_signal';
                $summary['profiles_no_subscribed_signal']++;
                continue;
            }

            if ((bool) ($profile->accepts_email_marketing ?? false)) {
                $decision['decision'] = 'already_enabled';
                $summary['profiles_already_enabled']++;
                continue;
            }

            if ($profile->email_opted_out_at !== null) {
                $decision['decision'] = 'blocked_existing_opt_out';
                $summary['profiles_blocked_existing_opt_out']++;
                continue;
            }

            $decision['decision'] = 'can_enable';
            $summary['profiles_can_enable']++;

            if (! $apply) {
                continue;
            }

            $changed = $consentService->applyToProfile($profile, [
                'accepts_email_marketing' => true,
            ], [
                'tenant_id' => $tenantId,
                'source_type' => 'square_marketing_import',
                'source_id' => 'profile:' . $profileId,
                'details' => [
                    'matched_methods' => array_keys((array) ($decision['methods'] ?? [])),
                    'square_customer_ids' => array_keys((array) ($decision['square_customer_ids'] ?? [])),
                    'row_numbers' => array_values((array) ($decision['row_numbers'] ?? [])),
                ],
            ]);

            if ($changed) {
                $summary['applied_email_enabled']++;
            }
        }
        unset($decision);

        if ($reportPath !== null) {
            $this->writeReport($reportPath, $profileDecisions, $profilesById);
        }

        $this->line('tenant_id=' . $tenantId);
        $this->line('mode=' . ($apply ? 'apply' : 'validate'));
        $this->line('file=' . $path);
        $this->line('rows_processed=' . (int) $summary['rows_processed']);
        $this->line('rows_skipped_blank=' . (int) $summary['rows_skipped_blank']);
        $this->line('rows_matched=' . (int) $summary['rows_matched']);
        $this->line('rows_unmatched=' . (int) $summary['rows_unmatched']);
        $this->line('rows_ambiguous=' . (int) $summary['rows_ambiguous']);
        $this->line('rows_subscribed=' . (int) $summary['rows_subscribed']);
        $this->line('rows_negative=' . (int) $summary['rows_negative']);
        $this->line('rows_unknown=' . (int) $summary['rows_unknown']);
        $this->line('matched_profiles=' . (int) $summary['matched_profiles']);
        $this->line('profiles_can_enable=' . (int) $summary['profiles_can_enable']);
        $this->line('profiles_already_enabled=' . (int) $summary['profiles_already_enabled']);
        $this->line('profiles_blocked_export_negative=' . (int) $summary['profiles_blocked_export_negative']);
        $this->line('profiles_blocked_existing_opt_out=' . (int) $summary['profiles_blocked_existing_opt_out']);
        $this->line('profiles_no_subscribed_signal=' . (int) $summary['profiles_no_subscribed_signal']);
        if ($apply) {
            $this->line('applied_email_enabled=' . (int) $summary['applied_email_enabled']);
        }
        if ($reportPath !== null) {
            $this->line('report_path=' . $reportPath);
        }

        return self::SUCCESS;
    }

    /**
     * @param Collection<int,MarketingProfile> $profiles
     */
    protected function buildIdentityIndexes(Collection $profiles, MarketingIdentityNormalizer $normalizer): void
    {
        $this->profileIdsByEmail = [];
        $this->profileIdsByPhone = [];

        foreach ($profiles as $profile) {
            $profileId = (int) ($profile->id ?? 0);
            if ($profileId <= 0) {
                continue;
            }

            $email = $normalizer->normalizeEmail((string) ($profile->normalized_email ?: $profile->email));
            if ($email !== null) {
                $this->profileIdsByEmail[$email][$profileId] = $profileId;
            }

            $phone = $normalizer->normalizePhone((string) ($profile->normalized_phone ?: $profile->phone));
            if ($phone !== null) {
                $this->profileIdsByPhone[$phone][$profileId] = $profileId;
            }
        }
    }

    protected function buildSquareIdIndex(int $tenantId): void
    {
        $this->profileIdsBySquareId = [];

        $rows = MarketingProfileLink::query()
            ->forTenantId($tenantId)
            ->whereIn('source_type', ['square_customer', 'square_marketing_contact'])
            ->get(['source_id', 'marketing_profile_id']);

        foreach ($rows as $row) {
            $squareId = $this->normalizeSquareCustomerId((string) ($row->source_id ?? ''));
            $profileId = (int) ($row->marketing_profile_id ?? 0);
            if ($squareId === null || $profileId <= 0) {
                continue;
            }

            $this->profileIdsBySquareId[$squareId][$profileId] = $profileId;
        }
    }

    /**
     * @return array{status:string,profile_id:?int,reason:string,method:?string}
     */
    protected function resolveProfile(?string $squareCustomerId, ?string $normalizedEmail, ?string $normalizedPhone): array
    {
        $squareMatches = $squareCustomerId !== null
            ? array_values($this->profileIdsBySquareId[$squareCustomerId] ?? [])
            : [];

        if (count($squareMatches) > 1) {
            return [
                'status' => 'ambiguous',
                'profile_id' => null,
                'reason' => 'square_customer_id_maps_to_multiple_profiles',
                'method' => null,
            ];
        }

        if (count($squareMatches) === 1) {
            return [
                'status' => 'matched',
                'profile_id' => (int) $squareMatches[0],
                'reason' => 'square_customer_id_match',
                'method' => 'square_customer_id',
            ];
        }

        $emailMatches = $normalizedEmail !== null
            ? array_values($this->profileIdsByEmail[$normalizedEmail] ?? [])
            : [];
        $phoneMatches = $normalizedPhone !== null
            ? array_values($this->profileIdsByPhone[$normalizedPhone] ?? [])
            : [];

        if (count($emailMatches) > 1) {
            return [
                'status' => 'ambiguous',
                'profile_id' => null,
                'reason' => 'email_maps_to_multiple_profiles',
                'method' => null,
            ];
        }

        if (count($phoneMatches) > 1) {
            return [
                'status' => 'ambiguous',
                'profile_id' => null,
                'reason' => 'phone_maps_to_multiple_profiles',
                'method' => null,
            ];
        }

        $identityMatches = array_values(array_unique(array_merge($emailMatches, $phoneMatches)));
        if (count($identityMatches) === 0) {
            return [
                'status' => 'unmatched',
                'profile_id' => null,
                'reason' => 'no_existing_profile_match',
                'method' => null,
            ];
        }

        if (count($identityMatches) > 1) {
            return [
                'status' => 'ambiguous',
                'profile_id' => null,
                'reason' => 'email_phone_conflict_across_profiles',
                'method' => null,
            ];
        }

        $method = match (true) {
            $normalizedEmail !== null && $normalizedPhone !== null => 'email_phone',
            $normalizedEmail !== null => 'email',
            $normalizedPhone !== null => 'phone',
            default => 'unknown',
        };

        return [
            'status' => 'matched',
            'profile_id' => (int) $identityMatches[0],
            'reason' => 'identity_match',
            'method' => $method,
        ];
    }

    protected function emailStatusClass(array $row): string
    {
        $raw = strtolower(trim((string) ($row['email_subscription_status'] ?? '')));
        if ($raw === 'subscribed' || $raw === 'active' || $raw === 'opted_in') {
            return 'subscribed';
        }

        if (in_array($raw, ['unsubscribed', 'bounced', 'suppressed', 'opted_out', 'complained'], true)) {
            return 'negative';
        }

        return 'unknown';
    }

    protected function normalizeSquareCustomerId(?string $value): ?string
    {
        $resolved = trim((string) $value);

        return $resolved !== '' ? strtoupper($resolved) : null;
    }

    protected function tenantIdOption(): ?int
    {
        $value = $this->option('tenant-id');
        if (! is_numeric($value)) {
            return null;
        }

        $tenantId = (int) $value;

        return $tenantId > 0 ? $tenantId : null;
    }

    protected function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }

    protected function resolvedFilePath(string $input): ?string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        $path = realpath($trimmed) ?: $trimmed;

        return is_file($path) ? $path : null;
    }

    protected function resolvedReportPath(mixed $value): ?string
    {
        $path = trim((string) $value);

        return $path !== '' ? $path : null;
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
     * @param array<int,string> $header
     * @param array<int,mixed> $raw
     * @return array<string,mixed>
     */
    protected function rowFromHeader(array $header, array $raw): array
    {
        $row = [];
        foreach ($header as $index => $key) {
            $row[$key] = $raw[$index] ?? null;
        }

        return $row;
    }

    /**
     * @param array<int,mixed> $raw
     */
    protected function isBlankRow(array $raw): bool
    {
        foreach ($raw as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,mixed> $values
     */
    protected function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $resolved = trim((string) $value);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $profileDecisions
     * @param Collection<int,MarketingProfile> $profilesById
     */
    protected function writeReport(string $path, array $profileDecisions, Collection $profilesById): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($path, 'wb');
        if (! $handle) {
            $this->warn('Could not write report to: ' . $path);

            return;
        }

        fputcsv($handle, [
            'profile_id',
            'decision',
            'email',
            'phone',
            'accepts_email_marketing',
            'email_opted_out_at',
            'subscribed_count',
            'negative_count',
            'unknown_count',
            'match_methods',
            'square_customer_ids',
            'row_numbers',
        ]);

        ksort($profileDecisions);
        foreach ($profileDecisions as $profileId => $decision) {
            /** @var MarketingProfile|null $profile */
            $profile = $profilesById->get((int) $profileId);
            fputcsv($handle, [
                (int) $profileId,
                (string) ($decision['decision'] ?? 'unknown'),
                $profile?->email,
                $profile?->phone,
                $profile ? ((bool) ($profile->accepts_email_marketing ?? false) ? '1' : '0') : null,
                $profile?->email_opted_out_at?->toDateTimeString(),
                (int) ($decision['subscribed_count'] ?? 0),
                (int) ($decision['negative_count'] ?? 0),
                (int) ($decision['unknown_count'] ?? 0),
                implode('|', array_keys((array) ($decision['methods'] ?? []))),
                implode('|', array_keys((array) ($decision['square_customer_ids'] ?? []))),
                implode('|', array_map('strval', (array) ($decision['row_numbers'] ?? []))),
            ]);
        }

        fclose($handle);
    }
}
