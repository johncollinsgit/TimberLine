<?php

namespace App\Services\Subscriptions;

use App\Models\IntegrationConnection;
use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeCredentialRevision;
use App\Models\SubscriptionRechargeOperation;
use App\Models\SubscriptionRechargeResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Source-owned Recharge shadow evidence. It has no provider-write or customer-adoption method. */
class SubscriptionRechargeReadService
{
    private const STREAMS = ['customers' => 'customer', 'addresses' => 'address', 'subscriptions' => 'subscription', 'payment_methods' => 'payment_method'];

    private const SCOPES = ['read_store', 'read_customers', 'read_subscriptions', 'read_payment_methods'];

    public function __construct(private SubscriptionRechargeOperationService $operations, private SubscriptionRechargeTransport $transport,
        private SubscriptionRechargeSnapshot $projection, private SubscriptionAccessService $access) {}

    public function readsEnabled(int $tenantId): bool
    {
        return SubscriptionRechargeOperationService::enabled($tenantId);
    }

    public function verifyConnection(int $tenantId, User $actor, int $sourceIntegrationConnectionId, array $expectedIdentity, string $requestUuid): array
    {
        $this->operations->authorize($tenantId, $actor);
        abort_unless(Str::isUuid($requestUuid), 422, 'A request UUID is required.');
        $expected = $this->expected($expectedIdentity);
        $source = $this->source($tenantId, $sourceIntegrationConnectionId);
        $sourceBinding = SubscriptionRechargeConnection::query()->forTenantId($tenantId)->where('source_integration_connection_id', $sourceIntegrationConnectionId)->first();
        if ($sourceBinding) {
            $this->sameIdentity($sourceBinding, $expected);
        }
        $existing = SubscriptionRechargeConnection::query()->forTenantId($tenantId)->where('platform', $expected['platform'])->where('provider_store_id', $expected['provider_store_id'])->first();
        $verificationRequest = ['expected_identity' => $expected, 'source_integration_connection_id' => $sourceIntegrationConnectionId];
        if ($existing) {
            $this->sameIdentity($existing, $expected);
            abort_if($existing->read_pause_reason, 409, 'Recharge connection reads are paused.');
            $replay = SubscriptionRechargeOperation::query()->forTenantId($tenantId)->where('connection_id', $existing->id)->where('request_uuid', strtolower($requestUuid))->first();
            if ($replay) {
                $this->operations->record($tenantId, $existing->id, $actor, 'verify_connection', $requestUuid, $verificationRequest, $replay->credential_revision_id);

                return $this->result($tenantId, $replay);
            }
        }
        $expectedActiveRevision = $existing?->active_credential_revision_id;
        $verificationAttempt = (string) Str::uuid();
        if ($existing) {
            DB::transaction(function () use ($tenantId, $actor, $existing, $verificationAttempt, $sourceIntegrationConnectionId, $requestUuid, $expectedActiveRevision) {
                $current = $this->connection($tenantId, $existing->id, true);
                $this->operations->authorize($tenantId, $actor);
                abort_unless($current->active_credential_revision_id === $expectedActiveRevision, 409, 'Recharge credentials changed before verification.');
                $state = $current->sync_state ?? [];
                $state['verification_attempt'] = ['attempt_uuid' => $verificationAttempt, 'request_uuid' => $requestUuid, 'source_integration_connection_id' => $sourceIntegrationConnectionId, 'actor_user_id' => $actor->id, 'started_at' => now()->toISOString()];
                $current->forceFill(['sync_state' => $state])->save();
            }, 3);
        }
        $token = $source->access_token;
        abort_unless(is_string($token) && trim($token) !== '', 409, 'The selected Recharge connection has no credential.');
        // Query a transient candidate before reserving an immutable identity.
        // A wrong initial expected ID cannot poison the permanent binding.
        $candidate = new SubscriptionRechargeConnection($expected + ['id' => $existing?->id ?? 0, 'tenant_id' => $tenantId, 'api_version' => SubscriptionRechargeTransport::API_VERSION]);
        $credential = new SubscriptionRechargeCredentialRevision(['tenant_id' => $tenantId, 'connection_id' => $candidate->id, 'credentials' => ['access_token' => $token], 'expires_at' => $source->expires_at]);
        $identity = $this->identity($candidate, $credential);
        $this->operations->authorize($tenantId, $actor);
        $operation = DB::transaction(function () use ($tenantId, $actor, $sourceIntegrationConnectionId, $source, $token, $expected, $identity, $requestUuid, $verificationRequest, $existing, $expectedActiveRevision, $verificationAttempt) {
            $currentSource = $this->source($tenantId, $sourceIntegrationConnectionId, true);
            abort_unless(hash_equals((string) $currentSource->access_token, $token), 409, 'Source credentials changed during verification.');
            $connection = SubscriptionRechargeConnection::query()->forTenantId($tenantId)->where('platform', $expected['platform'])->where('provider_store_id', $expected['provider_store_id'])->lockForUpdate()->first();
            if ($connection) {
                $this->sameIdentity($connection, $expected);
                abort_unless($existing && $connection->id === $existing->id && $connection->active_credential_revision_id === $expectedActiveRevision
                    && ($connection->sync_state['verification_attempt']['attempt_uuid'] ?? null) === $verificationAttempt, 409, 'Recharge verification was superseded. Start a new explicit request.');
            } else {
                abort_if($existing !== null, 409, 'The original Recharge connection is unavailable.');
                abort_if(SubscriptionRechargeConnection::query()->where('platform', $expected['platform'])->where('provider_store_id', $expected['provider_store_id'])->exists(), 409, 'This Recharge store is already bound.');
                $connection = SubscriptionRechargeConnection::query()->create($expected + ['tenant_id' => $tenantId, 'source_integration_connection_id' => $sourceIntegrationConnectionId,
                    'api_version' => SubscriptionRechargeTransport::API_VERSION, 'status' => 'verified', 'verified_at' => now()]);
            }
            $active = $connection->active_credential_revision_id ? $this->credential($tenantId, $connection->id, $connection->active_credential_revision_id) : null;
            if ($active && hash_equals((string) ($active->credentials['access_token'] ?? ''), $token) && ! $active->revoked_at && ! $active->expires_at?->isPast()) {
                $revision = $active;
            } else {
                $revision = SubscriptionRechargeCredentialRevision::query()->create(['tenant_id' => $tenantId, 'connection_id' => $connection->id,
                    'revision' => 1 + (int) SubscriptionRechargeCredentialRevision::query()->where('connection_id', $connection->id)->max('revision'), 'status' => 'active',
                    'credentials' => ['access_token' => $token, 'source_integration_connection_id' => $sourceIntegrationConnectionId], 'credential_fingerprint' => SubscriptionEvidence::digest($token), 'digest_key_id' => SubscriptionEvidence::keyId(),
                    'scopes' => $identity['scopes'], 'verified_at' => now(), 'expires_at' => $source->expires_at]);
            }
            $revision->forceFill(['scopes' => $identity['scopes'], 'verified_at' => now()])->save();
            $connection->forceFill(['status' => 'verified', 'active_credential_revision_id' => $revision->id, 'verified_at' => now(), 'scopes' => $identity['scopes'],
                'evidence' => ['store' => $identity['store'], 'verified_at' => now()->toISOString(), 'verification_attempt_uuid' => $verificationAttempt, 'api_version' => SubscriptionRechargeTransport::API_VERSION]])->save();
            $operation = $this->operations->record($tenantId, $connection->id, $actor, 'verify_connection', $requestUuid, $verificationRequest, $revision->id);
            $claimed = $this->operations->claim($tenantId, $operation->id, $actor);
            if ($claimed && ! $this->operations->complete($tenantId, $operation->id, $claimed->lease_token, 'completed', ['identity_verified' => true, 'scopes_ready' => ! array_diff(self::SCOPES, $identity['scopes']), 'scopes' => $identity['scopes'], 'store' => $identity['store']])) {
                throw new SubscriptionProviderReadException('recharge_lease_lost');
            }
            if ($active && $active->id !== $revision->id) {
                $active->forceFill(['status' => 'retired', 'rotated_at' => now()])->save();
            }

            return $operation->fresh();
        }, 3);

        return $this->result($tenantId, $operation);
    }

