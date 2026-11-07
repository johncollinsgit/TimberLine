<?php

namespace App\Services\Marketing;

use App\Models\MarketingImportRun;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Services\Tenancy\TenantSquareConfigResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class SquareMarketingSyncService
{
    public function __construct(
        protected MarketingProfileSyncService $profileSyncService,
        protected MarketingEventAttributionService $attributionService,
        protected MarketingConsentService $consentService,
        protected MarketingConversionAttributionService $conversionAttributionService,
        protected CandleCashRedemptionReconciliationService $redemptionReconciliationService,
        protected TenantSquareConfigResolver $configResolver
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function syncCustomers(array $options = []): array
    {
        $tenantId = $this->tenantIdFromOptions($options);
        if ($tenantId === null) {
            return $this->blockedResult('tenant_context_required');
        }
        if (empty($options['manual_refresh']) && app(SquareConnectionContext::class)->requiresOwnerRefresh($tenantId, $options['connection_id'] ?? null)) {
            return $this->blockedResult('core_source_requires_explicit_owner_refresh');
        }

        if (! $this->manualRefresh($tenantId, $options) && (! config('marketing.square.enabled') || ! config('marketing.square.sync_customers_enabled'))) {
            return ['status' => 'skipped', 'reason' => 'square_customers_sync_disabled'];
        }
        $config = $this->requireTenantConfig($tenantId, $options);
        if ($config === null) {
            return $this->blockedResult('tenant_square_config_missing');
        }

        $fetchClient = $this->clientForConfig($config);
        $options['source_binding'] = app(SquareConnectionContext::class)->publicBinding($config);
        $createUnmatched = ! empty($options['manual_refresh']) && ! empty($options['create_unmatched']);

        return $this->runSync(
            type: 'square_customers_sync',
            sourceLabel: 'square_customers',
            options: $options,
            tenantId: $tenantId,
            fetchPage: fn (?string $cursor, int $pageLimit, ?CarbonImmutable $since): array => $fetchClient->fetchCustomers($cursor, $pageLimit, $since),
            handleItem: function (array $payload, bool $dryRun, array &$summary) use ($tenantId, $config, $createUnmatched, $options): void {
                $payload['_source_binding'] = app(SquareConnectionContext::class)->publicBinding($config);
                $customer = $this->upsertSquareCustomer($payload, $dryRun, $tenantId);
                $summary[$customer['action']]++;

                $identity = $this->identityFromSquareCustomerPayload($payload);
                $result = app(SquareCustomerIdentityService::class)->sync($identity, $tenantId, $dryRun, [
                    ...app(SquareConnectionContext::class)->publicBinding($config), 'source_label' => 'square_customer_sync',
                    'source_id' => (string) ($payload['id'] ?? ''),
                    'create_unmatched' => $createUnmatched, 'actor_id' => $options['created_by'] ?? null, 'request_id' => $options['request_id'] ?? null,
                ]);
                $this->mergeProfileSummary($summary, $result);

                if (! $dryRun && (int) ($result['profile_id'] ?? 0) > 0) {
                    $profile = \App\Models\MarketingProfile::query()->find((int) $result['profile_id']);
                    if ($profile) {
                        app(SquareCustomerSource::class)->applyConsent(
                            $profile,
                            $payload,
                            [
                                'source_type' => 'square_customer_sync',
                                'source_id' => (string) ($payload['id'] ?? ''),
                                'details' => ['basis' => 'explicit_provider_unsubscribe_only', 'provider_preferences' => app(SquareCustomerSource::class)->observedPreferences($payload)],
                            ]
                        );
                    }
                }
            }
        );
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function syncOrders(array $options = []): array
    {
        $tenantId = $this->tenantIdFromOptions($options);
        if ($tenantId === null) {
            return $this->blockedResult('tenant_context_required');
        }
        if (empty($options['manual_refresh']) && app(SquareConnectionContext::class)->requiresOwnerRefresh($tenantId, $options['connection_id'] ?? null)) {
            return $this->blockedResult('core_source_requires_explicit_owner_refresh');
        }

        if (! $this->manualRefresh($tenantId, $options) && (! config('marketing.square.enabled') || ! config('marketing.square.sync_orders_enabled'))) {
            return ['status' => 'skipped', 'reason' => 'square_orders_sync_disabled'];
        }
        $config = $this->requireTenantConfig($tenantId, $options);
        if ($config === null) {
            return $this->blockedResult('tenant_square_config_missing');
        }

        $locationIds = $config['location_ids'] ?? [];
        // Location filters belong to this original connection, never a global company default.

        $fetchClient = $this->clientForConfig($config);
        $options['source_binding'] = app(SquareConnectionContext::class)->publicBinding($config);
        $sourceOnly = ($config['source_purpose'] ?? null) === 'customer_history' || ! empty($options['manual_refresh']) || (bool) ($options['historical_source_only'] ?? false);
        $options['historical_source_only'] = $sourceOnly;

        return $this->runSync(
            type: 'square_orders_sync',
            sourceLabel: 'square_orders',
            options: $options,
            tenantId: $tenantId,
            fetchPage: fn (?string $cursor, int $pageLimit, ?CarbonImmutable $since): array => $fetchClient->searchOrders(
                cursor: $cursor,
                limit: $pageLimit,
                since: $since,
                locationIds: $locationIds
            ),
            handleItem: function (array $payload, bool $dryRun, array &$summary) use ($tenantId, $config, $sourceOnly): void {
                $payload['_source_binding'] = app(SquareConnectionContext::class)->publicBinding($config);
                if ($sourceOnly) {
                    $payload['_everbranch_source_import'] = ['historical_only' => true, 'reward_earning_authority' => false, 'reward_redemption_authority' => false];
                }
                $orderResult = $this->upsertSquareOrder($payload, $dryRun, $tenantId);
                $summary[$orderResult['action']]++;

                $customer = null;
                $squareCustomerId = trim((string) ($payload['customer_id'] ?? ''));
                if ($squareCustomerId !== '') {
                    $customer = SquareCustomer::query()
                        ->where('square_customer_id', $squareCustomerId)
                        ->where('tenant_id', $tenantId)
                        ->first();
                }

                $identity = $this->identityFromSquareOrderPayload($payload, $customer);
                $result = app(SquareCustomerIdentityService::class)->sync($identity, $tenantId, $dryRun, [
                    ...app(SquareConnectionContext::class)->publicBinding($config), 'source_label' => 'square_order_sync',
                    'source_id' => (string) ($payload['id'] ?? ''),
                    'square_customer_id' => $squareCustomerId !== '' ? $squareCustomerId : null,
                ]);
                $this->mergeProfileSummary($summary, $result);

                if (! $dryRun && ! $sourceOnly && $orderResult['model']) {
                    $this->attributionService->refreshForSquareOrder($orderResult['model']);
                    $rewardSummary = $this->redemptionReconciliationService->reconcileSquareOrder($orderResult['model']);
                    $this->conversionAttributionService->attributeForSquareOrder($orderResult['model'], [
                        'reward_reconcile_summary' => $rewardSummary,
                    ]);
                }
            }
        );
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function syncPayments(array $options = []): array
    {
        $tenantId = $this->tenantIdFromOptions($options);
        if ($tenantId === null) {
            return $this->blockedResult('tenant_context_required');
        }
        if (empty($options['manual_refresh']) && app(SquareConnectionContext::class)->requiresOwnerRefresh($tenantId, $options['connection_id'] ?? null)) {
            return $this->blockedResult('core_source_requires_explicit_owner_refresh');
        }

        if (! $this->manualRefresh($tenantId, $options) && (! config('marketing.square.enabled') || ! config('marketing.square.sync_payments_enabled'))) {
            return ['status' => 'skipped', 'reason' => 'square_payments_sync_disabled'];
        }
        $config = $this->requireTenantConfig($tenantId, $options);
        if ($config === null) {
            return $this->blockedResult('tenant_square_config_missing');
        }

        $fetchClient = $this->clientForConfig($config);
        $options['source_binding'] = app(SquareConnectionContext::class)->publicBinding($config);

        return $this->runSync(
            type: 'square_payments_sync',
            sourceLabel: 'square_payments',
            options: $options,
            tenantId: $tenantId,
            fetchPage: fn (?string $cursor, int $pageLimit, ?CarbonImmutable $since): array => $fetchClient->fetchPayments($cursor, $pageLimit, $since),
            handleItem: function (array $payload, bool $dryRun, array &$summary) use ($tenantId, $config): void {
                $payload['_source_binding'] = app(SquareConnectionContext::class)->publicBinding($config);
                $paymentResult = $this->upsertSquarePayment($payload, $dryRun, $tenantId);
                $summary[$paymentResult['action']]++;

                $customer = null;
                $squareCustomerId = trim((string) ($payload['customer_id'] ?? ''));
                if ($squareCustomerId !== '') {
                    $customer = SquareCustomer::query()
                        ->where('square_customer_id', $squareCustomerId)
                        ->where('tenant_id', $tenantId)
                        ->first();
                }

                $identity = $this->identityFromSquarePaymentPayload($payload, $customer);
                $result = app(SquareCustomerIdentityService::class)->sync($identity, $tenantId, $dryRun, [
                    ...app(SquareConnectionContext::class)->publicBinding($config), 'source_label' => 'square_payment_sync',
                    'source_id' => (string) ($payload['id'] ?? ''),
                    'square_order_id' => (string) ($payload['order_id'] ?? ''),
                ]);
                $this->mergeProfileSummary($summary, $result);
            }
        );
    }

    /** Read-only card references; no token creates a wallet/customer association or a charge. */
    public function syncCards(array $options = []): array
    {
        $tenantId = $this->tenantIdFromOptions($options);
        if (! $tenantId) {
            return $this->blockedResult('tenant_context_required');
        }
        if (! $this->manualRefresh($tenantId, $options)) {
            return $this->blockedResult('explicit_owner_refresh_required');
        }
        $config = $this->requireTenantConfig($tenantId, $options);
        if (! $config) {
            return $this->blockedResult('tenant_square_config_missing');
        }
        $client = $this->clientForConfig($config);
        $binding = app(SquareConnectionContext::class)->publicBinding($config);
        $options['source_binding'] = $binding;
        $options['historical_source_only'] = true;

        return $this->runSync('square_cards_sync', 'square_cards', $options,
            fn (?string $cursor, int $limit, ?CarbonImmutable $since) => $client->fetchCards($cursor),
            function (array $payload, bool $dryRun, array &$summary) use ($tenantId, $config, $binding) {
                $customerId = trim((string) ($payload['customer_id'] ?? ''));
                $safe = app(SquareCustomerSource::class)->cards([$payload], $customerId, $config['merchant_id']);
                if ($customerId === '' || ! $safe) {
                    $summary['records_skipped']++;

                    return;
                }
                $key = 'square:'.substr(hash('sha256', json_encode([$tenantId, $config['merchant_id'], $config['environment']])), 0, 40);
                $query = \App\Models\CustomerExternalProfile::query()->where('tenant_id', $tenantId)->where('provider', 'square')
                    ->where('integration', 'square_customer')->where('store_key', $key)->where('external_customer_id', $customerId);
                $existing = $query->first();
                $summary[$existing ? 'updated' : 'created']++;
                if ($dryRun) {
                    return;
                }
                app(CustomerAccountFence::class)->forTenant($tenantId, function () use ($query, $safe, $binding, $tenantId, $key, $customerId) {
                    $row = $query->lockForUpdate()->first() ?? new \App\Models\CustomerExternalProfile(['tenant_id' => $tenantId, 'provider' => 'square',
                        'integration' => 'square_customer', 'store_key' => $key, 'external_customer_id' => $customerId]);
                    $meta = $row->raw_metafields ?? [];
                    $cards = collect(data_get($meta, 'square.cards', []))->keyBy('id');
                    foreach ($safe as $card) {
                        $cards->put($card['id'], $card + ['observed_at' => now()->toIso8601String()]);
                    }
                    $meta['square'] = array_replace((array) ($meta['square'] ?? []), ['source_binding' => $binding, 'cards' => $cards->values()->all(), 'identity_authority' => false]);
                    $profiles = MarketingProfileLink::query()->where('tenant_id', $tenantId)->where('source_type', 'square_customer')->where('source_id', $customerId)->get()
                        ->map(fn ($link) => app(CanonicalMarketingProfileResolver::class)->byId($tenantId, $link->marketing_profile_id));
                    $canonical = ! $profiles->containsStrict(null) && $profiles->unique('id')->count() === 1 ? $profiles->first() : null;
                    if ($canonical && (! $row->marketing_profile_id || app(CanonicalMarketingProfileResolver::class)->byId($tenantId, $row->marketing_profile_id)?->id === $canonical->id)) {
                        $row->marketing_profile_id = $canonical->id;
                    }
                    $row->fill(['raw_metafields' => $meta, 'synced_at' => now()])->save();
                });
            }, $tenantId);
    }

    /**
     * @param  callable(?string,int,?CarbonImmutable):array{items:array<int,array<string,mixed>>,cursor:?string}  $fetchPage
     * @param  callable(array<string,mixed>,bool,array<string,mixed>&):void  $handleItem
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    protected function runSync(
        string $type,
        string $sourceLabel,
        array $options,
        callable $fetchPage,
        callable $handleItem,
        int $tenantId
    ): array {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = $this->nullableInt($options['limit'] ?? null);
        $cursor = $this->nullableString($options['cursor'] ?? null);
        $since = $this->asDate($options['since'] ?? null);
        $createdBy = isset($options['created_by']) ? (int) $options['created_by'] : null;
        $checkpointEvery = max(1, (int) ($options['checkpoint_every'] ?? 100));

        $summary = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'profiles_created' => 0,
            'profiles_updated' => 0,
            'links_created' => 0,
            'links_reused' => 0,
            'reviews_created' => 0,
            'records_skipped' => 0,
            'errors' => 0,
            'source_binding' => $options['source_binding'] ?? [],
            'request_id' => $options['request_id'] ?? null,
            'historical_source_only' => (bool) ($options['historical_source_only'] ?? false),
            'create_unmatched' => ! empty($options['manual_refresh']) && ! empty($options['create_unmatched']),
        ];

        if (! empty($options['resume_run_id'])) {
            $prior = MarketingImportRun::tenantScopedRun((int) $options['resume_run_id'], $type, $tenantId);
            abort_unless($prior && $this->canonicalBinding($prior->summary['source_binding'] ?? []) === $this->canonicalBinding($summary['source_binding']), 409, 'Resume the original tenant, merchant and environment connection.');
            $cursor = data_get($prior->summary, 'checkpoint.cursor');
            $since = $this->asDate($prior->summary['since'] ?? null);
            abort_unless(($prior->summary['create_unmatched'] ?? false) === $summary['create_unmatched'] && (($prior->summary['mode'] ?? '') === 'dry-run') === $dryRun, 409, 'Resume the original preview/import and unmatched-customer choice.');
            abort_unless((is_string($cursor) && $cursor !== '') || ($prior->status === 'failed' && ($prior->summary['retry_page'] ?? false)), 409, 'No remaining provider page is recorded for this run.');
            $summary['resumed_from_run_id'] = $prior->id;
        }
        $intentHash = hash('sha256', json_encode([$type, $summary['source_binding'], $limit, $since?->toIso8601String(), $cursor, $dryRun, $summary['create_unmatched'], $summary['historical_source_only']], JSON_THROW_ON_ERROR));

        $run = app(CustomerAccountFence::class)->forTenant($tenantId, function () use ($options, $tenantId, $type, $sourceLabel, $dryRun, $limit, $cursor, $since, $checkpointEvery, $createdBy, $summary, $intentHash) {
            if (! empty($options['request_id'])) {
                $prior = MarketingImportRun::query()->where('tenant_id', $tenantId)->where('summary->request_id', $options['request_id'])->lockForUpdate()->first();
                if ($prior) {
                    abort_unless(hash_equals((string) ($prior->summary['intent_hash'] ?? ''), $intentHash) && (int) $prior->created_by === (int) $createdBy, 409, 'This refresh request belongs to different original content.');

                    return $prior;
                }
            }

            return MarketingImportRun::query()->create([
                'type' => $type,
                'status' => 'running',
                'source_label' => $sourceLabel,
                'started_at' => now(),
                'tenant_id' => $tenantId,
                'summary' => $summary + ['intent_hash' => $intentHash,
                    'mode' => $dryRun ? 'dry-run' : 'live-sync',
                    'limit' => $limit,
                    'cursor' => $cursor,
                    'since' => $since?->toIso8601String(),
                    'checkpoint_every' => $checkpointEvery,
                    'checkpoint' => [
                        'cursor' => $cursor,
                        'processed' => 0,
                        'updated_at' => now()->toDateTimeString(),
                    ],
                ],
                'created_by' => $createdBy,
            ]);
        });
        $summary['intent_hash'] = $intentHash;
        if (! $run->wasRecentlyCreated) {
            return ['status' => $run->status, 'run_id' => $run->id, 'summary' => $run->summary, 'replayed' => true];
        }

        try {
            $remaining = $limit;
            $nextCheckpoint = $checkpointEvery;
            do {
                app(SquareConnectionContext::class)->assertCurrent($tenantId, $summary['source_binding']);
                $pageLimit = $remaining === null ? 100 : min(100, $remaining);
                $page = $fetchPage($cursor, $pageLimit, $since);
                $items = is_array($page['items'] ?? null) ? $page['items'] : [];

                foreach ($items as $payload) {
                    if ($remaining !== null && $remaining <= 0 && empty($options['manual_refresh'])) {
                        break 2;
                    }

                    try {
                        $handle = function () use ($options, $tenantId, $handleItem, $payload, $dryRun, &$summary) {
                            app(SquareConnectionContext::class)->assertCurrent($tenantId, $summary['source_binding']);
                            if (! empty($options['manual_refresh'])) {
                                $this->manualRefresh($tenantId, $options);
                            }
                            $handleItem($payload, $dryRun, $summary);
                        };
                        // Historical manual imports are atomic source-only writes. Existing
                        // scheduled attribution retains its provider-outside-transaction boundary.
                        if (! empty($options['manual_refresh'])) {
                            app(CustomerAccountFence::class)->forTenant($tenantId, $handle);
                        } else {
                            $handle();
                        }
                        $summary['processed']++;
                    } catch (\Throwable $e) {
                        $summary['errors']++;
                        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException && in_array($e->getStatusCode(), [403, 409], true)) {
                            throw $e;
                        }
                        Log::warning('marketing square sync item failed', [
                            'type' => $type,
                            'error_class' => get_class($e),
                            'source_reference' => hash('sha256', (string) ($payload['id'] ?? 'unknown')),
                        ]);
                        if (! empty($options['manual_refresh'])) {
                            throw $e;
                        }
                    } finally {
                        if ($remaining !== null) {
                            $remaining--;
                        }
                    }
                }

                $cursor = $this->nullableString($page['cursor'] ?? null);
                if ($summary['processed'] >= $nextCheckpoint || $cursor === null || ($remaining !== null && $remaining <= 0)) {
                    $this->persistCheckpoint($run, $summary, $cursor, $limit, $since, $checkpointEvery, $dryRun);
                    while ($summary['processed'] >= $nextCheckpoint) {
                        $nextCheckpoint += $checkpointEvery;
                    }
                }
            } while ($cursor && ($remaining === null || $remaining > 0));

            $run->forceFill([
                'status' => $summary['errors'] > 0 || $cursor ? 'partial' : 'completed',
                'finished_at' => now(),
                'summary' => $this->finalSummary($summary, $cursor, $limit, $since, $checkpointEvery, $dryRun),
                'notes' => $dryRun ? 'Dry-run executed; source rows were not persisted.' : null,
            ])->save();
        } catch (\Throwable $e) {
            $summary['retry_page'] = true;
            if ($e instanceof \Illuminate\Http\Client\RequestException) {
                $summary['provider_error'] = ['http_status' => $e->response->status(), 'codes' => array_values(array_unique(array_column((array) $e->response->json('errors'), 'code')))];
            }
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'summary' => $this->finalSummary($summary, $cursor, $limit, $since, $checkpointEvery, $dryRun),
                'notes' => 'Square retrieval failed ('.class_basename($e).'); original checkpoint retained.',
            ])->save();

            throw $e;
        }

        return [
            'status' => $run->status,
            'run_id' => $run->id,
            'summary' => $summary,
        ];
    }

    /**
     * @return array{action:string}
     */
    protected function upsertSquareCustomer(array $payload, bool $dryRun, int $tenantId): array
    {
        $squareCustomerId = trim((string) ($payload['id'] ?? ''));
        if ($squareCustomerId === '') {
            return ['action' => 'updated'];
        }

        if ($dryRun) {
            $exists = SquareCustomer::query()
                ->where('square_customer_id', $squareCustomerId)
                ->where('tenant_id', $tenantId)
                ->exists();

            return ['action' => $exists ? 'updated' : 'created'];
        }

        return app(CustomerAccountFence::class)->forTenant($tenantId, function () use ($payload, $tenantId, $squareCustomerId): array {
            app(SquareConnectionContext::class)->assertCurrent($tenantId, $payload['_source_binding'] ?? []);
            $existing = SquareCustomer::query()
                ->where('square_customer_id', $squareCustomerId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()->first();
            $oldBinding = $existing?->raw_payload['_source_binding'] ?? [];
            $newBinding = $payload['_source_binding'] ?? [];
            abort_if(! empty($oldBinding['merchant_id']) && ! empty($newBinding['merchant_id']) && ($oldBinding['merchant_id'] !== $newBinding['merchant_id'] || ($oldBinding['environment'] ?? null) !== ($newBinding['environment'] ?? null)), 409, 'This Square source belongs to its original merchant/environment.');
            SquareCustomer::query()->updateOrCreate(
                [
                    'square_customer_id' => $squareCustomerId,
                    'tenant_id' => $tenantId,
                ],
                [
                    'given_name' => $this->nullableString($payload['given_name'] ?? null),
                    'family_name' => $this->nullableString($payload['family_name'] ?? null),
                    'email' => $this->nullableString($payload['email_address'] ?? null),
                    'phone' => $this->nullableString($payload['phone_number'] ?? null),
                    'reference_id' => $this->nullableString($payload['reference_id'] ?? null),
                    'group_ids' => is_array($payload['group_ids'] ?? null) ? $payload['group_ids'] : null,
                    'segment_ids' => is_array($payload['segment_ids'] ?? null) ? $payload['segment_ids'] : null,
                    'preferences' => is_array($payload['preferences'] ?? null) ? $payload['preferences'] : null,
                    'raw_payload' => app(SquareCustomerSource::class)->customer($payload),
                    'synced_at' => now(),
                    'tenant_id' => $tenantId,
                ]
            );

            return ['action' => $existing ? 'updated' : 'created'];
        });
    }

    /**
     * @return array{action:string,model:?SquareOrder}
     */
    protected function upsertSquareOrder(array $payload, bool $dryRun, int $tenantId): array
    {
        $squareOrderId = trim((string) ($payload['id'] ?? ''));
        if ($squareOrderId === '') {
            return ['action' => 'updated', 'model' => null];
        }

        if ($dryRun) {
            $exists = SquareOrder::query()
                ->where('square_order_id', $squareOrderId)
                ->where('tenant_id', $tenantId)
                ->exists();

            return ['action' => $exists ? 'updated' : 'created', 'model' => null];
        }

        return app(CustomerAccountFence::class)->forTenant($tenantId, function () use ($payload, $tenantId, $squareOrderId): array {
            app(SquareConnectionContext::class)->assertCurrent($tenantId, $payload['_source_binding'] ?? []);
            $existing = SquareOrder::query()
                ->where('square_order_id', $squareOrderId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()->first();
            $this->assertOriginalSourceBinding($existing?->raw_payload ?? [], $payload);
            if (data_get($existing?->raw_payload, '_everbranch_source_import.historical_only') === true) {
                $payload['_everbranch_source_import'] = $existing->raw_payload['_everbranch_source_import'];
            }
            $order = SquareOrder::query()->updateOrCreate(
                [
                    'square_order_id' => $squareOrderId,
                    'tenant_id' => $tenantId,
                ],
                [
                    'square_customer_id' => $this->nullableString($payload['customer_id'] ?? null),
                    'location_id' => $this->nullableString($payload['location_id'] ?? null),
                    'state' => $this->nullableString($payload['state'] ?? null),
                    'total_money_amount' => is_numeric($payload['total_money']['amount'] ?? null) ? (int) $payload['total_money']['amount'] : null,
                    'total_money_currency' => $this->nullableString($payload['total_money']['currency'] ?? null),
                    'closed_at' => $this->asDate($payload['closed_at'] ?? null),
                    'source_name' => $this->nullableString($payload['source']['name'] ?? $payload['source_name'] ?? null),
                    'raw_tax_names' => $this->extractTaxNames($payload),
                    'raw_payload' => app(SquareCustomerSource::class)->purchase($payload),
                    'synced_at' => now(),
                    'tenant_id' => $tenantId,
                ]
            );

            return ['action' => $existing ? 'updated' : 'created', 'model' => $order];
        });
    }

    /**
     * @return array{action:string}
     */
    protected function upsertSquarePayment(array $payload, bool $dryRun, int $tenantId): array
    {
        $squarePaymentId = trim((string) ($payload['id'] ?? ''));
        if ($squarePaymentId === '') {
            return ['action' => 'updated'];
        }

        if ($dryRun) {
            $exists = SquarePayment::query()
                ->where('square_payment_id', $squarePaymentId)
                ->where('tenant_id', $tenantId)
                ->exists();

            return ['action' => $exists ? 'updated' : 'created'];
        }

        return app(CustomerAccountFence::class)->forTenant($tenantId, function () use ($payload, $tenantId, $squarePaymentId): array {
            app(SquareConnectionContext::class)->assertCurrent($tenantId, $payload['_source_binding'] ?? []);
            $existing = SquarePayment::query()
                ->where('square_payment_id', $squarePaymentId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()->first();
            $this->assertOriginalSourceBinding($existing?->raw_payload ?? [], $payload);
            SquarePayment::query()->updateOrCreate(
                [
                    'square_payment_id' => $squarePaymentId,
                    'tenant_id' => $tenantId,
                ],
                [
                    'square_order_id' => $this->nullableString($payload['order_id'] ?? null),
                    'square_customer_id' => $this->nullableString($payload['customer_id'] ?? null),
                    'location_id' => $this->nullableString($payload['location_id'] ?? null),
                    'amount_money' => is_numeric($payload['amount_money']['amount'] ?? null) ? (int) $payload['amount_money']['amount'] : null,
                    'currency' => $this->nullableString($payload['amount_money']['currency'] ?? null),
                    'status' => $this->nullableString($payload['status'] ?? null),
                    'source_type' => $this->nullableString($payload['source_type'] ?? null),
                    'created_at_source' => $this->asDate($payload['created_at'] ?? null),
                    'raw_payload' => app(SquareCustomerSource::class)->purchase($payload),
                    'synced_at' => now(),
                    'tenant_id' => $tenantId,
                ]
            );

            return ['action' => $existing ? 'updated' : 'created'];
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function identityFromSquareCustomerPayload(array $payload): array
    {
        $sourceId = trim((string) ($payload['id'] ?? ''));

        return [
            'first_name' => $this->nullableString($payload['given_name'] ?? null),
            'last_name' => $this->nullableString($payload['family_name'] ?? null),
            'raw_email' => $this->nullableString($payload['email_address'] ?? null),
            'raw_phone' => $this->nullableString($payload['phone_number'] ?? null),
            'source_channels' => ['square'],
            'source_links' => [[
                'source_type' => 'square_customer',
                'source_id' => $sourceId,
                'source_meta' => [
                    'source_system' => 'square',
                    'source_record_type' => 'customer',
                    'external_id' => $sourceId,
                    'reference_id' => $payload['reference_id'] ?? null,
                ],
            ]],
            'primary_source' => [
                'source_type' => 'square_customer',
                'source_id' => $sourceId,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function consentFromSquareCustomerPayload(array $payload): array
    {
        return app(SquareCustomerSource::class)->consent($payload);
    }

    private function assertOriginalSourceBinding(array $old, array $incoming): void
    {
        $old = $old['_source_binding'] ?? [];
        $new = $incoming['_source_binding'] ?? [];
        abort_if(! empty($old['merchant_id']) && (($old['merchant_id'] !== ($new['merchant_id'] ?? null)) || ($old['environment'] ?? null) !== ($new['environment'] ?? null)), 409, 'This Square source belongs to its original merchant/environment.');
    }

    private function canonicalBinding(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->canonicalBinding($item) : $item, $value);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function identityFromSquareOrderPayload(array $payload, ?SquareCustomer $customer): array
    {
        $sourceId = trim((string) ($payload['id'] ?? ''));
        $squareCustomerId = trim((string) ($payload['customer_id'] ?? ''));
        $channels = ['square'];

        $sourceName = strtolower((string) ($payload['source']['name'] ?? $payload['source_name'] ?? ''));
        if (str_contains($sourceName, 'online')) {
            $channels[] = 'online';
        }

        $links = [[
            'source_type' => 'square_order',
            'source_id' => $sourceId,
            'source_meta' => [
                'source_system' => 'square',
                'source_record_type' => 'order_contact',
                'external_id' => $sourceId,
                'square_customer_id' => $squareCustomerId !== '' ? $squareCustomerId : null,
                'location_id' => $payload['location_id'] ?? null,
                'state' => $payload['state'] ?? null,
                'source_name' => $payload['source']['name'] ?? $payload['source_name'] ?? null,
            ],
        ]];
        if ($squareCustomerId !== '') {
            $links[] = [
                'source_type' => 'square_customer',
                'source_id' => $squareCustomerId,
                'source_meta' => [
                    'source_system' => 'square',
                    'source_record_type' => 'customer',
                    'external_id' => $squareCustomerId,
                ],
            ];
        }

        return [
            'first_name' => $customer?->given_name,
            'last_name' => $customer?->family_name,
            'raw_email' => $customer?->email,
            'raw_phone' => $customer?->phone,
            'source_channels' => array_values(array_unique($channels)),
            'source_links' => $links,
            'primary_source' => [
                'source_type' => 'square_order',
                'source_id' => $sourceId,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function identityFromSquarePaymentPayload(array $payload, ?SquareCustomer $customer): array
    {
        $sourceId = trim((string) ($payload['id'] ?? ''));
        $squareCustomerId = trim((string) ($payload['customer_id'] ?? ''));
        $squareOrderId = trim((string) ($payload['order_id'] ?? ''));

        $links = [[
            'source_type' => 'square_payment',
            'source_id' => $sourceId,
            'source_meta' => [
                'source_system' => 'square',
                'source_record_type' => 'payment',
                'external_id' => $sourceId,
                'square_order_id' => $squareOrderId !== '' ? $squareOrderId : null,
                'square_customer_id' => $squareCustomerId !== '' ? $squareCustomerId : null,
                'amount_money' => $payload['amount_money'] ?? null,
                'status' => $payload['status'] ?? null,
            ],
        ]];
        if ($squareOrderId !== '') {
            $links[] = [
                'source_type' => 'square_order',
                'source_id' => $squareOrderId,
                'source_meta' => [
                    'source_system' => 'square',
                    'source_record_type' => 'order_contact',
                    'external_id' => $squareOrderId,
                ],
            ];
        }
        if ($squareCustomerId !== '') {
            $links[] = [
                'source_type' => 'square_customer',
                'source_id' => $squareCustomerId,
                'source_meta' => [
                    'source_system' => 'square',
                    'source_record_type' => 'customer',
                    'external_id' => $squareCustomerId,
                ],
            ];
        }

        return [
            'first_name' => $customer?->given_name,
            'last_name' => $customer?->family_name,
            'raw_email' => $customer?->email,
            'raw_phone' => $customer?->phone,
            'source_channels' => ['square'],
            'source_links' => $links,
            'primary_source' => [
                'source_type' => 'square_payment',
                'source_id' => $sourceId,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    protected function extractTaxNames(array $payload): array
    {
        $names = [];

        foreach ((array) ($payload['taxes'] ?? []) as $tax) {
            $name = $this->nullableString($tax['name'] ?? null);
            if ($name) {
                $names[] = $name;
            }
        }

        foreach ((array) ($payload['line_items'] ?? []) as $lineItem) {
            foreach ((array) ($lineItem['applied_taxes'] ?? []) as $tax) {
                $name = $this->nullableString($tax['name'] ?? null);
                if ($name) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $result
     */
    protected function mergeProfileSummary(array &$summary, array $result): void
    {
        foreach (['profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped'] as $key) {
            $summary[$key] += (int) ($result[$key] ?? 0);
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    protected function persistCheckpoint(
        MarketingImportRun $run,
        array $summary,
        ?string $cursor,
        ?int $limit,
        ?CarbonImmutable $since,
        int $checkpointEvery,
        bool $dryRun
    ): void {
        $run->forceFill([
            'summary' => $this->finalSummary($summary, $cursor, $limit, $since, $checkpointEvery, $dryRun),
        ])->save();
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    protected function finalSummary(
        array $summary,
        ?string $cursor,
        ?int $limit,
        ?CarbonImmutable $since,
        int $checkpointEvery,
        bool $dryRun
    ): array {
        return array_merge($summary, [
            'mode' => $dryRun ? 'dry-run' : 'live-sync',
            'limit' => $limit,
            'cursor' => $cursor,
            'since' => $since?->toIso8601String(),
            'checkpoint_every' => $checkpointEvery,
            'checkpoint' => [
                'cursor' => $cursor,
                'processed' => (int) ($summary['processed'] ?? 0),
                'errors' => (int) ($summary['errors'] ?? 0),
                'updated_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function tenantIdFromOptions(array $options): ?int
    {
        $tenantId = $options['tenant_id'] ?? null;
        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        if (! is_numeric($tenantId)) {
            return null;
        }

        $tenant = (int) $tenantId;

        return $tenant > 0 ? $tenant : null;
    }

    protected function requireTenantConfig(int $tenantId, array $options = []): ?array
    {
        $config = app(SquareConnectionContext::class)->resolve($tenantId, $options['connection_id'] ?? null);
        if ($config && isset($options['expected_connection_generation'])) {
            abort_unless((string) $config['connection_generation'] === (string) $options['expected_connection_generation'], 409, 'The Square connection changed. Refresh its current state first.');
        }

        return $config;
    }

    protected function manualRefresh(int $tenantId, array $options): bool
    {
        if (empty($options['manual_refresh'])) {
            return false;
        }
        abort_unless(\Illuminate\Support\Str::isUuid($options['request_id'] ?? ''), 422, 'A refresh request UUID is required.');
        $actor = \App\Models\User::findOrFail((int) ($options['created_by'] ?? 0));
        app(SquareCustomerIdentityService::class)->authorize($tenantId, $actor);

        return true;
    }

    protected function clientForConfig(array $config): SquareClient
    {
        return new SquareClient(
            $config['access_token'] ?? null,
            $config['base_url'] ?? config('marketing.square.base_url')
        );
    }

    protected function blockedResult(string $reason): array
    {
        return [
            'status' => 'blocked',
            'reason' => $reason,
            'run_id' => null,
            'summary' => [
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'profiles_created' => 0,
                'profiles_updated' => 0,
                'links_created' => 0,
                'links_reused' => 0,
                'reviews_created' => 0,
                'records_skipped' => 0,
                'errors' => 0,
            ],
        ];
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(1, (int) $value);
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
