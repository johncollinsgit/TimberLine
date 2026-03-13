<?php

namespace App\Console\Commands;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Models\Order;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Services\Marketing\MarketingProfileSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class MarketingSyncProfiles extends Command
{
    protected $signature = 'marketing:sync-profiles
        {--source=all : all|shopify|growave|square}
        {--limit= : Maximum number of candidate records to process}
        {--chunk=500 : Chunk size for candidate streaming}
        {--since= : Process records updated on/after this datetime}
        {--order-id= : Process a single Shopify order row by ID}
        {--dry-run : Preview changes without writing}';

    protected $description = 'Backfill/sync canonical marketing profiles from Shopify, Growave, and Square source layers.';

    public function handle(MarketingProfileSyncService $syncService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $verbose = $this->getOutput()->isVerbose();
        $source = strtolower(trim((string) $this->option('source')));
        $source = $source !== '' ? $source : 'all';

        if (! in_array($source, ['all', 'shopify', 'growave', 'square'], true)) {
            $this->error('Invalid --source value. Use all|shopify|growave|square.');

            return self::FAILURE;
        }

        $limit = $this->integerOption('limit');
        $chunk = max(1, (int) ($this->integerOption('chunk') ?? 500));
        $orderId = $this->integerOption('order-id');
        $since = $this->dateOption('since');

        $summary = [
            'candidates_scanned' => 0,
            'matched_existing' => 0,
            'profiles_created' => 0,
            'profiles_updated' => 0,
            'links_created' => 0,
            'links_reused' => 0,
            'reviews_created' => 0,
            'ambiguous_collisions' => 0,
            'skipped_no_identity' => 0,
            'records_skipped' => 0,
            'errors' => 0,
            'shopify_order_candidates' => 0,
            'shopify_customer_candidates' => 0,
            'growave_candidates' => 0,
            'square_customer_candidates' => 0,
            'square_order_candidates' => 0,
            'square_payment_candidates' => 0,
        ];

        $run = $this->beginRun($dryRun, $source, $limit, $chunk, $since);

        try {
            if ($orderId !== null) {
                $order = Order::query()->find($orderId);
                if (! $order) {
                    $this->error("Order {$orderId} not found.");

                    return self::FAILURE;
                }

                $summary['shopify_order_candidates']++;
                $this->syncOrderCandidate($syncService, $order, $summary, $dryRun, $verbose);
            } else {
                $remaining = $limit;

                if (in_array($source, ['all', 'shopify'], true)) {
                    $this->syncShopifyOrderCandidates($syncService, $summary, $since, $chunk, $remaining, $dryRun, $verbose);
                    $this->syncExternalCandidates(
                        $syncService,
                        $summary,
                        ['shopify_customer'],
                        'shopify_customer_candidates',
                        $since,
                        $chunk,
                        $remaining,
                        $dryRun,
                        $verbose
                    );
                }

                if (in_array($source, ['all', 'growave'], true)) {
                    $this->syncExternalCandidates(
                        $syncService,
                        $summary,
                        ['growave'],
                        'growave_candidates',
                        $since,
                        $chunk,
                        $remaining,
                        $dryRun,
                        $verbose
                    );
                }

                if (in_array($source, ['all', 'square'], true)) {
                    $this->syncSquareCustomerCandidates($syncService, $summary, $since, $chunk, $remaining, $dryRun, $verbose);
                    $this->syncSquareOrderCandidates($syncService, $summary, $since, $chunk, $remaining, $dryRun, $verbose);
                    $this->syncSquarePaymentCandidates($syncService, $summary, $since, $chunk, $remaining, $dryRun, $verbose);
                }
            }
        } catch (\Throwable $e) {
            $summary['errors']++;

            $this->finishRun($run, 'failed', $summary, $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $status = $summary['errors'] > 0 ? 'partial' : 'completed';
        $this->finishRun($run, $status, $summary, $dryRun ? 'Dry-run executed; no writes performed.' : null);

        $this->line($dryRun ? 'mode=dry-run' : 'mode=live-sync');
        $this->line("source={$source}");
        foreach ([
            'candidates_scanned',
            'matched_existing',
            'profiles_created',
            'profiles_updated',
            'links_created',
            'links_reused',
            'reviews_created',
            'ambiguous_collisions',
            'skipped_no_identity',
            'records_skipped',
            'errors',
            'shopify_order_candidates',
            'shopify_customer_candidates',
            'growave_candidates',
            'square_customer_candidates',
            'square_order_candidates',
            'square_payment_candidates',
        ] as $key) {
            $this->line($key . '=' . (int) ($summary[$key] ?? 0));
        }

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string,int> $summary
     */
    protected function syncShopifyOrderCandidates(
        MarketingProfileSyncService $syncService,
        array &$summary,
        ?CarbonImmutable $since,
        int $chunk,
        ?int &$remaining,
        bool $dryRun,
        bool $verbose
    ): void {
        $query = Order::query()
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNotNull('shopify_order_id')
                    ->orWhere('source', 'like', 'shopify%');
            })
            ->when($since, fn (Builder $builder) => $builder->where('updated_at', '>=', $since))
            ->orderBy('id');

        foreach ($query->lazyById($chunk) as $order) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $summary['shopify_order_candidates']++;
            $this->syncOrderCandidate($syncService, $order, $summary, $dryRun, $verbose);

            if ($remaining !== null) {
                $remaining--;
            }
        }
    }