    /** One bounded page/slice. Accepted generation stays visible until both full passes reconcile. */
    public function sync(int $tenantId, User $actor, int $connectionId, string $requestUuid, array $bounds = []): array
    {
        $this->operations->authorize($tenantId, $actor);
        $restart = $bounds['restart'] ?? false;
        unset($bounds['restart']);
        abort_unless(is_bool($restart) && ! array_diff(array_keys($bounds), ['limit']), 422, 'Invalid Recharge read bounds.');
        $limit = $bounds['limit'] ?? 50;
        abort_unless(is_int($limit) && $limit > 0 && $limit <= 250, 422, 'Invalid Recharge page size.');
        $ready = $this->readiness($tenantId, $connectionId);
        abort_unless($ready['identity_verified'] && $ready['credential_ready'] && $ready['scopes_ready'], 409, 'Verified Recharge credentials and read scopes are required.');
        $operation = DB::transaction(function () use ($tenantId, $actor, $connectionId, $requestUuid, $limit, $restart) {
            $connection = $this->connection($tenantId, $connectionId, true);
            $state = $connection->sync_state ?? [];
            $prior = SubscriptionRechargeOperation::query()->forTenantId($tenantId)->where('connection_id', $connectionId)->where('request_uuid', strtolower($requestUuid))->first();
            if ($prior) {
                abort_unless(($prior->request['limit'] ?? null) === $limit && ($prior->request['restart'] ?? false) === $restart, 409, 'Recharge request UUID conflicts with its original content.');
                if (in_array($prior->status, ['failed', 'reading', 'unknown'], true)) {
                    $progress = $state['in_progress'] ?? [];
                    abort_unless(($progress['generation_id'] ?? null) === ($prior->request['generation_id'] ?? null) && ($progress['phase'] ?? null) !== 'restart_required'
                        && $this->position($progress) === ($prior->request['position'] ?? null), 409, 'This read slice advanced or requires a new generation.');
                    $this->assertNoActiveSlice($progress, $prior->id);
                    $progress['active_operation_id'] = $prior->id;
                    $state['in_progress'] = $progress;
                    $connection->forceFill(['sync_state' => $state])->save();
                }

                return $this->operations->record($tenantId, $connectionId, $actor, 'sync_slice', $requestUuid, $prior->request, $prior->credential_revision_id);
            }
            $progress = $state['in_progress'] ?? null;
            $this->assertNoActiveSlice($progress ?? []);
            if ($restart || ! $progress) {
                $progress = ['generation_id' => (string) Str::uuid(), 'credential_revision_id' => $connection->active_credential_revision_id, 'phase' => 'collect', 'stream' => 0, 'cursor' => null,
                    'started_at' => now()->toISOString(), 'counts' => [], 'digests' => [], 'verify_counts' => [], 'verify_digests' => [], 'limit' => $limit, 'verification_epoch' => $state['verification_attempt']['attempt_uuid'] ?? null];
            }
            abort_unless($progress['credential_revision_id'] === $connection->active_credential_revision_id && $progress['limit'] === $limit && $progress['phase'] !== 'restart_required'
                && ($progress['verification_epoch'] ?? null) === ($state['verification_attempt']['attempt_uuid'] ?? null), 409, 'Restart after reviewing changed credentials, verification, bounds or source evidence.');
            $operation = $this->operations->record($tenantId, $connectionId, $actor, 'sync_slice', $requestUuid,
                ['generation_id' => $progress['generation_id'], 'limit' => $limit, 'restart' => $restart, 'position' => $this->position($progress)], $connection->active_credential_revision_id);
            $progress['active_operation_id'] = $operation->id;
            $state['in_progress'] = $progress;
            $connection->forceFill(['sync_state' => $state])->save();

            return $operation;
        }, 3);
        $claimed = $this->operations->claim($tenantId, $operation->id, $actor);
        if (! $claimed) {
            return $this->result($tenantId, $operation);
        }
        try {
            $connection = $this->connection($tenantId, $connectionId);
            $progress = $connection->sync_state['in_progress'] ?? [];
            $this->fence($connection, $claimed, $progress);
            $reconciling = $progress['phase'] === 'reconcile';
            $verificationComplete = false;
            $complete = false;
            $collected = false;
            $snapshots = [];
            $type = null;
            if ($reconciling) {
                $identity = ['store' => $progress['verified_settings'], 'scopes' => $connection->scopes ?? []];
            } else {
                $credential = $this->credential($tenantId, $connectionId, $claimed->credential_revision_id);
                $identity = $this->identity($connection, $credential);
                if (array_diff(self::SCOPES, $identity['scopes'])) {
                    throw new SubscriptionProviderReadException('recharge_missing_scopes');
                }
                $stream = array_keys(self::STREAMS)[$progress['stream']];
                $type = self::STREAMS[$stream];
                $page = $this->page($connection, $credential, $stream, $progress['cursor'], $limit);
                $snapshots = array_map(fn ($row) => $this->projection->sanitize($type, $row), $page['rows']);
                $collected = $progress['phase'] === 'collect';
                foreach ($snapshots as $snapshot) {
                    $countKey = $collected ? 'counts' : 'verify_counts';
                    $digestKey = $collected ? 'digests' : 'verify_digests';
                    $progress[$countKey][$type] = ($progress[$countKey][$type] ?? 0) + 1;
                    $progress[$digestKey][$type] = hash('sha256', ($progress[$digestKey][$type] ?? '').SubscriptionEvidence::canonical($snapshot));
                }
                $progress['cursor'] = $page['next'];
                if ($page['next'] === null) {
                    $progress[$collected ? 'counts' : 'verify_counts'][$type] ??= 0;
                    $progress[$collected ? 'digests' : 'verify_digests'][$type] ??= '';
                    $progress['stream']++;
                    if ($progress['stream'] === count(self::STREAMS) && $collected) {
                        $progress['phase'] = 'verify';
                        $progress['stream'] = 0;
                    }
                }
                $verificationComplete = $progress['phase'] === 'verify' && $progress['stream'] === count(self::STREAMS);
                if ($verificationComplete) {
                    if ($progress['counts'] !== $progress['verify_counts'] || $progress['digests'] !== $progress['verify_digests']) {
                        throw new SubscriptionProviderReadException('recharge_source_changed');
                    }
                    $progress['phase'] = 'reconcile';
                    $progress['reconcile_cursor'] = 0;
                    $progress['verified_settings'] = $identity['store'];
                }
            }
            $observedAt = now();
            DB::transaction(function () use ($tenantId, $actor, $connectionId, $claimed, $progress, $collected, $type, $snapshots, $identity, $complete, $observedAt, $reconciling, $verificationComplete) {
                $current = $this->connection($tenantId, $connectionId, true);
                $state = $current->sync_state ?? [];
                $this->fence($current, $claimed, $state['in_progress'] ?? []);
                $this->operations->authorize($tenantId, $actor);
                if ($collected) {
                    if (! SubscriptionRechargeResource::query()->forTenantId($tenantId)->where('connection_id', $connectionId)->where('generation_id', $progress['generation_id'])->where('resource_type', 'store')->exists()) {
                        $this->storeResource($current, $claimed, $progress['generation_id'], 'store', $identity['store'], $observedAt);
                    }
                    foreach ($snapshots as $snapshot) {
                        $this->storeResource($current, $claimed, $progress['generation_id'], $type, $snapshot, $observedAt);
                    }
                }
                if ($reconciling) {
                    $batch = $this->reconcileBatch($current, $progress['generation_id'], $progress['reconcile_cursor']);
                    $progress['reconcile_cursor'] = $batch['cursor'];
                    $complete = $batch['done'];
                }
                if ($verificationComplete || $complete) {
                    $settings = SubscriptionRechargeResource::query()->forTenantId($tenantId)->where('connection_id', $connectionId)->where('generation_id', $progress['generation_id'])->where('resource_type', 'store')->sole();
                    if (SubscriptionEvidence::canonical($settings->snapshot) !== SubscriptionEvidence::canonical($identity['store'])) {
                        throw new SubscriptionProviderReadException('recharge_settings_changed');
                    }
                }
                if ($complete) {
                    $actualCounts = SubscriptionRechargeResource::query()->forTenantId($tenantId)->where('connection_id', $connectionId)->where('generation_id', $progress['generation_id'])->selectRaw('resource_type, COUNT(*) as total')->groupBy('resource_type')->pluck('total', 'resource_type')->all();
                    foreach ($progress['counts'] as $resourceType => $expectedCount) {
                        if ((int) ($actualCounts[$resourceType] ?? 0) !== $expectedCount) {
                            throw new SubscriptionProviderReadException('recharge_staged_count_mismatch');
                        }
                    }
                    SubscriptionRechargeResource::query()->forTenantId($tenantId)->where('connection_id', $connectionId)->where('generation_id', $progress['generation_id'])->update(['completeness' => 'complete']);
                    $state['last_complete'] = ['generation_id' => $progress['generation_id'], 'credential_revision_id' => $claimed->credential_revision_id,
                        'counts' => $progress['counts'], 'started_at' => $progress['started_at'], 'observed_at' => now()->toISOString(), 'operation_id' => $claimed->id, 'settings' => $identity['store']];
                    $state['in_progress'] = null;
                    $state['last_failure'] = null;
                    $current->last_complete_sync_at = now();
                } else {
                    $progress['active_operation_id'] = null;
                    $state['in_progress'] = $progress;
                }
                $current->forceFill(['sync_state' => $state, 'scopes' => $identity['scopes']])->save();
                if (! $this->operations->complete($tenantId, $claimed->id, $claimed->lease_token, 'completed', ['sync_complete' => $complete, 'generation_id' => $progress['generation_id'], 'phase' => $complete ? 'complete' : $progress['phase']])) {
                    throw new SubscriptionProviderReadException('recharge_lease_lost');
                }
            }, 3);
        } catch (SubscriptionProviderReadException $e) {
            $this->failed($tenantId, $claimed, $e->failureCode);
        } catch (\Throwable) {
            $this->failed($tenantId, $claimed, 'recharge_local_acceptance_failed');
        }

        return $this->result($tenantId, $claimed->fresh());
    }

