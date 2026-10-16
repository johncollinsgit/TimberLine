<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeCredentialRevision;
use App\Models\SubscriptionRechargeOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Dedicated query-only ledger. Legacy requests and Shopify operations never enter this lane. */
class SubscriptionRechargeOperationService
{
    public function __construct(private SubscriptionAccessService $access) {}

    public static function enabled(int $tenantId): bool
    {
        return config('subscriptions.recharge_reads_enabled', false) === true && in_array($tenantId, (array) config('subscriptions.recharge_read_tenant_ids', []), true);
    }

    public function authorize(int $tenantId, User $actor): void
    {
        $this->access->assertManager($tenantId, User::query()->find($actor->id));
        abort_unless(self::enabled($tenantId), 409, 'Recharge shadow reads are paused.');
    }

    public function record(int $tenantId, int $connectionId, User $actor, string $kind, string $uuid, array $request, ?int $revisionId = null): SubscriptionRechargeOperation
    {
        $this->authorize($tenantId, $actor);
        abort_unless(in_array($kind, ['verify_connection', 'sync_slice', 'refresh_customer'], true) && Str::isUuid($uuid), 422, 'Invalid Recharge read request.');
        SubscriptionEvidence::assertContent($request, 65536);

        return DB::transaction(function () use ($tenantId, $connectionId, $actor, $kind, $uuid, $request, $revisionId) {
            $connection = $this->connection($tenantId, $connectionId);
            $old = SubscriptionRechargeOperation::query()->forTenantId($tenantId)->where('connection_id', $connectionId)->where('request_uuid', strtolower($uuid))->lockForUpdate()->first();
            if ($old) {
                abort_unless($old->kind === $kind && ($revisionId === null || $old->credential_revision_id === $revisionId)
                    && hash_equals(SubscriptionEvidence::canonical($old->request), SubscriptionEvidence::canonical($request)), 409, 'Recharge request UUID conflicts with its original content.');

                return $old;
            }
            $revision = $this->revision($connection, $revisionId ?? $connection->active_credential_revision_id);

            return SubscriptionRechargeOperation::query()->create(['tenant_id' => $tenantId, 'connection_id' => $connectionId,
                'credential_revision_id' => $revision->id, 'actor_user_id' => $actor->id, 'request_uuid' => strtolower($uuid), 'kind' => $kind,
                'status' => 'queued', 'request' => $request, 'request_fingerprint' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($request)), 'digest_key_id' => SubscriptionEvidence::keyId()]);
        }, 3);
    }

    public function claim(int $tenantId, int $operationId, User $actor): ?SubscriptionRechargeOperation
    {
        $this->authorize($tenantId, $actor);

        return DB::transaction(function () use ($tenantId, $operationId) {
            $initial = SubscriptionRechargeOperation::query()->forTenantId($tenantId)->whereKey($operationId)->firstOrFail();
            $connection = $this->connection($tenantId, $initial->connection_id);
            $row = SubscriptionRechargeOperation::query()->forTenantId($tenantId)->where('connection_id', $connection->id)->whereKey($operationId)->lockForUpdate()->firstOrFail();
            if (! in_array($row->status, ['queued', 'failed', 'unknown', 'reading'], true) || ($row->status === 'reading' && $row->lease_expires_at?->isFuture()) || $row->attempts >= 20 || $row->next_attempt_at?->isFuture()) {
                return null;
            }
            $this->revision($connection, $row->credential_revision_id);
            $row->forceFill(['status' => 'reading', 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds(240), 'attempts' => $row->attempts + 1, 'failure_code' => null])->save();

            return $row;
        }, 3);
    }

    public function complete(int $tenantId, int $operationId, string $leaseToken, string $status, array $result = [], ?string $failureCode = null): bool
    {
        abort_unless(in_array($status, ['completed', 'failed', 'blocked'], true) && ($failureCode === null || preg_match('/^[a-z0-9_]{1,64}$/D', $failureCode)), 422);
        SubscriptionEvidence::assertContent($result);

        return DB::transaction(function () use ($tenantId, $operationId, $leaseToken, $status, $result, $failureCode) {
            $initial = SubscriptionRechargeOperation::query()->forTenantId($tenantId)->whereKey($operationId)->firstOrFail();
            $connection = SubscriptionRechargeConnection::query()->forTenantId($tenantId)->whereKey($initial->connection_id)->lockForUpdate()->firstOrFail();
            $row = SubscriptionRechargeOperation::query()->forTenantId($tenantId)->where('connection_id', $connection->id)->whereKey($operationId)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'reading' || ! $row->lease_token || ! hash_equals($row->lease_token, $leaseToken) || ! $row->lease_expires_at?->isFuture()) {
                return false;
            }
            if ($status === 'completed') {
                $this->authorize($tenantId, User::query()->findOrFail($row->actor_user_id));
                abort_unless($connection->status === 'verified' && $connection->verified_at && ! $connection->read_pause_reason, 409, 'Recharge reads are paused.');
                $this->revision($connection, $row->credential_revision_id);
            }
            $row->forceFill(['status' => $status, 'result' => $result, 'failure_code' => $failureCode, 'failure_at' => $failureCode ? now() : null,
                'completed_at' => now(), 'lease_token' => null, 'lease_expires_at' => null])->save();

            return true;
        }, 3);
    }

    private function connection(int $tenantId, int $id): SubscriptionRechargeConnection
    {
        $row = SubscriptionRechargeConnection::query()->forTenantId($tenantId)->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless($row->status === 'verified' && $row->verified_at && ! $row->read_pause_reason, 409, 'Recharge connection verification is required.');

        return $row;
    }

    private function revision(SubscriptionRechargeConnection $connection, ?int $id): SubscriptionRechargeCredentialRevision
    {
        $row = SubscriptionRechargeCredentialRevision::query()->forTenantId($connection->tenant_id)->where('connection_id', $connection->id)->whereKey($id)->lockForUpdate()->first();
        abort_unless($row && $row->status === 'active' && $row->id === $connection->active_credential_revision_id && ! $row->revoked_at && ! $row->expires_at?->isPast(), 409, 'Pinned Recharge credentials are unavailable.');

        return $row;
    }
}
