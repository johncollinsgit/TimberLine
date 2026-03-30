<?php

namespace App\Services\Marketing;

use App\Models\MarketingImportRun;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Services\Marketing\SquareClient;
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
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function syncCustomers(array $options = []): array
    {
        $tenantId = $this->tenantIdFromOptions($options);
        if ($tenantId === null) {
            return $this->blockedResult('tenant_context_required');
        }

        $config = $this->requireTenantConfig($tenantId);
        if ($config === null) {
            return $this->blockedResult('tenant_square_config_missing');
        }

        if (!config('marketing.square.enabled') || !config('marketing.square.sync_customers_enabled')) {
            return ['status' => 'skipped', 'reason' => 'square_customers_sync_disabled'];
        }

        $fetchClient = $this->clientForConfig($config);

        return $this->runSync(
            type: 'square_customers_sync',
            sourceLabel: 'square_customers',
            options: $options,
            tenantId: $tenantId,
            fetchPage: fn (?string $cursor, int $pageLimit, ?CarbonImmutable $since): array => $fetchClient->fetchCustomers($cursor, $pageLimit),
            handleItem: function (array $payload, bool $dryRun, array &$summary) use ($tenantId): void {
                $customer = $this->upsertSquareCustomer($payload, $dryRun, $tenantId);
                $summary[$customer['action']]++;

                $identity = $this->identityFromSquareCustomerPayload($payload);
                $result = $this->profileSyncService->syncExternalIdentity($identity, [
                    'dry_run' => $dryRun,
                    'review_context' => [
                        'source_label' => 'square_customer_sync',
                        'source_id' => (string) ($payload['id'] ?? ''),
                    ],
                ]);
                $this->mergeProfileSummary($summary, $result);

                if (! $dryRun && (int) ($result['profile_id'] ?? 0) > 0) {
                    $profile = \App\Models\MarketingProfile::query()->find((int) $result['profile_id']);
                    if ($profile) {
                        $this->consentService->applyToProfile(
                            $profile,
                            $this->consentFromSquareCustomerPayload($payload),
                            [
                                'source_type' => 'square_customer_sync',
                                'source_id' => (string) ($payload['id'] ?? ''),
                            ]
                        );
                    }
                }
            }
        );
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function syncOrders(array $options = []): array
    {
        $tenantId = $this->tenantIdFromOptions($options);
        if ($tenantId === null) {
            return $this->blockedResult('tenant_context_required');
        }

        $config = $this->requireTenantConfig($tenantId);
        if ($config === null) {
            return $this->blockedResult('tenant_square_config_missing');
        }

        if (!config('marketing.square.enabled') || !config('marketing.square.sync_orders_enabled')) {
            return ['status' => 'skipped', 'reason' => 'square_orders_sync_disabled'];
        }

        $locationIds = $config['location_ids'] ?? [];
        if ($locationIds === []) {
            $location = trim((string) config('marketing.square.location_id', ''));
            if ($location !== '') {
                $locationIds = [$location];
            }
        }

        $fetchClient = $this->clientForConfig($config);

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
            handleItem: function (array $payload, bool $dryRun, array &$summary) use ($tenantId): void {
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
                $result = $this->profileSyncService->syncExternalIdentity($identity, [
                    'dry_run' => $dryRun,
                    'review_context' => [
                        'source_label' => 'square_order_sync',
                        'source_id' => (string) ($payload['id'] ?? ''),
                        'square_customer_id' => $squareCustomerId !== '' ? $squareCustomerId : null,
                    ],
                ]);
                $this->mergeProfileSummary($summary, $result);

                if (! $dryRun && $orderResult['model']) {
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
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function syncPayments(array $options = []): array
    {
        $tenantId = $this->tenantIdFromOptions($options);
        if ($tenantId === null) {
            return $this->blockedResult('tenant_context_required');
        }

        $config = $this->requireTenantConfig($tenantId);
        if ($config === null) {
            return $this->blockedResult('tenant_square_config_missing');
        }

        if (!config('marketing.square.enabled') || !config('marketing.square.sync_payments_enabled')) {
            return ['status' => 'skipped', 'reason' => 'square_payments_sync_disabled'];
        }

        $fetchClient = $this->clientForConfig($config);

        return $this->runSync(
            type: 'square_payments_sync',
            sourceLabel: 'square_payments',
            options: $options,
            tenantId: $tenantId,
            fetchPage: fn (?string $cursor, int $pageLimit, ?CarbonImmutable $since): array => $fetchClient->fetchPayments($cursor, $pageLimit, $since),
            handleItem: function (array $payload, bool $dryRun, array &$summary) use ($tenantId): void {
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
                $result = $this->profileSyncService->syncExternalIdentity($identity, [
                    'dry_run' => $dryRun,
                    'review_context' => [
                        'source_label' => 'square_payment_sync',
                        'source_id' => (string) ($payload['id'] ?? ''),
                        'square_order_id' => (string) ($payload['order_id'] ?? ''),
                    ],
                ]);
                $this->mergeProfileSummary($summary, $result);
            }
        );
    }

    /**
     * @param callable(?string,int,?CarbonImmutable):array{items:array<int,array<string,mixed>>,cursor:?string} $fetchPage
     * @param callable(array<string,mixed>,bool,array<string,mixed>&):void $handleItem
     * @param array<string,mixed> $options
     * @param int $tenantId
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
        ];

        $run = MarketingImportRun::query()->create([
            'type' => $type,
            'status' => 'running',
            'source_label' => $sourceLabel,
            'started_at' => now(),
            'tenant_id' => $tenantId,
            'summary' => [
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

        try {
            $remaining = $limit;
            $nextCheckpoint = $checkpointEvery;
            do {
                $pageLimit = $remaining === null ? 100 : min(100, $remaining);
                $page = $fetchPage($cursor, $pageLimit, $since);
                $items = is_array($page['items'] ?? null) ? $page['items'] : [];

                foreach ($items as $payload) {
                    if ($remaining !== null && $remaining <= 0) {
                        break 2;
                    }

                    try {
                        $handleItem($payload, $dryRun, $summary);
                        $summary['processed']++;
                    } catch (\Throwable $e) {
                        $summary['errors']++;
                        Log::warning('marketing square sync item failed', [
                            'type' => $type,
                            'error' => $e->getMessage(),
                            'payload' => $payload,
                        ]);
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
                'status' => $summary['errors'] > 0 ? 'partial' : 'completed',
                'finished_at' => now(),
                'summary' => $this->finalSummary($summary, $cursor, $limit, $since, $checkpointEvery, $dryRun),
                'notes' => $dryRun ? 'Dry-run executed; source rows were not persisted.' : null,
            ])->save();
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'summary' => $this->finalSummary($summary, $cursor, $limit, $since, $checkpointEvery, $dryRun),
                'notes' => $e->getMessage(),
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

        $existing = SquareCustomer::query()
            ->where('square_customer_id', $squareCustomerId)
            ->where('tenant_id', $tenantId)
            ->first();
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
                'raw_payload' => $payload,
                'synced_at' => now(),
                'tenant_id' => $tenantId,
            ]
        );

        return ['action' => $existing ? 'updated' : 'created'];
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

        $existing = SquareOrder::query()
            ->where('square_order_id', $squareOrderId)
            ->where('tenant_id', $tenantId)
            ->first();
        $order = SquareOrder::query()->updateOrCreate(
            [
                'square_order_id' => $squareOrderId,
                'tenant_id' => $tenantId,
            ],
            [
                'square_customer_id' => $this->nullableString($payload['customer_id'] ?? null),
                'location_id' => $this->nullableString($payload['location_id'] ?? null),
                'state' => $this->nullableString($payload['state'] ?? null),
                'total_money_amount' => (int) ($payload['total_money']['amount'] ?? 0) ?: null,
                'total_money_currency' => $this->nullableString($payload['total_money']['currency'] ?? null),
                'closed_at' => $this->asDate($payload['closed_at'] ?? null),
                'source_name' => $this->nullableString($payload['source']['name'] ?? $payload['source_name'] ?? null),
                'raw_tax_names' => $this->extractTaxNames($payload),
                'raw_payload' => $payload,
                'synced_at' => now(),
                'tenant_id' => $tenantId,
            ]
        );

        return ['action' => $existing ? 'updated' : 'created', 'model' => $order];
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

        $existing = SquarePayment::query()
            ->where('square_payment_id', $squarePaymentId)
            ->where('tenant_id', $tenantId)
            ->first();
        SquarePayment::query()->updateOrCreate(
            [
                'square_payment_id' => $squarePaymentId,
                'tenant_id' => $tenantId,
            ],
            [
                'square_order_id' => $this->nullableString($payload['order_id'] ?? null),
                'square_customer_id' => $this->nullableString($payload['customer_id'] ?? null),
                'location_id' => $this->nullableString($payload['location_id'] ?? null),
                'amount_money' => (int) ($payload['amount_money']['amount'] ?? 0) ?: null,
                'currency' => $this->nullableString($payload['amount_money']['currency'] ?? null),
                'status' => $this->nullableString($payload['status'] ?? null),
                'source_type' => $this->nullableString($payload['source_type'] ?? null),
                'created_at_source' => $this->asDate($payload['created_at'] ?? null),
                'raw_payload' => $payload,
                'synced_at' => now(),
                'tenant_id' => $tenantId,
            ]
        );

        return ['action' => $existing ? 'updated' : 'created'];
    }

    /**
     * @param array<string,mixed> $payload
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
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function consentFromSquareCustomerPayload(array $payload): array
    {
        $preferences = is_array($payload['preferences'] ?? null) ? $payload['preferences'] : [];
        $phone = $this->nullableString($payload['phone_number'] ?? null);

        $emailUnsubscribed = $preferences['email_unsubscribed'] ?? null;
        $smsUnsubscribed = $preferences['sms_unsubscribed'] ?? null;
        $smsOptIn = is_bool($smsUnsubscribed)
            ? ! $smsUnsubscribed
            : ($phone !== null ? true : null);

        return [
            'accepts_email_marketing' => is_bool($emailUnsubscribed) ? !$emailUnsubscribed : null,
            'accepts_sms_marketing' => $smsOptIn,
            'email_opted_out_at' => $emailUnsubscribed === true ? now()->toIso8601String() : null,
            'sms_opted_out_at' => $smsUnsubscribed === true ? now()->toIso8601String() : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
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
     * @param array<string,mixed> $payload
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
     * @param array<string,mixed> $payload
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
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $result
     */
    protected function mergeProfileSummary(array &$summary, array $result): void
    {
        foreach (['profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped'] as $key) {
            $summary[$key] += (int) ($result[$key] ?? 0);
        }
    }

    /**
     * @param array<string,mixed> $summary
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
     * @param array<string,mixed> $summary
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

    protected function requireTenantConfig(int $tenantId): ?array
    {
        return $this->configResolver->resolveForTenant($tenantId);
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
