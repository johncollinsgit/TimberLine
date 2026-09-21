<?php

namespace App\Services\Replacements;

use App\Models\ReplacementEvidence;
use App\Models\ReplacementModule;
use App\Models\ReplacementSourceSnapshot;
use App\Models\ShopifyStore;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ReplacementReadinessService
{
    /** @return Collection<int,ReplacementModule> */
    public function ensureCatalog(int $tenantId): Collection
    {
        $stores = ShopifyStore::query()->forTenantId($tenantId)->orderBy('id')->get();

        foreach ($stores as $store) {
            $role = strtolower(trim((string) $store->store_role));
            foreach ((array) config('replacement_readiness.modules', []) as $moduleKey => $definition) {
                if (! in_array($role, (array) ($definition['store_roles'] ?? []), true)) {
                    continue;
                }

                $module = ReplacementModule::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'shopify_store_id' => (int) $store->id,
                        'module_key' => $moduleKey,
                    ],
                    [
                        'source_app' => (string) ($definition['source_app'] ?? $moduleKey),
                        'replacement_name' => (string) ($definition['replacement_name'] ?? $moduleKey),
                        'status' => 'draft',
                        'active_provider' => ($definition['activation_mode'] ?? '') === 'already_live' ? 'everbranch' : 'legacy',
                        'target_provider' => 'everbranch',
                        'activation_mode' => (string) ($definition['activation_mode'] ?? 'single_click'),
                        'metadata' => [
                            'label' => (string) ($definition['label'] ?? Str::headline($moduleKey)),
                            'expected_records' => $definition['expected_records'] ?? null,
                            'savings_per_year' => (float) ($definition['savings_per_year'] ?? 0),
                            'activation_adapter_ready' => false,
                        ],
                    ]
                );

                $this->ensureRequiredEvidence($module, (array) ($definition['required_checks'] ?? []));
            }
        }

        return ReplacementModule::query()
            ->forTenantId($tenantId)
            ->with(['store', 'evidence', 'sourceSnapshots', 'latestImportBatch', 'latestActivationRun'])
            ->orderBy('shopify_store_id')
            ->orderBy('id')
            ->get();
    }

    /** @param array<int,string> $requiredChecks */
    public function ensureRequiredEvidence(ReplacementModule $module, array $requiredChecks): void
    {
        foreach ($requiredChecks as $checkKey) {
            ReplacementEvidence::query()->firstOrCreate(
                [
                    'tenant_id' => (int) $module->tenant_id,
                    'replacement_module_id' => (int) $module->id,
                    'evidence_type' => 'check',
                    'evidence_key' => (string) $checkKey,
                    'version' => 1,
                ],
                ['status' => 'pending', 'required' => true]
            );
        }
    }

    /** @return array<string,mixed> */
    public function dashboard(int $tenantId, int $contextStoreId): array
    {
        $modules = $this->ensureCatalog($tenantId);
        $incrementalSavings = $modules
            ->where('activation_mode', '!=', 'already_live')
            ->groupBy('module_key')
            ->sum(fn (Collection $storeModules): float => (float) $storeModules->max(
                fn (ReplacementModule $module): float => (float) data_get($module->metadata, 'savings_per_year', 0)
            ));
        $confirmedSavings = (float) config('replacement_readiness.confirmed_annual_savings', 0);

        return [
            'activation_enabled' => (bool) config('replacement_readiness.activation_enabled', false),
            'context_store_id' => $contextStoreId,
            'confirmed_savings_per_year' => $confirmedSavings,
            'incremental_savings_per_year' => $incrementalSavings,
            'projected_savings_per_year' => $confirmedSavings + $incrementalSavings,
            'modules' => $modules->map(fn (ReplacementModule $module): array => $this->modulePayload($module, $contextStoreId))->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    public function modulePayload(ReplacementModule $module, int $contextStoreId): array
    {
        $evidence = $this->currentEvidence($module);
        $required = $evidence->where('required', true);
        $passing = $required->filter(fn (ReplacementEvidence $item): bool => $item->passes());
        $failed = $required->where('status', 'failed');
        $latestImport = $module->relationLoaded('latestImportBatch') ? $module->latestImportBatch : $module->latestImportBatch()->first();
        $latestActivation = $module->relationLoaded('latestActivationRun') ? $module->latestActivationRun : $module->latestActivationRun()->first();
        $adapterAvailable = app(ReplacementActivationAdapterRegistry::class)->for($module) !== null;

        return [
            'id' => (int) $module->id,
            'module_key' => $module->module_key,
            'label' => (string) data_get($module->metadata, 'label', Str::headline($module->module_key)),
            'source_app' => $module->source_app,
            'replacement_name' => $module->replacement_name,
            'status' => $module->status,
            'active_provider' => $module->active_provider,
            'target_provider' => $module->target_provider,
            'activation_mode' => $module->activation_mode,
            'store_id' => (int) $module->shopify_store_id,
            'store_key' => (string) $module->store?->store_key,
            'store_role' => (string) $module->store?->store_role,
            'store_matches_context' => (int) $module->shopify_store_id === $contextStoreId,
            'required_checks' => $required->count(),
            'passing_checks' => $passing->count(),
            'failed_checks' => $failed->count(),
            'progress_percent' => $required->count() > 0 ? (int) floor(($passing->count() / $required->count()) * 100) : 0,
            'checks' => $evidence->sortBy(['evidence_type', 'evidence_key'])->map(fn (ReplacementEvidence $item): array => [
                'type' => $item->evidence_type,
                'key' => $item->evidence_key,
                'label' => Str::headline($item->evidence_key),
                'status' => $item->status,
                'required' => (bool) $item->required,
                'verified_at' => $item->verified_at?->toIso8601String(),
                'message' => (string) data_get($item->payload, 'message', ''),
            ])->values()->all(),
            'snapshots' => $module->relationLoaded('sourceSnapshots') ? $module->sourceSnapshots->count() : $module->sourceSnapshots()->count(),
            'latest_import' => $latestImport ? [
                'status' => $latestImport->status,
                'source_count' => (int) $latestImport->source_count,
                'imported_count' => (int) $latestImport->imported_count,
                'warning_count' => (int) $latestImport->warning_count,
                'rejected_count' => (int) $latestImport->rejected_count,
                'completed_at' => $latestImport->completed_at?->toIso8601String(),
            ] : null,
            'latest_activation' => $latestActivation ? Arr::only($latestActivation->toArray(), ['operation', 'status', 'error_message', 'completed_at']) : null,
            'expected_records' => data_get($module->metadata, 'expected_records'),
            'savings_per_year' => (float) data_get($module->metadata, 'savings_per_year', 0),
            'adapter_available' => $adapterAvailable,
            'can_activate' => in_array($module->status, ['armed', 'armed_for_pilot'], true)
                && $adapterAvailable
                && (int) $module->shopify_store_id === $contextStoreId
                && (bool) config('replacement_readiness.activation_enabled', false),
            'activation_blockers' => $this->activationBlockers($module, $contextStoreId, $required, $adapterAvailable),
            'last_verified_at' => $module->last_verified_at?->toIso8601String(),
        ];
    }

    /** @param array<string,mixed> $payload */
    public function recordEvidence(ReplacementModule $module, string $type, string $key, string $status, array $payload, string $actorReference): ReplacementEvidence
    {
        if (! in_array($status, ['pending', 'passed', 'verified', 'failed', 'blocked', 'not_applicable'], true)) {
            throw new RuntimeException('Unsupported replacement evidence status.');
        }

        $latestEvidence = $module->evidence()->where('evidence_type', $type)->where('evidence_key', $key)->latest('version')->first();
        $latestVersion = (int) ($latestEvidence?->version ?? 0);
        $existingPending = $module->evidence()->where('evidence_type', $type)->where('evidence_key', $key)->where('version', max(1, $latestVersion))->first();

        if ($existingPending && $existingPending->status === 'pending') {
            $existingPending->forceFill([
                'status' => $status,
                'checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'payload' => $payload,
                'verified_at' => in_array($status, ReplacementEvidence::PASSING_STATUSES, true) ? now() : null,
                'verified_by' => $actorReference,
            ])->save();
            $evidence = $existingPending;
        } else {
            $evidence = $module->evidence()->create([
                'tenant_id' => (int) $module->tenant_id,
                'evidence_type' => $type,
                'evidence_key' => $key,
                'version' => $latestVersion + 1,
                'status' => $status,
                'required' => (bool) ($latestEvidence?->required ?? false),
                'checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'payload' => $payload,
                'verified_at' => in_array($status, ReplacementEvidence::PASSING_STATUSES, true) ? now() : null,
                'verified_by' => $actorReference,
            ]);
        }

        $this->refreshStatus($module);

        return $evidence;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $provenance */
    public function captureSnapshot(ReplacementModule $module, string $type, string $reference, array $payload, int $recordCount, array $provenance = []): ReplacementSourceSnapshot
    {
        $checksum = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return ReplacementSourceSnapshot::query()->firstOrCreate(
            ['replacement_module_id' => (int) $module->id, 'checksum' => $checksum],
            [
                'tenant_id' => (int) $module->tenant_id,
                'snapshot_type' => $type,
                'source_reference' => $reference,
                'record_count' => max(0, $recordCount),
                'payload' => $payload,
                'provenance' => $provenance,
                'captured_at' => now(),
            ]
        );
    }

    public function refreshStatus(ReplacementModule $module): ReplacementModule
    {
        return DB::transaction(function () use ($module): ReplacementModule {
            $locked = ReplacementModule::query()->whereKey($module->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['active', 'live_verified'], true)) {
                return $locked;
            }

            $required = $this->currentEvidence($locked)->where('required', true);
            $failed = $required->contains(fn (ReplacementEvidence $item): bool => in_array($item->status, ['failed', 'blocked'], true));
            $allPassed = $required->isNotEmpty() && $required->every(fn (ReplacementEvidence $item): bool => $item->passes());
            $hasCompletedImport = $locked->importBatches()->where('status', 'completed')->exists();
            $status = $failed
                ? 'blocked'
                : ($allPassed
                    ? ($locked->activation_mode === 'controlled_pilot' ? 'armed_for_pilot' : ($locked->activation_mode === 'already_live' ? 'live_verified' : 'armed'))
                    : ($hasCompletedImport ? 'shadow_testing' : 'draft'));

            $locked->forceFill([
                'status' => $status,
                'last_verified_at' => now(),
                'ready_at' => in_array($status, ['armed', 'armed_for_pilot', 'live_verified'], true) ? now() : null,
            ])->save();

            return $locked->fresh();
        });
    }

    /** @return Collection<int,ReplacementEvidence> */
    public function currentEvidence(ReplacementModule $module): Collection
    {
        $rows = $module->relationLoaded('evidence') ? $module->evidence : $module->evidence()->get();

        return $rows
            ->sortByDesc('version')
            ->unique(fn (ReplacementEvidence $item): string => $item->evidence_type.'|'.$item->evidence_key)
            ->values();
    }

    /** @param Collection<int,ReplacementEvidence> $required @return array<int,string> */
    private function activationBlockers(ReplacementModule $module, int $contextStoreId, Collection $required, bool $adapterAvailable): array
    {
        $blockers = $required
            ->reject(fn (ReplacementEvidence $item): bool => $item->passes())
            ->map(fn (ReplacementEvidence $item): string => Str::headline($item->evidence_key).' is '.$item->status.'.')
            ->values()
            ->all();

        if (! $adapterAvailable && $module->activation_mode !== 'already_live') {
            $blockers[] = 'The module-specific activation adapter is not ready.';
        }
        if ((int) $module->shopify_store_id !== $contextStoreId) {
            $blockers[] = 'Open the target Shopify store before activating this module.';
        }
        if (! (bool) config('replacement_readiness.activation_enabled', false) && $module->activation_mode !== 'already_live') {
            $blockers[] = 'The production activation release gate is off.';
        }

        return array_values(array_unique($blockers));
    }
}
