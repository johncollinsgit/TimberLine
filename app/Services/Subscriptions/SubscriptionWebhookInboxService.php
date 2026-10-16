<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionInstallation;
use App\Models\SubscriptionWebhookInbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Call only after installation-specific raw HMAC and endpoint validation. */
class SubscriptionWebhookInboxService
{
    public function __construct(protected SubscriptionAccessService $access) {}

    public function persist(int $tenantId, int $installationId, string $deliveryId, ?string $eventId, string $topic, string $apiVersion, string $rawBody, array $headers = [], ?string $quarantineReason = null, ?callable $afterCommit = null, array $signingEvidence = []): SubscriptionWebhookInbox
    {
        abort_unless(preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $deliveryId) && ($eventId === null || preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $eventId))
            && preg_match('/^[a-z_]+\/[a-z_]+$/D', $topic) && strlen($topic) <= 100 && preg_match('/^[0-9]{4}-[0-9]{2}$/D', $apiVersion)
            && strlen($rawBody) > 0 && strlen($rawBody) <= 262144 && ($quarantineReason === null || preg_match('/^[a-z0-9_]{1,64}$/D', $quarantineReason)), 422, 'Invalid subscription delivery envelope.');
        abort_unless(count($signingEvidence) === 2 && array_key_exists('app_secret_reference', $signingEvidence) && array_key_exists('app_secret_revision', $signingEvidence)
            && is_string($signingEvidence['app_secret_reference']) && preg_match('/^[a-zA-Z0-9_.:\/-]{1,190}$/D', $signingEvidence['app_secret_reference'])
            && is_string($signingEvidence['app_secret_revision']) && preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $signingEvidence['app_secret_revision']), 422, 'Verified signing-key revision is required.');
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            SubscriptionEvidence::assertContent($decoded);
        }
        $safeHeaders = [];
        foreach ($headers as $key => $value) {
            $key = strtolower((string) $key);
            if (in_array($key, ['x-shopify-shop-domain', 'x-shopify-topic', 'x-shopify-api-version', 'x-shopify-webhook-id', 'x-shopify-event-id', 'x-shopify-triggered-at'], true)) {
                $value = is_array($value) ? ($value[0] ?? '') : $value;
                abort_unless(is_string($value) && strlen($value) <= 512, 422);
                $safeHeaders[$key] = $value;
            }
        }

        return DB::transaction(function () use ($tenantId, $installationId, $deliveryId, $eventId, $topic, $apiVersion, $rawBody, $safeHeaders, $quarantineReason, $afterCommit, $decoded, $signingEvidence): SubscriptionWebhookInbox {
            $installation = SubscriptionInstallation::query()->forTenantId($tenantId)->whereKey($installationId)->lockForUpdate()->firstOrFail();
            abort_unless($installation->verified_at !== null, 409, 'A previously verified subscription installation is required.');
            $reason = $quarantineReason ?? (! $this->access->entitled($tenantId) ? 'entitlement_paused' : ($installation->read_pause_reason ?: ($installation->status !== 'verified' ? 'installation_paused' : null)));
            $reason ??= ! SubscriptionEvidence::readsEnabled($tenantId) ? 'provider_reads_paused' : null;
            $reason ??= $apiVersion !== $installation->api_version ? 'unsupported_api_version' : (! is_array($decoded) ? 'malformed_payload' : null);
            $existing = SubscriptionWebhookInbox::query()->forTenantId($tenantId)->where('installation_id', $installationId)->where('delivery_id', $deliveryId)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->raw_body, $rawBody) || $existing->topic !== $topic || $existing->api_version !== $apiVersion || $existing->event_id !== $eventId) {
                    $existing->forceFill(['status' => 'quarantined', 'quarantine_reason' => 'conflicting_delivery', 'conflict_count' => min(1000000000, $existing->conflict_count + 1),
                        'conflict_evidence' => $existing->conflict_evidence ?? ['raw_body' => $rawBody, 'headers' => $safeHeaders, 'verification' => $signingEvidence, 'topic' => $topic, 'api_version' => $apiVersion, 'event_id' => $eventId, 'received_at' => now()->toISOString()],
                        'lease_token' => null, 'lease_expires_at' => null])->save();
                } elseif ($reason && in_array($existing->status, ['pending', 'reading'], true)) {
                    $existing->forceFill(['status' => 'quarantined', 'quarantine_reason' => $reason, 'lease_token' => null, 'lease_expires_at' => null])->save();
                }

                return $existing;
            }
            $row = SubscriptionWebhookInbox::query()->create(['tenant_id' => $tenantId, 'installation_id' => $installationId, 'delivery_id' => $deliveryId, 'event_id' => $eventId,
                'topic' => $topic, 'api_version' => $apiVersion, 'body_fingerprint' => SubscriptionEvidence::digest($rawBody), 'digest_key_id' => SubscriptionEvidence::keyId(),
                'raw_body' => $rawBody, 'headers' => $safeHeaders, 'verification' => $signingEvidence, 'status' => $reason ? 'quarantined' : 'pending', 'quarantine_reason' => $reason]);
            if ($afterCommit && $row->status === 'pending') {
                $id = (int) $row->id;
                DB::afterCommit(function () use ($afterCommit, $id): void {
                    try {
                        $afterCommit($id);
                    } catch (\Throwable) { /* Durable pending work remains available to recovery. */
                    }
                });
            }

            return $row;
        }, 3);
    }

    public function claim(int $tenantId, int $inboxId, User $actor, int $leaseSeconds = 120): ?SubscriptionWebhookInbox
    {
        $this->access->assertManager($tenantId, $actor);
        abort_unless(SubscriptionEvidence::readsEnabled($tenantId), 409, 'Subscription provider reads are paused.');
        abort_unless($leaseSeconds >= 1 && $leaseSeconds <= 600, 422);

        return DB::transaction(function () use ($tenantId, $inboxId, $leaseSeconds): ?SubscriptionWebhookInbox {
            $scope = SubscriptionWebhookInbox::query()->forTenantId($tenantId)->whereKey($inboxId)->firstOrFail();
            $installation = SubscriptionInstallation::query()->forTenantId($tenantId)->whereKey($scope->installation_id)->lockForUpdate()->firstOrFail();
            $row = SubscriptionWebhookInbox::query()->forTenantId($tenantId)->whereKey($inboxId)->lockForUpdate()->firstOrFail();
            if ($installation->status !== 'verified' || $installation->read_pause_reason || ! $installation->verified_at
                || ! in_array($row->status, ['pending', 'reading'], true) || $row->attempts >= 20 || $row->next_attempt_at?->isFuture()
                || ($row->status === 'reading' && $row->lease_expires_at?->isFuture())) {
                return null;
            }
            $row->forceFill(['status' => 'reading', 'attempts' => $row->attempts + 1, 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds($leaseSeconds)])->save();

            return $row;
        }, 3);
    }

    public function complete(int $tenantId, int $inboxId, string $leaseToken, ?string $quarantineReason = null): bool
    {
        abort_unless($quarantineReason === null || preg_match('/^[a-z0-9_]{1,64}$/D', $quarantineReason), 422);

        return DB::transaction(function () use ($tenantId, $inboxId, $leaseToken, $quarantineReason): bool {
            $scope = SubscriptionWebhookInbox::query()->forTenantId($tenantId)->whereKey($inboxId)->firstOrFail();
            $installation = SubscriptionInstallation::query()->forTenantId($tenantId)->whereKey($scope->installation_id)->lockForUpdate()->firstOrFail();
            $row = SubscriptionWebhookInbox::query()->forTenantId($tenantId)->whereKey($inboxId)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'reading' || ! $row->lease_token || ! hash_equals($row->lease_token, $leaseToken) || ! $row->lease_expires_at?->isFuture()) {
                return false;
            }
            $reason = $quarantineReason ?? (! $this->access->entitled($tenantId) ? 'entitlement_paused' : ($installation->read_pause_reason ?: ($installation->status !== 'verified' ? 'installation_paused' : null)));
            $reason ??= ! SubscriptionEvidence::readsEnabled($tenantId) ? 'provider_reads_paused' : null;
            $row->forceFill(['status' => $reason ? 'quarantined' : 'completed', 'quarantine_reason' => $reason, 'completed_at' => now(), 'lease_token' => null, 'lease_expires_at' => null])->save();

            return true;
        }, 3);
    }
}
