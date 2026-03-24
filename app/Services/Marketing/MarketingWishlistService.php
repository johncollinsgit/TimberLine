<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileWishlistItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MarketingWishlistService
{
    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function addItem(MarketingProfile $profile, array $product, array $options = []): array
    {
        $context = $this->normalizeProductContext($product);
        if ($context['store_key'] === null) {
            throw new InvalidArgumentException('A verified Shopify store context is required for wishlist updates.');
        }
        if ($context['product_id'] === null) {
            throw new InvalidArgumentException('A product id is required for wishlist updates.');
        }

        $item = $this->findItem($profile, $context['store_key'], $context['product_id']);
        $attributes = $this->wishlistAttributes($profile, $context, $options);
        $now = now();

        if (! $item) {
            $item = MarketingProfileWishlistItem::query()->create([
                ...$attributes,
                'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
                'added_at' => $now,
                'last_added_at' => $now,
                'removed_at' => null,
            ]);

            return [
                'ok' => true,
                'created' => true,
                'restored' => false,
                'state' => 'wishlist_added',
                'item' => $item->fresh() ?? $item,
            ];
        }

        if ($item->isActive()) {
            $item->forceFill($attributes);
            if ($item->isDirty()) {
                $item->save();
            }

            return [
                'ok' => true,
                'created' => false,
                'restored' => false,
                'state' => 'wishlist_already_saved',
                'item' => $item->fresh() ?? $item,
            ];
        }

        $item->forceFill([
            ...$attributes,
            'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
            'last_added_at' => $now,
            'removed_at' => null,
        ])->save();

        return [
            'ok' => true,
            'created' => false,
            'restored' => true,
            'state' => 'wishlist_restored',
            'item' => $item->fresh() ?? $item,
        ];
    }

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function removeItem(MarketingProfile $profile, array $product, array $options = []): array
    {
        $context = $this->normalizeProductContext($product);
        if ($context['store_key'] === null) {
            throw new InvalidArgumentException('A verified Shopify store context is required for wishlist updates.');
        }
        if ($context['product_id'] === null) {
            throw new InvalidArgumentException('A product id is required for wishlist updates.');
        }

        $item = $this->findItem($profile, $context['store_key'], $context['product_id']);
        if (! $item) {
            return [
                'ok' => true,
                'removed' => false,
                'state' => 'wishlist_already_cleared',
                'item' => null,
            ];
        }

        if (! $item->isActive()) {
            return [
                'ok' => true,
                'removed' => false,
                'state' => 'wishlist_already_cleared',
                'item' => $item,
            ];
        }

        $item->forceFill([
            'status' => MarketingProfileWishlistItem::STATUS_REMOVED,
            'removed_at' => now(),
        ])->save();

        return [
            'ok' => true,
            'removed' => true,
            'state' => 'wishlist_removed',
            'item' => $item->fresh() ?? $item,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function storefrontPayload(?MarketingProfile $profile, array $context = []): array
    {
        $product = $this->normalizeProductContext($context);
        $limit = $this->boundedLimit($context['limit'] ?? 24);
        $identityStatus = trim((string) ($context['identity_status'] ?? 'missing_identity'));

        if (! $profile) {
            return [
                'viewer' => [
                    'profile_id' => null,
                    'state' => 'guest_ready',
                    'identity_status' => $identityStatus,
                ],
                'summary' => $this->emptySummary($product['store_key']),
                'product' => $this->productPayload($product, null),
                'items' => [],
                'recent_items' => [],
            ];
        }

        $items = $this->listWishlistItems($profile, [
            'store_key' => $product['store_key'],
            'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
            'limit' => $limit,
        ]);
        $summary = $this->summaryForProfile($profile, [
            'store_key' => $product['store_key'],
        ]);
        $productItem = $product['product_id'] !== null
            ? $this->findItem($profile, $product['store_key'], $product['product_id'])
            : null;

        return [
            'viewer' => [
                'profile_id' => (int) $profile->id,
                'state' => $summary['active_count'] > 0 ? 'wishlist_ready' : 'wishlist_empty',
                'identity_status' => $identityStatus !== '' ? $identityStatus : 'resolved',
            ],
            'summary' => $summary,
            'product' => $this->productPayload($product, $productItem),
            'items' => $items->map(fn (MarketingProfileWishlistItem $item): array => $this->itemPayload($item))->values()->all(),
            'recent_items' => $items->take(6)->map(fn (MarketingProfileWishlistItem $item): array => $this->itemPayload($item))->values()->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function backstagePayload(MarketingProfile $profile, int $limit = 25): array
    {
        $nativeItems = $this->listWishlistItems($profile, [
            'native_only' => true,
            'limit' => $limit,
        ]);
        $legacyItems = $this->listWishlistItems($profile, [
            'legacy_only' => true,
            'limit' => $limit,
        ]);
        $nativeSummary = $this->summaryForProfile($profile, ['native_only' => true]);
        $legacySummary = $this->summaryForProfile($profile, ['legacy_only' => true]);

        $preferredDataSource = $nativeSummary['total_count'] > 0
            ? 'native'
            : ($legacySummary['total_count'] > 0 ? 'legacy' : 'none');

        return [
            'native_items' => $nativeItems,
            'legacy_items' => $legacyItems,
            'native_summary' => $nativeSummary,
            'legacy_summary' => $legacySummary,
            'preferred_data_source' => $preferredDataSource,
            'preferred_summary' => $preferredDataSource === 'legacy' ? $legacySummary : $nativeSummary,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function summaryForProfile(MarketingProfile $profile, array $filters = []): array
    {
        $items = $this->queryForProfile($profile, $filters)->get();
        $activeItems = $items->where('status', MarketingProfileWishlistItem::STATUS_ACTIVE)->values();
        $removedItems = $items->where('status', MarketingProfileWishlistItem::STATUS_REMOVED)->values();

        return [
            'store_key' => $this->nullableString($filters['store_key'] ?? null),
            'total_count' => (int) $items->count(),
            'active_count' => (int) $activeItems->count(),
            'removed_count' => (int) $removedItems->count(),
            'last_added_at' => $this->dateTimeString(
                $activeItems
                    ->map(fn (MarketingProfileWishlistItem $item): ?CarbonInterface => $item->last_added_at ?: $item->added_at)
                    ->filter()
                    ->sortByDesc(fn (CarbonInterface $value): int => (int) $value->timestamp)
                    ->first()
            ),
            'last_removed_at' => $this->dateTimeString(
                $removedItems
                    ->map(fn (MarketingProfileWishlistItem $item): ?CarbonInterface => $item->removed_at)
                    ->filter()
                    ->sortByDesc(fn (CarbonInterface $value): int => (int) $value->timestamp)
                    ->first()
            ),
            'recent_additions_30d' => (int) $activeItems
                ->filter(function (MarketingProfileWishlistItem $item): bool {
                    $addedAt = $item->last_added_at ?: $item->added_at;

                    return $addedAt !== null && $addedAt->greaterThanOrEqualTo(now()->subDays(30));
                })
                ->count(),
            'active_product_ids' => $activeItems->pluck('product_id')->filter()->unique()->values()->all(),
            'active_product_handles' => $activeItems->pluck('product_handle')->filter()->unique()->values()->all(),
            'active_product_titles' => $activeItems->pluck('product_title')->filter()->unique()->values()->all(),
            'active_store_keys' => $activeItems->pluck('store_key')->filter()->unique()->values()->all(),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,MarketingProfileWishlistItem>
     */
    public function listWishlistItems(MarketingProfile $profile, array $filters = []): Collection
    {
        $query = $this->queryForProfile($profile, $filters);
        $limit = $this->boundedLimit($filters['limit'] ?? 25);

        return $query->limit($limit)->get();
    }

    /**
     * @return array<string,mixed>
     */
    public function itemPayload(MarketingProfileWishlistItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'product_id' => (string) $item->product_id,
            'product_variant_id' => $item->product_variant_id ? (string) $item->product_variant_id : null,
            'product_handle' => $item->product_handle ? (string) $item->product_handle : null,
            'product_title' => $item->product_title ? (string) $item->product_title : null,
            'product_url' => $item->product_url ? (string) $item->product_url : null,
            'status' => (string) $item->status,
            'store_key' => (string) $item->store_key,
            'provider' => (string) $item->provider,
            'integration' => (string) $item->integration,
            'source' => $item->source ? (string) $item->source : null,
            'source_surface' => $item->source_surface ? (string) $item->source_surface : null,
            'added_at' => $this->dateTimeString($item->added_at),
            'last_added_at' => $this->dateTimeString($item->last_added_at),
            'removed_at' => $this->dateTimeString($item->removed_at),
            'source_synced_at' => $this->dateTimeString($item->source_synced_at),
        ];
    }

    protected function findItem(MarketingProfile $profile, ?string $storeKey, ?string $productId): ?MarketingProfileWishlistItem
    {
        if ($storeKey === null || $productId === null) {
            return null;
        }

        return MarketingProfileWishlistItem::query()
            ->forTenantId($this->tenantId($profile))
            ->where('marketing_profile_id', $profile->id)
            ->where('store_key', $storeKey)
            ->where('product_id', $productId)
            ->first();
    }

    /**
     * @param array<string,mixed> $filters
     */
    protected function queryForProfile(MarketingProfile $profile, array $filters = []): Builder
    {
        $query = MarketingProfileWishlistItem::query()
            ->forTenantId($this->tenantId($profile))
            ->where('marketing_profile_id', $profile->id);

        $storeKey = $this->nullableString($filters['store_key'] ?? null);
        if ($storeKey !== null) {
            $query->where('store_key', $storeKey);
        }

        $productId = $this->nullableString($filters['product_id'] ?? null);
        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        $status = $this->nullableString($filters['status'] ?? null);
        if ($status !== null) {
            $query->where('status', $status);
        }

        if ((bool) ($filters['native_only'] ?? false)) {
            $query->where('provider', 'backstage')->where('integration', 'native');
        }

        if ((bool) ($filters['legacy_only'] ?? false)) {
            $query->where(function (Builder $builder): void {
                $builder->where('provider', '!=', 'backstage')
                    ->orWhere('integration', '!=', 'native');
            });
        }

        return $query
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(last_added_at, added_at, removed_at, source_synced_at, updated_at, created_at) DESC')
            ->orderByDesc('id');
    }

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    protected function wishlistAttributes(MarketingProfile $profile, array $product, array $options): array
    {
        return [
            'tenant_id' => $this->tenantId($profile, $product['tenant_id'] ?? null),
            'marketing_profile_id' => (int) $profile->id,
            'provider' => $this->nullableString($options['provider'] ?? null) ?? 'backstage',
            'integration' => $this->nullableString($options['integration'] ?? null) ?? 'native',
            'store_key' => $product['store_key'],
            'product_id' => $product['product_id'],
            'product_variant_id' => $product['product_variant_id'],
            'product_handle' => $product['product_handle'],
            'product_title' => $product['product_title'],
            'product_url' => $product['product_url'],
            'source' => $this->nullableString($options['source'] ?? null) ?? 'native_storefront',
            'source_surface' => $this->nullableString($options['source_surface'] ?? null),
            'source_ref' => $this->nullableString($options['source_ref'] ?? ($options['request_key'] ?? null)),
            'source_synced_at' => $options['source_synced_at'] ?? null,
            'raw_payload' => is_array($options['raw_payload'] ?? null) ? $options['raw_payload'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array{product_id:?string,product_variant_id:?string,product_handle:?string,product_title:?string,product_url:?string,store_key:?string,tenant_id:?int}
     */
    protected function normalizeProductContext(array $context): array
    {
        $tenantId = is_numeric($context['tenant_id'] ?? null) && (int) ($context['tenant_id'] ?? 0) > 0
            ? (int) $context['tenant_id']
            : null;

        return [
            'product_id' => $this->nullableString($context['product_id'] ?? null),
            'product_variant_id' => $this->nullableString($context['product_variant_id'] ?? null),
            'product_handle' => $this->nullableString($context['product_handle'] ?? null),
            'product_title' => $this->nullableString($context['product_title'] ?? null),
            'product_url' => $this->nullableString($context['product_url'] ?? null),
            'store_key' => $this->nullableString($context['store_key'] ?? null),
            'tenant_id' => $tenantId,
        ];
    }

    /**
     * @param array{product_id:?string,product_variant_id:?string,product_handle:?string,product_title:?string,product_url:?string,store_key:?string,tenant_id:?int} $product
     * @return array<string,mixed>
     */
    protected function productPayload(array $product, ?MarketingProfileWishlistItem $item): array
    {
        return [
            'id' => $product['product_id'],
            'variant_id' => $product['product_variant_id'],
            'handle' => $product['product_handle'],
            'title' => $product['product_title'],
            'url' => $product['product_url'],
            'store_key' => $product['store_key'],
            'in_wishlist' => $item?->isActive() ?? false,
            'wishlist_item_id' => $item?->isActive() ? (int) $item->id : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function emptySummary(?string $storeKey = null): array
    {
        return [
            'store_key' => $storeKey,
            'total_count' => 0,
            'active_count' => 0,
            'removed_count' => 0,
            'last_added_at' => null,
            'last_removed_at' => null,
            'recent_additions_30d' => 0,
            'active_product_ids' => [],
            'active_product_handles' => [],
            'active_product_titles' => [],
            'active_store_keys' => $storeKey ? [$storeKey] : [],
        ];
    }

    protected function boundedLimit(mixed $value): int
    {
        $limit = (int) $value;
        if ($limit <= 0) {
            return 25;
        }

        return min($limit, 100);
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function tenantId(MarketingProfile $profile, mixed $candidate = null): ?int
    {
        $tenantId = is_numeric($candidate) ? (int) $candidate : 0;
        if ($tenantId > 0) {
            return $tenantId;
        }

        $profileTenantId = (int) ($profile->tenant_id ?? 0);

        return $profileTenantId > 0 ? $profileTenantId : null;
    }

    protected function dateTimeString(?CarbonInterface $value): ?string
    {
        return $value?->toDateTimeString();
    }
}
