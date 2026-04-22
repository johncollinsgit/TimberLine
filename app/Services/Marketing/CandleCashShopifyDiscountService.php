<?php

namespace App\Services\Marketing;

use App\Models\CandleCashRedemption;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfileLink;
use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantMarketingSettingsResolver;
use Carbon\Carbon;
use RuntimeException;

class CandleCashShopifyDiscountService
{
    protected const LOOKUP_BY_CODE_QUERY = <<<'GRAPHQL'
query CandleCashDiscountByCode($code: String!) {
  codeDiscountNodeByCode(code: $code) {
    id
    codeDiscount {
      __typename
      ... on DiscountCodeBasic {
        title
        startsAt
        endsAt
        combinesWith {
          orderDiscounts
          productDiscounts
          shippingDiscounts
        }
      }
    }
  }
}
GRAPHQL;

    protected const CREATE_BASIC_DISCOUNT_MUTATION = <<<'GRAPHQL'
mutation CandleCashDiscountCodeBasicCreate($basicCodeDiscount: DiscountCodeBasicInput!) {
  discountCodeBasicCreate(basicCodeDiscount: $basicCodeDiscount) {
    codeDiscountNode {
      id
      codeDiscount {
        __typename
        ... on DiscountCodeBasic {
          title
          startsAt
          endsAt
          combinesWith {
            orderDiscounts
            productDiscounts
            shippingDiscounts
          }
        }
      }
    }
    userErrors {
      field
      message
      code
    }
  }
}
GRAPHQL;

    protected const UPDATE_BASIC_DISCOUNT_MUTATION = <<<'GRAPHQL'
mutation CandleCashDiscountCodeBasicUpdate($id: ID!, $basicCodeDiscount: DiscountCodeBasicInput!) {
  discountCodeBasicUpdate(id: $id, basicCodeDiscount: $basicCodeDiscount) {
    codeDiscountNode {
      id
      codeDiscount {
        __typename
        ... on DiscountCodeBasic {
          title
          startsAt
          endsAt
          combinesWith {
            orderDiscounts
            productDiscounts
            shippingDiscounts
          }
        }
      }
    }
    userErrors {
      field
      message
      code
    }
  }
}
GRAPHQL;

    public function __construct(
        protected CandleCashService $candleCashService,
        protected TenantMarketingSettingsResolver $settingsResolver
    ) {
    }

