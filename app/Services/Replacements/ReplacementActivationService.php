<?php

namespace App\Services\Replacements;

use App\Models\ReplacementActivationRun;
use App\Models\ReplacementEvidence;
use App\Models\ReplacementModule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReplacementActivationService
{
    public function __construct(
        private readonly ReplacementActivationAdapterRegistry $adapters,
        private readonly ReplacementReadinessService $readiness
    ) {}

    public function activate(ReplacementModule $module, User $actor, string $actorReference, string $idempotencyKey): ReplacementActivationRun
    {
        if (! (bool) config('replacement_readiness.activation_enabled', false)) {
            throw new RuntimeException('The production replacement activation gate is off.');
        }

        $existing = ReplacementActivationRun::query()
            ->where('replacement_module_id', $module->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        // Reconcile status before acquiring the activation lock. The locked
        // transaction below validates the gates again from current rows.
        $this->readiness->refreshStatus($module);

        return DB::transaction(function () use ($module, $actor, $actorReference, $idempotencyKey): ReplacementActivationRun {
            $locked = ReplacementModule::query()->whereKey($module->id)->lockForUpdate()->firstOrFail();

            $required = $this->readiness->currentEvidence($locked)->where('required', true);
            $requiredEvidenceReady = $required->isNotEmpty()
                && $required->every(fn (ReplacementEvidence $item): bool => $item->passes());

            if (! in_array($locked->status, ['armed', 'armed_for_pilot'], true) || ! $requiredEvidenceReady) {
                throw new RuntimeException('Every required readiness check must pass before activation.');
            }

            $adapter = $this->adapters->for($locked);
            if (! $adapter) {
                throw new RuntimeException('This replacement does not have a safe activation adapter yet.');
            }

            $run = ReplacementActivationRun::query()->create([
                'tenant_id' => (int) $locked->tenant_id,
                'replacement_module_id' => (int) $locked->id,
                'actor_user_id' => (int) $actor->id,
                'actor_reference' => $actorReference,
                'idempotency_key' => $idempotencyKey,
                'operation' => $locked->activation_mode === 'controlled_pilot' ? 'start_pilot' : 'activate',
                'status' => 'running',
                'before_payload' => $locked->only(['status', 'active_provider', 'target_provider', 'source_fingerprint', 'target_fingerprint', 'lock_version']),
                'started_at' => now(),
            ]);

            $rollbackPayload = [];
            try {
                $rollbackPayload = $adapter->activate($locked);
                $smoke = $adapter->smokeTest($locked->fresh());
                if (! (bool) ($smoke['passed'] ?? false)) {
                    throw new RuntimeException('Post-activation smoke checks failed.');
                }

                $run->forceFill([
                    'status' => 'completed',
                    'after_payload' => $locked->fresh()->only(['status', 'active_provider', 'target_provider', 'activated_at', 'lock_version']),
                    'rollback_payload' => $rollbackPayload,
                    'smoke_test_results' => $smoke,
                    'completed_at' => now(),
                ])->save();

                return $run->fresh();
            } catch (Throwable $exception) {
                if ($rollbackPayload !== []) {
                    $adapter->rollback($locked->fresh(), $rollbackPayload);
                }
                $run->forceFill([
                    'status' => 'rolled_back',
                    'rollback_payload' => $rollbackPayload,
                    'error_message' => $exception->getMessage(),
                    'completed_at' => now(),
                ])->save();

                ReplacementEvidence::query()->create([
                    'tenant_id' => (int) $locked->tenant_id,
                    'replacement_module_id' => (int) $locked->id,
                    'evidence_type' => 'activation',
                    'evidence_key' => 'activation_failure',
                    'version' => ((int) $locked->activationRuns()->count()) + 1,
                    'status' => 'failed',
                    'required' => false,
                    'payload' => ['message' => $exception->getMessage()],
                    'verified_by' => $actorReference,
                ]);

                throw $exception;
            }
        }, 3);
    }
}