    public function refreshCustomer(int $tenantId, User $actor, int $connectionId, string $providerCustomerId, string $requestUuid): array
    {
        $this->operations->authorize($tenantId, $actor);
        $id = SubscriptionRechargeTransport::id($providerCustomerId);
        $connection = $this->connection($tenantId, $connectionId);
        $operation = $this->operations->record($tenantId, $connectionId, $actor, 'refresh_customer', $requestUuid, ['customer_id' => $id]);
        $claimed = $this->operations->claim($tenantId, $operation->id, $actor);
        if (! $claimed) {
            return $this->result($tenantId, $operation);
        }
        try {
            $credential = $this->credential($tenantId, $connectionId, $claimed->credential_revision_id);
            $this->identity($connection, $credential);
            $raw = $this->transport->read($connection, $credential, 'customer', ['id' => $id]);
            $snapshot = $this->projection->sanitize('customer', $this->object($raw, 'customer'));
            if ($snapshot['id'] !== $id) {
                throw new SubscriptionProviderReadException('recharge_customer_identity_mismatch');
            }
            DB::transaction(function () use ($tenantId, $connectionId, $claimed, $snapshot) {
                $current = $this->connection($tenantId, $connectionId, true);
                if ($current->active_credential_revision_id !== $claimed->credential_revision_id) {
                    throw new SubscriptionProviderReadException('recharge_credential_changed');
                }
                $generation = (string) Str::uuid();
                $this->storeResource($current, $claimed, $generation, 'customer', $snapshot, now(), 'observation_only');
                if (! $this->operations->complete($tenantId, $claimed->id, $claimed->lease_token, 'completed', ['customer_provider_id' => $snapshot['id'], 'generation_id' => $generation, 'full_inventory' => false])) {
                    throw new SubscriptionProviderReadException('recharge_lease_lost');
                }
            }, 3);
        } catch (SubscriptionProviderReadException $e) {
            $this->failed($tenantId, $claimed, $e->failureCode);
        } catch (\Throwable) {
            $this->failed($tenantId, $claimed, 'recharge_local_acceptance_failed');
        }

        return $this->result($tenantId, $claimed->fresh());
    }

