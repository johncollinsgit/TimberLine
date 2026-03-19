<?php

namespace App\Services\Marketing;

use App\Models\CandleCashRedemption;
use App\Models\MarketingProfileLink;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
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
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @return array{discount_id:?string,discount_node_id:?string,store_key:string,starts_at:?string,ends_at:?\Carbon\CarbonInterface}
     */
    public function ensureDiscountForRedemption(CandleCashRedemption $redemption, ?string $preferredStoreKey = null): array
    {
        $rewardCode = trim((string) $redemption->redemption_code);
        if ($rewardCode === '') {
            throw new RuntimeException('Candle Cash redemption is missing a reward code.');
        }

        $store = $this->resolveStoreConfig($redemption, $preferredStoreKey);
        if (! $store) {
            throw new RuntimeException('No Shopify store could be resolved for this Candle Cash redemption.');
        }

        $client = new ShopifyGraphqlClient(
            trim((string) ($store['shop'] ?? '')),
            trim((string) ($store['token'] ?? '')),
            trim((string) ($store['api_version'] ?? '')) ?: '2026-01'
        );

        $lookup = $client->query(self::LOOKUP_BY_CODE_QUERY, [
            'code' => $rewardCode,
        ]);

        $existing = $this->discountIdentifiersFromPayload($lookup['codeDiscountNodeByCode'] ?? null);
        if ($existing !== null) {
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
            throw new RuntimeException('Shopify Candle Cash discount create response was invalid.');
        }

        $errors = $this->extractUserErrors((array) ($payload['userErrors'] ?? []));
        if ($errors !== []) {
            throw new RuntimeException('Shopify Candle Cash discount create failed: ' . implode(' | ', $errors));
        }

        $created = $this->discountIdentifiersFromPayload($payload['codeDiscountNode'] ?? null);
        if ($created === null) {
            throw new RuntimeException('Shopify Candle Cash discount create did not return a discount identifier.');
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

        if (in_array('retail', $linkedStoreKeys, true)) {
            array_unshift($candidates, 'retail');
        }

        foreach (array_values(array_unique(array_filter($candidates))) as $storeKey) {
            $store = ShopifyStores::find($storeKey);
            if ($store) {
                return $store;
            }
        }

        return ShopifyStores::find('retail') ?: (ShopifyStores::all()[0] ?? null);
    }

    /**
     * @return array<string,mixed>
     */
    protected function basicDiscountInput(CandleCashRedemption $redemption): array
    {
        $amount = $this->candleCashService->redemptionAmountForIssuedCode($redemption, $redemption->reward);
        if ($amount <= 0) {
            throw new RuntimeException('Candle Cash redemption amount is missing or invalid.');
        }

        return [
            'title' => $this->discountTitle($redemption),
            'code' => (string) $redemption->redemption_code,
            'startsAt' => $this->startsAtForDiscount($redemption)->toIso8601String(),
            'endsAt' => optional($this->endsAtForDiscount($redemption))->toIso8601String(),
            'appliesOncePerCustomer' => true,
            'customerSelection' => ['all' => true],
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
        return 'Candle Cash Applied';
    }

    /**
     * @param mixed $payload
     * @return array{discount_id:?string,discount_node_id:?string,starts_at:?string,ends_at:?\Carbon\CarbonInterface}|null
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
            ] : null;
        }

        return [
            'discount_id' => null,
            'discount_node_id' => $discountNodeId !== '' ? $discountNodeId : null,
            'starts_at' => trim((string) ($discount['startsAt'] ?? '')) ?: null,
            'ends_at' => ! empty($discount['endsAt']) ? Carbon::parse((string) $discount['endsAt']) : null,
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
}
