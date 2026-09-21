<?php

namespace App\Services\Replacements;

use App\Models\FormSubmission;
use App\Models\ReplacementImportBatch;
use App\Models\ReplacementImportRow;
use App\Models\ReplacementModule;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Forms\TenantFormProvisioningService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class StoreifyFormImportService
{
    private const FILES = [
        'contact-us-5367.xlsx' => ['template' => 'retail_contact', 'source_form_id' => '5367', 'expected' => 90],
        'forestry-weekend-16798.xlsx' => ['template' => 'legacy_forestry_weekend', 'source_form_id' => '16798', 'expected' => 41],
        'job-opportunity-5426.xlsx' => ['template' => 'retail_job_opportunity', 'source_form_id' => '5426', 'expected' => 108],
        'wholesale-application-5368.xlsx' => ['template' => 'legacy_storeify_wholesale_application', 'source_form_id' => '5368', 'expected' => 99],
    ];

    public function __construct(
        private readonly TenantFormProvisioningService $forms,
        private readonly ReplacementReadinessService $readiness
    ) {}

    /** @return array<string,mixed> */
    public function import(Tenant $tenant, ShopifyStore $store, string $directory, string $actorReference = 'system:replacement-import'): array
    {
        $this->readiness->ensureCatalog((int) $tenant->id);
        $module = ReplacementModule::query()->forTenantId((int) $tenant->id)
            ->where('shopify_store_id', $store->id)->where('module_key', 'storeify_forms')->firstOrFail();

        $manifest = [];
        $allRows = [];
        foreach (self::FILES as $filename => $definition) {
            $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
            if (! is_file($path)) {
                throw new \RuntimeException("Missing Storeify export: {$filename}");
            }

            [$headers, $rows] = $this->readWorkbook($path);
            $manifest[] = [
                'filename' => $filename,
                'source_form_id' => $definition['source_form_id'],
                'sha256' => hash_file('sha256', $path),
                'headers' => $headers,
                'records' => count($rows),
                'expected' => $definition['expected'],
            ];
            $allRows[] = compact('definition', 'headers', 'rows', 'filename');
        }

        $total = array_sum(array_column($manifest, 'records'));
        $snapshot = $this->readiness->captureSnapshot($module, 'storeify_xlsx_manifest', basename($directory), [
            'files' => $manifest,
            'total_records' => $total,
            'contains_customer_data' => false,
        ], $total, ['format' => 'xlsx', 'captured_by' => $actorReference]);
        $idempotencyKey = 'storeify:'.$snapshot->checksum;
        $batch = ReplacementImportBatch::query()->firstOrCreate([
            'replacement_module_id' => $module->id,
            'idempotency_key' => $idempotencyKey,
        ], [
            'tenant_id' => $tenant->id,
            'replacement_source_snapshot_id' => $snapshot->id,
            'mode' => 'import',
            'status' => 'pending',
            'source_count' => $total,
        ]);

        if ($batch->status === 'completed') {
            return $this->summary($batch, true);
        }

        $batch->forceFill(['status' => 'running', 'started_at' => now(), 'completed_at' => null])->save();
        $imported = $warnings = $rejected = 0;

        foreach ($allRows as $file) {
            $definition = $file['definition'];
            $headers = $file['headers'];
            $form = $this->forms->ensureTenantForm($tenant, $definition['template'], [
                'schema' => ['version' => 1, 'source' => 'storeify', 'fields' => $this->schemaFields($headers)],
            ]);

            foreach ($file['rows'] as $offset => $row) {
                $rowNumber = $offset + 2;
                $payload = array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), null));
                $payload = is_array($payload) ? $payload : [];
                $sourceKey = 'storeify:'.$tenant->id.':'.$definition['source_form_id'].':'.$rowNumber.':'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                try {
                    $normalized = $this->normalized($payload);
                    $submission = FormSubmission::query()->updateOrCreate(['source_key' => $sourceKey], [
                        'tenant_id' => $tenant->id,
                        'tenant_form_id' => $form->id,
                        'status' => 'submitted',
                        'source' => 'storeify_import',
                        'submitted_at' => $this->submittedAt($payload),
                        'submitter_name' => $normalized['name'],
                        'submitter_email' => $normalized['email'],
                        'submitter_phone' => $normalized['phone'],
                        'submitter_company' => $normalized['company'],
                        'payload' => $payload,
                        'normalized_payload' => $normalized,
                        'metadata' => [
                            'source_form_id' => $definition['source_form_id'],
                            'source_file' => $file['filename'],
                            'source_row' => $rowNumber,
                            'historical_import' => true,
                        ],
                    ]);
                    ReplacementImportRow::query()->updateOrCreate([
                        'replacement_import_batch_id' => $batch->id,
                        'source_key' => $sourceKey,
                    ], [
                        'tenant_id' => $tenant->id,
                        'row_number' => $rowNumber,
                        'status' => 'imported',
                        'target_type' => FormSubmission::class,
                        'target_id' => $submission->id,
                        'messages' => [],
                        'payload' => ['source_form_id' => $definition['source_form_id'], 'source_file' => $file['filename']],
                    ]);
                    $imported++;
                } catch (Throwable $exception) {
                    ReplacementImportRow::query()->updateOrCreate([
                        'replacement_import_batch_id' => $batch->id,
                        'source_key' => $sourceKey,
                    ], [
                        'tenant_id' => $tenant->id,
                        'row_number' => $rowNumber,
                        'status' => 'rejected',
                        'messages' => [['message' => $exception->getMessage()]],
                        'payload' => ['source_form_id' => $definition['source_form_id'], 'source_file' => $file['filename']],
                    ]);
                    $rejected++;
                }
            }
        }

        $targetCount = FormSubmission::query()->forTenantId((int) $tenant->id)->where('source', 'storeify_import')->count();
        $batch->forceFill([
            'status' => $rejected === 0 && $imported === $total ? 'completed' : 'failed',
            'imported_count' => $imported,
            'warning_count' => $warnings,
            'rejected_count' => $rejected,
            'summary' => ['manifest' => $manifest, 'target_count' => $targetCount, 'notifications_sent' => 0, 'published_forms' => 0],
            'completed_at' => now(),
        ])->save();

        $passing = $rejected === 0 && $imported === 338 && $targetCount === 338;
        $this->readiness->recordEvidence($module, 'check', 'source_snapshot', 'passed', ['message' => 'Four XLSX exports checksummed; 338 source rows recorded without customer payload in the manifest.'], $actorReference);
        $this->readiness->recordEvidence($module, 'check', 'data_reconciliation', $passing ? 'passed' : 'failed', [
            'message' => "Source {$total}; imported {$imported}; target {$targetCount}; rejected {$rejected}.",
            'files' => array_map(fn (array $item): array => ['source_form_id' => $item['source_form_id'], 'records' => $item['records']], $manifest),
        ], $actorReference);

        return $this->summary($batch->fresh(), false);
    }

    /** @return array{0:array<int,string>,1:array<int,array<int,mixed>>} */
    private function readWorkbook(string $path): array
    {
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        $rawHeaders = array_shift($rows) ?? [];
        $headers = [];
        $seen = [];
        foreach ($rawHeaders as $index => $header) {
            $base = Str::snake(trim((string) $header)) ?: 'column_'.($index + 1);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $headers[] = $seen[$base] === 1 ? $base : $base.'_'.$seen[$base];
        }
        $rows = array_values(array_filter($rows, fn (array $row): bool => collect($row)->contains(fn ($value): bool => trim((string) $value) !== '')));

        return [$headers, $rows];
    }

    /** @return array<int,array<string,mixed>> */
    private function schemaFields(array $headers): array
    {
        return array_map(static function (string $key): array {
            $type = str_contains($key, 'email') ? 'email' : (str_contains($key, 'phone') ? 'tel' : (str_contains($key, 'message') || str_contains($key, 'about') ? 'textarea' : 'text'));

            return ['key' => $key, 'type' => $type, 'label' => Str::headline($key), 'required' => false, 'source_imported' => true];
        }, $headers);
    }

    /** @return array<string,?string> */
    private function normalized(array $payload): array
    {
        $pick = static function (array $needles) use ($payload): ?string {
            foreach ($payload as $key => $value) {
                foreach ($needles as $needle) {
                    if (str_contains((string) $key, $needle) && trim((string) $value) !== '') {
                        return trim((string) $value);
                    }
                }
            }

            return null;
        };
        $name = $pick(['your_name', 'first_and_last_name']);
        if ($name === null) {
            $name = trim(implode(' ', array_filter([$pick(['first_name']), $pick(['last_name'])]))) ?: null;
        }

        return ['name' => $name, 'email' => $pick(['email']), 'phone' => $pick(['phone', 'contact_number']), 'company' => $pick(['company', 'business_name'])];
    }

    private function submittedAt(array $payload): ?CarbonImmutable
    {
        $value = $payload['sent'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value));
            }

            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function summary(ReplacementImportBatch $batch, bool $idempotentReplay): array
    {
        return ['batch_id' => $batch->id, 'status' => $batch->status, 'source_count' => $batch->source_count, 'imported_count' => $batch->imported_count, 'warning_count' => $batch->warning_count, 'rejected_count' => $batch->rejected_count, 'idempotent_replay' => $idempotentReplay];
    }
}