    public function readiness(int $tenantId, int $connectionId): array
    {
        $connection = $this->connection($tenantId, $connectionId);
        $state = $connection->sync_state ?? [];
        $credential = $connection->active_credential_revision_id ? $this->credential($tenantId, $connectionId, $connection->active_credential_revision_id) : null;
        $complete = $state['last_complete'] ?? null;
        $identity = $connection->status === 'verified' && $connection->verified_at !== null;
        $usable = $credential && $credential->status === 'active' && ! $credential->revoked_at && ! $credential->expires_at?->isPast();
        $missing = array_values(array_diff(self::SCOPES, $connection->scopes ?? []));
        $fresh = $complete && ! ($state['last_failure'] ?? null) && $complete['credential_revision_id'] === $connection->active_credential_revision_id
            && \Illuminate\Support\Carbon::parse($complete['started_at'])->gt(now()->subMinutes((int) config('subscriptions.freshness_minutes', 60)));

        return ['connection_id' => $connectionId, 'identity_verified' => $identity, 'credential_ready' => (bool) $usable, 'credential_expiry_known' => $credential?->expires_at !== null,
            'scopes_ready' => $missing === [], 'missing_scopes' => $missing, 'reads_enabled' => SubscriptionRechargeOperationService::enabled($tenantId),
            'last_complete_sync_at' => $connection->last_complete_sync_at?->toISOString(), 'counts' => $complete['counts'] ?? null, 'last_complete_counts' => $complete['counts'] ?? null, 'accepted_generation_id' => $complete['generation_id'] ?? null, 'last_complete_generation_id' => $complete['generation_id'] ?? null,
            'stale' => ! $fresh, 'read_queries_enabled' => SubscriptionRechargeOperationService::enabled($tenantId) && $this->access->entitled($tenantId) && $identity && (bool) $usable && $missing === [] && ! $connection->read_pause_reason,
            'coverage_blockers' => ['manual_settings_review_required', 'plans_not_imported', 'discounts_not_imported', 'charges_not_imported', 'orders_not_imported', 'one_times_not_imported', 'bundles_not_imported', 'metafields_not_imported', 'credits_not_imported', 'webhook_deletions_not_reconciled', 'source_to_local_mapping_unreviewed'],
            'freshness' => ! $complete ? 'never_synced' : ($fresh ? 'fresh' : 'stale'), 'sync_progress' => isset($state['in_progress']) ? ['phase' => $state['in_progress']['phase'], 'stream_index' => $state['in_progress']['stream'], 'staged_counts' => $state['in_progress']['counts']] : null,
            'provider' => 'recharge', 'mode' => 'shadow_only', 'servicing_enabled' => false, 'cutover_enabled' => false, 'background_reads_enabled' => false, 'payment_vault_copy_enabled' => false,
            'settings_coverage' => ['api_snapshot_available' => isset($connection->evidence['store']), 'manual_review_required' => ['notification_templates', 'portal_settings', 'cancellation_retention_rules', 'dunning_retry_rules', 'tax_shipping_rules', 'workflows', 'product_selling_plan_configuration', 'payment_processor_configuration']],
            'blockers' => array_values(array_filter([! $this->access->entitled($tenantId) ? 'subscription_addon_required' : null, ! SubscriptionRechargeOperationService::enabled($tenantId) ? 'recharge_reads_paused' : null,
                ! $identity ? 'recharge_connection_unverified' : null, ! $usable ? 'recharge_credential_unavailable' : null, $missing ? 'recharge_missing_scopes' : null,
                $connection->read_pause_reason, $state['last_failure']['code'] ?? null, ! $complete ? 'complete_shadow_sync_required' : (! $fresh ? 'shadow_observation_stale' : null)]))];
    }

