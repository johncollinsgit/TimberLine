<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Shopify\ShopifyCustomerMetafieldFetcher;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShopifyCustomerMetafieldSyncService
{
    public function __construct(
        protected GrowaveCustomerMetafieldParser $parser,
        protected MarketingIdentityNormalizer $normalizer
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
        $limit = max(1, (int) ($options['limit'] ?? 200));
        $pageSize = min(max(1, (int) ($options['page_size'] ?? 50)), 100);
        $cursor = $this->nullableString($options['cursor'] ?? null);

        $summary = [
            'processed' => 0,
            'records_with_growave_metafields' => 0,
            'created' => 0,
            'updated' => 0,
            'links_created' => 0,
            'links_reused' => 0,
            'records_skipped' => 0,
            'pages_processed' => 0,
            'errors' => 0,
        ];

        $run = MarketingImportRun::query()->create([
            'type' => 'shopify_customer_metafields_sync',
            'status' => 'running',
            'source_label' => 'shopify_growave_customers:'.$storeKey,
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
                $pageLimit = min($pageSize, $remaining);
                $page = $fetcher->fetchPage($cursor, $pageLimit);
                $summary['pages_processed']++;

                $customers = is_array($page['customers'] ?? null) ? $page['customers'] : [];
                foreach ($customers as $customer) {
                    if ($remaining <= 0) {
                        break 2;
                    }

                    $remaining--;
                    $summary['processed']++;

                    $parsed = $this->parser->parse((array) ($customer['metafields'] ?? []));
                    if ($parsed['raw_metafields'] === []) {
                        $summary['records_skipped']++;

                        continue;
                    }

                    $summary['records_with_growave_metafields']++;

                    if ($dryRun) {
                        $mapping = $this->resolveProfileMapping($storeKey, $customer, true);
                        $action = $this->upsertSnapshot($storeKey, $customer, $mapping['marketing_profile_id'], $parsed, true);
                    } else {
                        [$mapping, $action] = DB::transaction(function () use ($storeKey, $customer, $parsed): array {
                            $mapping = $this->resolveProfileMapping($storeKey, $customer, false);
                            $action = $this->upsertSnapshot(
                                $storeKey,
                                $customer,
                                $mapping['marketing_profile_id'],
                                $parsed,
                                false
                            );

                            return [$mapping, $action];
                        });
                    }

                    $summary['links_created'] += (int) ($mapping['links_created'] ?? 0);
                    $summary['links_reused'] += (int) ($mapping['links_reused'] ?? 0);
                    $summary[$action]++;
                }

                $cursor = $this->nullableString($page['cursor'] ?? null);
                $hasNext = (bool) ($page['has_next'] ?? false);
            } while ($hasNext && $cursor !== null && $remaining > 0);

            $run->forceFill([
                'status' => 'completed',
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
     *   first_name:?string,
     *   last_name:?string,
     *   metafields:array<int,array{namespace:string,key:string,value:string,type:?string}>
     * } $customer
     * @return array{marketing_profile_id:int,links_created:int,links_reused:int}
     */
    protected function resolveProfileMapping(string $storeKey, array $customer, bool $dryRun): array
    {
        $shopifyCustomerId = $this->requiredString(
            $customer['shopify_customer_id'] ?? null,
            'Shopify customer ID is missing from payload.'
        );
        $customerGid = $this->requiredString(
            $customer['gid'] ?? null,
            "Shopify customer gid missing for customer '{$shopifyCustomerId}'."
        );
        $canonicalSourceId = $storeKey.':'.$shopifyCustomerId;
        $sourceCandidates = $this->sourceIdCandidates($storeKey, $shopifyCustomerId, $customerGid);

        $idLinks = MarketingProfileLink::query()
            ->where('source_type', 'shopify_customer')
            ->whereIn('source_id', $sourceCandidates)
            ->get();

        $distinctProfileIds = $idLinks->pluck('marketing_profile_id')->filter()->unique()->values();
        if ($distinctProfileIds->count() > 1) {
            throw new RuntimeException(
                "Mapping conflict for Shopify customer {$shopifyCustomerId}: customer ID links map to multiple profiles."
            );
        }

        $normalizedEmail = $this->normalizer->normalizeEmail($this->nullableString($customer['email'] ?? null));
        $matchMethod = 'shopify_customer_id';

        if ($distinctProfileIds->count() === 1) {
            $profile = MarketingProfile::query()->find((int) $distinctProfileIds->first());
            if (! $profile) {
                throw new RuntimeException(
                    "Mapping error for Shopify customer {$shopifyCustomerId}: linked marketing profile no longer exists."
                );
            }

            // If we can resolve email to a different profile, treat it as a hard mapping error.
            if ($normalizedEmail !== null) {
                $emailMatches = MarketingProfile::query()
                    ->where('normalized_email', $normalizedEmail)
                    ->get();

                if ($emailMatches->count() > 1) {
                    throw new RuntimeException(
                        "Mapping conflict for Shopify customer {$shopifyCustomerId}: email '{$normalizedEmail}' is ambiguous."
                    );
                }

                if ($emailMatches->count() === 1 && (int) $emailMatches->first()->id !== (int) $profile->id) {
                    throw new RuntimeException(
                        "Mapping conflict for Shopify customer {$shopifyCustomerId}: ID and email point to different profiles."
                    );
                }
            }
        } else {
            if ($normalizedEmail === null) {
                throw new RuntimeException(
                    "Mapping error for Shopify customer {$shopifyCustomerId}: no Shopify ID link and no usable email fallback."
                );
            }

            $emailMatches = MarketingProfile::query()
                ->where('normalized_email', $normalizedEmail)
                ->get();

            if ($emailMatches->isEmpty()) {
                throw new RuntimeException(
                    "Mapping error for Shopify customer {$shopifyCustomerId}: no profile found for email '{$normalizedEmail}'."
                );
            }

            if ($emailMatches->count() > 1) {
                throw new RuntimeException(
                    "Mapping conflict for Shopify customer {$shopifyCustomerId}: email '{$normalizedEmail}' maps to multiple profiles."
                );
            }

            $profile = $emailMatches->first();
            $matchMethod = 'email';
        }

        $linksCreated = 0;
        $linksReused = 0;

        $existingCanonical = MarketingProfileLink::query()
            ->where('source_type', 'shopify_customer')
            ->where('source_id', $canonicalSourceId)
            ->first();

        if ($existingCanonical && (int) $existingCanonical->marketing_profile_id !== (int) $profile->id) {
            throw new RuntimeException(
                "Mapping conflict for Shopify customer {$shopifyCustomerId}: canonical Shopify link belongs to another profile."
            );
        }

        if ($dryRun) {
            if ($existingCanonical) {
                $linksReused = 1;
            } else {
                $linksCreated = 1;
            }
        } else {
            MarketingProfileLink::query()->updateOrCreate(
                [
                    'source_type' => 'shopify_customer',
                    'source_id' => $canonicalSourceId,
                ],
                [
                    'marketing_profile_id' => (int) $profile->id,
                    'source_meta' => [
                        'store_key' => $storeKey,
                        'shopify_customer_id' => $shopifyCustomerId,
                        'shopify_customer_gid' => $customerGid,
                    ],
                    'match_method' => $matchMethod === 'shopify_customer_id' ? 'shopify_customer_id' : 'exact_email',
                    'confidence' => $matchMethod === 'shopify_customer_id' ? 1.00 : 0.90,
                ]
            );

            if ($existingCanonical) {
                $linksReused = 1;
            } else {
                $linksCreated = 1;
            }
        }

        return [
            'marketing_profile_id' => (int) $profile->id,
            'links_created' => $linksCreated,
            'links_reused' => $linksReused,
        ];
    }

    /**
     * @param array{
     *   gid:string,
     *   shopify_customer_id:string,
     *   email:?string,
     *   first_name:?string,
     *   last_name:?string,
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
        int $marketingProfileId,
        array $parsed,
        bool $dryRun
    ): string {
        $shopifyCustomerId = $this->requiredString(
            $customer['shopify_customer_id'] ?? null,
            'Shopify customer ID is missing from payload.'
        );

        $lookup = [
            'provider' => 'shopify',
            'integration' => 'growave',
            'store_key' => $storeKey,
            'external_customer_id' => $shopifyCustomerId,
        ];

        if ($dryRun) {
            $exists = CustomerExternalProfile::query()->where($lookup)->exists();

            return $exists ? 'updated' : 'created';
        }

        $existing = CustomerExternalProfile::query()->where($lookup)->first();

        CustomerExternalProfile::query()->updateOrCreate(
            $lookup,
            [
                'marketing_profile_id' => $marketingProfileId,
                'external_customer_gid' => $this->nullableString($customer['gid'] ?? null),
                'email' => $this->nullableString($customer['email'] ?? null),
                'normalized_email' => $this->normalizer->normalizeEmail($this->nullableString($customer['email'] ?? null)),
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
     * @return array<int,string>
     */
    protected function sourceIdCandidates(string $storeKey, string $shopifyCustomerId, string $customerGid): array
    {
        return array_values(array_unique(array_filter([
            $storeKey.':'.$shopifyCustomerId,
            $shopifyCustomerId,
            $customerGid,
            $storeKey.':'.$customerGid,
        ], fn ($value): bool => trim((string) $value) !== '')));
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
}
