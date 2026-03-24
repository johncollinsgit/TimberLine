<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingProfileWishlistItem;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class GrowaveWishlistBackfillService
{
    public function __construct(
        protected GrowaveClient $client,
        protected MarketingIdentityNormalizer $normalizer
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function backfill(array $options = []): array
    {
        if (! (bool) config('marketing.growave.enabled', false)) {
            return [
                'status' => 'skipped',
                'reason' => 'growave_sync_disabled',
                'run_id' => null,
                'summary' => [],
            ];
        }

        $store = $this->nullableString($options['store'] ?? null);
        $limit = $this->nullableInt($options['limit'] ?? null);
        $profileId = $this->nullableInt($options['profile_id'] ?? null);
        $afterCandidateId = $this->nullableInt($options['after_candidate_id'] ?? null);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $latestOnly = ! array_key_exists('latest_only', $options) || (bool) ($options['latest_only'] ?? true);
        $perPage = min(max(1, (int) ($options['per_page'] ?? 50)), 50);
        $maxWishlistPages = max(1, (int) ($options['max_wishlist_pages'] ?? 20));
        $maxItemPages = max(1, (int) ($options['max_item_pages'] ?? 20));
        $pageDelayMs = max(0, (int) ($options['page_delay_ms'] ?? config('marketing.growave.page_delay_ms', 150)));

        $this->client->configureRuntime(array_filter([
            'retry_attempts' => $this->nullableInt($options['retry_attempts'] ?? null),
            'request_min_interval_ms' => $this->nullableInt($options['request_min_interval_ms'] ?? null),
            'request_jitter_ms' => $this->nullableInt($options['request_jitter_ms'] ?? null),
            'backoff_base_ms' => $this->nullableInt($options['backoff_base_ms'] ?? null),
            'backoff_max_ms' => $this->nullableInt($options['backoff_max_ms'] ?? null),
        ], static fn (mixed $value): bool => $value !== null));

        $summary = [
            'processed_candidates' => 0,
            'candidates_skipped_duplicate_snapshot' => 0,
            'wishlists_seen' => 0,
            'wishlist_items_seen' => 0,
            'mapped_items' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'dry_run_would_create' => 0,
            'dry_run_would_update' => 0,
            'skipped_native_authoritative' => 0,
            'skipped_unresolved_profile' => 0,
            'skipped_missing_store_key' => 0,
            'skipped_missing_customer_identifier' => 0,
            'skipped_unmappable_product' => 0,
            'errors' => 0,
            'unmappable_by_reason' => [],
        ];

        $run = MarketingImportRun::query()->create([
            'type' => 'growave_wishlist_backfill',
            'status' => 'running',
            'source_label' => $store !== null ? 'growave:' . $store : 'growave:all',
            'started_at' => now(),
            'summary' => [
                'store' => $store,
                'limit' => $limit,
                'profile_id' => $profileId,
                'after_candidate_id' => $afterCandidateId,
                'latest_only' => $latestOnly,
                'dry_run' => $dryRun,
                'per_page' => $perPage,
                'max_wishlist_pages' => $maxWishlistPages,
                'max_item_pages' => $maxItemPages,
                'page_delay_ms' => $pageDelayMs,
            ],
        ]);

        $seenCandidates = [];

        try {
            $query = $this->candidateQuery($store, $profileId, $afterCandidateId);

            foreach ($query->cursor() as $candidate) {
                if ($limit !== null && (int) $summary['processed_candidates'] >= $limit) {
                    break;
                }

                if ($latestOnly) {
                    $dedupeKey = $this->candidateDedupeKey($candidate);
                    if (isset($seenCandidates[$dedupeKey])) {
                        $summary['candidates_skipped_duplicate_snapshot']++;

                        continue;
                    }

                    $seenCandidates[$dedupeKey] = true;
                }

                $summary['processed_candidates']++;

                try {
                    $this->processCandidate(
                        candidate: $candidate,
                        dryRun: $dryRun,
                        perPage: $perPage,
                        maxWishlistPages: $maxWishlistPages,
                        maxItemPages: $maxItemPages,
                        pageDelayMs: $pageDelayMs,
                        summary: $summary
                    );
                } catch (\Throwable $e) {
                    $summary['errors']++;

                    Log::warning('growave wishlist backfill candidate failed', [
                        'candidate_id' => (int) $candidate->id,
                        'store_key' => $candidate->store_key,
                        'external_customer_id' => $candidate->external_customer_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $finalSummary = array_merge($summary, [
                'store' => $store,
                'limit' => $limit,
                'profile_id' => $profileId,
                'after_candidate_id' => $afterCandidateId,
                'latest_only' => $latestOnly,
                'dry_run' => $dryRun,
            ]);

            $run->forceFill([
                'status' => ((int) $summary['errors'] > 0) ? 'partial' : 'completed',
                'finished_at' => now(),
                'summary' => $finalSummary,
            ])->save();

            return [
                'status' => (string) $run->status,
                'run_id' => (int) $run->id,
                'summary' => $finalSummary,
            ];
        } catch (\Throwable $e) {
            $summary['errors']++;
            $failedSummary = array_merge($summary, [
                'store' => $store,
                'limit' => $limit,
                'profile_id' => $profileId,
                'after_candidate_id' => $afterCandidateId,
                'latest_only' => $latestOnly,
                'dry_run' => $dryRun,
            ]);

            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'summary' => $failedSummary,
                'notes' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $summary
     */
    protected function processCandidate(
        CustomerExternalProfile $candidate,
        bool $dryRun,
        int $perPage,
        int $maxWishlistPages,
        int $maxItemPages,
        int $pageDelayMs,
        array &$summary
    ): void {
        $storeKey = $this->nullableString($candidate->store_key);
        if ($storeKey === null) {
            $summary['skipped_missing_store_key']++;
            $this->incrementReason($summary, 'missing_store_key');

            return;
        }

        $profile = $this->resolveProfileForCandidate($candidate, $storeKey);
        if (! $profile) {
            $summary['skipped_unresolved_profile']++;
            $this->incrementReason($summary, 'unresolved_profile');

            return;
        }

        $wishlistResult = $this->fetchWishlistsWithIdentifierFallback(
            candidate: $candidate,
            perPage: $perPage,
            maxPages: $maxWishlistPages,
            pageDelayMs: $pageDelayMs
        );

        if ($wishlistResult === null) {
            $summary['skipped_missing_customer_identifier']++;
            $this->incrementReason($summary, 'missing_customer_identifier');

            return;
        }

        $wishlists = (array) ($wishlistResult['wishlists'] ?? []);
        $summary['wishlists_seen'] += (int) data_get($wishlistResult, 'summary.total', count($wishlists));

        foreach ($wishlists as $wishlist) {
            if (! is_array($wishlist)) {
                continue;
            }

            $items = $this->wishlistItemsFromWishlistPayload($wishlist);
            if ($items === [] && $this->wishlistPayloadLooksLikeItem($wishlist)) {
                $items = [$wishlist];
            }

            if ($items === []) {
                $wishlistIdentifier = $this->wishlistIdentifier($wishlist);
                if ($wishlistIdentifier !== null) {
                    $fetchedItems = $this->fetchAllWishlistItems(
                        wishlistIdentifier: $wishlistIdentifier,
                        perPage: $perPage,
                        maxPages: $maxItemPages,
                        pageDelayMs: $pageDelayMs
                    );
                    $items = (array) ($fetchedItems['items'] ?? []);
                    $summary['wishlist_items_seen'] += (int) data_get($fetchedItems, 'summary.total', count($items));
                }
            } else {
                $summary['wishlist_items_seen'] += count($items);
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $mapped = $this->mapWishlistItem(
                    item: $item,
                    wishlist: $wishlist,
                    candidate: $candidate,
                    storeKey: $storeKey
                );

                if ($mapped === null) {
                    $summary['skipped_unmappable_product']++;
                    $this->incrementReason($summary, 'missing_product_id');

                    continue;
                }

                $summary['mapped_items']++;

                $this->upsertMappedWishlistRow(
                    profile: $profile,
                    mapped: $mapped,
                    dryRun: $dryRun,
                    summary: $summary
                );
            }
        }
    }

    /**
     * @return array{wishlists:array<int,array<string,mixed>>,summary:array<string,mixed>}|null
     */
    protected function fetchWishlistsWithIdentifierFallback(
        CustomerExternalProfile $candidate,
        int $perPage,
        int $maxPages,
        int $pageDelayMs
    ): ?array {
        $identifiers = $this->candidateIdentifiers($candidate);
        if ($identifiers === []) {
            return null;
        }

        $fallback = null;

        foreach ($identifiers as $identifier) {
            $result = $this->fetchAllWishlists(
                identifier: $identifier,
                perPage: $perPage,
                maxPages: $maxPages,
                pageDelayMs: $pageDelayMs
            );

            if ($fallback === null) {
                $fallback = $result;
            }

            if (! (bool) data_get($result, 'summary.not_found', false)) {
                return $result;
            }
        }

        return $fallback;
    }

    /**
     * @return array{wishlists:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    protected function fetchAllWishlists(string $identifier, int $perPage, int $maxPages, int $pageDelayMs): array
    {
        $offset = 0;
        $pages = 0;
        $total = null;
        $wishlists = [];
        $notFound = false;

        while ($pages < $maxPages) {
            $pages++;
            $payload = $this->client->getWishlists($identifier, $perPage, $offset);
            $pageItems = $this->wishlistCollectionFromPayload($payload);
            $notFound = $notFound || (bool) ($payload['notFound'] ?? false);
            $wishlists = [...$wishlists, ...$pageItems];

            $perPageValue = max(1, (int) ($payload['perPage'] ?? $perPage));
            $currentOffset = max(0, (int) ($payload['currentOffset'] ?? $offset));
            $offset = $currentOffset + $perPageValue;
            $total = max(0, (int) ($payload['totalCount'] ?? count($wishlists)));

            if ($notFound || $offset >= $total || $pageItems === []) {
                break;
            }

            $this->sleepMilliseconds($pageDelayMs);
        }

        return [
            'wishlists' => $wishlists,
            'summary' => [
                'total' => max(0, (int) ($total ?? count($wishlists))),
                'pages' => $pages,
                'not_found' => $notFound,
            ],
        ];
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    protected function fetchAllWishlistItems(string $wishlistIdentifier, int $perPage, int $maxPages, int $pageDelayMs): array
    {
        $offset = 0;
        $pages = 0;
        $total = null;
        $items = [];
        $notFound = false;

        while ($pages < $maxPages) {
            $pages++;
            $payload = $this->client->getWishlistItems($wishlistIdentifier, $perPage, $offset);
            $pageItems = $this->wishlistItemsCollectionFromPayload($payload);
            $notFound = $notFound || (bool) ($payload['notFound'] ?? false);
            $items = [...$items, ...$pageItems];

            $perPageValue = max(1, (int) ($payload['perPage'] ?? $perPage));
            $currentOffset = max(0, (int) ($payload['currentOffset'] ?? $offset));
            $offset = $currentOffset + $perPageValue;
            $total = max(0, (int) ($payload['totalCount'] ?? count($items)));

            if ($notFound || $offset >= $total || $pageItems === []) {
                break;
            }

            $this->sleepMilliseconds($pageDelayMs);
        }

        return [
            'items' => $items,
            'summary' => [
                'total' => max(0, (int) ($total ?? count($items))),
                'pages' => $pages,
                'not_found' => $notFound,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $wishlist
     * @return array<string,mixed>|null
     */
    protected function mapWishlistItem(array $item, array $wishlist, CustomerExternalProfile $candidate, string $storeKey): ?array
    {
        $productId = $this->normalizeShopifyId(
            $this->valueFromSources([$item, $wishlist], [
                'product.shopifyProductId',
                'product.productId',
                'product.id',
                'shopifyProductId',
                'productId',
                'product_id',
                'product.gid',
                'product.shopifyProductGid',
            ])
        );

        if ($productId === null) {
            return null;
        }

        $productVariantId = $this->normalizeShopifyVariantId(
            $this->valueFromSources([$item, $wishlist], [
                'product.shopifyVariantId',
                'product.variantId',
                'productVariantId',
                'product_variant_id',
                'variantId',
                'variant.id',
                'variant.gid',
            ])
        );

        $productHandle = $this->nullableString($this->valueFromSources([$item, $wishlist], [
            'product.handle',
            'product.productHandle',
            'product_handle',
            'productHandle',
            'handle',
            'product.slug',
        ]));
        $productUrl = $this->nullableString($this->valueFromSources([$item, $wishlist], [
            'product.url',
            'product.productUrl',
            'product_url',
            'productUrl',
            'url',
        ]));

        if ($productHandle === null && $productUrl !== null) {
            $productHandle = $this->handleFromUrl($productUrl);
        }

        if ($productUrl === null && $productHandle !== null) {
            $productUrl = '/products/' . ltrim($productHandle, '/');
        }

        $productTitle = $this->nullableString($this->valueFromSources([$item, $wishlist], [
            'product.title',
            'product.productTitle',
            'product_title',
            'productTitle',
            'title',
            'name',
        ]));

        $removedAt = $this->asDate($this->valueFromSources([$item, $wishlist], [
            'removedAt',
            'removed_at',
            'deletedAt',
            'deleted_at',
            'product.removedAt',
            'product.deletedAt',
        ]));
        $statusToken = strtolower(trim((string) $this->valueFromSources([$item, $wishlist], [
            'status',
            'state',
            'product.status',
        ])));
        $explicitRemoved = $removedAt !== null
            || $this->truthy($this->valueFromSources([$item, $wishlist], ['isRemoved', 'removed', 'isDeleted', 'deleted']))
            || in_array($statusToken, ['removed', 'deleted', 'archived'], true);

        $status = $explicitRemoved
            ? MarketingProfileWishlistItem::STATUS_REMOVED
            : MarketingProfileWishlistItem::STATUS_ACTIVE;

        $addedAt = $this->asDate($this->valueFromSources([$item, $wishlist], [
            'addedAt',
            'added_at',
            'createdAt',
            'created_at',
            'savedAt',
            'saved_at',
            'product.addedAt',
        ]));
        $lastAddedAt = $this->asDate($this->valueFromSources([$item, $wishlist], [
            'lastAddedAt',
            'last_added_at',
            'updatedAt',
            'updated_at',
            'product.updatedAt',
        ])) ?: $addedAt;
        $sourceSyncedAt = $this->asDate($this->valueFromSources([$item, $wishlist], [
            'updatedAt',
            'updated_at',
        ])) ?: $this->asDate($candidate->synced_at);

        $wishlistId = $this->nullableString($this->valueFromSources([$wishlist], ['id', 'wishlistId', 'wishlist_id']));
        $itemId = $this->nullableString($this->valueFromSources([$item], ['id', 'wishlistItemId', 'wishlist_item_id']));
        $sourceRef = $this->nullableString(implode(':', array_values(array_filter([
            $wishlistId,
            $itemId,
            $productId,
        ], static fn ($value): bool => trim((string) $value) !== ''))));

        $tenantId = is_numeric($candidate->tenant_id) ? (int) $candidate->tenant_id : null;

        return [
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'product_id' => $productId,
            'product_variant_id' => $productVariantId,
            'product_handle' => $productHandle,
            'product_title' => $productTitle,
            'product_url' => $productUrl,
            'status' => $status,
            'source' => 'growave_wishlist_import',
            'source_surface' => 'growave_wishlist',
            'source_ref' => $sourceRef,
            'added_at' => $addedAt,
            'last_added_at' => $lastAddedAt,
            'removed_at' => $status === MarketingProfileWishlistItem::STATUS_REMOVED ? $removedAt : null,
            'source_synced_at' => $sourceSyncedAt,
            'raw_payload' => [
                'wishlist' => $wishlist,
                'item' => $item,
                'candidate' => [
                    'id' => (int) $candidate->id,
                    'store_key' => $storeKey,
                    'external_customer_id' => $this->nullableString($candidate->external_customer_id),
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $mapped
     * @param array<string,mixed> $summary
     */
    protected function upsertMappedWishlistRow(MarketingProfile $profile, array $mapped, bool $dryRun, array &$summary): void
    {
        $tenantId = is_numeric($profile->tenant_id) ? (int) $profile->tenant_id : null;
        if ($tenantId === null && is_numeric($mapped['tenant_id'] ?? null)) {
            $tenantId = (int) $mapped['tenant_id'];
        }

        $existing = MarketingProfileWishlistItem::query()
            ->forTenantId($tenantId)
            ->where('marketing_profile_id', $profile->id)
            ->where('store_key', (string) $mapped['store_key'])
            ->where('product_id', (string) $mapped['product_id'])
            ->first();

        if ($existing && $this->isNativeAuthoritative($existing)) {
            $summary['skipped_native_authoritative']++;
            $this->incrementReason($summary, 'native_authoritative');

            return;
        }

        if (! $existing) {
            $createPayload = [
                'tenant_id' => $tenantId,
                'marketing_profile_id' => (int) $profile->id,
                'provider' => 'growave',
                'integration' => 'growave',
                'store_key' => (string) $mapped['store_key'],
                'product_id' => (string) $mapped['product_id'],
                'product_variant_id' => $mapped['product_variant_id'] ?? null,
                'product_handle' => $mapped['product_handle'] ?? null,
                'product_title' => $mapped['product_title'] ?? null,
                'product_url' => $mapped['product_url'] ?? null,
                'status' => $mapped['status'] ?? MarketingProfileWishlistItem::STATUS_ACTIVE,
                'source' => $mapped['source'] ?? 'growave_wishlist_import',
                'source_surface' => $mapped['source_surface'] ?? 'growave_wishlist',
                'source_ref' => $mapped['source_ref'] ?? null,
                'added_at' => $mapped['added_at'] ?? null,
                'last_added_at' => $mapped['last_added_at'] ?? ($mapped['added_at'] ?? null),
                'removed_at' => $mapped['removed_at'] ?? null,
                'source_synced_at' => $mapped['source_synced_at'] ?? null,
                'raw_payload' => $mapped['raw_payload'] ?? null,
            ];

            if ($dryRun) {
                $summary['dry_run_would_create']++;

                return;
            }

            MarketingProfileWishlistItem::query()->create($createPayload);
            $summary['created']++;

            return;
        }

        $updatePayload = $this->mergeLegacyRow($existing, $mapped, $tenantId);

        if (! $this->wouldChange($existing, $updatePayload)) {
            $summary['unchanged']++;

            return;
        }

        if ($dryRun) {
            $summary['dry_run_would_update']++;

            return;
        }

        $existing->forceFill($updatePayload)->save();
        $summary['updated']++;
    }

    /**
     * @param array<string,mixed> $mapped
     * @return array<string,mixed>
     */
    protected function mergeLegacyRow(MarketingProfileWishlistItem $existing, array $mapped, ?int $tenantId): array
    {
        $incomingAddedAt = $this->asDate($mapped['added_at'] ?? null);
        $incomingLastAddedAt = $this->asDate($mapped['last_added_at'] ?? null) ?: $incomingAddedAt;
        $incomingRemovedAt = $this->asDate($mapped['removed_at'] ?? null);
        $incomingStatus = (string) ($mapped['status'] ?? MarketingProfileWishlistItem::STATUS_ACTIVE);

        $existingAddedAt = $this->asDate($existing->added_at);
        $existingLastAddedAt = $this->asDate($existing->last_added_at) ?: $existingAddedAt;
        $existingRemovedAt = $this->asDate($existing->removed_at);

        $status = $incomingStatus;
        $removedAt = null;
        if ($incomingStatus === MarketingProfileWishlistItem::STATUS_REMOVED) {
            $status = MarketingProfileWishlistItem::STATUS_REMOVED;
            $removedAt = $this->latestDate($existingRemovedAt, $incomingRemovedAt)
                ?: $incomingRemovedAt
                ?: $existingRemovedAt
                ?: now();
        }

        if ($incomingStatus === MarketingProfileWishlistItem::STATUS_ACTIVE) {
            $status = MarketingProfileWishlistItem::STATUS_ACTIVE;
            $removedAt = null;
        }

        return [
            'tenant_id' => $existing->tenant_id ?: $tenantId,
            'provider' => 'growave',
            'integration' => 'growave',
            'product_variant_id' => $mapped['product_variant_id'] ?? $existing->product_variant_id,
            'product_handle' => $mapped['product_handle'] ?? $existing->product_handle,
            'product_title' => $mapped['product_title'] ?? $existing->product_title,
            'product_url' => $mapped['product_url'] ?? $existing->product_url,
            'status' => $status,
            'source' => $mapped['source'] ?? ($existing->source ?: 'growave_wishlist_import'),
            'source_surface' => $mapped['source_surface'] ?? ($existing->source_surface ?: 'growave_wishlist'),
            'source_ref' => $mapped['source_ref'] ?? $existing->source_ref,
            'added_at' => $this->earliestDate($existingAddedAt, $incomingAddedAt),
            'last_added_at' => $this->latestDate($existingLastAddedAt, $incomingLastAddedAt),
            'removed_at' => $removedAt,
            'source_synced_at' => $this->latestDate(
                $this->asDate($existing->source_synced_at),
                $this->asDate($mapped['source_synced_at'] ?? null)
            ),
            'raw_payload' => $mapped['raw_payload'] ?? $existing->raw_payload,
        ];
    }

    protected function resolveProfileForCandidate(CustomerExternalProfile $candidate, string $storeKey): ?MarketingProfile
    {
        $tenantId = is_numeric($candidate->tenant_id) ? (int) $candidate->tenant_id : null;
        $marketingProfileId = is_numeric($candidate->marketing_profile_id) ? (int) $candidate->marketing_profile_id : null;

        if ($marketingProfileId !== null && $marketingProfileId > 0) {
            return MarketingProfile::query()
                ->forTenantId($tenantId)
                ->find($marketingProfileId);
        }

        $externalCustomerId = $this->nullableString($candidate->external_customer_id);
        if ($externalCustomerId !== null) {
            $resolvedProfileId = CustomerExternalProfile::query()
                ->forTenantId($tenantId)
                ->where('provider', 'shopify')
                ->whereIn('integration', ['shopify_customer', 'shopify_admin'])
                ->where('store_key', $storeKey)
                ->where('external_customer_id', $externalCustomerId)
                ->whereNotNull('marketing_profile_id')
                ->orderByDesc('id')
                ->value('marketing_profile_id');

            if (is_numeric($resolvedProfileId) && (int) $resolvedProfileId > 0) {
                return MarketingProfile::query()
                    ->forTenantId($tenantId)
                    ->find((int) $resolvedProfileId);
            }

            $sourceId = $storeKey . ':' . $externalCustomerId;
            $linkProfileId = MarketingProfileLink::query()
                ->whereIn('source_type', ['shopify_customer', 'growave_customer'])
                ->where('source_id', $sourceId)
                ->value('marketing_profile_id');

            if (is_numeric($linkProfileId) && (int) $linkProfileId > 0) {
                return MarketingProfile::query()
                    ->forTenantId($tenantId)
                    ->find((int) $linkProfileId);
            }
        }

        $normalizedEmail = $this->normalizer->normalizeEmail(
            $this->nullableString($candidate->email) ?: $this->nullableString($candidate->normalized_email)
        );
        if ($normalizedEmail !== null) {
            $emailMatches = MarketingProfile::query()
                ->forTenantId($tenantId)
                ->where('normalized_email', $normalizedEmail)
                ->limit(2)
                ->pluck('id');

            if ($emailMatches->count() === 1) {
                return MarketingProfile::query()
                    ->forTenantId($tenantId)
                    ->find((int) $emailMatches->first());
            }
        }

        $normalizedPhone = $this->normalizer->normalizePhone(
            $this->nullableString($candidate->phone) ?: $this->nullableString($candidate->normalized_phone)
        );
        if ($normalizedPhone !== null) {
            $phoneMatches = MarketingProfile::query()
                ->forTenantId($tenantId)
                ->where('normalized_phone', $normalizedPhone)
                ->limit(2)
                ->pluck('id');

            if ($phoneMatches->count() === 1) {
                return MarketingProfile::query()
                    ->forTenantId($tenantId)
                    ->find((int) $phoneMatches->first());
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    protected function candidateIdentifiers(CustomerExternalProfile $candidate): array
    {
        $values = [
            $this->nullableString($candidate->external_customer_id),
            $this->nullableString($candidate->email),
            $this->nullableString($candidate->normalized_email),
            $this->nullableString($candidate->phone),
            $this->nullableString($candidate->normalized_phone),
        ];

        $identifiers = [];

        foreach ($values as $value) {
            if ($value === null || in_array($value, $identifiers, true)) {
                continue;
            }

            $identifiers[] = $value;
        }

        return $identifiers;
    }

    protected function candidateDedupeKey(CustomerExternalProfile $candidate): string
    {
        $storeKey = $this->nullableString($candidate->store_key) ?: 'unknown';
        $identity = $this->nullableString($candidate->external_customer_id)
            ?: $this->nullableString($candidate->normalized_email)
            ?: $this->nullableString($candidate->normalized_phone)
            ?: ('row:' . (int) $candidate->id);

        return $storeKey . '|' . $identity;
    }

    protected function candidateQuery(?string $store, ?int $profileId, ?int $afterCandidateId): Builder
    {
        return CustomerExternalProfile::query()
            ->where('provider', 'shopify')
            ->where('integration', 'growave')
            ->when($store !== null, fn (Builder $query) => $query->where('store_key', $store))
            ->when($profileId !== null, fn (Builder $query) => $query->where('marketing_profile_id', $profileId))
            ->when($afterCandidateId !== null, fn (Builder $query) => $query->where('id', '>', $afterCandidateId))
            ->orderByDesc('id');
    }

    /**
     * @param array<string,mixed> $summary
     */
    protected function incrementReason(array &$summary, string $reason): void
    {
        $reasons = is_array($summary['unmappable_by_reason'] ?? null)
            ? $summary['unmappable_by_reason']
            : [];

        $reasons[$reason] = ((int) ($reasons[$reason] ?? 0)) + 1;
        $summary['unmappable_by_reason'] = $reasons;
    }

    protected function isNativeAuthoritative(MarketingProfileWishlistItem $item): bool
    {
        return (string) $item->provider === 'backstage'
            && (string) $item->integration === 'native';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    protected function wishlistCollectionFromPayload(array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (is_array($items)) {
            return array_values(array_filter($items, 'is_array'));
        }

        $wishlists = $payload['wishlists'] ?? null;
        if (is_array($wishlists)) {
            return array_values(array_filter($wishlists, 'is_array'));
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            if (is_array($data['items'] ?? null)) {
                return array_values(array_filter($data['items'], 'is_array'));
            }

            if (is_array($data['wishlists'] ?? null)) {
                return array_values(array_filter($data['wishlists'], 'is_array'));
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    protected function wishlistItemsCollectionFromPayload(array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (is_array($items)) {
            return array_values(array_filter($items, 'is_array'));
        }

        $wishlistItems = $payload['wishlistItems'] ?? null;
        if (is_array($wishlistItems)) {
            return array_values(array_filter($wishlistItems, 'is_array'));
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            if (is_array($data['items'] ?? null)) {
                return array_values(array_filter($data['items'], 'is_array'));
            }

            if (is_array($data['wishlistItems'] ?? null)) {
                return array_values(array_filter($data['wishlistItems'], 'is_array'));
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $wishlist
     * @return array<int,array<string,mixed>>
     */
    protected function wishlistItemsFromWishlistPayload(array $wishlist): array
    {
        $items = $wishlist['items'] ?? null;
        if (is_array($items)) {
            return array_values(array_filter($items, 'is_array'));
        }

        $wishlistItems = $wishlist['wishlistItems'] ?? null;
        if (is_array($wishlistItems)) {
            return array_values(array_filter($wishlistItems, 'is_array'));
        }

        $products = $wishlist['products'] ?? null;
        if (is_array($products)) {
            return array_values(array_filter($products, 'is_array'));
        }

        return [];
    }

    /**
     * @param array<string,mixed> $wishlist
     */
    protected function wishlistPayloadLooksLikeItem(array $wishlist): bool
    {
        return $this->normalizeShopifyId($this->valueFromSources([$wishlist], [
            'product.shopifyProductId',
            'product.productId',
            'product.id',
            'shopifyProductId',
            'productId',
            'product_id',
            'product.gid',
        ])) !== null;
    }

    /**
     * @param array<string,mixed> $wishlist
     */
    protected function wishlistIdentifier(array $wishlist): ?string
    {
        return $this->nullableString($this->valueFromSources([$wishlist], [
            'id',
            'wishlistId',
            'wishlist_id',
            'identifier',
        ]));
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     */
    protected function valueFromSources(array $sources, array $paths): mixed
    {
        foreach ($paths as $path) {
            foreach ($sources as $source) {
                if (! is_array($source)) {
                    continue;
                }

                $value = data_get($source, $path);
                if ($value === null) {
                    continue;
                }

                if (is_string($value) && trim($value) === '') {
                    continue;
                }

                return $value;
            }
        }

        return null;
    }

    protected function wouldChange(MarketingProfileWishlistItem $existing, array $payload): bool
    {
        foreach ($payload as $key => $incomingValue) {
            $currentValue = $existing->getAttribute($key);
            if (! $this->valuesEqual($currentValue, $incomingValue)) {
                return true;
            }
        }

        return false;
    }

    protected function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a instanceof CarbonInterface || $b instanceof CarbonInterface) {
            $aDate = $this->asDate($a);
            $bDate = $this->asDate($b);

            return ($aDate?->toIso8601String()) === ($bDate?->toIso8601String());
        }

        if (is_array($a) || is_array($b)) {
            return json_encode((array) $a) === json_encode((array) $b);
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        if ($a === null && $b === null) {
            return true;
        }

        return (string) $a === (string) $b;
    }

    protected function normalizeShopifyId(mixed $value): ?string
    {
        $string = $this->nullableString($value);
        if ($string === null) {
            return null;
        }

        if (preg_match('#gid://shopify/Product/([^/?]+)#i', $string, $matches) === 1) {
            $extracted = $this->nullableString($matches[1] ?? null);

            return $extracted ?? $string;
        }

        return $string;
    }

    protected function normalizeShopifyVariantId(mixed $value): ?string
    {
        $string = $this->nullableString($value);
        if ($string === null) {
            return null;
        }

        if (preg_match('#gid://shopify/ProductVariant/([^/?]+)#i', $string, $matches) === 1) {
            $extracted = $this->nullableString($matches[1] ?? null);

            return $extracted ?? $string;
        }

        return $string;
    }

    protected function handleFromUrl(string $url): ?string
    {
        if (preg_match('#/products/([^/?#]+)#i', $url, $matches) !== 1) {
            return null;
        }

        return $this->nullableString($matches[1] ?? null);
    }

    protected function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $string = strtolower(trim((string) $value));

        return in_array($string, ['1', 'true', 'yes', 'y'], true);
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        $string = $this->nullableString($value);
        if ($string === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function earliestDate(?CarbonInterface $first, ?CarbonInterface $second): ?CarbonImmutable
    {
        $a = $this->asDate($first);
        $b = $this->asDate($second);

        if (! $a) {
            return $b;
        }

        if (! $b) {
            return $a;
        }

        return $a->lessThan($b) ? $a : $b;
    }

    protected function latestDate(?CarbonInterface $first, ?CarbonInterface $second): ?CarbonImmutable
    {
        $a = $this->asDate($first);
        $b = $this->asDate($second);

        if (! $a) {
            return $b;
        }

        if (! $b) {
            return $a;
        }

        return $a->greaterThan($b) ? $a : $b;
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

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}

