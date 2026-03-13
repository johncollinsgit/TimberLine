<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Services\Shopify\ShopifyCustomerMetafieldFetcher;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShopifyCustomerMetafieldSyncService
{
    public function __construct(
        protected GrowaveCustomerMetafieldParser $parser,
        protected MarketingIdentityNormalizer $normalizer,
        protected MarketingProfileSyncService $profileSyncService
    ) {}

    /**
     * @param  array<string,mixed>  $store
     * @param  array<string,mixed>  $options
     * @return array{status:string,run_id:int,summary:array<string,int>}
     */
    public function syncStore(array $store, array $options = []): array
    {
        $storeKey = $this->requiredString($store['key'] ?? null, 'Shopify store key is missing.');
        $shopDomain = $this->requiredString($store['shop'] ?? null, "Shopify shop domain missing for store '{$storeKey}'.");
        $token = $this->requiredString($store['token'] ?? null, "Shopify token missing for store '{$storeKey}'.");
        $apiVersion = $this->nullableString($store['api_version'] ?? null) ?: '2026-01';

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = $this->nullableInt($options['limit'] ?? null);
        $pageSize = min(max(1, (int) ($options['page_size'] ?? 50)), 100);
        $cursor = $this->nullableString($options['cursor'] ?? null);

        $summary = [
            'processed' => 0,
            'records_with_growave_metafields' => 0,
            'created' => 0,
            'updated' => 0,
            'matched_existing' => 0,
            'profiles_created' => 0,
            'profiles_updated' => 0,
            'links_created' => 0,
            'links_reused' => 0,
            'reviews_created' => 0,
            'ambiguous_collisions' => 0,
            'skipped_no_identity' => 0,
            'records_skipped' => 0,
            'pages_processed' => 0,
            'errors' => 0,
        ];

        $run = MarketingImportRun::query()->create([
            'type' => 'shopify_customer_metafields_sync',
            'status' => 'running',
            'source_label' => 'shopify_growave_customers:' . $storeKey,
            'started_at' => now(),
            'summary' => [
                'mode' => $dryRun ? 'dry-run' : 'live-sync',
                'store_key' => $storeKey,
                'cursor' => $cursor,
                'limit' => $limit,
                'page_size' => $pageSize,
            ],
        ]);

        Log::info('shopify customer metafield sync started', [
            'store_key' => $storeKey,
            'dry_run' => $dryRun,
            'limit' => $limit,
            'page_size' => $pageSize,
            'run_id' => $run->id,
        ]);

        try {
            $fetcher = new ShopifyCustomerMetafieldFetcher(
                new ShopifyGraphqlClient($shopDomain, $token, $apiVersion)
            );

            $remaining = $limit;
            do {
                $pageLimit = $remaining === null ? $pageSize : min($pageSize, $remaining);
                if ($pageLimit <= 0) {
                    break;
                }

                $page = $fetcher->fetchPage($cursor, $pageLimit);
                $summary['pages_processed']++;

                $customers = is_array($page['customers'] ?? null) ? $page['customers'] : [];
                foreach ($customers as $customer) {
                    if ($remaining !== null && $remaining <= 0) {
                        break 2;
                    }

                    if ($remaining !== null) {
                        $remaining--;
                    }
                    $summary['processed']++;

                    try {
                        $this->syncCustomerRecord($storeKey, $customer, $summary, $dryRun);
                    } catch (\Throwable $e) {
                        $summary['errors']++;
                        Log::warning('shopify customer metafield sync customer failed', [
                            'store_key' => $storeKey,
                            'customer_id' => $customer['shopify_customer_id'] ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $cursor = $this->nullableString($page['cursor'] ?? null);
                $hasNext = (bool) ($page['has_next'] ?? false);
            } while ($hasNext && $cursor !== null && ($remaining === null || $remaining > 0));

            $run->forceFill([
                'status' => $summary['errors'] > 0 ? 'partial' : 'completed',
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $dryRun ? 'Dry-run executed; no source rows were written.' : null,
            ])->save();

            Log::info('shopify customer metafield sync completed', [
                'store_key' => $storeKey,
                'run_id' => $run->id,
                'summary' => $summary,
            ]);

            return [
                'status' => (string) $run->status,
                'run_id' => (int) $run->id,
                'summary' => $summary,
            ];
        } catch (\Throwable $e) {
            $summary['errors']++;

            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $e->getMessage(),
            ])->save();

            Log::error('shopify customer metafield sync failed', [
                'store_key' => $storeKey,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param array{
     *   gid:string,
     *   shopify_customer_id:string,
     *   email:?string,
     *   phone:?string,
     *   first_name:?string,
     *   last_name:?string,
     *   order_count:?int,
     *   last_order_at:?string,
     *   accepts_marketing:?bool,
     *   updated_at:?string,
     *   metafields:array<int,array{namespace:string,key:string,value:string,type:?string}>
     * } $customer
     * @param array<string,int> $summary
     */
    protected function syncCustomerRecord(string $storeKey, array $customer, array &$summary, bool $dryRun): void
    {
        $parsed = $this->parser->parse((array) ($customer['metafields'] ?? []));
        $hasGrowaveMetafields = $parsed['raw_metafields'] !== [];
        if ($hasGrowaveMetafields) {
            $summary['records_with_growave_metafields']++;
        }

        $identity = $this->identityPayloadFromCustomer($storeKey, $customer, $hasGrowaveMetafields);
        $reviewContext = [
            'source_label' => 'shopify_customer_metafield_sync',
            'store_key' => $storeKey,
            'source_id' => (string) ($identity['primary_source']['source_id'] ?? ''),
        ];

        if ($dryRun) {
            $syncResult = $this->profileSyncService->syncExternalIdentity($identity, [
                'dry_run' => true,
                'review_context' => $reviewContext,
            ]);
            $action = $this->upsertSnapshot($storeKey, $customer, $parsed, $syncResult['profile_id'] ?? null, $hasGrowaveMetafields, true);
        } else {
            [$syncResult, $action] = DB::transaction(function () use ($storeKey, $customer, $parsed, $hasGrowaveMetafields, $reviewContext): array {
                $syncResult = $this->profileSyncService->syncExternalIdentity(
                    $this->identityPayloadFromCustomer($storeKey, $customer, $hasGrowaveMetafields),
                    [
                        'dry_run' => false,
                        'review_context' => $reviewContext,
                    ]
                );

                $action = $this->upsertSnapshot(
                    $storeKey,
                    $customer,
                    $parsed,
                    $syncResult['profile_id'] ?? null,
                    $hasGrowaveMetafields,
                    false
                );

                return [$syncResult, $action];
            });
        }

        $summary[$action]++;
        $this->mergeProfileSummary($summary, $syncResult);
    }

    /**
     * @param array{
     *   gid:string,
     *   shopify_customer_id:string,
     *   email:?string,
     *   phone:?string,
     *   first_name:?string,
     *   last_name:?string,
     *   order_count:?int,
     *   last_order_at:?string,
     *   accepts_marketing:?bool,
     *   updated_at:?string,
     *   metafields:array<int,array{namespace:string,key:string,value:string,type:?string}>
     * } $customer
     * @return array<string,mixed>
     */
    protected function identityPayloadFromCustomer(string $storeKey, array $customer, bool $hasGrowaveMetafields): array
    {
        $shopifyCustomerId = $this->requiredString(
            $customer['shopify_customer_id'] ?? null,
            'Shopify customer ID is missing from payload.'
        );
        $customerGid = $this->requiredString(
            $customer['gid'] ?? null,
            "Shopify customer gid missing for customer '{$shopifyCustomerId}'."
        );
        $canonicalSourceId = $storeKey . ':' . $shopifyCustomerId;

        $sourceChannels = ['shopify'];
        if ($storeKey === 'wholesale') {
            $sourceChannels[] = 'wholesale';
        } else {
            $sourceChannels[] = 'online';
        }
        if ($hasGrowaveMetafields) {
            $sourceChannels[] = 'growave';
        }

        $sourceLinks = [[
            'source_type' => 'shopify_customer',
            'source_id' => $canonicalSourceId,
            'source_meta' => [
                'source_system' => 'shopify',
                'store_key' => $storeKey,
                'shopify_customer_id' => $shopifyCustomerId,
                'shopify_customer_gid' => $customerGid,
            ],
        ]];

        if ($hasGrowaveMetafields) {
            $sourceLinks[] = [
                'source_type' => 'growave_customer',
                'source_id' => $canonicalSourceId,
                'source_meta' => [
                    'source_system' => 'growave',
                    'store_key' => $storeKey,
                    'shopify_customer_id' => $shopifyCustomerId,
                    'shopify_customer_gid' => $customerGid,
                ],
            ];
        }

        return [
            'first_name' => $this->nullableString($customer['first_name'] ?? null),
            'last_name' => $this->nullableString($customer['last_name'] ?? null),
            'raw_email' => $this->nullableString($customer['email'] ?? null),
            'raw_phone' => $this->nullableString($customer['phone'] ?? null),
            'source_channels' => array_values(array_unique(array_filter($sourceChannels))),
            'source_links' => $sourceLinks,
            'primary_source' => [
                'source_type' => $hasGrowaveMetafields ? 'growave_customer' : 'shopify_customer',
                'source_id' => $canonicalSourceId,
            ],
        ];
    }

    /**
     * @param array{
     *   gid:string,
     *   shopify_customer_id:string,
     *   email:?string,
     *   phone:?string,
     *   first_name:?string,
     *   last_name:?string,
     *   order_count:?int,
     *   last_order_at:?string,
     *   accepts_marketing:?bool,
     *   updated_at:?string,
     *   metafields:array<int,array{namespace:string,key:string,value:string,type:?string}>
     * } $customer
     * @param array{
     *   raw_metafields:array<int,array{namespace:string,key:string,value:string,type:?string}>,
     *   points_balance:?int,
     *   vip_tier:?string,
     *   referral_link:?string
     * } $parsed
     * @return 'created'|'updated'
     */
    protected function upsertSnapshot(
        string $storeKey,
        array $customer,
        array $parsed,
        mixed $marketingProfileId,
        bool $hasGrowaveMetafields,
        bool $dryRun
    ): string {
        $shopifyCustomerId = $this->requiredString(
            $customer['shopify_customer_id'] ?? null,
            'Shopify customer ID is missing from payload.'
        );

        $lookup = [
            'provider' => 'shopify',
            'integration' => $hasGrowaveMetafields ? 'growave' : 'shopify_customer',
            'store_key' => $storeKey,
            'external_customer_id' => $shopifyCustomerId,
        ];

        if ($dryRun) {
            return CustomerExternalProfile::query()->where($lookup)->exists() ? 'updated' : 'created';
        }

        $existing = CustomerExternalProfile::query()->where($lookup)->first();

        $firstName = $this->nullableString($customer['first_name'] ?? null);
        $lastName = $this->nullableString($customer['last_name'] ?? null);
        $fullName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
        $lastOrderAt = $this->parseDate($customer['last_order_at'] ?? null);
        $updatedAt = $this->parseDate($customer['updated_at'] ?? null);

        CustomerExternalProfile::query()->updateOrCreate(
            $lookup,
            [
                'marketing_profile_id' => is_numeric($marketingProfileId) && (int) $marketingProfileId > 0 ? (int) $marketingProfileId : null,
                'external_customer_gid' => $this->nullableString($customer['gid'] ?? null),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $fullName !== '' ? $fullName : null,
                'email' => $this->nullableString($customer['email'] ?? null),
                'normalized_email' => $this->normalizer->normalizeEmail($this->nullableString($customer['email'] ?? null)),
                'phone' => $this->nullableString($customer['phone'] ?? null),
                'normalized_phone' => $this->normalizer->normalizePhone($this->nullableString($customer['phone'] ?? null)),
                'accepts_marketing' => is_bool($customer['accepts_marketing'] ?? null) ? (bool) $customer['accepts_marketing'] : null,
                'order_count' => is_numeric($customer['order_count'] ?? null) ? max(0, (int) $customer['order_count']) : null,
                'last_order_at' => $lastOrderAt,
                'last_activity_at' => $this->latestDate($lastOrderAt, $updatedAt),
                'source_channels' => array_values(array_filter(array_unique([
                    'shopify',
                    $hasGrowaveMetafields ? 'growave' : null,
                ]))),
                'raw_metafields' => $parsed['raw_metafields'],
                'points_balance' => $parsed['points_balance'],
                'vip_tier' => $parsed['vip_tier'],
                'referral_link' => $parsed['referral_link'],
                'synced_at' => now(),
            ]
        );

        return $existing ? 'updated' : 'created';
    }

    /**
     * @param array<string,int> $summary
     * @param array<string,mixed> $syncResult
     */
    protected function mergeProfileSummary(array &$summary, array $syncResult): void
    {
        foreach (['profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped'] as $key) {
            $summary[$key] += (int) ($syncResult[$key] ?? 0);
        }

        if (
            (int) ($syncResult['profile_id'] ?? 0) > 0
            && (int) ($syncResult['profiles_created'] ?? 0) === 0
            && (int) ($syncResult['reviews_created'] ?? 0) === 0
            && (int) ($syncResult['records_skipped'] ?? 0) === 0
        ) {
            $summary['matched_existing']++;
        }

        if (in_array((string) ($syncResult['reason'] ?? ''), [
            'missing_email_phone',
            'create_not_allowed',
            'no_action_taken',
        ], true)) {
            $summary['skipped_no_identity']++;
        }

        $summary['ambiguous_collisions'] += (int) ($syncResult['reviews_created'] ?? 0);
    }

    protected function requiredString(mixed $value, string $message): string
    {
        $string = trim((string) $value);
        if ($string === '') {
            throw new RuntimeException($message);
        }

        return $string;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
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

    protected function parseDate(mixed $value): ?CarbonImmutable
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

    protected function latestDate(?CarbonImmutable $left, ?CarbonImmutable $right): ?CarbonImmutable
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return $left->greaterThan($right) ? $left : $right;
    }
}
