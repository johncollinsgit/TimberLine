<?php

namespace App\Services\Marketing;

use App\Models\MarketingImportRun;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SquareMarketingSyncService
{
    public function __construct(
        protected SquareClient $client,
        protected MarketingProfileSyncService $profileSyncService,
        protected MarketingEventAttributionService $attributionService,
        protected MarketingConsentService $consentService
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function syncCustomers(array $options = []): array
    {
        if (!config('marketing.square.enabled') || !config('marketing.square.sync_customers_enabled')) {
            return ['status' => 'skipped', 'reason' => 'square_customers_sync_disabled'];
        }

        return $this->runSync(
            type: 'square_customers_sync',
            sourceLabel: 'square_customers',
            options: $options,
            fetchPage: fn (?string $cursor, int $pageLimit, ?CarbonImmutable $since): array => $this->client->fetchCustomers($cursor, $pageLimit),
            handleItem: function (array $payload, bool $dryRun, array &$summary): void {
                $customer = $this->upsertSquareCustomer($payload, $dryRun);
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
                        $this->consentService->applyToProfile($profile, $this->consentFromSquareCustomerPayload($payload));
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
        if (!config('marketing.square.enabled') || !config('marketing.square.sync_orders_enabled')) {
            return ['status' => 'skipped', 'reason' => 'square_orders_sync_disabled'];
        }

        $locationIds = config('marketing.square.location_ids', []);
        if ($locationIds === []) {
            $location = trim((string) config('marketing.square.location_id', ''));
            if ($location !== '') {
                $locationIds = [$location];
            }
        }

        return $this->runSync(
            type: 'square_orders_sync',
            sourceLabel: 'square_orders',
            options: $options,
            fetchPage: fn (?string $cursor, int $pageLimit, ?CarbonImmutable $since): array => $this->client->searchOrders(
                cursor: $cursor,
                limit: $pageLimit,
                since: $since,
                locationIds: $locationIds
            ),
            handleItem: function (array $payload, bool $dryRun, array &$summary): void {
                $orderResult = $this->upsertSquareOrder($payload, $dryRun);
                $summary[$orderResult['action']]++;

                if (! $dryRun) {
                    $this->attributionService->refreshForSquareOrder($orderResult['model']);
                }

                $customer = null;
                $squareCustomerId = trim((string) ($payload['customer_id'] ?? ''));
                if ($squareCustomerId !== '') {
                    $customer = SquareCustomer::query()->where('square_customer_id', $squareCustomerId)->first();
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
            }
        );
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function syncPayments(array $options = []): array
    {
        if (!config('marketing.square.enabled') || !config('marketing.square.sync_payments_enabled')) {
            return ['status' => 'skipped', 'reason' => 'square_payments_sync_disabled'];
        }

        return $this->runSync(
            type: 'square_payments_sync',
            sourceLabel: 'square_payments',
            options: $options,
            fetchPage: fn (?string $cursor, int $pageLimit, ?CarbonImmutable $since): array => $this->client->fetchPayments($cursor, $pageLimit, $since),
            handleItem: function (array $payload, bool $dryRun, array &$summary): void {
                $paymentResult = $this->upsertSquarePayment($payload, $dryRun);
                $summary[$paymentResult['action']]++;

                $customer = null;
                $squareCustomerId = trim((string) ($payload['customer_id'] ?? ''));
                if ($squareCustomerId !== '') {
                    $customer = SquareCustomer::query()->where('square_customer_id', $squareCustomerId)->first();
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
     * @return array<string,mixed>
     */
    protected function runSync(
        string $type,
        string $sourceLabel,
        array $options,
        callable $fetchPage,
        callable $handleItem
    ): array {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = max(1, (int) ($options['limit'] ?? 200));
        $cursor = $this->nullableString($options['cursor'] ?? null);
        $since = $this->asDate($options['since'] ?? null);
        $createdBy = isset($options['created_by']) ? (int) $options['created_by'] : null;

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
            'summary' => [
                'mode' => $dryRun ? 'dry-run' : 'live-sync',
                'cursor' => $cursor,
                'since' => $since?->toIso8601String(),
            ],
            'created_by' => $createdBy,
        ]);

        try {
            $remaining = $limit;
            do {
                $pageLimit = min(100, $remaining);
                $page = $fetchPage($cursor, $pageLimit, $since);
                $items = is_array($page['items'] ?? null) ? $page['items'] : [];

                foreach ($items as $payload) {
                    if ($remaining <= 0) {
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
                        $remaining--;
                    }
                }

                $cursor = $this->nullableString($page['cursor'] ?? null);
            } while ($cursor && $remaining > 0);

            $run->forceFill([
                'status' => $summary['errors'] > 0 ? 'partial' : 'completed',
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $dryRun ? 'Dry-run executed; source rows were not persisted.' : null,
            ])->save();
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'summary' => $summary,
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
    protected function upsertSquareCustomer(array $payload, bool $dryRun): array
    {
        $squareCustomerId = trim((string) ($payload['id'] ?? ''));
        if ($squareCustomerId === '') {
            return ['action' => 'updated'];
        }

        if ($dryRun) {
            $exists = SquareCustomer::query()->where('square_customer_id', $squareCustomerId)->exists();
            return ['action' => $exists ? 'updated' : 'created'];
        }

        $existing = SquareCustomer::query()->where('square_customer_id', $squareCustomerId)->first();
        SquareCustomer::query()->updateOrCreate(
            ['square_customer_id' => $squareCustomerId],
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
            ]
        );

        return ['action' => $existing ? 'updated' : 'created'];
    }

    /**
     * @return array{action:string,model:?SquareOrder}
     */
    protected function upsertSquareOrder(array $payload, bool $dryRun): array
    {
        $squareOrderId = trim((string) ($payload['id'] ?? ''));
        if ($squareOrderId === '') {
            return ['action' => 'updated', 'model' => null];
        }

        if ($dryRun) {
            $exists = SquareOrder::query()->where('square_order_id', $squareOrderId)->exists();
            return ['action' => $exists ? 'updated' : 'created', 'model' => null];
        }

        $existing = SquareOrder::query()->where('square_order_id', $squareOrderId)->first();
        $order = SquareOrder::query()->updateOrCreate(
            ['square_order_id' => $squareOrderId],
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
            ]
        );

        return ['action' => $existing ? 'updated' : 'created', 'model' => $order];
    }

    /**
     * @return array{action:string}
     */
    protected function upsertSquarePayment(array $payload, bool $dryRun): array
    {
        $squarePaymentId = trim((string) ($payload['id'] ?? ''));
        if ($squarePaymentId === '') {
            return ['action' => 'updated'];
        }

        if ($dryRun) {
            $exists = SquarePayment::query()->where('square_payment_id', $squarePaymentId)->exists();
            return ['action' => $exists ? 'updated' : 'created'];
        }

        $existing = SquarePayment::query()->where('square_payment_id', $squarePaymentId)->first();
        SquarePayment::query()->updateOrCreate(
            ['square_payment_id' => $squarePaymentId],
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

        $emailUnsubscribed = $preferences['email_unsubscribed'] ?? null;
        $smsUnsubscribed = $preferences['sms_unsubscribed'] ?? null;

        return [
            'accepts_email_marketing' => is_bool($emailUnsubscribed) ? !$emailUnsubscribed : null,
            'accepts_sms_marketing' => is_bool($smsUnsubscribed) ? !$smsUnsubscribed : null,
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
                'location_id' => $payload['location_id'] ?? null,
                'state' => $payload['state'] ?? null,
                'source_name' => $payload['source']['name'] ?? $payload['source_name'] ?? null,
            ],
        ]];
        if ($squareCustomerId !== '') {
            $links[] = [
                'source_type' => 'square_customer',
                'source_id' => $squareCustomerId,
                'source_meta' => [],
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
                'amount_money' => $payload['amount_money'] ?? null,
                'status' => $payload['status'] ?? null,
            ],
        ]];
        if ($squareOrderId !== '') {
            $links[] = [
                'source_type' => 'square_order',
                'source_id' => $squareOrderId,
                'source_meta' => [],
            ];
        }
        if ($squareCustomerId !== '') {
            $links[] = [
                'source_type' => 'square_customer',
                'source_id' => $squareCustomerId,
                'source_meta' => [],
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

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);
        return $string !== '' ? $string : null;
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
