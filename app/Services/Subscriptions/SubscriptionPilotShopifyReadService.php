<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use App\Services\Shopify\ShopifyGraphqlClient;

/** Explicit, bounded, query-only reads for the reviewed pilot's original store. */
class SubscriptionPilotShopifyReadService
{
    public function projectNativeReceipt(SubscriptionBillingCycle $cycle, array $order): void
    {
        $proof = data_get($cycle->reconciliation, 'latest_shopify_receipt', []);
        abort_unless($cycle->processor === 'shopify' && $cycle->paid_at && $cycle->handoff_status === 'confirmed'
            && in_array($proof['status'] ?? null, ['succeeded', 'refunded', 'disputed'], true) && ($proof['order_id'] ?? null) === $cycle->shopify_order_id
            && ($proof['receipt_digest'] ?? null) === SubscriptionEvidence::digest(SubscriptionEvidence::canonical($order)), 409);
        $store = ShopifyStore::query()->forTenantId($cycle->tenant_id)->findOrFail($cycle->terms['shopify_store_id']);
        $money = data_get($order, 'totalPriceSet.shopMoney');
        $payload = ['id' => basename($order['id']), 'customer' => ['id' => $cycle->terms['shopify_customer_id']], 'test' => $order['test'],
            'processed_at' => $order['processedAt'], 'created_at' => $order['createdAt'], 'updated_at' => $order['updatedAt'], 'cancelled_at' => $order['cancelledAt'],
            'financial_status' => $proof['status'] === 'disputed' ? 'disputed' : strtolower($order['displayFinancialStatus']), 'fulfillment_status' => strtolower($order['displayFulfillmentStatus']),
            'total_price' => $money['amount'], 'currency' => $money['currencyCode'],
            'note_attributes' => array_map(fn ($a) => ['name' => $a['key'], 'value' => $a['value']], $order['customAttributes']),
            'transactions' => array_map(fn ($t) => ['id' => basename($t['id']), 'gateway' => $t['gateway'], 'kind' => $t['kind'], 'status' => $t['status'],
                'amount' => data_get($t, 'amountSet.shopMoney.amount')], $order['transactions'])];
        app(SubscriptionPilotService::class)->projectShopifyOrder($cycle->tenant_id, $store->store_key, $payload, 'shopify_read');
    }

