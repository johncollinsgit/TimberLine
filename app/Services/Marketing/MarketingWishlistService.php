<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileWishlistItem;
use App\Models\MarketingWishlistList;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MarketingWishlistService
{
    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function addItem(?MarketingProfile $profile, array $product, array $options = []): array
    {
        $context = $this->normalizeProductContext($product);
        if ($context['store_key'] === null) {
            throw new InvalidArgumentException('A verified Shopify store context is required for wishlist updates.');
        }
        if ($context['product_id'] === null) {
            throw new InvalidArgumentException('A product id is required for wishlist updates.');
        }

        $owner = $this->resolveOwner($profile, $context, $options, true);
        $list = $owner['list'];
        if (! $list instanceof MarketingWishlistList) {
            throw new InvalidArgumentException('A wishlist owner is required for wishlist updates.');
        }

        $item = $this->findItemForList($list, $context['store_key'], $context['product_id']);
        $attributes = $this->wishlistAttributes($profile, $list, $context, $options);
        $now = now();

        if (! $item) {
            $item = MarketingProfileWishlistItem::query()->create([
                ...$attributes,
                'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
                'added_at' => $now,
                'last_added_at' => $now,
                'removed_at' => null,
            ]);

            $this->touchList($list, $now);

            return [
                'ok' => true,
                'created' => true,
                'restored' => false,
                'state' => 'wishlist_added',
                'item' => $item->fresh(['wishlistList']) ?? $item,
                'list' => $list->fresh(),
                'guest_token' => $owner['guest_token'],
            ];
        }

        if ($item->isActive()) {
            $item->forceFill($attributes);
            if ($item->isDirty()) {
                $item->save();
            }
            $this->touchList($list, $now);

            return [
                'ok' => true,
                'created' => false,
                'restored' => false,
                'state' => 'wishlist_already_saved',
                'item' => $item->fresh(['wishlistList']) ?? $item,
                'list' => $list->fresh(),
                'guest_token' => $owner['guest_token'],
            ];
        }

        $item->forceFill([
            ...$attributes,
            'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
            'last_added_at' => $now,
            'removed_at' => null,
        ])->save();
        $this->touchList($list, $now);

        return [
            'ok' => true,
            'created' => false,
            'restored' => true,
            'state' => 'wishlist_restored',
            'item' => $item->fresh(['wishlistList']) ?? $item,
            'list' => $list->fresh(),
            'guest_token' => $owner['guest_token'],
        ];
    }

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function removeItem(?MarketingProfile $profile, array $product, array $options = []): array
    {
        $context = $this->normalizeProductContext($product);
        if ($context['store_key'] === null) {
            throw new InvalidArgumentException('A verified Shopify store context is required for wishlist updates.');
        }
        if ($context['product_id'] === null) {
            throw new InvalidArgumentException('A product id is required for wishlist updates.');
        }

        $owner = $this->resolveOwner($profile, $context, $options, false);
        $list = $owner['list'];

        if ($list instanceof MarketingWishlistList) {
            $item = $this->findItemForList($list, $context['store_key'], $context['product_id']);
        } else {
            $item = $this->findAnyOwnerItem($profile, $owner['guest_token'], $context['store_key'], $context['product_id'], $context['tenant_id']);
        }

        if (! $item) {
            return [
                'ok' => true,
                'removed' => false,
                'state' => 'wishlist_already_cleared',
                'item' => null,
                'guest_token' => $owner['guest_token'],
            ];
        }

        if (! $item->isActive()) {
            return [
                'ok' => true,
                'removed' => false,
                'state' => 'wishlist_already_cleared',
                'item' => $item,
                'guest_token' => $owner['guest_token'],
            ];
        }

        $item->forceFill([
            'status' => MarketingProfileWishlistItem::STATUS_REMOVED,
            'removed_at' => now(),
        ])->save();

        if ($item->wishlistList) {
            $this->touchList($item->wishlistList, now());
        }

        return [
            'ok' => true,
            'removed' => true,
            'state' => 'wishlist_removed',
            'item' => $item->fresh(['wishlistList']) ?? $item,
            'guest_token' => $owner['guest_token'],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $options
     */
    public function createList(?MarketingProfile $profile, array $context = [], array $options = []): MarketingWishlistList
    {
        $normalized = $this->normalizeProductContext($context);
        $tenantId = $this->tenantId($profile, $normalized['tenant_id'] ?? null);
        $guestToken = $this->nullableString($options['guest_token'] ?? $context['guest_token'] ?? null);
        $name = $this->listName($options['name'] ?? $context['name'] ?? null, false);

        if ($profile === null && $guestToken === null) {
            throw new InvalidArgumentException('A guest token or marketing profile is required before creating a wishlist list.');
        }

        $query = MarketingWishlistList::query()
            ->forTenantId($tenantId)
            ->when($profile !== null, fn (Builder $builder) => $builder->where('marketing_profile_id', $profile->id))
            ->when($profile === null && $guestToken !== null, fn (Builder $builder) => $builder->whereNull('marketing_profile_id')->where('guest_token', $guestToken))
            ->where('name', $name);

        $existing = $query->first();
        if ($existing) {
            return $existing;
        }

        return MarketingWishlistList::query()->create([
            'tenant_id' => $tenantId,
            'marketing_profile_id' => $profile?->id,
            'guest_token' => $profile ? null : $guestToken,
            'store_key' => $normalized['store_key'],
            'name' => $name,
            'is_default' => false,
            'status' => MarketingWishlistList::STATUS_ACTIVE,
            'source' => $this->nullableString($options['source'] ?? null) ?? 'native_storefront',
            'last_activity_at' => now(),
            'metadata' => is_array($options['metadata'] ?? null) ? $options['metadata'] : null,
        ]);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function mergeGuestWishlist(MarketingProfile $profile, string $guestToken, array $context = []): array
    {
        $normalized = $this->normalizeProductContext($context);
        $tenantId = $this->tenantId($profile, $normalized['tenant_id'] ?? null);
        $guestToken = trim($guestToken);

        if ($guestToken === '') {
            return [
                'merged_lists' => 0,
                'merged_items' => 0,
            ];
        }

        $guestLists = MarketingWishlistList::query()
            ->forTenantId($tenantId)
            ->whereNull('marketing_profile_id')
            ->where('guest_token', $guestToken)
            ->with('items')
            ->get();

        if ($guestLists->isEmpty()) {
            return [
                'merged_lists' => 0,
                'merged_items' => 0,
            ];
        }

        $mergedLists = 0;
        $mergedItems = 0;

        foreach ($guestLists as $guestList) {
            $targetList = $guestList->is_default
                ? $this->ensureDefaultList($profile, $guestToken, $normalized['store_key'], $tenantId)
                : $this->resolveNamedList($profile, null, $guestList->name, $normalized['store_key'], $tenantId, true);

            foreach ($guestList->items as $guestItem) {
                $existing = $this->findItemForList($targetList, (string) $guestItem->store_key, (string) $guestItem->product_id);

                if ($existing) {
                    if (! $existing->isActive() && $guestItem->isActive()) {
                        $existing->forceFill([
                            'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
                            'removed_at' => null,
                            'last_added_at' => $guestItem->last_added_at ?: $guestItem->added_at ?: now(),
                        ])->save();
                    }

                    $guestItem->delete();
                    continue;
                }

                $guestItem->forceFill([
                    'marketing_profile_id' => $profile->id,
                    'wishlist_list_id' => $targetList->id,
                    'guest_token' => null,
                ])->save();
                $mergedItems++;
            }

            $guestList->delete();
            $mergedLists++;
        }

        return [
            'merged_lists' => $mergedLists,
            'merged_items' => $mergedItems,
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
        $guestToken = $this->nullableString($context['guest_token'] ?? null);

        if ($profile !== null && $guestToken !== null) {
            $this->mergeGuestWishlist($profile, $guestToken, $context);
        }

        $tenantId = $this->tenantId($profile, $product['tenant_id'] ?? null);

        if (! $profile && $guestToken === null) {
            return [
                'guest_token' => null,
                'viewer' => [
                    'profile_id' => null,
                    'state' => 'guest_ready',
                    'identity_status' => $identityStatus,
                    'guest_token' => null,
                ],
                'summary' => $this->emptySummary($product['store_key']),
                'product' => $this->productPayload($product, null, null),
                'active_list' => null,
                'default_list' => null,
                'lists' => [],
                'items' => [],
                'recent_items' => [],
            ];
        }

        $defaultList = $this->ensureDefaultList($profile, $guestToken, $product['store_key'], $tenantId);
        $this->attachLegacyItemsToDefaultList($profile, $guestToken, $tenantId, $defaultList);

        $lists = $this->listsForOwner($profile, $guestToken, $tenantId, $product['store_key']);
        $activeListId = $this->positiveInt($context['wishlist_list_id'] ?? null);
        $activeList = $activeListId
            ? $lists->firstWhere('id', $activeListId)
            : $defaultList;
        $activeList = $activeList instanceof MarketingWishlistList ? $activeList : $defaultList;

        $items = $this->queryForOwner($profile, $guestToken, [
            'tenant_id' => $tenantId,
            'store_key' => $product['store_key'],
            'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
            'wishlist_list_id' => $activeList?->id,
        ])->limit($limit)->get();

        $recentItems = $this->queryForOwner($profile, $guestToken, [
            'tenant_id' => $tenantId,
            'store_key' => $product['store_key'],
            'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
        ])->limit(min($limit, 6))->get();

        $summary = $this->summaryForOwner($profile, $guestToken, [
            'tenant_id' => $tenantId,
            'store_key' => $product['store_key'],
        ]);

        $productItem = $product['product_id'] !== null
            ? $this->findAnyOwnerItem($profile, $guestToken, $product['store_key'], $product['product_id'], $tenantId)
            : null;

        return [
            'guest_token' => $guestToken,
            'viewer' => [
                'profile_id' => $profile ? (int) $profile->id : null,
                'state' => $summary['active_count'] > 0 ? 'wishlist_ready' : 'wishlist_empty',
                'identity_status' => $identityStatus !== '' ? $identityStatus : ($profile ? 'resolved' : 'guest_token'),
                'guest_token' => $guestToken,
            ],
            'summary' => $summary,
            'product' => $this->productPayload($product, $productItem, $activeList),
            'active_list' => $activeList ? $this->listPayload($activeList) : null,
            'default_list' => $defaultList ? $this->listPayload($defaultList) : null,
            'lists' => $lists->map(fn (MarketingWishlistList $list): array => $this->listPayload($list, [
                'item_count' => (int) $this->queryForOwner($profile, $guestToken, [
                    'tenant_id' => $tenantId,
                    'wishlist_list_id' => $list->id,
                    'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
                ])->count(),
            ]))->values()->all(),
            'items' => $items->map(fn (MarketingProfileWishlistItem $item): array => $this->itemPayload($item))->values()->all(),
            'recent_items' => $recentItems->map(fn (MarketingProfileWishlistItem $item): array => $this->itemPayload($item))->values()->all(),
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
            'lists' => $this->listsForOwner($profile, null, $this->tenantId($profile), null),
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
        return $this->summaryForOwner($profile, null, [
            ...$filters,
            'tenant_id' => $this->tenantId($profile, $filters['tenant_id'] ?? null),
        ]);
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,MarketingProfileWishlistItem>
     */
    public function listWishlistItems(MarketingProfile $profile, array $filters = []): Collection
    {
        $query = $this->queryForOwner($profile, null, [
            ...$filters,
            'tenant_id' => $this->tenantId($profile, $filters['tenant_id'] ?? null),
        ]);
        $limit = $this->boundedLimit($filters['limit'] ?? 25);

        return $query->limit($limit)->get();
    }

    /**
     * @return array<string,mixed>
     */
    public function itemPayload(MarketingProfileWishlistItem $item): array
    {
        $list = $item->relationLoaded('wishlistList') ? $item->wishlistList : $item->wishlistList()->first();

        return [
            'id' => (int) $item->id,
            'wishlist_list_id' => $item->wishlist_list_id ? (int) $item->wishlist_list_id : null,
            'wishlist_list_name' => $list?->name ? (string) $list->name : null,
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
            'guest_token' => $item->guest_token ? (string) $item->guest_token : null,
            'added_at' => $this->dateTimeString($item->added_at),
            'last_added_at' => $this->dateTimeString($item->last_added_at),
            'removed_at' => $this->dateTimeString($item->removed_at),
            'source_synced_at' => $this->dateTimeString($item->source_synced_at),
        ];
    }

    /**
     * @param array<string,mixed>|null $summary
     * @return array<string,mixed>
     */
    public function listPayload(MarketingWishlistList $list, ?array $summary = null): array
    {
        return [
            'id' => (int) $list->id,
            'name' => (string) $list->name,
            'is_default' => (bool) $list->is_default,
            'status' => (string) $list->status,
            'store_key' => $list->store_key ? (string) $list->store_key : null,
            'item_count' => (int) ($summary['item_count'] ?? 0),
            'last_activity_at' => $this->dateTimeString($list->last_activity_at),
        ];
    }

    protected function resolveOwner(?MarketingProfile $profile, array $product, array $options, bool $createIfMissing): array
    {
        $guestToken = $this->nullableString($options['guest_token'] ?? $product['guest_token'] ?? null);
        $tenantId = $this->tenantId($profile, $product['tenant_id'] ?? null);

        if ($profile === null && $guestToken === null) {
            throw new InvalidArgumentException('A guest token or customer identity is required for wishlist updates.');
        }

        if ($profile !== null && $guestToken !== null) {
            $this->mergeGuestWishlist($profile, $guestToken, $product);
        }

        $listId = $this->positiveInt($options['wishlist_list_id'] ?? $product['wishlist_list_id'] ?? null);
        $listName = $this->nullableString($options['list_name'] ?? $product['list_name'] ?? null);
        $storeKey = $product['store_key'];

        $list = null;
        if ($listId !== null) {
            $list = MarketingWishlistList::query()
                ->forTenantId($tenantId)
                ->whereKey($listId)
                ->first();
        }

        if (! $list && $listName !== null) {
            $list = $this->resolveNamedList($profile, $guestToken, $listName, $storeKey, $tenantId, $createIfMissing);
        }

        if (! $list && $createIfMissing) {
            $list = $this->ensureDefaultList($profile, $guestToken, $storeKey, $tenantId);
        }

        return [
            'tenant_id' => $tenantId,
            'guest_token' => $guestToken,
            'list' => $list,
        ];
    }

    protected function ensureDefaultList(?MarketingProfile $profile, ?string $guestToken, ?string $storeKey, ?int $tenantId): MarketingWishlistList
    {
        $query = MarketingWishlistList::query()
            ->forTenantId($tenantId)
            ->where('is_default', true)
            ->when($storeKey !== null, fn (Builder $builder) => $builder->where(function (Builder $query) use ($storeKey): void {
                $query->whereNull('store_key')->orWhere('store_key', $storeKey);
            }))
            ->when($profile !== null, fn (Builder $builder) => $builder->where('marketing_profile_id', $profile->id))
            ->when($profile === null && $guestToken !== null, fn (Builder $builder) => $builder->whereNull('marketing_profile_id')->where('guest_token', $guestToken));

        $existing = $query->orderByDesc('id')->first();
        if ($existing) {
            if ($storeKey !== null && $existing->store_key === null) {
                $existing->forceFill(['store_key' => $storeKey])->save();
            }

            return $existing;
        }

        return MarketingWishlistList::query()->create([
            'tenant_id' => $tenantId,
            'marketing_profile_id' => $profile?->id,
            'guest_token' => $profile ? null : $guestToken,
            'store_key' => $storeKey,
            'name' => $this->listName(null, true),
            'is_default' => true,
            'status' => MarketingWishlistList::STATUS_ACTIVE,
            'source' => 'native_storefront',
            'last_activity_at' => now(),
        ]);
    }

    protected function resolveNamedList(
        ?MarketingProfile $profile,
        ?string $guestToken,
        string $name,
        ?string $storeKey,
        ?int $tenantId,
        bool $createIfMissing
    ): ?MarketingWishlistList {
        $query = MarketingWishlistList::query()
            ->forTenantId($tenantId)
            ->when($profile !== null, fn (Builder $builder) => $builder->where('marketing_profile_id', $profile->id))
            ->when($profile === null && $guestToken !== null, fn (Builder $builder) => $builder->whereNull('marketing_profile_id')->where('guest_token', $guestToken))
            ->where('name', $this->listName($name, false));

        $existing = $query->first();
        if ($existing) {
            return $existing;
        }

        if (! $createIfMissing) {
            return null;
        }

        return MarketingWishlistList::query()->create([
            'tenant_id' => $tenantId,
            'marketing_profile_id' => $profile?->id,
            'guest_token' => $profile ? null : $guestToken,
            'store_key' => $storeKey,
            'name' => $this->listName($name, false),
            'is_default' => false,
            'status' => MarketingWishlistList::STATUS_ACTIVE,
            'source' => 'native_storefront',
            'last_activity_at' => now(),
        ]);
    }

    protected function attachLegacyItemsToDefaultList(
        ?MarketingProfile $profile,
        ?string $guestToken,
        ?int $tenantId,
        MarketingWishlistList $defaultList
    ): void {
        $query = MarketingProfileWishlistItem::query()
            ->forTenantId($tenantId)
            ->whereNull('wishlist_list_id')
            ->when($profile !== null, fn (Builder $builder) => $builder->where('marketing_profile_id', $profile->id))
            ->when($profile === null && $guestToken !== null, fn (Builder $builder) => $builder->whereNull('marketing_profile_id')->where('guest_token', $guestToken));

        $query->update([
            'wishlist_list_id' => $defaultList->id,
            'guest_token' => $profile ? null : $guestToken,
        ]);
    }

    protected function listsForOwner(?MarketingProfile $profile, ?string $guestToken, ?int $tenantId, ?string $storeKey): Collection
    {
        return MarketingWishlistList::query()
            ->forTenantId($tenantId)
            ->when($profile !== null, fn (Builder $builder) => $builder->where('marketing_profile_id', $profile->id))
            ->when($profile === null && $guestToken !== null, fn (Builder $builder) => $builder->whereNull('marketing_profile_id')->where('guest_token', $guestToken))
            ->when($storeKey !== null, fn (Builder $builder) => $builder->where(function (Builder $query) use ($storeKey): void {
                $query->whereNull('store_key')->orWhere('store_key', $storeKey);
            }))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    protected function findItemForList(MarketingWishlistList $list, ?string $storeKey, ?string $productId): ?MarketingProfileWishlistItem
    {
        if ($storeKey === null || $productId === null) {
            return null;
        }

        return MarketingProfileWishlistItem::query()
            ->where('wishlist_list_id', $list->id)
            ->where('store_key', $storeKey)
            ->where('product_id', $productId)
            ->first();
    }

    protected function findAnyOwnerItem(
        ?MarketingProfile $profile,
        ?string $guestToken,
        ?string $storeKey,
        ?string $productId,
        ?int $tenantId
    ): ?MarketingProfileWishlistItem {
        if ($storeKey === null || $productId === null) {
            return null;
        }

        return $this->queryForOwner($profile, $guestToken, [
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'product_id' => $productId,
        ])->first();
    }

    /**
     * @param array<string,mixed> $filters
     */
    protected function queryForOwner(?MarketingProfile $profile, ?string $guestToken, array $filters = []): Builder
    {
        $tenantId = is_numeric($filters['tenant_id'] ?? null) ? (int) $filters['tenant_id'] : null;
        $query = MarketingProfileWishlistItem::query()
            ->when($tenantId !== null, fn (Builder $builder) => $builder->forTenantId($tenantId))
            ->where(function (Builder $builder) use ($profile, $guestToken): void {
                if ($profile !== null) {
                    $builder->where('marketing_profile_id', $profile->id);

                    return;
                }

                $builder->whereNull('marketing_profile_id')
                    ->where('guest_token', $guestToken);
            });

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

        $listId = $this->positiveInt($filters['wishlist_list_id'] ?? null);
        if ($listId !== null) {
            $query->where('wishlist_list_id', $listId);
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
            ->with('wishlistList')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(last_added_at, added_at, removed_at, source_synced_at, updated_at, created_at) DESC')
            ->orderByDesc('id');
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    protected function summaryForOwner(?MarketingProfile $profile, ?string $guestToken, array $filters = []): array
    {
        $tenantId = is_numeric($filters['tenant_id'] ?? null) ? (int) $filters['tenant_id'] : null;
        $baseQuery = $this->queryForOwner($profile, $guestToken, $filters)->reorder();
        $activeQuery = (clone $baseQuery)->where('status', MarketingProfileWishlistItem::STATUS_ACTIVE);
        $removedQuery = (clone $baseQuery)->where('status', MarketingProfileWishlistItem::STATUS_REMOVED);
        $activeItems = (clone $activeQuery)->get([
            'product_id',
            'product_handle',
            'product_title',
            'store_key',
            'added_at',
            'last_added_at',
        ]);
        $removedItems = (clone $removedQuery)->get([
            'removed_at',
        ]);
        $lists = $this->listsForOwner($profile, $guestToken, $tenantId, $this->nullableString($filters['store_key'] ?? null));

        return [
            'store_key' => $this->nullableString($filters['store_key'] ?? null),
            'total_count' => (int) (clone $baseQuery)->count(),
            'active_count' => (int) (clone $activeQuery)->count(),
            'removed_count' => (int) (clone $removedQuery)->count(),
            'list_count' => (int) $lists->count(),
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

    protected function touchList(MarketingWishlistList $list, CarbonInterface $timestamp): void
    {
        $list->forceFill([
            'last_activity_at' => $timestamp,
        ])->save();
    }

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    protected function wishlistAttributes(?MarketingProfile $profile, MarketingWishlistList $list, array $product, array $options): array
    {
        return [
            'tenant_id' => $this->tenantId($profile, $product['tenant_id'] ?? $list->tenant_id),
            'marketing_profile_id' => $profile?->id,
            'wishlist_list_id' => $list->id,
            'guest_token' => $profile ? null : $list->guest_token,
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
     * @return array{product_id:?string,product_variant_id:?string,product_handle:?string,product_title:?string,product_url:?string,store_key:?string,tenant_id:?int,guest_token:?string,wishlist_list_id:?int,list_name:?string}
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
            'guest_token' => $this->nullableString($context['guest_token'] ?? null),
            'wishlist_list_id' => $this->positiveInt($context['wishlist_list_id'] ?? null),
            'list_name' => $this->nullableString($context['list_name'] ?? null),
        ];
    }

    /**
     * @param array{product_id:?string,product_variant_id:?string,product_handle:?string,product_title:?string,product_url:?string,store_key:?string,tenant_id:?int,guest_token:?string,wishlist_list_id:?int,list_name:?string} $product
     * @return array<string,mixed>
     */
    protected function productPayload(array $product, ?MarketingProfileWishlistItem $item, ?MarketingWishlistList $activeList): array
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
            'wishlist_list_id' => $item?->isActive()
                ? ($item->wishlist_list_id ? (int) $item->wishlist_list_id : null)
                : ($activeList?->id),
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
            'list_count' => 0,
            'last_added_at' => null,
            'last_removed_at' => null,
            'recent_additions_30d' => 0,
            'active_product_ids' => [],
            'active_product_handles' => [],
            'active_product_titles' => [],
            'active_store_keys' => $storeKey ? [$storeKey] : [],
        ];
    }

    protected function listName(mixed $value, bool $isDefault): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $isDefault ? 'Saved Items' : 'My Wishlist';
        }

        return Str::limit($value, 160, '');
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

    protected function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function tenantId(?MarketingProfile $profile, mixed $candidate = null): ?int
    {
        $tenantId = is_numeric($candidate) ? (int) $candidate : 0;
        if ($tenantId > 0) {
            return $tenantId;
        }

        $profileTenantId = (int) ($profile?->tenant_id ?? 0);

        return $profileTenantId > 0 ? $profileTenantId : null;
    }

    protected function dateTimeString(?CarbonInterface $value): ?string
    {
        return $value?->toDateTimeString();
    }
}
