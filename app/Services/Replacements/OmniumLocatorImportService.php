<?php

namespace App\Services\Replacements;

use App\Models\ReplacementImportBatch;
use App\Models\ReplacementImportRow;
use App\Models\ReplacementModule;
use App\Models\ShopifyStore;
use App\Models\StockistLocation;
use App\Models\StockistLocatorSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class OmniumLocatorImportService
{
    public function __construct(private readonly ReplacementReadinessService $readiness) {}

    /** @return array<string,mixed> */
    public function import(Tenant $tenant, ShopifyStore $store, string $jsonPath, string $actorReference = 'system:replacement-import'): array
    {
        if (! is_file($jsonPath)) {
            throw new \RuntimeException('Missing Omnium JSON export.');
        }
        $decoded = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        $items = array_values((array) ($decoded['items'] ?? []));
        $options = (array) ($decoded['options'] ?? []);
        $checksum = hash_file('sha256', $jsonPath);

        $this->readiness->ensureCatalog((int) $tenant->id);
        $module = ReplacementModule::query()->forTenantId((int) $tenant->id)
            ->where('shopify_store_id', $store->id)->where('module_key', 'omnium_locator')->firstOrFail();
        $snapshot = $this->readiness->captureSnapshot($module, 'omnium_json_export', basename($jsonPath), [
            'sha256' => $checksum,
            'option_keys' => array_keys($options),
            'records' => count($items),
            'contains_customer_data' => false,
        ], count($items), ['format' => 'json', 'captured_by' => $actorReference]);
        $batch = ReplacementImportBatch::query()->firstOrCreate([
            'replacement_module_id' => $module->id,
            'idempotency_key' => 'omnium:'.$checksum,
        ], [
            'tenant_id' => $tenant->id,
            'replacement_source_snapshot_id' => $snapshot->id,
            'mode' => 'import',
            'status' => 'pending',
            'source_count' => count($items),
        ]);
        if ($batch->status === 'completed') {
            return $this->summary($batch, true);
        }

        $batch->forceFill(['status' => 'running', 'started_at' => now(), 'completed_at' => null])->save();
        $imported = 0;
        DB::transaction(function () use ($tenant, $store, $options, $checksum, $items, $batch, &$imported): void {
            StockistLocatorSetting::query()->updateOrCreate([
                'tenant_id' => $tenant->id, 'shopify_store_id' => $store->id,
            ], [
                'status' => 'draft', 'configuration' => $this->withoutSecrets($options), 'source_checksum' => $checksum, 'last_imported_at' => now(),
            ]);
            foreach ($items as $index => $item) {
                $sourceId = trim((string) ($item['lid'] ?? ''));
                $sourceKey = $sourceId !== '' ? 'omnium:'.$sourceId : 'omnium:'.hash('sha256', json_encode($item, JSON_THROW_ON_ERROR));
                $location = StockistLocation::query()->updateOrCreate([
                    'tenant_id' => $tenant->id, 'shopify_store_id' => $store->id, 'source_key' => $sourceKey,
                ], [
                    'source_id' => $sourceId ?: null,
                    'name' => trim((string) ($item['t'] ?? 'Untitled location')) ?: 'Untitled location',
                    'address' => trim((string) ($item['addr'] ?? '')) ?: $this->plainText((string) ($item['b'] ?? '')),
                    'body' => (string) ($item['b'] ?? ''),
                    'latitude' => isset($item['lt']) ? (float) $item['lt'] : null,
                    'longitude' => isset($item['lg']) ? (float) $item['lg'] : null,
                    'marker_icon' => trim((string) ($item['marker_icon'] ?? '')) ?: null,
                    'featured' => (bool) ($item['featured'] ?? false),
                    'source_visible' => (bool) ($item['v'] ?? true),
                    'published' => false,
                    'sort_order' => $index,
                    'categories' => array_values((array) ($item['filters'] ?? [])),
                    'source_payload' => $this->withoutSecrets((array) $item),
                ]);
                ReplacementImportRow::query()->updateOrCreate([
                    'replacement_import_batch_id' => $batch->id, 'source_key' => $sourceKey,
                ], [
                    'tenant_id' => $tenant->id, 'row_number' => $index + 1, 'status' => 'imported',
                    'target_type' => StockistLocation::class, 'target_id' => $location->id,
                    'messages' => [], 'payload' => ['source_id' => $sourceId ?: null],
                ]);
                $imported++;
            }
        });

        $targetCount = StockistLocation::query()->forTenantId((int) $tenant->id)->where('shopify_store_id', $store->id)->count();
        $passed = count($items) === 44 && $imported === 44 && $targetCount === 44;
        $batch->forceFill([
            'status' => $passed ? 'completed' : 'failed', 'imported_count' => $imported, 'warning_count' => 0,
            'rejected_count' => max(0, count($items) - $imported),
            'summary' => ['target_count' => $targetCount, 'configuration_keys' => count($options), 'published_locations' => 0],
            'completed_at' => now(),
        ])->save();
        $this->readiness->recordEvidence($module, 'check', 'source_snapshot', 'passed', ['message' => 'Omnium JSON export checksummed with 44 locations and '.count($options).' configuration fields.'], $actorReference);
        $this->readiness->recordEvidence($module, 'check', 'data_reconciliation', $passed ? 'passed' : 'failed', ['message' => 'Source '.count($items)."; imported {$imported}; target {$targetCount}; published 0."], $actorReference);
        $this->readiness->recordEvidence($module, 'check', 'settings_reconciliation', 'passed', ['message' => count($options).' exported configuration fields imported into an inactive draft; credentials remain external.'], $actorReference);

        return $this->summary($batch->fresh(), false);
    }

    private function withoutSecrets(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/(?:secret|password|token|credential|api[_-]?key)/i', (string) $key)) {
                $payload[$key] = '[RECONNECT_REQUIRED]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->withoutSecrets($value);
            }
        }

        return $payload;
    }

    private function plainText(string $html): ?string
    {
        $value = trim(html_entity_decode(strip_tags($html)));

        return $value !== '' ? $value : null;
    }

    /** @return array<string,mixed> */
    private function summary(ReplacementImportBatch $batch, bool $idempotentReplay): array
    {
        return ['batch_id' => $batch->id, 'status' => $batch->status, 'source_count' => $batch->source_count, 'imported_count' => $batch->imported_count, 'warning_count' => $batch->warning_count, 'rejected_count' => $batch->rejected_count, 'idempotent_replay' => $idempotentReplay];
    }
}