    /**
     * @param array<string,int> $summary
     * @param array<int,string> $integrations
     */
    protected function syncExternalCandidates(
        MarketingProfileSyncService $syncService,
        array &$summary,
        array $integrations,
        string $counterKey,
        ?CarbonImmutable $since,
        int $chunk,
        ?int &$remaining,
        bool $dryRun,
        bool $verbose
    ): void {
        if (! Schema::hasTable('customer_external_profiles')) {
            return;
        }

        $query = CustomerExternalProfile::query()
            ->whereIn('integration', $integrations)
            ->when($since, fn (Builder $builder) => $builder->where('updated_at', '>=', $since))
            ->orderBy('id');

        foreach ($query->lazyById($chunk) as $external) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $summary[$counterKey]++;

            try {
                $result = $syncService->syncExternalIdentity(
                    $this->identityPayloadFromExternalProfile($external),
                    [
                        'dry_run' => $dryRun,
                        'review_context' => [
                            'source_label' => 'marketing_profiles_sync',
                            'source_id' => (string) $external->id,
                            'provider' => (string) $external->provider,
                            'integration' => (string) $external->integration,
                        ],
                    ]
                );
                $this->mergeSyncResult($summary, $result);

                if (! $dryRun && (int) ($result['profile_id'] ?? 0) > 0) {
                    $external->marketing_profile_id = (int) $result['profile_id'];
                    $external->save();
                }

                if ($verbose) {
                    $this->line(sprintf(
                        'external#%d status=%s reason=%s profile=%s',
                        (int) $external->id,
                        (string) ($result['status'] ?? 'unknown'),
                        (string) ($result['reason'] ?? 'n/a'),
                        $result['profile_id'] !== null ? (string) $result['profile_id'] : '-'
                    ));
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                if ($verbose) {
                    $this->warn('external#' . (int) $external->id . ' error=' . $e->getMessage());
                }
            }

            if ($remaining !== null) {
                $remaining--;
            }
        }
    }

