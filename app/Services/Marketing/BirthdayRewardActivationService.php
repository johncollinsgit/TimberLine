<?php

namespace App\Services\Marketing;

use App\Models\BirthdayRewardIssuance;
use App\Models\MarketingProfileLink;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BirthdayRewardActivationService
{
    protected const LOOKUP_BY_CODE_QUERY = <<<'GRAPHQL'
query BirthdayDiscountByCode($code: String!) {
  codeDiscountNodeByCode(code: $code) {
    id
    codeDiscount {
      __typename
      ... on DiscountCodeBasic {
        id
        title
        startsAt
        endsAt
      }
      ... on DiscountCodeFreeShipping {
        id
        title
        startsAt
        endsAt
      }
    }
  }
}
GRAPHQL;

    protected const CREATE_BASIC_DISCOUNT_MUTATION = <<<'GRAPHQL'
mutation BirthdayDiscountCodeBasicCreate($basicCodeDiscount: DiscountCodeBasicInput!) {
  discountCodeBasicCreate(basicCodeDiscount: $basicCodeDiscount) {
    codeDiscountNode {
      id
      codeDiscount {
        __typename
        ... on DiscountCodeBasic {
          id
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

    protected const CREATE_FREE_SHIPPING_DISCOUNT_MUTATION = <<<'GRAPHQL'
mutation BirthdayDiscountCodeFreeShippingCreate($freeShippingCodeDiscount: DiscountCodeFreeShippingInput!) {
  discountCodeFreeShippingCreate(freeShippingCodeDiscount: $freeShippingCodeDiscount) {
    codeDiscountNode {
      id
      codeDiscount {
        __typename
        ... on DiscountCodeFreeShipping {
          id
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
        protected BirthdayRewardEngineService $rewardEngine,
        protected BirthdayProfileService $birthdayProfileService,
        protected MarketingStorefrontEventLogger $eventLogger
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function activate(BirthdayRewardIssuance $issuance, array $options = []): array
    {
        $prepared = DB::transaction(function () use ($issuance, $options): array {
            /** @var BirthdayRewardIssuance|null $locked */
            $locked = BirthdayRewardIssuance::query()
                ->with(['birthdayProfile', 'marketingProfile'])
                ->whereKey($issuance->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->birthdayProfile || ! $locked->marketingProfile) {
                return [
                    'ok' => false,
                    'state' => 'birthday_reward_not_ready',
                    'error' => 'reward_missing_profile',
                    'issuance' => $locked,
                ];
            }

            if ($locked->isExpired()) {
                $locked->forceFill(['status' => 'expired'])->save();

                return [
                    'ok' => false,
                    'state' => 'birthday_reward_expired',
                    'error' => 'reward_expired',
                    'issuance' => $locked->fresh(),
                ];
            }

            if ((string) $locked->status === 'cancelled') {
                return [
                    'ok' => false,
                    'state' => 'birthday_reward_cancelled',
                    'error' => 'reward_cancelled',
                    'issuance' => $locked,
                ];
            }

            if ((string) $locked->status === 'redeemed') {
                return [
                    'ok' => false,
                    'state' => 'birthday_reward_redeemed',
                    'error' => 'reward_already_redeemed',
                    'issuance' => $locked,
                ];
            }

            if (
                $locked->isActivated()
                && $locked->resolvedDiscountSyncStatus() === 'synced'
                && (($locked->shopify_discount_id !== null && trim((string) $locked->shopify_discount_id) !== '')
                    || ($locked->shopify_discount_node_id !== null && trim((string) $locked->shopify_discount_node_id) !== ''))
            ) {
                return [
                    'ok' => true,
                    'state' => 'already_claimed',
                    'error' => null,
                    'issuance' => $locked,
                ];
            }

            if ($locked->reward_type === 'points') {
                return [
                    'ok' => (string) $locked->status === 'claimed',
                    'state' => (string) $locked->status === 'claimed' ? 'already_claimed' : 'birthday_reward_not_ready',
                    'error' => (string) $locked->status === 'claimed' ? null : 'points_reward_does_not_require_discount_sync',
                    'issuance' => $locked,
                ];
            }

            if (! in_array((string) $locked->status, ['issued', 'claimed'], true)) {
                return [
                    'ok' => false,
                    'state' => 'birthday_reward_not_ready',
                    'error' => 'reward_not_claimable',
                    'issuance' => $locked,
                ];
            }

            if ($locked->reward_code === null || trim((string) $locked->reward_code) === '') {
                $locked->reward_code = $this->rewardEngine->generateUniqueCodeForRewardType(
                    (string) $locked->reward_type,
                    (int) $locked->cycle_year
                );
            }

            $store = $this->resolveStoreConfig($locked, $options);
            if (! $store) {
                $locked->forceFill([
                    'discount_sync_status' => 'failed',
                    'discount_sync_error' => 'No Shopify store could be resolved for this reward.',
                ])->save();

                $this->writeAudit($locked, 'birthday_reward_activation_requested', [
                    'result' => 'failed',
                    'reason' => 'missing_shopify_store',
                    'reward_code' => $locked->reward_code,
                ]);

                return [
                    'ok' => false,
                    'state' => 'birthday_reward_not_ready',
                    'error' => 'missing_shopify_store',
                    'issuance' => $locked->fresh(),
                ];
            }

            $locked->forceFill([
                'shopify_store_key' => (string) $store['key'],
                'discount_sync_status' => 'syncing',
                'discount_sync_error' => null,
            ])->save();

            $this->writeAudit($locked, 'birthday_reward_activation_requested', [
                'reward_code' => $locked->reward_code,
                'shopify_store_key' => (string) $store['key'],
                'status_before' => (string) $locked->status,
            ]);

            return [
                'ok' => true,
                'state' => 'activation_requested',
                'error' => null,
                'issuance' => $locked->fresh(),
                'store' => $store,
            ];
        });

        if (! (bool) ($prepared['ok'] ?? false)) {
            return $prepared;
        }

        if (! isset($prepared['store'])) {
            return $prepared;
        }

        /** @var BirthdayRewardIssuance $preparedIssuance */
        $preparedIssuance = $prepared['issuance'];
        /** @var array<string,mixed> $store */
        $store = $prepared['store'];

        try {
            $sync = $this->ensureShopifyDiscount($preparedIssuance, $store);
        } catch (\Throwable $e) {
            $failed = DB::transaction(function () use ($preparedIssuance, $e): BirthdayRewardIssuance {
                /** @var BirthdayRewardIssuance $locked */
                $locked = BirthdayRewardIssuance::query()
                    ->with('birthdayProfile')
                    ->whereKey($preparedIssuance->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $locked->forceFill([
                    'discount_sync_status' => 'failed',
                    'discount_sync_error' => $e->getMessage(),
                ])->save();

                return $locked->fresh();
            });

            $this->writeAudit($failed, 'birthday_reward_discount_sync_failed', [
                'reward_code' => $failed->reward_code,
                'shopify_store_key' => $failed->shopify_store_key,
                'error' => $e->getMessage(),
            ]);

            $this->eventLogger->log('birthday_reward_discount_sync_failed', [
                'status' => 'error',
                'issue_type' => 'shopify_discount_sync_failed',
                'source_surface' => (string) ($options['source_surface'] ?? 'system'),
                'endpoint' => (string) ($options['endpoint'] ?? 'birthday_reward_activation'),
                'marketing_profile_id' => (int) $failed->marketing_profile_id,
                'source_type' => 'birthday_reward',
                'source_id' => (string) $failed->id,
                'dedupe_key' => sha1('birthday_reward_discount_sync_failed|' . $failed->id . '|' . $failed->shopify_store_key . '|' . $failed->reward_code),
                'meta' => [
                    'reward_code' => $failed->reward_code,
                    'shopify_store_key' => $failed->shopify_store_key,
                    'error' => $e->getMessage(),
                ],
                'resolution_status' => 'open',
            ]);

            return [
                'ok' => false,
                'state' => 'birthday_reward_activation_failed',
                'error' => 'shopify_discount_sync_failed',
                'issuance' => $failed,
            ];
        }

        $activated = DB::transaction(function () use ($preparedIssuance, $sync): BirthdayRewardIssuance {
            /** @var BirthdayRewardIssuance $locked */
            $locked = BirthdayRewardIssuance::query()
                ->with('birthdayProfile')
                ->whereKey($preparedIssuance->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $locked->status === 'redeemed') {
                return $locked;
            }

            $activatedAt = $locked->resolvedActivationAt() ?: now();

            $locked->forceFill([
                'status' => 'claimed',
                'claimed_at' => $locked->claimed_at ?: $activatedAt,
                'activated_at' => $locked->activated_at ?: $activatedAt,
                'shopify_discount_id' => (string) ($sync['discount_id'] ?? '') !== '' ? (string) $sync['discount_id'] : $locked->shopify_discount_id,
                'shopify_discount_node_id' => (string) ($sync['discount_node_id'] ?? '') !== '' ? (string) $sync['discount_node_id'] : $locked->shopify_discount_node_id,
                'shopify_store_key' => (string) ($sync['store_key'] ?? '') !== '' ? (string) $sync['store_key'] : $locked->shopify_store_key,
                'discount_sync_status' => 'synced',
                'discount_sync_error' => null,
                'expires_at' => $locked->expires_at ?: ($sync['ends_at'] ?? null),
            ])->save();

            return $locked->fresh();
        });

        $this->writeAudit($activated, 'birthday_reward_discount_synced', [
            'reward_code' => $activated->reward_code,
            'shopify_store_key' => $activated->shopify_store_key,
            'shopify_discount_id' => $activated->shopify_discount_id,
            'shopify_discount_node_id' => $activated->shopify_discount_node_id,
        ]);

        $this->eventLogger->log('birthday_reward_discount_synced', [
            'status' => 'ok',
            'issue_type' => null,
            'source_surface' => (string) ($options['source_surface'] ?? 'system'),
            'endpoint' => (string) ($options['endpoint'] ?? 'birthday_reward_activation'),
            'marketing_profile_id' => (int) $activated->marketing_profile_id,
            'source_type' => 'birthday_reward',
            'source_id' => (string) $activated->id,
            'dedupe_key' => sha1('birthday_reward_discount_synced|' . $activated->id . '|' . $activated->shopify_store_key . '|' . $activated->reward_code),
            'meta' => [
                'reward_code' => $activated->reward_code,
                'shopify_store_key' => $activated->shopify_store_key,
                'shopify_discount_id' => $activated->shopify_discount_id,
                'shopify_discount_node_id' => $activated->shopify_discount_node_id,
            ],
            'resolution_status' => 'resolved',
        ]);

        return [
            'ok' => true,
            'state' => 'already_claimed',
            'error' => null,
            'issuance' => $activated,
        ];
    }

    /**
     * @param array<string,mixed> $store
     * @return array{discount_id:?string,discount_node_id:?string,store_key:string,starts_at:?string,ends_at:?\Carbon\CarbonInterface}
     */
    protected function ensureShopifyDiscount(BirthdayRewardIssuance $issuance, array $store): array
    {
        $client = new ShopifyGraphqlClient(
            trim((string) ($store['shop'] ?? '')),
            trim((string) ($store['token'] ?? '')),
            trim((string) ($store['api_version'] ?? '')) ?: '2026-01'
        );

        $lookup = $client->query(self::LOOKUP_BY_CODE_QUERY, [
            'code' => (string) $issuance->reward_code,
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

        if ((string) $issuance->reward_type === 'free_shipping') {
            $data = $client->query(self::CREATE_FREE_SHIPPING_DISCOUNT_MUTATION, [
                'freeShippingCodeDiscount' => $this->freeShippingDiscountInput($issuance),
            ]);

            $payload = $data['discountCodeFreeShippingCreate'] ?? null;
        } else {
            $data = $client->query(self::CREATE_BASIC_DISCOUNT_MUTATION, [
                'basicCodeDiscount' => $this->basicDiscountInput($issuance),
            ]);

            $payload = $data['discountCodeBasicCreate'] ?? null;
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Shopify discount create response was invalid.');
        }

        $errors = $this->extractUserErrors((array) ($payload['userErrors'] ?? []));
        if ($errors !== []) {
            throw new RuntimeException('Shopify discount create failed: ' . implode(' | ', $errors));
        }

        $created = $this->discountIdentifiersFromPayload($payload['codeDiscountNode'] ?? null);
        if ($created === null) {
            throw new RuntimeException('Shopify discount create did not return a discount identifier.');
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
     * @param array<string,mixed> $options
     * @return array<string,mixed>|null
     */
    protected function resolveStoreConfig(BirthdayRewardIssuance $issuance, array $options = []): ?array
    {
        $candidates = [];

        $explicitStoreKey = strtolower(trim((string) ($options['store_key'] ?? '')));
        if ($explicitStoreKey !== '') {
            $candidates[] = $explicitStoreKey;
        }

        $savedStoreKey = strtolower(trim((string) ($issuance->shopify_store_key ?? '')));
        if ($savedStoreKey !== '') {
            $candidates[] = $savedStoreKey;
        }

        $linkedStoreKeys = MarketingProfileLink::query()
            ->where('marketing_profile_id', $issuance->marketing_profile_id)
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
    protected function basicDiscountInput(BirthdayRewardIssuance $issuance): array
    {
        $amount = $issuance->reward_value !== null ? (float) $issuance->reward_value : 0.0;
        if ($amount <= 0) {
            throw new RuntimeException('Birthday discount reward value is missing or invalid.');
        }

        return [
            'title' => $this->discountTitle($issuance),
            'code' => (string) $issuance->reward_code,
            'startsAt' => $this->startsAtForDiscount($issuance)->toIso8601String(),
            'endsAt' => optional($this->endsAtForDiscount($issuance))->toIso8601String(),
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

    /**
     * @return array<string,mixed>
     */
    protected function freeShippingDiscountInput(BirthdayRewardIssuance $issuance): array
    {
        return [
            'title' => $this->discountTitle($issuance),
            'code' => (string) $issuance->reward_code,
            'startsAt' => $this->startsAtForDiscount($issuance)->toIso8601String(),
            'endsAt' => optional($this->endsAtForDiscount($issuance))->toIso8601String(),
            'appliesOncePerCustomer' => true,
            'customerSelection' => ['all' => true],
            'destinationSelection' => ['all' => true],
        ];
    }

    protected function startsAtForDiscount(BirthdayRewardIssuance $issuance): \Carbon\CarbonInterface
    {
        return $issuance->claim_window_starts_at ?: $issuance->issued_at ?: now();
    }

    protected function endsAtForDiscount(BirthdayRewardIssuance $issuance): ?\Carbon\CarbonInterface
    {
        return $issuance->expires_at ?: $issuance->claim_window_ends_at;
    }

    protected function discountTitle(BirthdayRewardIssuance $issuance): string
    {
        $base = trim((string) ($issuance->reward_name ?: 'Birthday Reward'));

        return sprintf('%s %s #%d', $base, (string) $issuance->cycle_year, (int) $issuance->id);
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

        $discountId = trim((string) ($discount['id'] ?? ''));

        return [
            'discount_id' => $discountId !== '' ? $discountId : null,
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

    /**
     * @param array<string,mixed> $payload
     */
    protected function writeAudit(BirthdayRewardIssuance $issuance, string $action, array $payload = []): void
    {
        if (! $issuance->birthdayProfile) {
            return;
        }

        $this->birthdayProfileService->writeAudit(
            profile: $issuance->birthdayProfile,
            action: $action,
            source: 'birthday_reward_activation',
            isUncertain: false,
            payload: $payload
        );
    }
}