    private function identity(SubscriptionRechargeConnection $connection, SubscriptionRechargeCredentialRevision $credential): array
    {
        $store = $this->projection->sanitize('store', $this->object($this->transport->read($connection, $credential, 'store'), 'store'));
        foreach (['provider_store_id' => $store['id'], 'identifier' => $store['identifier'] ?? null, 'platform' => $store['external_platform'] ?? null] as $field => $value) {
            if (! is_string($value) || $value !== $connection->$field) {
                throw new SubscriptionProviderReadException('recharge_store_identity_mismatch');
            }
        }
        $information = $this->object($this->transport->read($connection, $credential, 'token_information'), 'token_information');
        if (! is_array($information['scopes'] ?? null) || ! array_is_list($information['scopes'])) {
            throw new SubscriptionProviderReadException('recharge_missing_scope_evidence');
        }
        $scopes = [];
        foreach ($information['scopes'] as $scope) {
            if (! is_string($scope) || ! preg_match('/^[a-z_]{1,100}$/D', $scope)) {
                throw new SubscriptionProviderReadException('recharge_invalid_scope_evidence');
            } $scopes[] = $scope;
        }
        $scopes = array_values(array_unique($scopes));
        sort($scopes, SORT_STRING);

        return ['store' => $store, 'scopes' => $scopes];
    }