    /**
     * @return array{discount_id:?string,discount_node_id:?string,store_key:string,starts_at:?string,ends_at:?\Carbon\CarbonInterface}
     */
    public function ensureDiscountForRedemption(CandleCashRedemption $redemption, ?string $preferredStoreKey = null): array
    {
        $rewardCode = trim((string) $redemption->redemption_code);
        if ($rewardCode === '') {
            throw new RuntimeException('Reward redemption is missing a reward code.');
        }

        $store = $this->resolveStoreConfig($redemption, $preferredStoreKey);
        if (! $store) {
            throw new RuntimeException('No Shopify store could be resolved for this reward redemption.');
        }

        $client = new ShopifyGraphqlClient(
            trim((string) ($store['shop'] ?? '')),
            trim((string) ($store['token'] ?? '')),
            trim((string) ($store['api_version'] ?? '')) ?: '2026-01'
        );
        $desiredCombinesWith = $this->combinesWithInput($redemption);

        $lookup = $client->query(self::LOOKUP_BY_CODE_QUERY, [
            'code' => $rewardCode,
        ]);

        $existing = $this->discountIdentifiersFromPayload($lookup['codeDiscountNodeByCode'] ?? null);
        if ($existing !== null) {
            if (! $this->combinesWithMatches($existing['combines_with'] ?? null, $desiredCombinesWith)) {
                $existing = $this->updateDiscountCombinesWith(
                    $client,
                    $existing,
                    $desiredCombinesWith
                );
            }

            return [
                'discount_id' => $existing['discount_id'],
                'discount_node_id' => $existing['discount_node_id'],
                'store_key' => (string) ($store['key'] ?? ''),
                'starts_at' => $existing['starts_at'],
                'ends_at' => $existing['ends_at'],
            ];
        }

        $data = $client->query(self::CREATE_BASIC_DISCOUNT_MUTATION, [
            'basicCodeDiscount' => $this->basicDiscountInput($redemption),
        ]);

        $payload = $data['discountCodeBasicCreate'] ?? null;
        if (! is_array($payload)) {
            throw new RuntimeException('Shopify reward discount create response was invalid.');
        }

        $errors = $this->extractUserErrors((array) ($payload['userErrors'] ?? []));
        if ($errors !== []) {
            throw new RuntimeException('Shopify reward discount create failed: ' . implode(' | ', $errors));
        }

        $created = $this->discountIdentifiersFromPayload($payload['codeDiscountNode'] ?? null);
        if ($created === null) {
            throw new RuntimeException('Shopify reward discount create did not return a discount identifier.');
        }

        return [
            'discount_id' => $created['discount_id'],
            'discount_node_id' => $created['discount_node_id'],
            'store_key' => (string) ($store['key'] ?? ''),
            'starts_at' => $created['starts_at'],
            'ends_at' => $created['ends_at'],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function resolveStoreConfig(CandleCashRedemption $redemption, ?string $preferredStoreKey = null): ?array
    {
        $tenantId = $this->tenantIdForRedemption($redemption);
        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }

        $candidates = [];
        $preferredStoreKey = strtolower(trim((string) $preferredStoreKey));
        if ($preferredStoreKey !== '') {
            $candidates[] = $preferredStoreKey;
        }

        $contextStoreKey = strtolower(trim((string) data_get($redemption->redemption_context, 'shopify_store_key', '')));
        if ($contextStoreKey !== '') {
            $candidates[] = $contextStoreKey;
        }

        $platform = strtolower(trim((string) ($redemption->platform ?? '')));
        if ($platform !== '') {
            $candidates[] = $platform;
        }

        $linkedStoreKeys = MarketingProfileLink::query()
            ->forTenantId($tenantId)
            ->where('marketing_profile_id', $redemption->marketing_profile_id)
            ->where('source_type', 'shopify_customer')
            ->pluck('source_id')
            ->map(function ($sourceId): ?string {
                $value = trim((string) $sourceId);
                if (preg_match('/^(retail|wholesale):/i', $value, $matches) === 1) {
                    return strtolower((string) $matches[1]);
                }

                return null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($linkedStoreKeys as $storeKey) {
            $candidates[] = $storeKey;
        }

        $externalStoreKeys = CustomerExternalProfile::query()
            ->forTenantId($tenantId)
            ->where('marketing_profile_id', $redemption->marketing_profile_id)
            ->pluck('store_key')
            ->map(fn ($storeKey): ?string => strtolower(trim((string) $storeKey)) ?: null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($externalStoreKeys as $storeKey) {
            $candidates[] = $storeKey;
        }

        foreach (array_values(array_unique(array_filter($candidates))) as $storeKey) {
            $store = ShopifyStores::find($storeKey);
            if ($store && $this->storeOwnedByTenant($store, $tenantId)) {
                return $store;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    protected function basicDiscountInput(CandleCashRedemption $redemption): array
    {
        $amount = $this->candleCashService->redemptionAmountForIssuedCode($redemption, $redemption->reward);
        if ($amount <= 0) {
            throw new RuntimeException('Reward redemption amount is missing or invalid.');
        }

        return [
            'title' => $this->discountTitle($redemption),
            'code' => (string) $redemption->redemption_code,
            'startsAt' => $this->startsAtForDiscount($redemption)->toIso8601String(),
            'endsAt' => optional($this->endsAtForDiscount($redemption))->toIso8601String(),
            'appliesOncePerCustomer' => true,
            'customerSelection' => ['all' => true],
            'combinesWith' => $this->combinesWithInput($redemption),
            'customerGets' => [
                'items' => ['all' => true],
                'value' => [
                    'discountAmount' => [
                        'amount' => number_format($amount, 2, '.', ''),
                        'appliesOnEachItem' => false,
                    ],
                ],
            ],
        ];
    }

    protected function startsAtForDiscount(CandleCashRedemption $redemption): \Carbon\CarbonInterface
    {
        return $redemption->issued_at ?: now();
    }

    protected function endsAtForDiscount(CandleCashRedemption $redemption): ?\Carbon\CarbonInterface
    {
        return $redemption->expires_at;
    }

    protected function discountTitle(CandleCashRedemption $redemption): string
    {
        return 'Reward Credit Applied';
    }

    /**
     * @param mixed $payload
     * @return array{discount_id:?string,discount_node_id:?string,starts_at:?string,ends_at:?\Carbon\CarbonInterface,combines_with:?array{orderDiscounts:bool,productDiscounts:bool,shippingDiscounts:bool}}|null
     */
    protected function discountIdentifiersFromPayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $discountNodeId = trim((string) ($payload['id'] ?? ''));
        $discount = $payload['codeDiscount'] ?? null;
        if (! is_array($discount)) {
            return $discountNodeId !== '' ? [
                'discount_id' => null,
                'discount_node_id' => $discountNodeId,
                'starts_at' => null,
                'ends_at' => null,
                'combines_with' => null,
            ] : null;
        }

        return [
            'discount_id' => null,
            'discount_node_id' => $discountNodeId !== '' ? $discountNodeId : null,
            'starts_at' => trim((string) ($discount['startsAt'] ?? '')) ?: null,
            'ends_at' => ! empty($discount['endsAt']) ? Carbon::parse((string) $discount['endsAt']) : null,
            'combines_with' => is_array($discount['combinesWith'] ?? null)
                ? $this->normalizedCombinesWith((array) $discount['combinesWith'])
                : null,
        ];
    }

    /**
     * @param array<int,mixed> $errors
     * @return array<int,string>
     */
    protected function extractUserErrors(array $errors): array
    {
        return collect($errors)
            ->map(function ($error): string {
                if (! is_array($error)) {
                    return trim((string) $error);
                }

                return trim((string) ($error['message'] ?? 'unknown_error'));
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{orderDiscounts:bool,productDiscounts:bool,shippingDiscounts:bool}
     */
    protected function combinesWithInput(CandleCashRedemption $redemption): array
    {
        return $this->desiredCombinationStateForTenant($this->tenantIdForRedemption($redemption));
    }

    /**
     * @return array{orderDiscounts:bool,productDiscounts:bool,shippingDiscounts:bool}
     */
    protected function desiredCombinationStateForTenant(?int $tenantId): array
    {
        $default = $this->normalizedCombinesWith([]);
        if ($tenantId === null || $tenantId <= 0) {
            return $default;
        }

        $policy = $this->settingsResolver->array(TenantRewardsPolicyService::POLICY_KEY, $tenantId);
        $redemptionRules = (array) ($policy['redemption_rules'] ?? []);
        $stackingMode = strtolower(trim((string) ($redemptionRules['stacking_mode'] ?? 'no_stacking')));

        return match ($stackingMode) {
            'shipping_only' => [
                'orderDiscounts' => false,
                'productDiscounts' => false,
                'shippingDiscounts' => true,
            ],
            'selected_promo_types' => $this->combinesWithFromSelectedPromoTypes(
                (array) ($redemptionRules['selected_stackable_promo_types'] ?? [])
            ),
            default => $default,
        };
    }

    /**
     * @param array<int,mixed> $selectedPromoTypes
     * @return array{orderDiscounts:bool,productDiscounts:bool,shippingDiscounts:bool}
     */
    protected function combinesWithFromSelectedPromoTypes(array $selectedPromoTypes): array
    {
        $normalized = collect($selectedPromoTypes)
            ->map(function (mixed $value): ?string {
                $normalizedValue = strtolower(trim((string) $value));
                if ($normalizedValue === '') {
                    return null;
                }

                return str_replace([' ', '-'], '_', $normalizedValue);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $orderDiscounts = false;
        $productDiscounts = false;
        $shippingDiscounts = false;

        foreach ($normalized as $promoType) {
            if (in_array($promoType, ['all', 'all_promotions', 'all_promo_types'], true)) {
                $orderDiscounts = true;
                $productDiscounts = true;
                $shippingDiscounts = true;
                break;
            }

            if (in_array($promoType, ['order', 'order_discount', 'order_discounts', 'cart', 'cart_discount', 'cart_discounts'], true)) {
                $orderDiscounts = true;
                continue;
            }

            if (in_array($promoType, ['product', 'product_discount', 'product_discounts', 'line_item', 'line_items', 'item', 'item_discounts'], true)) {
                $productDiscounts = true;
                continue;
            }

            if (in_array($promoType, ['shipping', 'shipping_discount', 'shipping_discounts', 'shipping_rate', 'shipping_rates'], true)) {
                $shippingDiscounts = true;
            }
        }

        return [
            'orderDiscounts' => $orderDiscounts,
            'productDiscounts' => $productDiscounts,
            'shippingDiscounts' => $shippingDiscounts,
        ];
    }

    /**
     * @param array{discount_id:?string,discount_node_id:?string,starts_at:?string,ends_at:?\Carbon\CarbonInterface,combines_with:?array{orderDiscounts:bool,productDiscounts:bool,shippingDiscounts:bool}} $existingDiscount
     * @param array{orderDiscounts:bool,productDiscounts:bool,shippingDiscounts:bool} $desiredCombinesWith
     * @return array{discount_id:?string,discount_node_id:?string,starts_at:?string,ends_at:?\Carbon\CarbonInterface,combines_with:?array{orderDiscounts:bool,productDiscounts:bool,shippingDiscounts:bool}}
     */
    protected function updateDiscountCombinesWith(
        ShopifyGraphqlClient $client,
        array $existingDiscount,
        array $desiredCombinesWith
    ): array {
        $discountNodeId = trim((string) ($existingDiscount['discount_node_id'] ?? ''));
        if ($discountNodeId === '') {
            throw new RuntimeException('Shopify reward discount update failed: missing discount node identifier.');
        }

        $data = $client->query(self::UPDATE_BASIC_DISCOUNT_MUTATION, [
            'id' => $discountNodeId,
            'basicCodeDiscount' => [
                'combinesWith' => $this->normalizedCombinesWith($desiredCombinesWith),
            ],
        ]);

        $payload = $data['discountCodeBasicUpdate'] ?? null;
        if (! is_array($payload)) {
            throw new RuntimeException('Shopify reward discount update response was invalid.');
        }

        $errors = $this->extractUserErrors((array) ($payload['userErrors'] ?? []));
        if ($errors !== []) {
            throw new RuntimeException('Shopify reward discount update failed: ' . implode(' | ', $errors));
        }

        $updated = $this->discountIdentifiersFromPayload($payload['codeDiscountNode'] ?? null);
        if ($updated === null) {
            throw new RuntimeException('Shopify reward discount update did not return a discount identifier.');
        }

        return $updated;
    }

    /**
     * @param mixed $currentCombinesWith
     * @param array{orderDiscounts:bool,productDiscounts:bool,shippingDiscounts:bool} $desiredCombinesWith
     */
    protected function combinesWithMatches(mixed $currentCombinesWith, array $desiredCombinesWith): bool
    {
        return $this->normalizedCombinesWith($currentCombinesWith) === $this->normalizedCombinesWith($desiredCombinesWith);
    }

    /**
     * @param mixed $combinesWith
     * @return array{orderDiscounts:bool,productDiscounts:bool,shippingDiscounts:bool}
     */
    protected function normalizedCombinesWith(mixed $combinesWith): array
    {
        return [
            'orderDiscounts' => (bool) data_get($combinesWith, 'orderDiscounts', false),
            'productDiscounts' => (bool) data_get($combinesWith, 'productDiscounts', false),
            'shippingDiscounts' => (bool) data_get($combinesWith, 'shippingDiscounts', false),
        ];
    }

    protected function tenantIdForRedemption(CandleCashRedemption $redemption): ?int
    {
        $profile = $redemption->relationLoaded('profile')
            ? $redemption->profile
            : $redemption->profile()->first(['id', 'tenant_id']);

        return $profile && is_numeric($profile->tenant_id) && (int) $profile->tenant_id > 0
            ? (int) $profile->tenant_id
            : null;
    }

    /**
     * @param array<string,mixed> $store
     */
    protected function storeOwnedByTenant(array $store, int $tenantId): bool
    {
        $resolvedTenantId = is_numeric($store['tenant_id'] ?? null) ? (int) $store['tenant_id'] : null;
        $storeKey = strtolower(trim((string) ($store['key'] ?? '')));

        if (($resolvedTenantId === null || $resolvedTenantId <= 0) && $storeKey !== '') {
            $resolvedTenantId = (int) (ShopifyStore::query()
                ->where('store_key', $storeKey)
                ->value('tenant_id') ?? 0);
        }

        return $resolvedTenantId !== null && $resolvedTenantId > 0 && $resolvedTenantId === $tenantId;
    }
}