    /**
     * @param array<string,int> $summary
     */
    protected function syncSquareCustomerCandidates(
        MarketingProfileSyncService $syncService,
        array &$summary,
        ?CarbonImmutable $since,
        int $chunk,
        ?int &$remaining,
        bool $dryRun,
        bool $verbose
    ): void {
        if (! Schema::hasTable('square_customers')) {
            return;
        }

        $query = SquareCustomer::query()
            ->when($since, fn (Builder $builder) => $builder->where('updated_at', '>=', $since))
            ->orderBy('id');

        foreach ($query->lazyById($chunk) as $customer) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $summary['square_customer_candidates']++;

            try {
                $result = $syncService->syncExternalIdentity(
                    $this->identityPayloadFromSquareCustomer($customer),
                    [
                        'dry_run' => $dryRun,
                        'review_context' => [
                            'source_label' => 'marketing_profiles_sync',
                            'source_id' => (string) $customer->square_customer_id,
                            'source_record_id' => (int) $customer->id,
                            'provider' => 'square',
                            'integration' => 'square_customer',
                        ],
                    ]
                );
                $this->mergeSyncResult($summary, $result);

                if ($verbose) {
                    $this->line(sprintf(
                        'square_customer#%s status=%s reason=%s profile=%s',
                        (string) $customer->square_customer_id,
                        (string) ($result['status'] ?? 'unknown'),
                        (string) ($result['reason'] ?? 'n/a'),
                        $result['profile_id'] !== null ? (string) $result['profile_id'] : '-'
                    ));
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                if ($verbose) {
                    $this->warn('square_customer#' . (string) $customer->square_customer_id . ' error=' . $e->getMessage());
                }
            }

            if ($remaining !== null) {
                $remaining--;
            }
        }
    }

    /**
     * @param array<string,int> $summary
     */
    protected function syncSquareOrderCandidates(
        MarketingProfileSyncService $syncService,
        array &$summary,
        ?CarbonImmutable $since,
        int $chunk,
        ?int &$remaining,
        bool $dryRun,
        bool $verbose
    ): void {
        if (! Schema::hasTable('square_orders')) {
            return;
        }

        $query = SquareOrder::query()
            ->with('customer')
            ->when($since, fn (Builder $builder) => $builder->where('updated_at', '>=', $since))
            ->orderBy('id');

        foreach ($query->lazyById($chunk) as $order) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $summary['square_order_candidates']++;

            try {
                $result = $syncService->syncExternalIdentity(
                    $this->identityPayloadFromSquareOrder($order),
                    [
                        'dry_run' => $dryRun,
                        'review_context' => [
                            'source_label' => 'marketing_profiles_sync',
                            'source_id' => (string) $order->square_order_id,
                            'source_record_id' => (int) $order->id,
                            'provider' => 'square',
                            'integration' => 'square_order',
                        ],
                    ]
                );
                $this->mergeSyncResult($summary, $result);

                if ($verbose) {
                    $this->line(sprintf(
                        'square_order#%s status=%s reason=%s profile=%s',
                        (string) $order->square_order_id,
                        (string) ($result['status'] ?? 'unknown'),
                        (string) ($result['reason'] ?? 'n/a'),
                        $result['profile_id'] !== null ? (string) $result['profile_id'] : '-'
                    ));
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                if ($verbose) {
                    $this->warn('square_order#' . (string) $order->square_order_id . ' error=' . $e->getMessage());
                }
            }

            if ($remaining !== null) {
                $remaining--;
            }
        }
    }

    /**
     * @param array<string,int> $summary
     */
    protected function syncSquarePaymentCandidates(
        MarketingProfileSyncService $syncService,
        array &$summary,
        ?CarbonImmutable $since,
        int $chunk,
        ?int &$remaining,
        bool $dryRun,
        bool $verbose
    ): void {
        if (! Schema::hasTable('square_payments')) {
            return;
        }

        $query = SquarePayment::query()
            ->with('customer')
            ->when($since, fn (Builder $builder) => $builder->where('updated_at', '>=', $since))
            ->orderBy('id');

        foreach ($query->lazyById($chunk) as $payment) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $summary['square_payment_candidates']++;

            try {
                $result = $syncService->syncExternalIdentity(
                    $this->identityPayloadFromSquarePayment($payment),
                    [
                        'dry_run' => $dryRun,
                        'review_context' => [
                            'source_label' => 'marketing_profiles_sync',
                            'source_id' => (string) $payment->square_payment_id,
                            'source_record_id' => (int) $payment->id,
                            'provider' => 'square',
                            'integration' => 'square_payment',
                        ],
                    ]
                );
                $this->mergeSyncResult($summary, $result);

                if ($verbose) {
                    $this->line(sprintf(
                        'square_payment#%s status=%s reason=%s profile=%s',
                        (string) $payment->square_payment_id,
                        (string) ($result['status'] ?? 'unknown'),
                        (string) ($result['reason'] ?? 'n/a'),
                        $result['profile_id'] !== null ? (string) $result['profile_id'] : '-'
                    ));
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                if ($verbose) {
                    $this->warn('square_payment#' . (string) $payment->square_payment_id . ' error=' . $e->getMessage());
                }
            }

            if ($remaining !== null) {
                $remaining--;
            }
        }
    }

