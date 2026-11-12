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
                if ($customer['action'] === 'records_skipped') {
                    return;
                }

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
        if (! empty($options['updated_since'])) {
            $options['request_context'] = ['date_field' => 'updated_at'];
        }

        return $this->runSync(
            type: 'square_orders_sync',
            sourceLabel: 'square_orders',
            options: $options,
            tenantId: $tenantId,
            fetchPage: fn (?string $cursor, int $pageLimit, ?CarbonImmutable $since): array => $fetchClient->searchOrders(
                cursor: $cursor,
                limit: $pageLimit,
                since: $since,
                locationIds: $locationIds,
                dateField: ! empty($options['updated_since']) ? 'updated_at' : 'closed_at'
            ),
            handleItem: function (array $payload, bool $dryRun, array &$summary) use ($tenantId, $config, $sourceOnly): void {
                $payload['_source_binding'] = app(SquareConnectionContext::class)->publicBinding($config);
                if ($sourceOnly) {
                    $payload['_everbranch_source_import'] = ['historical_only' => true, 'reward_earning_authority' => false, 'reward_redemption_authority' => false];
                }
                $orderResult = $this->upsertSquareOrder($payload, $dryRun, $tenantId);
                $summary[$orderResult['action']]++;
                if ($orderResult['action'] === 'records_skipped') {
                    return;
                }

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
        if (! empty($options['updated_since'])) {
            $options['request_context'] = ['date_field' => 'updated_at'];
        }

        return $this->runSync(
            type: 'square_payments_sync',
            sourceLabel: 'square_payments',
            options: $options,
            tenantId: $tenantId,
            fetchPage: fn (?string $cursor, int $pageLimit, ?CarbonImmutable $since): array => $fetchClient->fetchLocationPayments($cursor, $pageLimit, $since, $config['location_ids'] ?? [], ! empty($options['updated_since'])),
            handleItem: function (array $payload, bool $dryRun, array &$summary) use ($tenantId, $config): void {
                $payload['_source_binding'] = app(SquareConnectionContext::class)->publicBinding($config);
                $paymentResult = $this->upsertSquarePayment($payload, $dryRun, $tenantId);
                $summary[$paymentResult['action']]++;
                if ($paymentResult['action'] === 'records_skipped') {
                    return;
                }

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

    /** Targeted verified-provider source refresh. No rewards, campaign or inventory effects. */
    public function refreshSourceObject(string $kind, string $id, array $options): array
    {
        $tenant = $this->tenantIdFromOptions($options);
        abort_unless($tenant && in_array($kind, ['customer', 'order', 'payment'], true) && $id !== '' && strlen($id) <= 192 && ! preg_match('/[\x00-\x20\x7F]/', $id), 422);
        abort_unless($this->manualRefresh($tenant, $options), 403);
        $config = $this->requireTenantConfig($tenant, $options);
        abort_unless($config, 409, 'The original Square reader is unavailable.');
        $client = $this->clientForConfig($config);
        $options = [...$options, 'limit' => 5, 'historical_source_only' => true,
            'source_binding' => app(SquareConnectionContext::class)->publicBinding($config),
            'request_context' => ['kind' => $kind, 'source_id_hash' => hash('sha256', $id)]];
        $retrieve = function (string $type, string $source) use ($client, $config): array {
            $row = $client->retrieveSourceObject($type, $source);
            abort_unless(($row['id'] ?? null) === $source, 409, 'The original Square object did not match its requested source ID.');
            if ($type !== 'customer') {
                abort_unless(in_array($row['location_id'] ?? null, $config['location_ids'] ?? [], true), 409, 'The Square object belongs to an unreviewed location.');
            }

            return ['kind' => $type, 'payload' => $row];
        };

        return $this->runSync('square_source_object_sync', 'square_source_object:'.$kind, $options,
            function () use ($kind, $id, $retrieve): array {
                $target = $retrieve($kind, $id);
                $rows = [];
                $order = $kind === 'payment' && filled($target['payload']['order_id'] ?? null) ? $retrieve('order', $target['payload']['order_id']) : null;
                if ($order) {
                    abort_unless($order['payload']['location_id'] === $target['payload']['location_id'], 409, 'The original payment and order locations disagree.');
                }
                $customerIds = array_values(array_unique(array_filter([$target['payload']['customer_id'] ?? null, $order['payload']['customer_id'] ?? null], fn ($value) => is_string($value) && $value !== '')));
                if ($kind !== 'customer') {
                    foreach ($customerIds as $customer) {
                        try {
                            $rows[] = $retrieve('customer', $customer);
                        } catch (\Illuminate\Http\Client\RequestException $error) {
                            if ($error->response->status() !== 404) {
                                throw $error;
                            }
                        }
                    }
                }
                if ($order) {
                    $rows[] = $order;
                } $rows[] = $target;

                return ['items' => $rows, 'cursor' => null];
            }, function (array $record, bool $dryRun, array &$summary) use ($tenant, $config, $options): void {
                $payload = $record['payload'];
                $kind = $record['kind'];
                $payload['_source_binding'] = app(SquareConnectionContext::class)->publicBinding($config);
                $payload['_everbranch_source_import'] = ['historical_only' => true, 'reward_earning_authority' => false, 'reward_redemption_authority' => false];
                $stored = match ($kind) {
                    'customer' => $this->upsertSquareCustomer($payload, $dryRun, $tenant),
                    'order' => $this->upsertSquareOrder($payload, $dryRun, $tenant), 'payment' => $this->upsertSquarePayment($payload, $dryRun, $tenant)
                };
                $summary[$stored['action']]++;
                if ($stored['action'] === 'records_skipped') {
                    return;
                }
                $customer = filled($payload['customer_id'] ?? null) ? SquareCustomer::forTenantId($tenant)->where('square_customer_id', $payload['customer_id'])->first() : null;
                $identity = match ($kind) {
                    'customer' => $this->identityFromSquareCustomerPayload($payload),
                    'order' => $this->identityFromSquareOrderPayload($payload, $customer), 'payment' => $this->identityFromSquarePaymentPayload($payload, $customer)
                };
                $result = app(SquareCustomerIdentityService::class)->sync($identity, $tenant, $dryRun, [...app(SquareConnectionContext::class)->publicBinding($config),
                    'source_label' => 'square_'.$kind.'_sync', 'source_id' => $payload['id'], 'create_unmatched' => $kind === 'customer' && ! empty($options['create_unmatched']),
                    'actor_id' => $options['created_by'], 'request_id' => $options['request_id']]);
                $this->mergeProfileSummary($summary, $result);
                if ($kind === 'customer' && ! $dryRun && ($result['profile_id'] ?? null)) {
                    app(SquareCustomerSource::class)->applyConsent(\App\Models\MarketingProfile::forTenantId($tenant)->findOrFail($result['profile_id']), $payload,
                        ['source_type' => 'square_customer_sync', 'source_id' => $payload['id'], 'details' => ['basis' => 'explicit_provider_unsubscribe_only']]);
                }
            }, $tenant);
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
        // These Square cursors bind the provider page size, not our unit budget.
        // Cards use a different adapter which deliberately ignores this argument.
        $fixedPagination = in_array($type, ['square_customers_sync', 'square_orders_sync', 'square_payments_sync'], true);
        $providerPageLimit = $limit === null ? 100 : min(100, $limit);

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
            'source_refresh_policy_version' => $options['source_refresh_policy_version'] ?? null,
            'source_root_request_id' => $options['source_root_request_id'] ?? null,
            'generation_restart_from_run_id' => $options['generation_restart_from_run_id'] ?? null,
            'request_context' => $options['request_context'] ?? [],
        ];

        if (! empty($options['resume_run_id'])) {
            $prior = MarketingImportRun::tenantScopedRun((int) $options['resume_run_id'], $type, $tenantId);
            abort_unless($prior && $this->canonicalBinding($prior->summary['source_binding'] ?? []) === $this->canonicalBinding($summary['source_binding']), 409, 'Resume the original tenant, merchant and environment connection.');
            $cursor = data_get($prior->summary, 'checkpoint.cursor');
            $since = $this->asDate($prior->summary['since'] ?? null);
            abort_unless(($prior->summary['create_unmatched'] ?? false) === $summary['create_unmatched'] && (($prior->summary['mode'] ?? '') === 'dry-run') === $dryRun, 409, 'Resume the original preview/import and unmatched-customer choice.');
            abort_unless(($prior->summary['request_context'] ?? []) === $summary['request_context'], 409, 'Resume the original source query and date field.');
            abort_unless((is_string($cursor) && $cursor !== '') || ($prior->status === 'failed' && ($prior->summary['retry_page'] ?? false)), 409, 'No remaining provider page is recorded for this run.');
            $summary['resumed_from_run_id'] = $prior->id;
            if ($fixedPagination) {
                $providerPageLimit = $this->originalProviderPageLimit($prior, $tenantId, $type, $summary['source_binding'], $summary['request_context'], $since);
            }
        }
        if ($fixedPagination) {
            abort_if($limit !== null && $limit < $providerPageLimit, 409, 'The resume budget must allow at least one original Square provider page ('.$providerPageLimit.' records).');
            $summary['provider_page_limit'] = $providerPageLimit;
        }
        $intent = [$type, $summary['source_binding'], $limit, $since?->toIso8601String(), $cursor, $dryRun, $summary['create_unmatched'], $summary['historical_source_only']];
        if ($summary['request_context']) {
            $intent[] = $summary['request_context'];
        }
        $legacyIntentHash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
        $intentHash = $fixedPagination ? hash('sha256', json_encode([...$intent, ['provider_page_limit' => $providerPageLimit]], JSON_THROW_ON_ERROR)) : $legacyIntentHash;

        $run = app(CustomerAccountFence::class)->forTenant($tenantId, function () use ($options, $tenantId, $type, $sourceLabel, $dryRun, $limit, $cursor, $since, $checkpointEvery, $createdBy, $summary, $intentHash, $legacyIntentHash) {
            if (! empty($options['request_id'])) {
                $prior = MarketingImportRun::query()->where('tenant_id', $tenantId)->where('summary->request_id', $options['request_id'])->lockForUpdate()->first();
                if ($prior) {
                    $expectedHash = array_key_exists('provider_page_limit', $prior->summary ?? []) ? $intentHash : $legacyIntentHash;
                    abort_unless(hash_equals((string) ($prior->summary['intent_hash'] ?? ''), $expectedHash) && (int) $prior->created_by === (int) $createdBy, 409, 'This refresh request belongs to different original content.');

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
            $seenCursors = [];
            do {
                if ($fixedPagination && $remaining !== null && $remaining < $providerPageLimit) {
                    // A short nonterminal page can leave nine of a 500-record
                    // budget. Save its cursor; do not change the next request to nine.
                    $this->persistCheckpoint($run, $summary, $cursor, $limit, $since, $checkpointEvery, $dryRun);
                    break;
                }
                $cursorHash = hash('sha256', (string) $cursor);
                abort_if(isset($seenCursors[$cursorHash]), 409, 'Square returned a repeated source cursor. The original checkpoint is retained for review.');
                $seenCursors[$cursorHash] = true;
                if (! empty($options['source_refresh_policy_version'])) {
                    app(SquareSourceRefresh::class)->assertPolicyCurrent($tenantId, $options['source_refresh_policy_version']);
                }
                app(SquareConnectionContext::class)->assertCurrent($tenantId, $summary['source_binding']);
                $pageLimit = $fixedPagination ? $providerPageLimit : ($remaining === null ? 100 : min(100, $remaining));
                $page = $fetchPage($cursor, $pageLimit, $since);
                $items = is_array($page['items'] ?? null) ? $page['items'] : [];

                foreach ($items as $payload) {
                    if ($remaining !== null && $remaining <= 0 && empty($options['manual_refresh'])) {
                        break 2;
                    }

                    try {
                        $handle = function () use ($options, $tenantId, $handleItem, $payload, $dryRun, &$summary) {
                            if (! empty($options['source_refresh_policy_version'])) {
                                app(SquareSourceRefresh::class)->assertPolicyCurrent($tenantId, $options['source_refresh_policy_version']);
                            }
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

    /** Old resumed runs may have a different unit budget from the original cursor request. */
    private function originalProviderPageLimit(MarketingImportRun $run, int $tenantId, string $type, array $binding, array $context, ?CarbonImmutable $since): int
    {
        $seen = [];
        for ($depth = 0; $depth < 128; $depth++) {
            abort_if(isset($seen[$run->id]), 409, 'Review the original Square cursor page size: the resume lineage is cyclic.');
            $seen[$run->id] = true;
            $summary = $run->summary ?? [];
            abort_unless((int) $run->tenant_id === $tenantId && $run->type === $type
                && $this->canonicalBinding($summary['source_binding'] ?? []) === $this->canonicalBinding($binding)
                && ($summary['request_context'] ?? []) === $context
                && $this->asDate($summary['since'] ?? null)?->toIso8601String() === $since?->toIso8601String(),
                409, 'Review the original Square cursor page size: its source query lineage changed.');
            if (array_key_exists('provider_page_limit', $summary)) {
                $size = $summary['provider_page_limit'];
                abort_unless(is_int($size) && $size >= 1 && $size <= 100, 409, 'Review the original Square cursor page size before resuming.');

                return $size;
            }
            // The old adapter could enter a later location using only its
            // remaining budget, so the root limit cannot prove that page size.
            abort_if($type === 'square_payments_sync' && count($binding['location_ids'] ?? []) > 1, 409,
                'Review the original Square cursor page size: legacy multi-location payment page sizes were not recorded.');
            $parent = $summary['resumed_from_run_id'] ?? null;
            if ($parent === null) {
                // finalSummary advances summary.cursor, so only the retained
                // original intent can prove that this chain began without one.
                abort_unless($this->legacySourceIntentMatches($summary, $type, $binding, $context, null), 409, 'Review the original Square cursor page size: the initial request cannot be proved.');
                $originalLimit = $this->nullableInt($summary['limit'] ?? null);

                return $originalLimit === null ? 100 : min(100, $originalLimit);
            }
            abort_unless(is_int($parent) && $parent > 0, 409, 'Review the original Square cursor page size: the parent run is invalid.');
            $ancestor = MarketingImportRun::tenantScopedRun($parent, $type, $tenantId);
            abort_unless($ancestor, 409, 'Review the original Square cursor page size: the original run is unavailable.');
            $parentCursor = data_get($ancestor->summary, 'checkpoint.cursor');
            abort_unless($parentCursor === null || is_string($parentCursor), 409, 'Review the original Square cursor page size: the parent cursor is invalid.');
            abort_unless($this->legacySourceIntentMatches($summary, $type, $binding, $context, $parentCursor), 409, 'Review the original Square cursor page size: the parent request cannot be proved.');
            $run = $ancestor;
        }
        abort(409, 'Review the original Square cursor page size: the bounded resume lineage is too long.');
    }

    private function legacySourceIntentMatches(array $summary, string $type, array $binding, array $context, ?string $initialCursor): bool
    {
        // MySQL can reorder JSON object keys. Canonical equality is checked by
        // the lineage reader; the fresh trusted builder retains the original
        // request projection's order. Keep the stored projection as a fallback
        // for unchanged legacy builders, never relax the stored intent hash.
        foreach ([$binding, $summary['source_binding'] ?? []] as $projection) {
            $intent = [$type, $projection, $this->nullableInt($summary['limit'] ?? null), $this->asDate($summary['since'] ?? null)?->toIso8601String(),
                $initialCursor, ($summary['mode'] ?? '') === 'dry-run', (bool) ($summary['create_unmatched'] ?? false), (bool) ($summary['historical_source_only'] ?? false)];
            if ($context) {
                $intent[] = $context;
            }
            if (hash_equals((string) ($summary['intent_hash'] ?? ''), hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)))) {
                return true;
            }
        }

        return false;
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
            if ($existing && $this->olderSource($existing->raw_payload ?? [], $payload)) {
                return ['action' => 'records_skipped'];
            }
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
            if ($existing && $this->olderSource($existing->raw_payload ?? [], $payload)) {
                return ['action' => 'records_skipped', 'model' => $existing];
            }
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
            if ($existing && $this->olderSource($existing->raw_payload ?? [], $payload)) {
                return ['action' => 'records_skipped'];
            }
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

    private function olderSource(array $old, array $incoming): bool
    {
        if (isset($old['version']) && is_numeric($old['version'])) {
            if (! isset($incoming['version']) || ! is_numeric($incoming['version']) || (int) $incoming['version'] < (int) $old['version']) {
                return true;
            }
        }
        $previous = $this->asDate($old['updated_at'] ?? null);
        $next = $this->asDate($incoming['updated_at'] ?? null);

        return $previous && (! $next || $next->lessThan($previous));
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
