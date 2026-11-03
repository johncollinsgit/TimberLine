<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\BirthdayRewardIssuance;
use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use Illuminate\Support\Str;

/** Internal, default-off provider operation; an inactive code still cannot authorize POS use. */
final class PosBirthdayShopifyDeactivation
{
    private const DEACTIVATE = <<<'GRAPHQL'
mutation BirthdayCodeDeactivate($id: ID!) {
  discountCodeDeactivate(id: $id) { codeDiscountNode { id } userErrors { field message } }
}
GRAPHQL;

    private const READ = <<<'GRAPHQL'
query BirthdayCodeAfterDeactivation($id: ID!) {
  node(id: $id) { ... on DiscountCodeNode { id codeDiscount { ... on DiscountCodeBasic { status asyncUsageCount } } } }
}
GRAPHQL;

    public function deactivateForReview(int $tenantId, int $issuanceId): array
    {
        if (config('pos_live.birthday_shopify_deactivation_enabled', false) !== true
            || config('pos_live.birthday_channel_fence_enabled', false) !== true) {
            throw new PosException('birthday_deactivation_disabled', 403);
        }
        $issuance = BirthdayRewardIssuance::query()->whereKey($issuanceId)->first();
        $profile = $issuance ? MarketingProfile::query()->where('tenant_id', $tenantId)->whereKey($issuance->marketing_profile_id)->first() : null;
        $store = $profile ? ShopifyStore::query()->forTenantId($tenantId)->where('store_key', $issuance->shopify_store_key)->first() : null;
        $node = (string) ($issuance?->shopify_discount_node_id ?? '');
        if (! $profile || ! $store || ! $store->installed_at || ! $store->access_token
            || ! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', (string) $store->shop_domain)
            || ! preg_match('/^gid:\/\/shopify\/DiscountCodeNode\/[0-9]+$/D', $node)
            || $issuance->reward_type !== 'discount_code') {
            throw new PosException('birthday_deactivation_binding_invalid', 403);
        }
        $fence = app(PosBirthdayOnlineFence::class);
        $operation = (string) Str::uuid();
        $fence->begin($tenantId, (int) $profile->id, (int) $issuance->cycle_year, $issuanceId, $operation);
        $state = 'provider_review_required';
        try {
            $client = new PosBirthdayShopifyClient((string) $store->shop_domain, (string) $store->access_token);
            $mutation = $client->query(self::DEACTIVATE, ['id' => $node]);
            $result = $mutation['discountCodeDeactivate'] ?? null;
            if (! is_array($result) || ($result['userErrors'] ?? null) !== [] || ($result['codeDiscountNode']['id'] ?? null) !== $node) {
                throw new \RuntimeException('Unconfirmed deactivation');
            }
            $read = $client->query(self::READ, ['id' => $node]);
            $nodeResult = $read['node'] ?? null;
            if (($nodeResult['id'] ?? null) !== $node || ($nodeResult['codeDiscount']['status'] ?? null) !== 'EXPIRED'
                || ! is_int($nodeResult['codeDiscount']['asyncUsageCount'] ?? null)) {
                throw new \RuntimeException('Unconfirmed provider state');
            }
            $state = $nodeResult['codeDiscount']['asyncUsageCount'] === 0 ? 'inactive_review_required' : 'usage_review_required';
        } catch (\Throwable) {
            // A lost response may have completed the mutation; never blindly retry.
        }
        try {
            // Always retain the annual fence in review, including a fresh inactive read.
            $fence->finish($tenantId, (int) $profile->id, (int) $issuance->cycle_year, $issuanceId, $operation, false);
        } catch (PosException) {
            // Concurrent online use may already have moved the fence to review.
        }

        return ['state' => $state, 'redeemable' => false];
    }
}