    /**
     * @param array<string,int> $summary
     */
    protected function syncOrderCandidate(
        MarketingProfileSyncService $syncService,
        Order $order,
        array &$summary,
        bool $dryRun,
        bool $verbose
    ): void {
        try {
            $result = $syncService->syncOrder($order, [
                'dry_run' => $dryRun,
                'identity_context' => $this->identityContextFromOrder($order),
            ]);
            $this->mergeSyncResult($summary, $result);

            if ($verbose) {
                $this->line(sprintf(
                    'order#%d status=%s reason=%s profile=%s',
                    (int) $order->id,
                    (string) ($result['status'] ?? 'unknown'),
                    (string) ($result['reason'] ?? 'n/a'),
                    $result['profile_id'] !== null ? (string) $result['profile_id'] : '-'
                ));
            }
        } catch (\Throwable $e) {
            $summary['errors']++;
            if ($verbose) {
                $this->warn('order#' . (int) $order->id . ' error=' . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string,int> $summary
     * @param array<string,mixed> $result
     */
    protected function mergeSyncResult(array &$summary, array $result): void
    {
        $summary['candidates_scanned']++;

        foreach (['profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped'] as $key) {
            $summary[$key] += (int) ($result[$key] ?? 0);
        }

        if (
            (int) ($result['profile_id'] ?? 0) > 0
            && (int) ($result['profiles_created'] ?? 0) === 0
            && (int) ($result['reviews_created'] ?? 0) === 0
            && (int) ($result['records_skipped'] ?? 0) === 0
        ) {
            $summary['matched_existing']++;
        }

        if (in_array((string) ($result['reason'] ?? ''), [
            'missing_email_phone',
            'create_not_allowed',
            'no_action_taken',
        ], true)) {
            $summary['skipped_no_identity']++;
        }

        $summary['ambiguous_collisions'] += (int) ($result['reviews_created'] ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    protected function identityContextFromOrder(Order $order): array
    {
        $attributes = $order->getAttributes();

        $email = $this->firstNonEmpty([
            $attributes['email'] ?? null,
            $attributes['customer_email'] ?? null,
            $attributes['shipping_email'] ?? null,
            $attributes['billing_email'] ?? null,
        ]);
        $phone = $this->firstNonEmpty([
            $attributes['phone'] ?? null,
            $attributes['customer_phone'] ?? null,
            $attributes['shipping_phone'] ?? null,
            $attributes['billing_phone'] ?? null,
        ]);
        $firstName = $this->nullableString($attributes['first_name'] ?? null);
        $lastName = $this->nullableString($attributes['last_name'] ?? null);
        $shopifyCustomerId = $this->nullableString($attributes['shopify_customer_id'] ?? null);
        $storeKey = $this->nullableString($attributes['shopify_store_key'] ?? $attributes['shopify_store'] ?? null);

        $sourceChannels = ['shopify'];
        if ($storeKey === 'wholesale') {
            $sourceChannels[] = 'wholesale';
        } else {
            $sourceChannels[] = 'online';
        }

        return [
            'email' => $email,
            'phone' => $phone,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'shopify_customer_id' => $shopifyCustomerId,
            'store_key' => $storeKey,
            'source_channels' => array_values(array_unique($sourceChannels)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function identityPayloadFromSquareCustomer(SquareCustomer $customer): array
    {
        $sourceId = (string) $customer->square_customer_id;

        return [
            'first_name' => $this->nullableString($customer->given_name),
            'last_name' => $this->nullableString($customer->family_name),
            'raw_email' => $this->nullableString($customer->email),
            'raw_phone' => $this->nullableString($customer->phone),
            'source_channels' => ['square'],
            'source_links' => [[
                'source_type' => 'square_customer',
                'source_id' => $sourceId,
                'source_meta' => [
                    'source_system' => 'square',
                    'source_record_type' => 'customer',
                    'external_id' => $sourceId,
                    'reference_id' => $this->nullableString($customer->reference_id),
                ],
            ]],
            'primary_source' => [
                'source_type' => 'square_customer',
                'source_id' => $sourceId,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function identityPayloadFromSquareOrder(SquareOrder $order): array
    {
        $sourceId = (string) $order->square_order_id;
        $squareCustomerId = $this->nullableString($order->square_customer_id);
        $sourceName = strtolower((string) $order->source_name);

        $channels = ['square'];
        if (str_contains($sourceName, 'online')) {
            $channels[] = 'online';
        }

        $customer = $order->relationLoaded('customer')
            ? $order->customer
            : ($squareCustomerId !== null
                ? SquareCustomer::query()->where('square_customer_id', $squareCustomerId)->first()
                : null);

        $sourceLinks = [[
            'source_type' => 'square_order',
            'source_id' => $sourceId,
            'source_meta' => [
                'source_system' => 'square',
                'source_record_type' => 'order_contact',
                'external_id' => $sourceId,
                'square_customer_id' => $squareCustomerId,
                'location_id' => $this->nullableString($order->location_id),
                'state' => $this->nullableString($order->state),
                'source_name' => $this->nullableString($order->source_name),
            ],
        ]];

        if ($squareCustomerId !== null) {
            $sourceLinks[] = [
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
            'first_name' => $this->nullableString($customer?->given_name),
            'last_name' => $this->nullableString($customer?->family_name),
            'raw_email' => $this->nullableString($customer?->email),
            'raw_phone' => $this->nullableString($customer?->phone),
            'source_channels' => array_values(array_unique($channels)),
            'source_links' => $sourceLinks,
            'primary_source' => [
                'source_type' => 'square_order',
                'source_id' => $sourceId,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function identityPayloadFromSquarePayment(SquarePayment $payment): array
    {
        $sourceId = (string) $payment->square_payment_id;
        $squareOrderId = $this->nullableString($payment->square_order_id);
        $squareCustomerId = $this->nullableString($payment->square_customer_id);

        $customer = $payment->relationLoaded('customer')
            ? $payment->customer
            : ($squareCustomerId !== null
                ? SquareCustomer::query()->where('square_customer_id', $squareCustomerId)->first()
                : null);

        $sourceLinks = [[
            'source_type' => 'square_payment',
            'source_id' => $sourceId,
            'source_meta' => [
                'source_system' => 'square',
                'source_record_type' => 'payment',
                'external_id' => $sourceId,
                'square_order_id' => $squareOrderId,
                'square_customer_id' => $squareCustomerId,
                'status' => $this->nullableString($payment->status),
            ],
        ]];

        if ($squareOrderId !== null) {
            $sourceLinks[] = [
                'source_type' => 'square_order',
                'source_id' => $squareOrderId,
                'source_meta' => [
                    'source_system' => 'square',
                    'source_record_type' => 'order_contact',
                    'external_id' => $squareOrderId,
                ],
            ];
        }

        if ($squareCustomerId !== null) {
            $sourceLinks[] = [
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
            'first_name' => $this->nullableString($customer?->given_name),
            'last_name' => $this->nullableString($customer?->family_name),
            'raw_email' => $this->nullableString($customer?->email),
            'raw_phone' => $this->nullableString($customer?->phone),
            'source_channels' => ['square'],
            'source_links' => $sourceLinks,
            'primary_source' => [
                'source_type' => 'square_payment',
                'source_id' => $sourceId,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function identityPayloadFromExternalProfile(CustomerExternalProfile $external): array
    {
        $sourceId = $this->externalSourceId($external);

        $sourceLinks = [[
            'source_type' => (string) $external->integration === 'growave' ? 'growave_customer' : 'shopify_customer',
            'source_id' => $sourceId,
            'source_meta' => [
                'source_system' => (string) $external->integration === 'growave' ? 'growave' : 'shopify',
                'external_profile_id' => (int) $external->id,
                'provider' => (string) $external->provider,
                'integration' => (string) $external->integration,
                'store_key' => $external->store_key,
            ],
        ]];

        if ((string) $external->integration === 'growave') {
            $sourceLinks[] = [
                'source_type' => 'shopify_customer',
                'source_id' => $sourceId,
                'source_meta' => [
                    'source_system' => 'shopify',
                    'external_profile_id' => (int) $external->id,
                    'provider' => (string) $external->provider,
                    'integration' => (string) $external->integration,
                    'store_key' => $external->store_key,
                ],
            ];
        }

        $sourceChannels = is_array($external->source_channels) ? $external->source_channels : [];
        $sourceChannels[] = (string) $external->provider;
        if ((string) $external->integration === 'growave') {
            $sourceChannels[] = 'growave';
        }

        return [
            'first_name' => $this->nullableString($external->first_name),
            'last_name' => $this->nullableString($external->last_name),
            'full_name' => $this->nullableString($external->full_name),
            'raw_email' => $this->nullableString($external->email),
            'raw_phone' => $this->nullableString($external->phone),
            'source_channels' => array_values(array_unique(array_filter($sourceChannels))),
            'source_links' => $sourceLinks,
            'primary_source' => [
                'source_type' => $sourceLinks[0]['source_type'],
                'source_id' => $sourceLinks[0]['source_id'],
            ],
        ];
    }

    protected function externalSourceId(CustomerExternalProfile $external): string
    {
        $storeKey = $this->nullableString($external->store_key);
        $externalId = $this->nullableString($external->external_customer_id);

        if ($externalId === null) {
            return 'external-profile:' . $external->id;
        }

        return $storeKey !== null ? $storeKey . ':' . $externalId : $externalId;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function beginRun(
        bool $dryRun,
        string $source,
        ?int $limit,
        int $chunk,
        ?CarbonImmutable $since
    ): ?array {
        if (! Schema::hasTable('marketing_import_runs')) {
            return null;
        }

        $run = MarketingImportRun::query()->create([
            'type' => 'marketing_profiles_sync',
            'status' => 'running',
            'source_label' => 'marketing_profiles:' . $source,
            'started_at' => now(),
            'summary' => [
                'mode' => $dryRun ? 'dry-run' : 'live-sync',
                'source' => $source,
                'limit' => $limit,
                'chunk' => $chunk,
                'since' => $since?->toIso8601String(),
            ],
        ]);

        return [
            'id' => (int) $run->id,
        ];
    }

    /**
     * @param array<string,mixed>|null $run
     * @param array<string,int> $summary
     */
    protected function finishRun(?array $run, string $status, array $summary, ?string $notes = null): void
    {
        if ($run === null || ! isset($run['id'])) {
            return;
        }

        $model = MarketingImportRun::query()->find((int) $run['id']);
        if (! $model) {
            return;
        }

        $model->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'summary' => $summary,
            'notes' => $notes,
        ])->save();
    }

    protected function integerOption(string $key): ?int
    {
        $value = $this->option($key);
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(1, (int) $value) : null;
    }

    protected function dateOption(string $key): ?CarbonImmutable
    {
        $value = trim((string) $this->option($key));
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable $e) {
            $this->warn("Invalid --{$key} value '{$value}', ignoring.");

            return null;
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @param array<int,mixed> $values
     */
    protected function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
