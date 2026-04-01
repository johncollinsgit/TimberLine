<?php

namespace App\Services\Marketing;

use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Shopify\ShopifyCustomerMetafieldFetcher;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShopifyCustomerBirthdaySyncService
{
    public function __construct(
        protected GrowaveBirthdayMetafieldParser $parser,
        protected BirthdayProfileService $birthdayProfileService,
        protected ShopifyBirthdayMetafieldService $shopifyBirthdayMetafieldService,
        protected MarketingIdentityNormalizer $normalizer
    ) {
    }

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
        $tenantId = $this->tenantIdFromStore($store);

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $writeBack = (bool) ($options['write_back'] ?? false);
        $limit = max(1, (int) ($options['limit'] ?? 200));
        $pageSize = min(max(1, (int) ($options['page_size'] ?? 50)), 100);
        $cursor = $this->nullableString($options['cursor'] ?? null);

        $summary = [
            'processed' => 0,
            'records_with_birthday_metafields' => 0,
            'created' => 0,
            'updated' => 0,
            'partial_or_uncertain' => 0,
            'records_skipped' => 0,
            'pages_processed' => 0,
            'links_created' => 0,
            'links_reused' => 0,
            'write_back_updates' => 0,
            'errors' => 0,
        ];

        $run = MarketingImportRun::query()->create([
            'type' => 'shopify_customer_birthdays_sync',
            'status' => 'running',
            'source_label' => 'shopify_birthday_customers:'.$storeKey,
            'started_at' => now(),
            'tenant_id' => $tenantId,
            'summary' => [
                'mode' => $dryRun ? 'dry-run' : 'live-sync',
                'store_key' => $storeKey,
                'cursor' => $cursor,
                'limit' => $limit,
                'page_size' => $pageSize,
                'write_back' => $writeBack,
            ],
        ]);

        Log::info('shopify customer birthday sync started', [
            'store_key' => $storeKey,
            'dry_run' => $dryRun,
            'write_back' => $writeBack,
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

                    $summary['records_with_birthday_metafields']++;
                    if ((bool) ($parsed['is_partial'] ?? false) || (bool) ($parsed['is_uncertain'] ?? false)) {
                        $summary['partial_or_uncertain']++;
                    }

                    if ($dryRun) {
                        $mapping = $this->resolveProfileMapping($storeKey, $customer, true, $tenantId);
                        $snapshot = CustomerBirthdayProfile::query()
                            ->where('marketing_profile_id', (int) $mapping['marketing_profile_id'])
                            ->first();

                        $action = $snapshot ? 'updated' : 'created';
                        $birthdayProfile = $snapshot;
                        $profile = MarketingProfile::query()->forTenantId($tenantId)->find((int) $mapping['marketing_profile_id']);
                    } else {
                        [$mapping, $action, $birthdayProfile, $profile] = DB::transaction(function () use ($storeKey, $customer, $parsed, $tenantId): array {
                            $mapping = $this->resolveProfileMapping($storeKey, $customer, false, $tenantId);

                            $profile = MarketingProfile::query()->forTenantId($tenantId)->findOrFail((int) $mapping['marketing_profile_id']);
                            [$action, $birthdayProfile] = $this->upsertBirthdayFromParsed($profile, $parsed);

                            return [$mapping, $action, $birthdayProfile, $profile];
                        });
                    }

                    $summary['links_created'] += (int) ($mapping['links_created'] ?? 0);
                    $summary['links_reused'] += (int) ($mapping['links_reused'] ?? 0);

                    if ($action === 'created') {
                        $summary['created']++;
                    } elseif ($action === 'updated') {
                        $summary['updated']++;
                    } else {
                        $summary['records_skipped']++;
                    }

                    if (! $dryRun && $writeBack && $birthdayProfile && $profile) {
                        $writeBackResult = $this->shopifyBirthdayMetafieldService->writeBirthdayForProfile(
                            profile: $profile,
                            birthday: $birthdayProfile,
                            options: ['store_keys' => [$storeKey]]
                        );

                        $summary['write_back_updates'] += (int) ($writeBackResult['updated'] ?? 0);
                        $errors = (array) ($writeBackResult['errors'] ?? []);
                        if ($errors !== []) {
                            throw new RuntimeException('Birthday write-back failed: '.implode(' | ', $errors));
                        }
                    }
                }

                $cursor = $this->nullableString($page['cursor'] ?? null);
                $hasNext = (bool) ($page['has_next'] ?? false);
            } while ($hasNext && $cursor !== null && $remaining > 0);

            $run->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $dryRun ? 'Dry-run executed; no birthday rows were written.' : null,
            ])->save();

            Log::info('shopify customer birthday sync completed', [
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

            Log::error('shopify customer birthday sync failed', [
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
    protected function resolveProfileMapping(string $storeKey, array $customer, bool $dryRun, ?int $tenantId): array
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
            $profile = MarketingProfile::query()->forTenantId($tenantId)->find((int) $distinctProfileIds->first());
            if (! $profile) {
                throw new RuntimeException(
                    "Mapping error for Shopify customer {$shopifyCustomerId}: linked marketing profile no longer exists."
                );
            }

            if ($normalizedEmail !== null) {
                $emailMatches = MarketingProfile::query()
                    ->forTenantId($tenantId)
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
                    ->forTenantId($tenantId)
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
     *   raw_metafields:array<int,array{namespace:string,key:string,value:string,type:?string}>,
     *   birth_month:?int,
     *   birth_day:?int,
     *   birth_year:?int,
     *   birthday_full_date:?string,
     *   is_partial:bool,
     *   is_uncertain:bool,
     *   source:string
     * } $parsed
     * @return array{0:'created'|'updated'|'skipped',1:?CustomerBirthdayProfile}
     */
    protected function upsertBirthdayFromParsed(MarketingProfile $profile, array $parsed): array
    {
        /** @var CustomerBirthdayProfile|null $existing */
        $existing = CustomerBirthdayProfile::query()
            ->where('marketing_profile_id', $profile->id)
            ->first();

        if (
            ($parsed['birth_month'] ?? null) === null
            && ($parsed['birth_day'] ?? null) === null
            && $existing
            && $existing->birth_month
            && $existing->birth_day
        ) {
            $this->birthdayProfileService->writeAudit(
                profile: $existing,
                action: 'birthday_import_partial_skipped',
                source: (string) ($parsed['source'] ?? 'shopify_metafield'),
                isUncertain: true,
                payload: [
                    'reason' => 'incoming_import_missing_month_day',
                    'raw_metafields' => $parsed['raw_metafields'] ?? [],
                ]
            );

            return ['skipped', $existing];
        }

        $birthday = $this->birthdayProfileService->captureForProfile(
            profile: $profile,
            payload: [
                'birth_month' => $parsed['birth_month'] ?? null,
                'birth_day' => $parsed['birth_day'] ?? null,
                'birth_year' => $parsed['birth_year'] ?? null,
                'birthday_full_date' => $parsed['birthday_full_date'] ?? null,
                'source' => (string) ($parsed['source'] ?? 'shopify_metafield'),
                'source_captured_at' => now()->toIso8601String(),
            ],
            options: [
                'source' => (string) ($parsed['source'] ?? 'shopify_metafield'),
                'is_uncertain' => (bool) ($parsed['is_uncertain'] ?? false),
                'replace_source' => true,
            ]
        );

        // Preserve raw import traces for migration audits.
        $birthday->forceFill([
            'metadata' => array_merge((array) ($birthday->metadata ?? []), [
                'last_shopify_import' => [
                    'synced_at' => now()->toIso8601String(),
                    'raw_metafields' => $parsed['raw_metafields'] ?? [],
                    'is_partial' => (bool) ($parsed['is_partial'] ?? false),
                    'is_uncertain' => (bool) ($parsed['is_uncertain'] ?? false),
                ],
            ]),
        ])->save();

        return [$existing ? 'updated' : 'created', $birthday->fresh()];
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

    protected function tenantIdFromStore(array $store): ?int
    {
        $tenantId = is_numeric($store['tenant_id'] ?? null) ? (int) $store['tenant_id'] : null;
        return $tenantId;
    }
}