    private function page(SubscriptionRechargeConnection $connection, SubscriptionRechargeCredentialRevision $credential, string $stream, ?string $cursor, int $limit): array
    {
        $response = $this->transport->read($connection, $credential, $stream, ['cursor' => $cursor, 'limit' => $limit]);
        if (! is_array($response[$stream] ?? null) || ! array_is_list($response[$stream]) || count($response[$stream]) > $limit || ! array_key_exists('next_cursor', $response) || ! array_key_exists('previous_cursor', $response)) {
            throw new SubscriptionProviderReadException('recharge_incomplete_pagination');
        }
        $next = $response['next_cursor'];
        if ($next !== null && (! is_string($next) || $next === '' || $next === $cursor || strlen($next) > 4096 || ! $response[$stream])) {
            throw new SubscriptionProviderReadException('recharge_invalid_cursor');
        }
        $seen = [];
        foreach ($response[$stream] as $row) {
            if (! is_array($row)) {
                throw new SubscriptionProviderReadException('recharge_incomplete_resource');
            } $id = SubscriptionRechargeTransport::id($row['id'] ?? null);
            if (isset($seen[$id])) {
                throw new SubscriptionProviderReadException('recharge_duplicate_resource');
            } $seen[$id] = true;
        }

        return ['rows' => $response[$stream], 'next' => $next];
    }