    public const QUERY = <<<'GRAPHQL'
query PilotShopifyOrders($query: String!, $after: String) {
  shop { myshopifyDomain }
  orders(first: 50, query: $query, after: $after, sortKey: CREATED_AT) {
    nodes {
      id processedAt createdAt updatedAt test cancelledAt displayFinancialStatus displayFulfillmentStatus
      customer { id }
      customAttributes { key value }
      totalPriceSet { shopMoney { amount currencyCode } }
      transactions(first: 50) { id gateway kind status amountSet { shopMoney { amount currencyCode } } }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GRAPHQL;

    public function refresh(int $tenantId, User $actor, int $memberId, ?string $guardCycleDate = null,
        ?SubscriptionBillingCycle $nativeCycle = null, bool $sandbox = false): int
    {
        $access = app(SubscriptionAccessService::class);
        $access->assertManager($tenantId, $actor);
        $member = SubscriptionPilotMember::query()->forTenantId($tenantId)->findOrFail($memberId);
        $store = ShopifyStore::query()->forTenantId($tenantId)->findOrFail($member->shopify_store_id);
        abort_unless($member->details['shop_domain'] === $store->shop_domain && filled($store->access_token)
            && preg_match('/^[a-z0-9-]+\.myshopify\.com$/D', $store->shop_domain), 409, 'The original Shopify store needs reconnection.');
        $fingerprint = SubscriptionEvidence::digest($store->access_token);
        $client = new ShopifyGraphqlClient($store->shop_domain, $store->access_token, SubscriptionAccessService::API_VERSION);
        $from = $member->target_date->subMonthsNoOverflow(6)->startOfMonth()->toDateString();
        $query = 'customer_id:'.$member->shopify_customer_id.' created_at:>='.$from.' test:'.($sandbox ? 'true' : 'false');
        $cursor = null;
        $orders = [];
        $seen = [];
        for ($page = 0; $page < 10; $page++) {
            $result = $client->query(self::QUERY, ['query' => $query, 'after' => $cursor]);
            abort_unless(strtolower((string) data_get($result, 'shop.myshopifyDomain')) === $store->shop_domain, 409, 'Shopify store identity changed.');
            $connection = $result['orders'] ?? [];
            abort_unless(isset($connection['nodes'], $connection['pageInfo']['hasNextPage']) && is_array($connection['nodes']), 409, 'Shopify order coverage is incomplete.');
            foreach ($connection['nodes'] as $row) {
                abort_unless(data_get($row, 'customer.id') === 'gid://shopify/Customer/'.$member->shopify_customer_id, 409, 'Shopify returned a different customer.');
                abort_unless(isset($row['id']) && ! isset($seen[$row['id']]), 409, 'Shopify repeated an order during capture.');
                $seen[$row['id']] = true;
                $orders[] = $row;
            }
            if (! $connection['pageInfo']['hasNextPage']) {
                break;
            }
            $next = $connection['pageInfo']['endCursor'] ?? null;
            abort_unless(is_string($next) && $next !== '' && $next !== $cursor && $page < 9, 409, 'Shopify order coverage requires another reviewed capture.');
            $cursor = $next;
        }
        $access->assertManager($tenantId, $actor->fresh());
        $fresh = ShopifyStore::query()->forTenantId($tenantId)->findOrFail($store->id);
        abort_unless($fresh->shop_domain === $store->shop_domain && hash_equals($fingerprint, SubscriptionEvidence::digest($fresh->access_token)), 409, 'The original Shopify credential changed during capture.');
        $count = 0;
        foreach ($orders as $row) {
            if ($guardCycleDate !== null) {
                $date = \Carbon\CarbonImmutable::parse($row['processedAt'] ?? $row['createdAt'])->setTimezone($member->timezone)->toDateString();
                if ($date === $guardCycleDate) {
                    abort_unless(is_array($row['transactions'] ?? null) && count($row['transactions']) < 50, 409, 'Shopify transaction coverage needs review.');
                    // Any real collection for this customer is conservative review,
                    // including an order without Recharge or pilot attributes.
                    // The external Stripe mirror is never a Shopify collection.
                    $originalNativeReceipt = $nativeCycle && SubscriptionShopifyBillingProtocol::excludes($nativeCycle, $member, $guardCycleDate, $row);
                    if (! $originalNativeReceipt && collect($row['transactions'])->contains(fn ($transaction) => strtolower((string) ($transaction['gateway'] ?? '')) === 'shopify_payments'
                        && strtoupper((string) ($transaction['status'] ?? '')) === 'SUCCESS'
                        && in_array(strtoupper((string) ($transaction['kind'] ?? '')), ['SALE', 'CAPTURE'], true))) {
                        throw new \App\Exceptions\SubscriptionPilotCoverageException('shopify_collection_in_window', 'A Shopify Payments collection already exists in this customer billing window.');
                    }
                }
            }
            $money = data_get($row, 'totalPriceSet.shopMoney');
            abort_unless(is_array($money), 409, 'Shopify order totals require review.');
            $payload = ['id' => basename($row['id']), 'customer' => ['id' => $member->shopify_customer_id], 'test' => $row['test'],
                'processed_at' => $row['processedAt'], 'created_at' => $row['createdAt'], 'updated_at' => $row['updatedAt'], 'cancelled_at' => $row['cancelledAt'],
                'financial_status' => strtolower((string) $row['displayFinancialStatus']), 'fulfillment_status' => strtolower((string) $row['displayFulfillmentStatus']),
                'total_price' => $money['amount'], 'currency' => $money['currencyCode'],
                'note_attributes' => array_map(fn ($a) => ['name' => $a['key'], 'value' => $a['value']], $row['customAttributes']),
                'transactions' => array_map(fn ($t) => ['id' => basename($t['id']), 'gateway' => $t['gateway'], 'kind' => $t['kind'], 'status' => $t['status'],
                    'amount' => data_get($t, 'amountSet.shopMoney.amount')], $row['transactions'])];
            $count += app(SubscriptionPilotService::class)->projectShopifyOrder($tenantId, $store->store_key, $payload, 'shopify_read');
        }

        return $count;
    }
}
