<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;
use App\Models\SubscriptionOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Read-only durable work. This service cannot execute Shopify operations. */
class SubscriptionOperationService
{
    private const KINDS = ['installation_probe', 'owned_contract_sync', 'inbox_reconcile'];

    public function __construct(protected SubscriptionAccessService $access) {}

    public function record(int $tenantId, int $installationId, User $actor, string $kind, string $requestUuid, array $request = [], ?int $credentialRevisionId = null, ?callable $afterCommit = null): SubscriptionOperation
    {
        $this->access->assertManager($tenantId, $actor);
        abort_unless(in_array($kind, self::KINDS, true) && Str::isUuid($requestUuid), 422, 'Invalid subscription read request.');
        SubscriptionEvidence::assertContent($request, 65536);

        return DB::transaction(function () use ($tenantId, $installationId, $actor, $kind, $requestUuid, $request, $credentialRevisionId, $afterCommit): SubscriptionOperation {
            $installation = $this->installation($tenantId, $installationId);
            $existing = SubscriptionOperation::query()->forTenantId($tenantId)->where('installation_id', $installationId)->where('request_uuid', strtolower($requestUuid))->lockForUpdate()->first();
            if ($existing) {
                // Compare original encrypted content as well as its keyed digest: APP_KEY
                // rotation must not make identical accepted requests look conflicting.
                abort_unless($existing->kind === $kind && ($credentialRevisionId === null || $existing->credential_revision_id === $credentialRevisionId)
                    && hash_equals(SubscriptionEvidence::canonical($existing->request), SubscriptionEvidence::canonical($request)), 409, 'Subscription request UUID conflicts with its original content.');

                return $existing;
            }
            $revision = $this->revision($installation, $kind, $credentialRevisionId ?? $installation->active_credential_revision_id);
            $operation = SubscriptionOperation::query()->create([
                'tenant_id' => $tenantId, 'installation_id' => $installationId, 'credential_revision_id' => $revision->id,
                'actor_user_id' => $actor->id, 'kind' => $kind, 'request_uuid' => strtolower($requestUuid),
                'request' => $request, 'request_fingerprint' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical(['kind' => $kind, 'credential_revision_id' => $revision->id, 'request' => $request])),
                'digest_key_id' => SubscriptionEvidence::keyId(), 'status' => 'queued',
            ]);
            if ($afterCommit) {
                $id = (int) $operation->id;
                DB::afterCommit(function () use ($afterCommit, $id): void {
                    try {
                        $afterCommit($id);
                    } catch (\Throwable) { /* A scanner can recover the durable queued row. */
                    }
                });
            }

            return $operation;
        }, 3);
    }

    public function claim(int $tenantId, int $operationId, User $actor, int $leaseSeconds = 120): ?SubscriptionOperation
    {
        $this->access->assertManager($tenantId, $actor);
        abort_unless(SubscriptionEvidence::readsEnabled($tenantId), 409, 'Subscription provider reads are paused.');
        abort_unless($leaseSeconds >= 1 && $leaseSeconds <= 600, 422);

        return DB::transaction(function () use ($tenantId, $operationId, $leaseSeconds): ?SubscriptionOperation {
            $scope = SubscriptionOperation::query()->forTenantId($tenantId)->whereKey($operationId)->firstOrFail();
            $installation = $this->installation($tenantId, $scope->installation_id);
            $row = SubscriptionOperation::query()->forTenantId($tenantId)->whereKey($operationId)->lockForUpdate()->firstOrFail();
            if (! in_array($row->status, ['queued', 'failed', 'unknown', 'reading'], true) || $row->attempts >= 20
                || ($row->status === 'reading' && $row->lease_expires_at?->isFuture()) || $row->next_attempt_at?->isFuture()) {
                return null;
            }
            $this->revision($installation, $row->kind, $row->credential_revision_id);
            $row->forceFill(['status' => 'reading', 'attempts' => $row->attempts + 1, 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds($leaseSeconds), 'failure_code' => null])->save();

            return $row;
        }, 3);
    }

    /** May run inside the caller's short projection transaction. No network calls. */
    public function complete(int $tenantId, int $operationId, string $leaseToken, string $status, array $result = [], ?string $failureCode = null): bool
    {
        abort_unless(in_array($status, ['completed', 'blocked', 'failed', 'unknown'], true) && ($failureCode === null || preg_match('/^[a-z0-9_]{1,64}$/D', $failureCode)), 422);
        SubscriptionEvidence::assertContent($result);

        return DB::transaction(function () use ($tenantId, $operationId, $leaseToken, $status, $result, $failureCode): bool {
            $scope = SubscriptionOperation::query()->forTenantId($tenantId)->whereKey($operationId)->firstOrFail();
            $installation = $this->installation($tenantId, $scope->installation_id);
            $row = SubscriptionOperation::query()->forTenantId($tenantId)->whereKey($operationId)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'reading' || ! $row->lease_token || ! hash_equals($row->lease_token, $leaseToken) || ! $row->lease_expires_at?->isFuture()) {
                return false;
            }
            if ($status === 'completed') {
                $this->access->assertManager($tenantId, User::find($row->actor_user_id));
                abort_unless(SubscriptionEvidence::readsEnabled($tenantId), 409, 'Subscription provider reads are paused.');
                $this->revision($installation, $row->kind, $row->credential_revision_id);
            }
            $row->forceFill(['status' => $status, 'result' => $result, 'failure_code' => $failureCode, 'lease_token' => null, 'lease_expires_at' => null,
                'completed_at' => in_array($status, ['completed', 'blocked'], true) ? now() : null])->save();

            return true;
        }, 3);
    }

    private function installation(int $tenantId, int $id): SubscriptionInstallation
    {
        $row = SubscriptionInstallation::query()->forTenantId($tenantId)->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless(\App\Models\ShopifyStore::query()->whereKey($row->shopify_store_id)->where('tenant_id', $tenantId)->where('shop_domain', $row->shop_domain)->exists(), 409, 'Subscription store ownership changed.');

        return $row;
    }

    private function revision(SubscriptionInstallation $installation, string $kind, ?int $revisionId): SubscriptionCredentialRevision
    {
        $pending = $kind === 'installation_probe';
        abort_unless(in_array($installation->status, $pending ? ['pending_verification', 'verified'] : ['verified'], true)
            && ! $installation->read_pause_reason, 409, 'Subscription provider reads are paused.');
        $revision = SubscriptionCredentialRevision::query()->forTenantId($installation->tenant_id)->where('installation_id', $installation->id)->whereKey($revisionId)->lockForUpdate()->first();
        abort_unless($revision && ! $revision->revoked_at && ! $revision->expires_at?->isPast()
            && (($pending && $revision->status === 'pending_verification') || ($revision->status === 'active' && $installation->active_credential_revision_id === $revision->id)), 409, 'The pinned subscription credential is unavailable.');

        return $revision;
    }
}
