<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;
use App\Models\SubscriptionOperation;
use App\Models\SubscriptionProviderResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionProviderReadService
{
    public function __construct(private SubscriptionAccessService $access, private SubscriptionOperationService $operations,
        private SubscriptionShopifyReadTransport $transport, private SubscriptionOwnedContractReader $reader) {}

    /** Explicit setup or same-identity rotation. Credentials come only from this tenant-owned row. */
    public function verifyInstallation(int $tenantId, User $actor, int $shopifyStoreId, array $expectedIdentity, string $requestUuid): array
    {
        $this->assertReads($tenantId, $actor);
        abort_unless(Str::isUuid($requestUuid), 422, 'A request UUID is required.');
        $expected = $this->expected($expectedIdentity);
        $operation = DB::transaction(function () use ($tenantId, $actor, $shopifyStoreId, $expected, $requestUuid) {
            $store = ShopifyStore::query()->where('tenant_id', $tenantId)->whereKey($shopifyStoreId)->lockForUpdate()->firstOrFail();
            $domain = $store->shop_domain;
            abort_unless(preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $domain), 422, 'A canonical Shopify domain is required.');
            $binding = $this->access->binding($tenantId);
            abort_if($binding && ((int) $binding['shopify_store_id'] !== $shopifyStoreId || $binding['shop_domain'] !== $domain || $binding['app_client_id'] !== $expected['public_client_id']), 409, 'The existing subscription binding requires review.');
            $installation = SubscriptionInstallation::query()->forTenantId($tenantId)->where('shopify_store_id', $shopifyStoreId)->lockForUpdate()->first();
            if ($installation) {
                foreach ($expected + ['shop_domain' => $domain] as $field => $value) {
                    abort_unless($value === $installation->$field, 409, 'The original subscription installation cannot be rebound.');
                }
            } else {
                abort_if(SubscriptionInstallation::query()->where('app_installation_gid', $expected['app_installation_gid'])->orWhere(fn ($q) => $q->where('shop_gid', $expected['shop_gid'])->where('app_gid', $expected['app_gid']))->exists(), 409, 'This Shopify installation is already bound.');
                $installation = SubscriptionInstallation::query()->create($expected + ['tenant_id' => $tenantId, 'shopify_store_id' => $shopifyStoreId,
                    'shop_domain' => $domain, 'api_version' => SubscriptionShopifyReadTransport::API_VERSION, 'status' => 'pending_verification']);
            }
            $original = SubscriptionOperation::query()->forTenantId($tenantId)->where('installation_id', $installation->id)->where('request_uuid', strtolower($requestUuid))->first();
            if ($original) {
                return $this->operations->record($tenantId, $installation->id, $actor, 'installation_probe', $requestUuid, ['expected_identity' => $expected], $original->credential_revision_id);
            }
            $token = $store->access_token;
            abort_unless(is_string($token) && trim($token) !== '', 409, 'The selected store has no credential.');
            $active = SubscriptionCredentialRevision::query()->forTenantId($tenantId)->where('installation_id', $installation->id)->whereKey($installation->active_credential_revision_id)->first();
            if ($active && hash_equals((string) ($active->credentials['access_token'] ?? ''), $token) && ! $active->revoked_at && ! $active->expires_at?->isPast()) {
                $revision = $active;
            } else {
                $revision = SubscriptionCredentialRevision::query()->create([
                    'tenant_id' => $tenantId, 'installation_id' => $installation->id,
                    'revision' => 1 + (int) SubscriptionCredentialRevision::query()->where('installation_id', $installation->id)->max('revision'),
                    'status' => 'pending_verification', 'credentials' => ['access_token' => $token],
                    'credential_fingerprint' => SubscriptionEvidence::digest($token), 'digest_key_id' => SubscriptionEvidence::keyId(), 'grant_type' => 'existing_shopify_store',
                    // Access-token rotation is not app-secret rotation. Carry
                    // forward only this installation's already pinned material.
                    'app_secret' => $active?->app_secret, 'app_secret_reference' => $active?->app_secret_reference,
                    'app_secret_revision' => $active?->app_secret_revision, 'app_secret_expires_at' => $active?->app_secret_expires_at,
                ]);
            }

            return $this->operations->record($tenantId, $installation->id, $actor, 'installation_probe', $requestUuid, ['expected_identity' => $expected], $revision->id);
        }, 3);

        return $this->runProbe($tenantId, $actor, $operation);
    }

    public function probe(int $tenantId, User $actor, int $installationId, string $requestUuid): array
    {
        $this->assertReads($tenantId, $actor);
        $installation = $this->installation($tenantId, $installationId);
        abort_unless($installation->active_credential_revision_id, 409, 'Complete explicit installation setup first.');
        $operation = $this->operations->record($tenantId, $installationId, $actor, 'installation_probe', $requestUuid, [], $installation->active_credential_revision_id);

        return $this->runProbe($tenantId, $actor, $operation);
    }

    /** Local audited recovery only; a verified installation can never use this path. */
    public function correctPendingSetup(int $tenantId, User $actor, int $installationId, array $expectedIdentity, string $requestUuid): array
    {
        $this->access->assertManager($tenantId, $actor);
        $expected = $this->expected($expectedIdentity);
        abort_unless(Str::isUuid($requestUuid), 422, 'A new correction UUID is required.');
        $operation = DB::transaction(function () use ($tenantId, $actor, $installationId, $expected, $requestUuid) {
            $i = $this->installation($tenantId, $installationId, true);
            $replay = SubscriptionOperation::query()->forTenantId($tenantId)->where('installation_id', $installationId)->where('request_uuid', strtolower($requestUuid))->first();
            if ($replay) {
                abort_unless(($replay->request['local_action'] ?? null) === 'correct_pending_setup' && ($replay->request['after'] ?? null) === $expected, 409, 'Correction UUID conflicts with its original content.');

                return $replay;
            }
            abort_unless($i->status === 'pending_verification' && ! $i->verified_at && ! $i->active_credential_revision_id
                && ! SubscriptionCredentialRevision::query()->where('installation_id', $i->id)->whereNotNull('verified_at')->exists()
                && ! SubscriptionProviderResource::query()->where('installation_id', $i->id)->exists()
                && ShopifyStore::query()->whereKey($i->shopify_store_id)->where('tenant_id', $tenantId)->where('shop_domain', $i->shop_domain)->exists(), 409, 'Only an unaccepted installation setup can be corrected.');
            $operations = SubscriptionOperation::query()->forTenantId($tenantId)->where('installation_id', $i->id)->lockForUpdate()->get();
            foreach ($operations as $prior) {
                abort_if(($prior->status === 'reading' && $prior->lease_expires_at?->isFuture()) || ($prior->result['identity_verified'] ?? false), 409, 'Accepted or active verification cannot be corrected.');
            }
            $before = $i->only(array_keys($expected));
            $revision = SubscriptionCredentialRevision::query()->forTenantId($tenantId)->where('installation_id', $i->id)->orderByDesc('revision')->firstOrFail();
            $audit = $this->operations->record($tenantId, $i->id, $actor, 'installation_probe', $requestUuid,
                ['local_action' => 'correct_pending_setup', 'before' => $before, 'after' => $expected], $revision->id);
            foreach ($operations as $prior) {
                if (in_array($prior->status, ['queued', 'reading', 'failed', 'unknown'], true) || ($prior->kind === 'installation_probe' && $prior->status === 'blocked' && $prior->failure_code !== 'setup_identity_corrected')) {
                    $prior->forceFill(['status' => 'blocked', 'failure_code' => 'setup_identity_corrected', 'lease_token' => null, 'lease_expires_at' => null,
                        'result' => ['prior_status' => $prior->status, 'prior_failure_code' => $prior->failure_code, 'prior_result' => $prior->result, 'correction_operation_id' => $audit->id], 'completed_at' => now()])->save();
                }
            }
            // Explicit exception to model immutability before first acceptance.
            // The immutable audit request retains both expected tuples forever.
            SubscriptionInstallation::query()->forTenantId($tenantId)->whereKey($i->id)->whereNull('verified_at')->whereNull('active_credential_revision_id')->update($expected + ['evidence' => null, 'sync_state' => null, 'updated_at' => now()]);
            $audit->forceFill(['status' => 'blocked', 'failure_code' => 'setup_identity_corrected', 'result' => ['local_correction_recorded' => true, 'fresh_verification_required' => true], 'completed_at' => now()])->save();

            return $audit;
        }, 3);

        return $this->result($tenantId, $operation);
    }

    /** One bounded resumable slice. Only a fully reverified generation publishes a total. */
    public function syncOwnedContracts(int $tenantId, User $actor, int $installationId, string $requestUuid, array $bounds = []): array
    {
        $this->assertReads($tenantId, $actor);
        $installation = $this->installation($tenantId, $installationId);
        $ready = $this->readiness($tenantId, $installationId);
        abort_unless($ready['identity_verified'] && $ready['credential_ready'] && $ready['scopes_ready'] && ! $installation->read_pause_reason, 409, 'Verified installation credentials and read scopes are required.');
        $restart = $bounds['restart'] ?? false;
        abort_unless(is_bool($restart), 422);
        unset($bounds['restart']);
        $bounds = $this->bounds($bounds);
        $operation = DB::transaction(function () use ($tenantId, $actor, $installationId, $requestUuid, $bounds, $restart) {
            $i = $this->installation($tenantId, $installationId, true);
            $existing = SubscriptionOperation::query()->forTenantId($tenantId)->where('installation_id', $installationId)->where('request_uuid', strtolower($requestUuid))->first();
            if ($existing) {
                abort_unless(($existing->request['bounds'] ?? null) === $bounds && ($existing->request['restart'] ?? false) === $restart, 409, 'Subscription request UUID conflicts with its original content.');
                $state = $i->sync_state ?? [];
                $progress = $state['in_progress'] ?? [];
                if (in_array($existing->status, ['failed', 'unknown', 'reading'], true) && ($progress['generation'] ?? null) === ($existing->request['generation'] ?? null)) {
                    abort_unless(($progress['phase'] ?? null) !== 'restart_required' && ($existing->request['slice_position'] ?? null) === $this->slicePosition($progress), 409, 'This read slice has already advanced or requires an explicit restart. Use a new request UUID.');
                    $active = SubscriptionOperation::query()->whereKey($progress['active_operation_id'] ?? null)->first();
                    abort_if($active && $active->id !== $existing->id && $active->status === 'reading' && $active->lease_expires_at?->isFuture(), 409, 'A subscription read slice is already in progress.');
                    $progress['active_operation_id'] = $existing->id;
                    $state['in_progress'] = $progress;
                    $i->forceFill(['sync_state' => $state])->save();
                }

                return $this->operations->record($tenantId, $installationId, $actor, 'owned_contract_sync', $requestUuid, $existing->request, $existing->credential_revision_id);
            }
            $state = $i->sync_state ?? [];
            $progress = $state['in_progress'] ?? null;
            if ($progress && ($progress['active_operation_id'] ?? null)) {
                $active = SubscriptionOperation::query()->whereKey($progress['active_operation_id'])->first();
                abort_if($active?->status === 'reading' && $active->lease_expires_at?->isFuture(), 409, 'A subscription read slice is already in progress.');
            }
            if ($restart || ! $progress) {
                $progress = ['generation' => (string) Str::uuid(), 'credential_revision_id' => $i->active_credential_revision_id,
                    'phase' => 'collect', 'cursor' => null, 'pending' => [], 'index_done' => false, 'count' => 0, 'digest' => '',
                    'verify_cursor' => null, 'verify_count' => 0, 'verify_digest' => '', 'started_at' => now()->toISOString(), 'bounds' => $bounds];
            }
            abort_unless($progress['credential_revision_id'] === $i->active_credential_revision_id && $progress['bounds'] === $bounds && $progress['phase'] !== 'restart_required', 409, 'Restart the subscription observation after reviewing changed credentials, bounds or source evidence.');
            $op = $this->operations->record($tenantId, $installationId, $actor, 'owned_contract_sync', $requestUuid,
                ['generation' => $progress['generation'], 'bounds' => $bounds, 'restart' => $restart, 'slice_position' => $this->slicePosition($progress)], $i->active_credential_revision_id);
            $progress['first_operation_id'] ??= $op->id;
            $progress['active_operation_id'] = $op->id;
            $state['in_progress'] = $progress;
            $i->forceFill(['sync_state' => $state])->save();

            return $op;
        }, 3);
        $claimed = $this->operations->claim($tenantId, $operation->id, $actor, 240);
        if (! $claimed) {
            return $this->result($tenantId, $operation);
        }
        try {
            $installation = $this->installation($tenantId, $installationId);
            $state = $installation->sync_state ?? [];
            $progress = $state['in_progress'] ?? [];
            $this->fence($installation, $claimed, $progress);
            $revision = $this->revision($tenantId, $installationId, $claimed->credential_revision_id);
            $identity = $this->identity($installation, $revision);
            if ($this->missingScopes($identity['scopes'])) {
                throw new SubscriptionProviderReadException('missing_required_scopes');
            }
            $resources = [];
            $completed = false;
            if ($progress['phase'] === 'collect') {
                if (! $progress['pending'] && ! $progress['index_done']) {
                    $page = $this->indexPage($installation, $revision, $progress['cursor']);
                    $seen = $this->stagedContracts($installation, $progress);
                    foreach ($page['nodes'] as $node) {
                        if (isset($seen[$node['id']])) {
                            throw new SubscriptionProviderReadException('duplicate_contract');
                        }
                    }
                    $progress['pending'] = $page['nodes'];
                    $progress['cursor'] = $page['next'];
                    $progress['index_done'] = $page['done'];
                }
                $slice = array_splice($progress['pending'], 0, $bounds['max_contracts']);
                $index = [];
                foreach ($slice as $node) {
                    $index[$node['id']] = $node['revisionId'];
                }
                if ($index) {
                    $resources = $this->reader->read($installation, $revision, $bounds, $index)['resources'];
                }
                foreach ($slice as $node) {
                    $progress['digest'] = $this->indexDigest($progress['digest'], $node);
                    $progress['count']++;
                }
                if (! $progress['pending'] && $progress['index_done']) {
                    $progress['phase'] = 'verify';
                }
            }
            // Empty or small initial pages can verify immediately. Larger stores
            // resume their second pass one provider page per explicit request.
            if ($progress['phase'] === 'verify') {
                $page = $this->indexPage($installation, $revision, $progress['verify_cursor']);
                foreach ($page['nodes'] as $node) {
                    $progress['verify_digest'] = $this->indexDigest($progress['verify_digest'], $node);
                    $progress['verify_count']++;
                }
                $progress['verify_cursor'] = $page['next'];
                if ($page['done']) {
                    if ($progress['verify_count'] !== $progress['count'] || ! hash_equals($progress['digest'], $progress['verify_digest'])) {
                        throw new SubscriptionProviderReadException('changing_contract_index');
                    }
                    $completed = true;
                }
            }
            $this->assertReads($tenantId, $actor);
            DB::transaction(function () use ($tenantId, $installationId, $claimed, $identity, $progress, $resources, $completed) {
                $current = $this->installation($tenantId, $installationId, true);
                $state = $current->sync_state ?? [];
                $this->fence($current, $claimed, $state['in_progress'] ?? []);
                $summary = ['generation' => $progress['generation'], 'sync_complete' => $completed, 'staged_contract_count' => $progress['count'],
                    'phase' => $completed ? 'complete' : $progress['phase'], 'observed_at' => now()->toISOString(), 'resources' => $resources];
                if ($completed) {
                    $seen = [];
                    foreach ($this->generationOperations($current, $progress) as $prior) {
                        foreach (($prior->result['resources'] ?? []) as $row) {
                            $this->project($current, $claimed, $row, $seen);
                        }
                    }
                    foreach ($resources as $row) {
                        $this->project($current, $claimed, $row, $seen);
                    }
                    if (count($seen) !== $progress['count']) {
                        throw new SubscriptionProviderReadException('staging_count_mismatch');
                    }
                    SubscriptionProviderResource::query()->forTenantId($tenantId)->where('installation_id', $installationId)->where('sync_operation_id', '!=', $claimed->id)->update(['stale_reason' => 'not_in_latest_observation']);
                    $accepted = ['operation_id' => $claimed->id, 'credential_revision_id' => $claimed->credential_revision_id,
                        'owned_contract_count' => $progress['count'], 'generation' => $progress['generation'], 'started_at' => $progress['started_at'],
                        'observed_at' => $summary['observed_at'], 'consistency' => 'stable_consecutive_reads',
                        'cycle_window' => ['start_index' => $progress['bounds']['cycle_start'], 'end_index' => $progress['bounds']['cycle_end']]];
                    $state['last_complete'] = $accepted;
                    $state['last_failure'] = null;
                    $state['in_progress'] = null;
                    $current->last_synced_at = now();
                } else {
                    $progress['active_operation_id'] = null;
                    $state['in_progress'] = $progress;
                }
                $current->forceFill(['scopes' => $identity['scopes'], 'sync_state' => $state])->save();
                if (! $this->operations->complete($tenantId, $claimed->id, $claimed->lease_token, 'completed', $summary)) {
                    throw new SubscriptionProviderReadException('read_lease_lost');
                }
            }, 3);
        } catch (SubscriptionProviderReadException $e) {
            $this->failed($tenantId, $claimed, $e->failureCode);
        } catch (\Throwable) {
            $this->failed($tenantId, $claimed, 'local_read_acceptance_failed');
        }

        return $this->result($tenantId, $claimed->fresh());
    }

    private function indexPage(SubscriptionInstallation $i, SubscriptionCredentialRevision $r, ?string $cursor): array
    {
        $connection = $this->transport->query($i, $r, 'SubscriptionOwnedContracts', ['after' => $cursor])['subscriptionContracts'] ?? null;
        if (! is_array($connection) || ! is_array($connection['nodes'] ?? null) || ! array_is_list($connection['nodes']) || count($connection['nodes']) > 25
            || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null) || ! array_key_exists('endCursor', $connection['pageInfo'])) {
            throw new SubscriptionProviderReadException('incomplete_connection');
        }
        $nodes = [];
        $seen = [];
        foreach ($connection['nodes'] as $node) {
            $id = $node['id'] ?? null;
            if (! is_string($id) || ! preg_match('/^gid:\/\/shopify\/SubscriptionContract\/[1-9][0-9]*$/D', $id) || strlen($id) > 190 || ($node['app']['id'] ?? null) !== $i->app_gid || isset($seen[$id])) {
                throw new SubscriptionProviderReadException('contract_ownership_mismatch');
            }
            $seen[$id] = true;
            $nodes[] = ['id' => $id, 'app' => ['id' => $i->app_gid], 'revisionId' => SubscriptionUnsignedRevision::parse($node['revisionId'] ?? null)];
        }
        $next = $connection['pageInfo']['endCursor'];
        $done = ! $connection['pageInfo']['hasNextPage'];
        if (! $done && (! $nodes || ! is_string($next) || $next === '' || strlen($next) > 2048 || $next === $cursor)) {
            throw new SubscriptionProviderReadException('invalid_connection_cursor');
        }

        return ['nodes' => $nodes, 'next' => $done ? null : $next, 'done' => $done];
    }

    private function indexDigest(string $previous, array $node): string
    {
        return hash('sha256', $previous.SubscriptionEvidence::canonical($node));
    }

    private function slicePosition(array $progress): string
    {
        return hash('sha256', SubscriptionEvidence::canonical(array_intersect_key($progress, array_flip([
            'phase', 'cursor', 'pending', 'index_done', 'count', 'digest', 'verify_cursor', 'verify_count', 'verify_digest',
        ]))));
    }

    private function generationOperations(SubscriptionInstallation $i, array $progress): \Generator
    {
        foreach (SubscriptionOperation::query()->forTenantId($i->tenant_id)->where('installation_id', $i->id)->where('kind', 'owned_contract_sync')
            ->where('id', '>=', $progress['first_operation_id'])->where('status', 'completed')->orderBy('id')->cursor() as $op) {
            if (($op->request['generation'] ?? null) === $progress['generation']) {
                yield $op;
            }
        }
    }

    private function stagedContracts(SubscriptionInstallation $i, array $progress): array
    {
        $seen = [];
        foreach ($this->generationOperations($i, $progress) as $operation) {
            foreach (($operation->result['resources'] ?? []) as $row) {
                if ($row['resource_type'] === 'contract') {
                    $seen[$row['provider_gid']] = true;
                }
            }
        }

        return $seen;
    }

    private function fence(SubscriptionInstallation $i, SubscriptionOperation $op, array $progress): void
    {
        if ($i->active_credential_revision_id !== $op->credential_revision_id || ($progress['generation'] ?? null) !== ($op->request['generation'] ?? null)
            || ($progress['active_operation_id'] ?? null) !== $op->id) {
            throw new SubscriptionProviderReadException('read_generation_changed');
        }
        if ((int) ($i->evidence['operation_id'] ?? 0) > $op->id) {
            throw new SubscriptionProviderReadException('identity_evidence_superseded');
        }
    }

    private function project(SubscriptionInstallation $i, SubscriptionOperation $op, array $row, array &$seen): void
    {
        if ($row['resource_type'] === 'contract') {
            if (isset($seen[$row['provider_gid']])) {
                throw new SubscriptionProviderReadException('duplicate_staged_contract');
            }
            $seen[$row['provider_gid']] = true;
        }
        $existing = SubscriptionProviderResource::query()->forTenantId($i->tenant_id)->where('installation_id', $i->id)->where('resource_type', $row['resource_type'])->where('provider_gid', $row['provider_gid'])->lockForUpdate()->first();
        if ($existing && $existing->provider_revision !== null && $row['provider_revision'] !== null && SubscriptionUnsignedRevision::compare($row['provider_revision'], $existing->provider_revision) < 0) {
            throw new SubscriptionProviderReadException('stale_contract_revision');
        }
        $values = $row + ['tenant_id' => $i->tenant_id, 'installation_id' => $i->id, 'sync_operation_id' => $op->id, 'observed_at' => now(), 'completeness' => 'complete', 'stale_reason' => null];
        if ($existing) {
            $existing->forceFill($values)->save();
        } else {
            SubscriptionProviderResource::query()->create($values);
        }
    }

    /** Offline only. Safe for page rendering; never consults a provider or global store slots. */
    public function readiness(int $tenantId, int $installationId): array
    {
        $installation = $this->installation($tenantId, $installationId);
        $revision = $installation->active_credential_revision_id ? $this->revision($tenantId, $installationId, $installation->active_credential_revision_id) : null;
        $entitled = $this->access->entitled($tenantId);
        $ownedStore = ShopifyStore::query()->where('tenant_id', $tenantId)->whereKey($installation->shopify_store_id)->where('shop_domain', $installation->shop_domain)->exists();
        $identity = $installation->status === 'verified' && $installation->verified_at !== null && $ownedStore && $installation->api_version === SubscriptionShopifyReadTransport::API_VERSION;
        $credential = $revision && $revision->status === 'active' && ! $revision->revoked_at && ! $revision->expires_at?->isPast();
        $missing = $this->missingScopes($installation->scopes ?? []);
        $state = $installation->sync_state ?? [];
        $complete = $state['last_complete'] ?? null;
        $lastFailure = $state['last_failure']['code'] ?? null;
        $fresh = $complete && $installation->last_synced_at?->gt(now()->subMinutes((int) config('subscriptions.freshness_minutes', 60)))
            && \Illuminate\Support\Carbon::parse($complete['started_at'])->gt(now()->subMinutes((int) config('subscriptions.freshness_minutes', 60))) && ! $lastFailure
            && ($complete['credential_revision_id'] ?? null) === $installation->active_credential_revision_id;
        $readEnabled = $this->enabled($tenantId);

        return [
            'installation_id' => $installationId, 'entitled' => $entitled, 'identity_verified' => $identity,
            'credential_ready' => (bool) $credential, 'credential_status' => $revision?->revoked_at ? 'revoked' : ($revision?->expires_at?->isPast() ? 'expired' : ($revision?->status ?? 'missing')),
            'credential_expiry_known' => $revision?->expires_at !== null, 'scopes_ready' => $missing === [], 'missing_scopes' => $missing,
            'last_complete_sync_at' => $installation->last_synced_at?->toISOString(),
            'owned_contract_count' => $complete === null ? null : (int) $complete['owned_contract_count'],
            'freshness' => $complete === null ? 'never_synced' : ($fresh ? 'fresh' : 'stale'), 'stale' => ! $fresh,
            'read_queries_enabled' => $readEnabled && $entitled && $identity && (bool) $credential && $missing === [] && ! $installation->read_pause_reason && ! in_array($lastFailure, ['provider_access_denied', 'missing_required_scopes', 'installation_identity_mismatch'], true),
            'webhook_signing_secret_configured' => (bool) ($revision?->app_secret && ! $revision?->app_secret_expires_at?->isPast()),
            'webhook_setup_complete' => false,
            'sync_progress' => isset($state['in_progress']) ? ['phase' => $state['in_progress']['phase'], 'staged_contract_count' => $state['in_progress']['count'], 'started_at' => $state['in_progress']['started_at']] : null,
            'provider_execution_enabled' => false, 'purchases_enabled' => false, 'background_reads_enabled' => false,
            'current_app_selling_plan_count' => null, 'all_app_selling_plan_count' => null,
            'blockers' => array_values(array_filter([! $entitled ? 'subscription_addon_required' : null, ! $readEnabled ? 'provider_reads_paused' : null,
                ! $identity ? 'installation_verification_required' : null, ! $credential ? 'credential_unavailable' : null,
                $missing ? 'missing_required_scopes' : null, $installation->read_pause_reason, $lastFailure,
                $complete === null ? 'complete_sync_required' : (! $fresh ? 'observation_stale' : null)])),
            'servicing_blockers' => ['provider_mutations_disabled', 'expiring_offline_token_lifecycle_not_implemented', 'merchant_acceptance_required'],
        ];
    }

    private function runProbe(int $tenantId, User $actor, SubscriptionOperation $operation): array
    {
        $claimed = $this->operations->claim($tenantId, $operation->id, $actor, 60);
        if (! $claimed) {
            return $this->result($tenantId, $operation);
        }
        try {
            $installation = $this->installation($tenantId, $claimed->installation_id);
            $revision = $this->revision($tenantId, $installation->id, $claimed->credential_revision_id);
            $identity = $this->identity($installation, $revision);
            $this->assertReads($tenantId, $actor);
            DB::transaction(function () use ($tenantId, $claimed, $identity) {
                $installation = $this->installation($tenantId, $claimed->installation_id, true);
                $revision = $this->revision($tenantId, $installation->id, $claimed->credential_revision_id);
                $priorState = $installation->sync_state ?? [];
                if (max((int) ($installation->evidence['operation_id'] ?? 0), (int) ($priorState['last_complete']['operation_id'] ?? 0), (int) ($priorState['last_failure']['operation_id'] ?? 0)) > $claimed->id) {
                    throw new SubscriptionProviderReadException('identity_evidence_superseded');
                }
                if ($revision->status !== 'pending_verification' && $installation->active_credential_revision_id !== $revision->id) {
                    throw new SubscriptionProviderReadException('credential_revision_changed');
                }
                if ($revision->status === 'pending_verification' && SubscriptionCredentialRevision::query()->where('installation_id', $installation->id)->where('revision', '>', $revision->revision)->where('status', 'active')->exists()) {
                    throw new SubscriptionProviderReadException('credential_revision_changed');
                }
                $previous = $installation->active_credential_revision_id;
                $revision->forceFill(['status' => 'active', 'verified_at' => now()])->save();
                $state = $installation->sync_state ?? [];
                $state['last_failure'] = null;
                $installation->forceFill(['status' => 'verified', 'active_credential_revision_id' => $revision->id, 'scopes' => $identity['scopes'], 'sync_state' => $state,
                    'shop_timezone' => $identity['timezone'], 'verified_at' => now(), 'evidence' => ['identity' => $identity, 'operation_id' => $claimed->id]])->save();
                if (! $this->operations->complete($tenantId, $claimed->id, $claimed->lease_token, 'completed', ['identity_verified' => true, 'scopes_ready' => $this->missingScopes($identity['scopes']) === []])) {
                    throw new SubscriptionProviderReadException('read_lease_lost');
                }
                if ($previous && $previous !== $revision->id) {
                    SubscriptionCredentialRevision::query()->forTenantId($tenantId)->where('installation_id', $installation->id)->whereKey($previous)->update(['status' => 'retired', 'rotated_at' => now()]);
                }
            }, 3);
        } catch (SubscriptionProviderReadException $e) {
            $this->failed($tenantId, $claimed, $e->failureCode);
        } catch (\Throwable) {
            $this->failed($tenantId, $claimed, 'local_read_acceptance_failed');
        }

        return $this->result($tenantId, $claimed->fresh());
    }

    private function identity(SubscriptionInstallation $i, SubscriptionCredentialRevision $r): array
    {
        $data = $this->transport->query($i, $r, 'SubscriptionInstallationIdentity');
        $shop = $data['shop'] ?? [];
        $appInstallation = $data['currentAppInstallation'] ?? [];
        $app = $appInstallation['app'] ?? [];
        foreach (['shop_gid' => $shop['id'] ?? null, 'shop_domain' => $shop['myshopifyDomain'] ?? null, 'app_gid' => $app['id'] ?? null,
            'app_installation_gid' => $appInstallation['id'] ?? null, 'public_client_id' => $app['apiKey'] ?? null] as $field => $actual) {
            if (! is_string($actual) || ! hash_equals((string) $i->$field, $actual)) {
                throw new SubscriptionProviderReadException('installation_identity_mismatch');
            }
        }
        if (! is_array($appInstallation['accessScopes'] ?? null) || ! is_string($shop['ianaTimezone'] ?? null) || ! in_array($shop['ianaTimezone'], \DateTimeZone::listIdentifiers(), true) || ! is_string($app['webhookApiVersion'] ?? null) || strlen($app['webhookApiVersion']) > 32) {
            throw new SubscriptionProviderReadException('incomplete_installation_identity');
        }
        $scopes = [];
        foreach ($appInstallation['accessScopes'] as $scope) {
            if (! is_string($scope['handle'] ?? null) || ! preg_match('/^[a-z_]{1,100}$/D', $scope['handle'])) {
                throw new SubscriptionProviderReadException('invalid_scope_evidence');
            }
            $scopes[] = $scope['handle'];
        }
        $scopes = array_values(array_unique($scopes));
        sort($scopes, SORT_STRING);

        return ['shop_gid' => $i->shop_gid, 'shop_domain' => $i->shop_domain, 'app_gid' => $i->app_gid,
            'app_installation_gid' => $i->app_installation_gid, 'public_client_id' => $i->public_client_id, 'api_version' => $i->api_version,
            'timezone' => $shop['ianaTimezone'], 'scopes' => $scopes, 'app_webhook_api_version' => $app['webhookApiVersion']];
    }

    private function failed(int $tenantId, SubscriptionOperation $operation, string $code): void
    {
        DB::transaction(function () use ($tenantId, $operation, $code) {
            if (! $this->operations->complete($tenantId, $operation->id, $operation->lease_token, in_array($code, ['provider_access_denied', 'missing_required_scopes', 'installation_identity_mismatch'], true) ? 'blocked' : 'failed', [], $code)) {
                return;
            }
            $installation = $this->installation($tenantId, $operation->installation_id, true);
            $state = $installation->sync_state ?? [];
            // Failed candidate rotations and obsolete workers keep their own
            // operation evidence without invalidating a newer accepted state.
            if ($installation->active_credential_revision_id && $installation->active_credential_revision_id !== $operation->credential_revision_id) {
                return;
            }
            if ($operation->kind === 'installation_probe' && max((int) ($installation->evidence['operation_id'] ?? 0), (int) ($state['last_complete']['operation_id'] ?? 0)) > $operation->id) {
                return;
            }
            if ($operation->kind === 'owned_contract_sync') {
                $progress = $state['in_progress'] ?? [];
                if (($progress['generation'] ?? null) !== ($operation->request['generation'] ?? null) || ($progress['active_operation_id'] ?? null) !== $operation->id) {
                    return;
                }
                $progress['active_operation_id'] = null;
                if (in_array($code, ['changing_contract_index', 'mixed_contract_revision', 'changing_contract_observation', 'duplicate_contract', 'duplicate_staged_contract', 'staging_count_mismatch', 'stale_contract_revision'], true)) {
                    $progress['phase'] = 'restart_required';
                }
                $state['in_progress'] = $progress;
            }
            $state['last_failure'] = ['code' => $code, 'operation_id' => $operation->id, 'at' => now()->toISOString()];
            $installation->forceFill(['sync_state' => $state])->save();
        }, 3);
    }

    private function result(int $tenantId, SubscriptionOperation $operation): array
    {
        return ['operation_id' => (int) $operation->id, 'status' => $operation->status, 'failure_code' => $operation->failure_code,
            'sync_complete' => $operation->result['sync_complete'] ?? false,
            'readiness' => $this->readiness($tenantId, (int) $operation->installation_id)];
    }

    private function installation(int $tenantId, int $id, bool $lock = false): SubscriptionInstallation
    {
        $q = SubscriptionInstallation::query()->forTenantId($tenantId)->whereKey($id);

        return ($lock ? $q->lockForUpdate() : $q)->firstOrFail();
    }

    private function revision(int $tenantId, int $installationId, int $id): SubscriptionCredentialRevision
    {
        return SubscriptionCredentialRevision::query()->forTenantId($tenantId)->where('installation_id', $installationId)->whereKey($id)->firstOrFail();
    }

    private function assertReads(int $tenantId, User $actor): void
    {
        $this->access->assertManager($tenantId, $actor);
        abort_unless($this->enabled($tenantId), 409, 'Subscription provider reads are paused.');
    }

    private function enabled(int $tenantId): bool
    {
        return config('subscriptions.provider_reads_enabled', false) === true && in_array($tenantId, config('subscriptions.provider_read_tenant_ids', []), true);
    }

    private function expected(array $identity): array
    {
        $keys = ['shop_gid' => 'Shop', 'app_gid' => 'App', 'app_installation_gid' => 'AppInstallation', 'public_client_id' => null];
        abort_unless(count($identity) === count($keys) && ! array_diff(array_keys($identity), array_keys($keys)), 422, 'An explicit complete installation identity is required.');
        foreach ($keys as $key => $type) {
            $value = $identity[$key] ?? null;
            abort_unless(is_string($value) && strlen($value) <= ($type ? 190 : 128)
                && ($type ? preg_match('/^gid:\/\/shopify\/'.$type.'\/[1-9][0-9]*$/D', $value) : preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $value)), 422, 'Invalid expected installation identity.');
        }

        return $identity;
    }

    private function missingScopes(array $scopes): array
    {
        $groups = ['read_own_subscription_contracts' => ['read_own_subscription_contracts', 'write_own_subscription_contracts'],
            'read_customers' => ['read_customers', 'write_customers'], 'read_customer_payment_methods' => ['read_customer_payment_methods'],
            'read_products' => ['read_products', 'write_products']];

        return array_keys(array_filter($groups, fn ($alternatives) => ! array_intersect($alternatives, $scopes)));
    }

    private function bounds(array $bounds): array
    {
        $defaults = ['max_contracts' => 2, 'max_pages' => 100, 'max_rows' => 3000, 'max_seconds' => 75, 'cycle_start' => 1, 'cycle_end' => 12];
        $max = ['max_contracts' => 100, 'max_pages' => 250, 'max_rows' => 10000, 'max_seconds' => 90, 'cycle_start' => 1000000, 'cycle_end' => 1000000];
        abort_if(array_diff(array_keys($bounds), array_keys($defaults)), 422, 'Unknown subscription read bound.');
        $bounds += $defaults;
        foreach ($bounds as $key => $value) {
            abort_unless(is_int($value) && $value >= 1 && $value <= $max[$key], 422, 'Invalid subscription read bound.');
        }
        abort_unless($bounds['cycle_end'] >= $bounds['cycle_start'] && $bounds['cycle_end'] - $bounds['cycle_start'] <= 99, 422, 'Invalid cycle window.');

        return $bounds;
    }
}
