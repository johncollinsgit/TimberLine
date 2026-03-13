<?php

namespace App\Services\Marketing;

use App\Models\MarketingGroup;
use App\Models\MarketingGroupImportRow;
use App\Models\MarketingGroupImportRun;
use App\Models\MarketingGroupMember;

class MarketingGroupImportService
{
    public function __construct(
        protected MarketingProfileSyncService $profileSyncService,
        protected \App\Support\Marketing\MarketingIdentityNormalizer $normalizer
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function importFromCsv(
        MarketingGroup $group,
        string $filePath,
        ?int $createdBy = null,
        bool $dryRun = false
    ): array {
        $run = MarketingGroupImportRun::query()->create([
            'marketing_group_id' => $group->id,
            'file_name' => basename($filePath),
            'status' => 'running',
            'started_at' => now(),
            'created_by' => $createdBy,
            'summary' => [
                'mode' => $dryRun ? 'dry-run' : 'live',
            ],
        ]);

        $summary = [
            'rows' => 0,
            'profiles_created' => 0,
            'profiles_updated' => 0,
            'links_created' => 0,
            'links_reused' => 0,
            'reviews_created' => 0,
            'records_skipped' => 0,
            'members_added' => 0,
            'members_existing' => 0,
            'errors' => 0,
        ];

        try {
            $file = new \SplFileObject($filePath);
            $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);

            $header = null;
            $rowNumber = 0;

            foreach ($file as $row) {
                if (! is_array($row)) {
                    continue;
                }

                if ($header === null) {
                    $header = $this->normalizeHeader($row);
                    $this->assertExpectedColumns($header);
                    continue;
                }

                $rowNumber++;
                $payload = $this->mapRow($header, $row);
                if ($payload === null) {
                    continue;
                }

                $summary['rows']++;

                $email = trim((string) ($payload['email'] ?? ''));
                $phone = trim((string) ($payload['phone'] ?? ''));
                $firstName = trim((string) ($payload['first_name'] ?? ''));
                $lastName = trim((string) ($payload['last_name'] ?? ''));

                $identity = [
                    'first_name' => $firstName !== '' ? $firstName : null,
                    'last_name' => $lastName !== '' ? $lastName : null,
                    'raw_email' => $email !== '' ? $email : null,
                    'raw_phone' => $phone !== '' ? $phone : null,
                    'source_channels' => ['manual_import'],
                    'source_links' => [[
                        'source_type' => 'group_import_contact',
                        'source_id' => (string) $run->id . ':' . $rowNumber,
                        'source_meta' => [
                            'group_id' => $group->id,
                        ],
                    ]],
                    'primary_source' => [
                        'source_type' => 'group_import_contact',
                        'source_id' => (string) $run->id . ':' . $rowNumber,
                    ],
                ];

                $result = $this->profileSyncService->syncExternalIdentity($identity, [
                    'dry_run' => $dryRun,
                    'review_context' => [
                        'source_label' => 'group_csv_import',
                        'source_id' => (string) $run->id . ':' . $rowNumber,
                        'group_id' => $group->id,
                    ],
                ]);

                foreach (['profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped'] as $key) {
                    $summary[$key] += (int) ($result[$key] ?? 0);
                }

                $profileId = (int) ($result['profile_id'] ?? 0);
                $status = 'imported';
                $messages = [];
                if (($result['status'] ?? '') === 'review') {
                    $status = 'review';
                    $messages[] = 'Identity conflict routed to review.';
                }

                if ($profileId > 0) {
                    if (! $dryRun) {
                        $memberExists = MarketingGroupMember::query()
                            ->where('marketing_group_id', $group->id)
                            ->where('marketing_profile_id', $profileId)
                            ->exists();

                        if ($memberExists) {
                            $summary['members_existing']++;
                        } else {
                            MarketingGroupMember::query()->create([
                                'marketing_group_id' => $group->id,
                                'marketing_profile_id' => $profileId,
                                'added_by' => $createdBy,
                            ]);
                            $summary['members_added']++;
                        }
                    }
                } else {
                    $status = 'skipped';
                    $messages[] = 'No profile resolved from row identity values.';
                }

                if (! $dryRun) {
                    MarketingGroupImportRow::query()->create([
                        'marketing_group_import_run_id' => $run->id,
                        'row_number' => $rowNumber,
                        'status' => $status,
                        'external_key' => $email !== '' ? $this->normalizer->normalizeEmail($email) : $this->normalizer->normalizePhone($phone),
                        'marketing_profile_id' => $profileId > 0 ? $profileId : null,
                        'messages' => $messages,
                        'payload' => $payload,
                    ]);
                }
            }

            $run->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $dryRun ? 'Dry-run executed; group members were not persisted.' : null,
            ])->save();
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

        return [
            'run_id' => $run->id,
            'status' => $run->status,
            'summary' => $summary,
        ];
    }

    /**
     * @param array<int,mixed> $header
     * @return array<int,string>
     */
    protected function normalizeHeader(array $header): array
    {
        return array_map(function ($value): string {
            return strtolower(trim((string) $value));
        }, $header);
    }

    /**
     * @param array<int,string> $header
     */
    protected function assertExpectedColumns(array $header): void
    {
        $expected = ['email', 'phone', 'first_name', 'last_name'];
        $missing = collect($expected)
            ->filter(fn (string $column) => ! in_array($column, $header, true))
            ->values()
            ->all();

        if ($missing === []) {
            return;
        }

        throw new \RuntimeException('CSV header missing required columns: ' . implode(', ', $missing));
    }

    /**
     * @param array<int,string> $header
     * @param array<int,mixed> $row
     * @return array<string,string>|null
     */
    protected function mapRow(array $header, array $row): ?array
    {
        if ($header === []) {
            return null;
        }

        $mapped = [];
        foreach ($header as $index => $key) {
            if ($key === '') {
                continue;
            }
            $mapped[$key] = trim((string) ($row[$index] ?? ''));
        }

        if ($mapped === []) {
            return null;
        }

        return [
            'email' => (string) ($mapped['email'] ?? ''),
            'phone' => (string) ($mapped['phone'] ?? ''),
            'first_name' => (string) ($mapped['first_name'] ?? ''),
            'last_name' => (string) ($mapped['last_name'] ?? ''),
        ];
    }
}