    private function storeResource(SubscriptionRechargeConnection $connection, SubscriptionRechargeOperation $operation, string $generation, string $type, array $snapshot, mixed $observedAt, string $completeness = 'staged'): void
    {
        if (SubscriptionRechargeResource::query()->forTenantId($connection->tenant_id)->where('connection_id', $connection->id)->where('generation_id', $generation)->where('resource_type', $type)->where('provider_id', $snapshot['id'])->exists()) {
            throw new SubscriptionProviderReadException('recharge_duplicate_resource');
        }
        SubscriptionRechargeResource::query()->create(['tenant_id' => $connection->tenant_id, 'connection_id' => $connection->id, 'sync_operation_id' => $operation->id, 'generation_id' => $generation,
            'resource_type' => $type, 'provider_id' => $snapshot['id'], 'parent_provider_id' => $snapshot['address_id'] ?? ($snapshot['customer_id'] ?? null), 'customer_provider_id' => $type === 'customer' ? $snapshot['id'] : ($snapshot['customer_id'] ?? null),
            'status' => $snapshot['status'] ?? 'observed', 'source_updated_at' => $snapshot['updated_at'] ?? null, 'observed_at' => $observedAt, 'completeness' => $completeness, 'mapping_state' => 'unreviewed',
            'snapshot' => $snapshot, 'canonical_digest' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($snapshot)), 'digest_key_id' => SubscriptionEvidence::keyId()]);
    }

    /** Local reconciliation is resumable and bounded independently of inventory size. */
    private function reconcileBatch(SubscriptionRechargeConnection $connection, string $generation, int $afterId): array
    {
        $resources = fn (string $type) => SubscriptionRechargeResource::query()->forTenantId($connection->tenant_id)->where('connection_id', $connection->id)->where('generation_id', $generation)->where('resource_type', $type);
        $batch = SubscriptionRechargeResource::query()->forTenantId($connection->tenant_id)->where('connection_id', $connection->id)->where('generation_id', $generation)->where('id', '>', $afterId)->orderBy('id')->limit(100)->get();
        foreach ($batch as $row) {
            if ($row->resource_type === 'address') {
                if (! $resources('customer')->where('provider_id', $row->customer_provider_id)->exists()) {
                    throw new SubscriptionProviderReadException('recharge_address_customer_mismatch');
                }
                $method = $row->snapshot['payment_method_id'] ?? null;
                if ($method !== null && ! $resources('payment_method')->where('provider_id', $method)->where('customer_provider_id', $row->customer_provider_id)->exists()) {
                    throw new SubscriptionProviderReadException('recharge_address_payment_mismatch');
                }
            } elseif ($row->resource_type === 'subscription') {
                if (! $resources('customer')->where('provider_id', $row->customer_provider_id)->exists() || ! $resources('address')->where('provider_id', $row->parent_provider_id)->where('customer_provider_id', $row->customer_provider_id)->exists()) {
                    throw new SubscriptionProviderReadException('recharge_subscription_relationship_mismatch');
                }
            } elseif ($row->resource_type === 'payment_method') {
                if (! $resources('customer')->where('provider_id', $row->customer_provider_id)->exists()) {
                    throw new SubscriptionProviderReadException('recharge_payment_customer_mismatch');
                }
            } elseif ($row->resource_type === 'customer') {
                foreach (['subscriptions_total_count' => null, 'subscriptions_active_count' => 'active'] as $field => $status) {
                    if (array_key_exists($field, $row->snapshot)) {
                        $query = $resources('subscription')->where('customer_provider_id', $row->provider_id);
                        if ($status) {
                            $query->where('status', $status);
                        }
                        if (! is_int($row->snapshot[$field]) || $row->snapshot[$field] !== $query->count()) {
                            throw new SubscriptionProviderReadException('recharge_customer_count_mismatch');
                        }
                    }
                }
            }
        }

        return ['cursor' => $batch->last()?->id ?? $afterId, 'done' => $batch->count() < 100];
    }

    private function failed(int $tenantId, SubscriptionRechargeOperation $operation, string $code): void
    {
        DB::transaction(function () use ($tenantId, $operation, $code) {
            if (! $this->operations->complete($tenantId, $operation->id, $operation->lease_token, 'failed', [], $code)) {
                return;
            }
            if ($operation->kind !== 'sync_slice') {
                return;
            }
            $connection = $this->connection($tenantId, $operation->connection_id, true);
            $state = $connection->sync_state ?? [];
            $progress = $state['in_progress'] ?? [];
            if (($progress['generation_id'] ?? null) !== ($operation->request['generation_id'] ?? null) || ($progress['active_operation_id'] ?? null) !== $operation->id || $connection->active_credential_revision_id !== $operation->credential_revision_id) {
                return;
            }
            $progress['active_operation_id'] = null;
            if (in_array($code, ['recharge_source_changed', 'recharge_settings_changed', 'recharge_duplicate_resource', 'recharge_customer_count_mismatch', 'recharge_address_customer_mismatch', 'recharge_address_payment_mismatch', 'recharge_subscription_relationship_mismatch', 'recharge_payment_customer_mismatch'], true)) {
                $progress['phase'] = 'restart_required';
            }
            $state['in_progress'] = $progress;
            $state['last_failure'] = ['code' => $code, 'operation_id' => $operation->id, 'at' => now()->toISOString()];
            $connection->forceFill(['sync_state' => $state])->save();
        }, 3);
    }

    private function assertNoActiveSlice(array $progress, ?int $same = null): void
    {
        $active = SubscriptionRechargeOperation::query()->find($progress['active_operation_id'] ?? null);
        abort_if($active && $active->id !== $same && (($active->status === 'reading' && $active->lease_expires_at?->isFuture()) || ($active->status === 'queued' && $active->created_at->gt(now()->subMinutes(5)))), 409, 'A Recharge read slice is already in progress.');
    }

    private function fence(SubscriptionRechargeConnection $connection, SubscriptionRechargeOperation $operation, array $progress): void
    {
        if ($connection->active_credential_revision_id !== $operation->credential_revision_id || ($progress['active_operation_id'] ?? null) !== $operation->id || ($progress['generation_id'] ?? null) !== ($operation->request['generation_id'] ?? null)) {
            throw new SubscriptionProviderReadException('recharge_generation_changed');
        }
        if (($progress['verification_epoch'] ?? null) !== ($connection->sync_state['verification_attempt']['attempt_uuid'] ?? null)) {
            throw new SubscriptionProviderReadException('recharge_verification_superseded');
        }
    }

    private function position(array $progress): string
    {
        return hash('sha256', SubscriptionEvidence::canonical(array_intersect_key($progress, array_flip(['phase', 'stream', 'cursor', 'counts', 'digests', 'verify_counts', 'verify_digests', 'reconcile_cursor']))));
    }

    private function object(array $response, string $key): array
    {
        if (! isset($response[$key]) || ! is_array($response[$key]) || array_is_list($response[$key])) {
            throw new SubscriptionProviderReadException('recharge_incomplete_response');
        }

        return $response[$key];
    }

    private function expected(array $identity): array
    {
        abort_unless(count($identity) === 3 && ! array_diff(array_keys($identity), ['provider_store_id', 'platform', 'identifier']), 422, 'An explicit complete Recharge identity is required.');
        $identity['provider_store_id'] = SubscriptionRechargeTransport::id($identity['provider_store_id'] ?? null);
        abort_unless(is_string($identity['platform'] ?? null) && strlen($identity['platform']) >= 1 && strlen($identity['platform']) <= 32 && is_string($identity['identifier'] ?? null) && strlen($identity['identifier']) >= 1 && strlen($identity['identifier']) <= 190, 422, 'Invalid Recharge expected identity.');

        return $identity;
    }

    private function sameIdentity(SubscriptionRechargeConnection $connection, array $expected): void
    {
        foreach ($expected as $field => $value) {
            abort_unless($value === $connection->$field, 409, 'A verified Recharge connection cannot be rebound.');
        }
    }

    private function source(int $tenantId, int $id, bool $lock = false): IntegrationConnection
    {
        $query = IntegrationConnection::query()->where('tenant_id', $tenantId)->where('provider', 'recharge')->whereKey($id);
        $row = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_unless(in_array($row->status, ['pending', 'connected'], true) && ! $row->isExpired(), 409, 'The selected Recharge credential source is unavailable.');

        return $row;
    }

    private function connection(int $tenantId, int $id, bool $lock = false): SubscriptionRechargeConnection
    {
        $query = SubscriptionRechargeConnection::query()->forTenantId($tenantId)->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function credential(int $tenantId, int $connectionId, int $id): SubscriptionRechargeCredentialRevision
    {
        return SubscriptionRechargeCredentialRevision::query()->forTenantId($tenantId)->where('connection_id', $connectionId)->whereKey($id)->firstOrFail();
    }

    private function result(int $tenantId, SubscriptionRechargeOperation $operation): array
    {
        return ['operation_id' => (int) $operation->id, 'status' => $operation->status, 'failure_code' => $operation->failure_code, 'sync_complete' => (bool) ($operation->result['sync_complete'] ?? false), 'readiness' => $this->readiness($tenantId, $operation->connection_id)];
    }
}
