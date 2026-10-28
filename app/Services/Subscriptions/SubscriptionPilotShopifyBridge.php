<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionPilotCycle;
use App\Models\SubscriptionPilotMember;
use App\Services\Shopify\ShopifyGraphqlClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SubscriptionPilotShopifyBridge
{
    public const QUOTE_QUERY = <<<'GRAPHQL'
query PilotQuote($customer: ID!, $variants: [ID!]!) {
  shop { myshopifyDomain currencyCode }
  customer(id: $customer) { id defaultAddress { firstName lastName company address1 address2 city provinceCode countryCodeV2 zip phone } }
  nodes(ids: $variants) { ... on ProductVariant { id title price product { id title status } inventoryItem { requiresShipping } } }
}
GRAPHQL;

    public const ORDER_FIELDS = <<<'GRAPHQL'
id processedAt createdAt updatedAt test cancelledAt displayFinancialStatus displayFulfillmentStatus
customer { id }
customAttributes { key value }
totalPriceSet { shopMoney { amount currencyCode } }
lineItems(first: 25) { nodes { variant { id } quantity originalUnitPriceSet { shopMoney { amount currencyCode } } } pageInfo { hasNextPage } }
transactions(first: 50) { id gateway paymentId kind status amountSet { shopMoney { amount currencyCode } } }
GRAPHQL;

    public const CREATE_MUTATION = <<<'GRAPHQL'
mutation PilotPaidOrder($order: OrderCreateOrderInput!, $options: OrderCreateOptionsInput!) {
  orderCreate(order: $order, options: $options) { order { id } userErrors { field message } }
}
GRAPHQL;

    public const PERMISSIONS_QUERY = 'query PilotShopifyPermissions { shop { myshopifyDomain } currentAppInstallation { accessScopes { handle } } }';

    public function assertWriteReady(SubscriptionPilotMember $member): void
    {
        $store = $this->store($member->tenant_id, $member->shopify_store_id, $member->details['shop_domain']);
        $result = $this->reader($store)->query(self::PERMISSIONS_QUERY);
        $scopes = collect(data_get($result, 'currentAppInstallation.accessScopes', []))->pluck('handle');
        abort_unless(data_get($result, 'shop.myshopifyDomain') === $store->shop_domain && $scopes->contains('write_orders')
            && ($scopes->contains('read_customers') || $scopes->contains('write_customers'))
            && ($scopes->contains('read_products') || $scopes->contains('write_products')), 423, 'Shopify order creation and customer/product read permissions are required before Stripe billing.');
    }

    public function quote(SubscriptionPilotMember $member): array
    {
        $this->assertWriteReady($member);
        $store = $this->store($member->tenant_id, $member->shopify_store_id, $member->details['shop_domain']);
        $lines = $member->details['lines'];
        $ids = array_map(fn ($line) => 'gid://shopify/ProductVariant/'.$line['variant_id'], $lines);
        $result = $this->reader($store)->query(self::QUOTE_QUERY, ['customer' => 'gid://shopify/Customer/'.$member->shopify_customer_id, 'variants' => $ids]);
        abort_unless(data_get($result, 'shop.myshopifyDomain') === $store->shop_domain
            && data_get($result, 'shop.currencyCode') === $member->currency
            && data_get($result, 'customer.id') === 'gid://shopify/Customer/'.$member->shopify_customer_id
            && count($result['nodes'] ?? []) === count($ids), 409, 'Original Shopify customer, currency or products need review.');
        foreach ($ids as $i => $id) {
            $row = $result['nodes'][$i];
            abort_unless(($row['id'] ?? null) === $id && data_get($row, 'product.status') === 'ACTIVE'
                && data_get($row, 'inventoryItem.requiresShipping') === true, 409, 'The subscription product is unavailable for shipping.');
        }
        $source = data_get($result, 'customer.defaultAddress');
        abort_unless(is_array($source), 409, 'A Shopify shipping address is required.');
        $address = array_intersect_key($source, array_flip(['firstName', 'lastName', 'company', 'address1', 'address2', 'city', 'provinceCode', 'zip', 'phone']));
        $address['countryCode'] = $source['countryCodeV2'] ?? null;
        foreach (['firstName', 'lastName', 'address1', 'city', 'provinceCode', 'zip', 'countryCode'] as $field) {
            abort_unless(is_string($address[$field] ?? null) && trim($address[$field]) !== '', 409, 'Complete the Shopify shipping address first.');
        }
        abort_unless($address['countryCode'] === 'US', 409, 'This controlled pilot requires reviewed US delivery and tax terms.');

        return ['shopify_store_id' => $store->id, 'shop_domain' => $store->shop_domain, 'shipping_address' => $address, 'lines' => $lines];
    }

    public function handoff(SubscriptionPilotCycle $cycle): SubscriptionPilotCycle
    {
        abort_unless($cycle->payment_status === 'succeeded' && $cycle->paid_at && $cycle->payment_intent_id, 409, 'A confirmed Stripe payment is required.');
        $verifier = app(SubscriptionPilotPaymentVerifier::class);
        abort_unless($verifier->captured($cycle, $verifier->read($cycle)), 409, 'The original payment is no longer a complete captured payment.');
        $terms = $cycle->terms;
        $store = $this->store($cycle->tenant_id, $terms['shopify_store_id'], $terms['shop_domain']);
        // Always look for an existing original result before any mutation.
        $query = 'query PilotOrderRecovery($query: String!) { shop { myshopifyDomain } orders(first: 10, query: $query) { nodes { '.self::ORDER_FIELDS.' } pageInfo { hasNextPage } } }';
        $result = $this->reader($store)->query($query, ['query' => 'tag:everbranch-pilot-'.$cycle->public_id]);
        abort_unless(data_get($result, 'shop.myshopifyDomain') === $store->shop_domain && data_get($result, 'orders.pageInfo.hasNextPage') === false, 409, 'Shopify handoff coverage needs review.');
        $orders = data_get($result, 'orders.nodes', []);
        abort_unless(is_array($orders) && count($orders) <= 1, 409, 'More than one Shopify order may exist for this payment.');
        if (count($orders) === 1) {
            return $this->confirm($cycle, $orders[0]);
        }
        if ($cycle->handoff_started_at) {
            $cycle->update(['handoff_status' => 'unknown', 'last_error_code' => 'original_shopify_result_unconfirmed']);

            return $cycle->fresh();
        }
        abort_unless((bool) config('subscriptions.pilot_handoff_enabled') && in_array($cycle->tenant_id, config('subscriptions.pilot_tenant_ids', []), true), 423, 'The pilot Shopify handoff is paused.');
        $variables = $this->payload($cycle);
        $claimed = DB::transaction(function () use ($cycle, $variables) {
            $row = SubscriptionPilotCycle::query()->forTenantId($cycle->tenant_id)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            if ($row->handoff_started_at) {
                return false;
            }
            abort_unless($row->payment_status === 'succeeded' && $row->payment_intent_id === $cycle->payment_intent_id, 409);
            $row->update(['shopify_payload' => $variables, 'handoff_started_at' => now(), 'handoff_status' => 'sending']);

            return true;
        });
        if (! $claimed) {
            return $cycle->fresh();
        }
        try {
            $freshStore = $this->store($cycle->tenant_id, $terms['shopify_store_id'], $terms['shop_domain']);
            abort_unless(hash_equals($store->access_token, $freshStore->access_token), 409, 'The original Shopify credential changed.');
            // orderCreate has no retry here. A timeout/5xx is reconciled by tag,
            // never by issuing another create or charging the customer again.
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $store->access_token, 'Accept' => 'application/json'])
                ->withoutRedirecting()->connectTimeout(5)->timeout(20)->post('https://'.$store->shop_domain.'/admin/api/'.SubscriptionAccessService::API_VERSION.'/graphql.json',
                    ['query' => self::CREATE_MUTATION, 'variables' => $variables]);
            abort_unless($response->successful() && strlen($response->body()) <= 2097152, 502);
            $body = $response->json();
            abort_unless(is_array($body) && empty($body['errors']) && empty(data_get($body, 'data.orderCreate.userErrors'))
                && preg_match('#^gid://shopify/Order/[1-9][0-9]+$#D', (string) data_get($body, 'data.orderCreate.order.id')), 409);
            $id = data_get($body, 'data.orderCreate.order.id');
            $read = $this->reader($store)->query('query PilotCreatedOrder($id: ID!) { shop { myshopifyDomain } order(id: $id) { '.self::ORDER_FIELDS.' } }', ['id' => $id]);
            abort_unless(data_get($read, 'shop.myshopifyDomain') === $store->shop_domain, 409);

            return $this->confirm($cycle->fresh(), $read['order'] ?? []);
        } catch (\Throwable) {
            $cycle->fresh()->update(['handoff_status' => 'unknown', 'last_error_code' => 'shopify_handoff_reconciliation_required']);

            return $cycle->fresh();
        }
    }

    public function payload(SubscriptionPilotCycle $cycle): array
    {
        $terms = $cycle->terms;
        $money = fn (int $amount) => ['shopMoney' => ['amount' => self::decimal($amount), 'currencyCode' => $cycle->currency]];
        $lines = array_map(fn ($line) => ['variantId' => 'gid://shopify/ProductVariant/'.$line['variant_id'], 'quantity' => $line['quantity'],
            'priceSet' => $money($line['unit_price_cents']), 'requiresShipping' => true], $terms['lines']);
        $rate = intdiv($terms['tax_rate_bps'], 10000).'.'.str_pad((string) ($terms['tax_rate_bps'] % 10000), 4, '0', STR_PAD_LEFT);
        $tax = $terms['tax_cents'] > 0 ? [['title' => 'Sales tax', 'rate' => $rate, 'priceSet' => $money($terms['tax_cents'])]] : [];

        return ['order' => ['currency' => $cycle->currency, 'customer' => ['toAssociate' => ['id' => 'gid://shopify/Customer/'.$terms['shopify_customer_id']]],
            'lineItems' => $lines, 'shippingAddress' => $terms['shipping_address'], 'shippingLines' => [['title' => 'Subscription shipping', 'priceSet' => $money($terms['shipping_cents'])]],
            'taxLines' => $tax, 'taxesIncluded' => false, 'financialStatus' => 'PAID', 'sourceName' => 'everbranch_subscriptions', 'sourceIdentifier' => $cycle->public_id,
            'test' => ! $terms['merchant']['livemode'], 'tags' => ['Everbranch subscription', 'everbranch-pilot-'.$cycle->public_id],
            'customAttributes' => [['key' => 'everbranch_pilot_cycle', 'value' => $cycle->public_id], ['key' => 'everbranch_stripe_payment_intent', 'value' => $cycle->payment_intent_id],
                ['key' => 'everbranch_stripe_account', 'value' => $terms['merchant']['account_id']], ['key' => 'everbranch_terms_hash', 'value' => $terms['terms_hash']]],
            'transactions' => [['kind' => 'SALE', 'status' => 'SUCCESS', 'gateway' => 'everbranch_stripe', 'authorizationCode' => $cycle->payment_intent_id,
                'amountSet' => $money($cycle->amount_cents), 'test' => ! $terms['merchant']['livemode']]]],
            'options' => ['inventoryBehaviour' => 'DECREMENT_OBEYING_POLICY', 'sendReceipt' => false, 'sendFulfillmentReceipt' => false]];
    }

    private function confirm(SubscriptionPilotCycle $cycle, array $order): SubscriptionPilotCycle
    {
        $attributes = collect($order['customAttributes'] ?? [])->pluck('value', 'key')->all();
        abort_unless(($attributes['everbranch_pilot_cycle'] ?? null) === $cycle->public_id
            && ($attributes['everbranch_stripe_payment_intent'] ?? null) === $cycle->payment_intent_id
            && ($attributes['everbranch_stripe_account'] ?? null) === $cycle->terms['merchant']['account_id']
            && ($attributes['everbranch_terms_hash'] ?? null) === $cycle->terms['terms_hash']
            && data_get($order, 'customer.id') === 'gid://shopify/Customer/'.$cycle->terms['shopify_customer_id']
            && ($order['displayFinancialStatus'] ?? null) === 'PAID' && empty($order['cancelledAt'])
            && ($order['test'] ?? null) === ! $cycle->terms['merchant']['livemode']
            && data_get($order, 'totalPriceSet.shopMoney.currencyCode') === $cycle->currency
            && self::cents((string) data_get($order, 'totalPriceSet.shopMoney.amount')) === $cycle->amount_cents
            && data_get($order, 'lineItems.pageInfo.hasNextPage') === false, 409, 'Shopify returned different payment or order terms.');
        $actual = collect(data_get($order, 'lineItems.nodes', []))->map(fn ($line) => [data_get($line, 'variant.id'), $line['quantity'], self::cents((string) data_get($line, 'originalUnitPriceSet.shopMoney.amount'))])->sort()->values()->all();
        $expected = collect($cycle->terms['lines'])->map(fn ($line) => ['gid://shopify/ProductVariant/'.$line['variant_id'], $line['quantity'], $line['unit_price_cents']])->sort()->values()->all();
        abort_unless($actual === $expected && preg_match('#^gid://shopify/Order/[1-9][0-9]+$#D', $order['id'] ?? ''), 409, 'Shopify product lines differ from the paid terms.');
        $id = basename($order['id']);
        $cycle->update(['shopify_order_id' => $id, 'handoff_status' => 'confirmed', 'last_error_code' => null]);
        // Same owned-source projector as normal Shopify ingestion. The external
        // transaction records a Stripe receipt and never another collection.
        $store = $this->store($cycle->tenant_id, $cycle->terms['shopify_store_id'], $cycle->terms['shop_domain']);
        app(SubscriptionPilotService::class)->projectShopifyOrder($cycle->tenant_id, $store->store_key, [
            'id' => $id, 'customer' => ['id' => $cycle->terms['shopify_customer_id']], 'test' => $order['test'],
            'processed_at' => $order['processedAt'], 'created_at' => $order['createdAt'], 'updated_at' => $order['updatedAt'],
            'cancelled_at' => $order['cancelledAt'], 'financial_status' => strtolower($order['displayFinancialStatus']),
            'fulfillment_status' => strtolower($order['displayFulfillmentStatus']),
            'total_price' => data_get($order, 'totalPriceSet.shopMoney.amount'), 'currency' => $cycle->currency,
            'note_attributes' => array_map(fn ($a) => ['name' => $a['key'], 'value' => $a['value']], $order['customAttributes']),
            'transactions' => array_map(fn ($t) => ['id' => basename($t['id']), 'gateway' => $t['gateway'], 'kind' => $t['kind'], 'status' => $t['status'],
                'amount' => data_get($t, 'amountSet.shopMoney.amount')], $order['transactions']),
        ], 'shopify_read');

        return $cycle->fresh();
    }

    private function store(int $tenantId, int $storeId, string $domain): ShopifyStore
    {
        $store = ShopifyStore::query()->forTenantId($tenantId)->findOrFail($storeId);
        abort_unless($store->shop_domain === $domain && preg_match('/^[a-z0-9-]+\.myshopify\.com$/D', $domain) && filled($store->access_token), 409, 'The original Shopify store needs reconnection.');

        return $store;
    }

    private function reader(ShopifyStore $store): ShopifyGraphqlClient
    {
        return new ShopifyGraphqlClient($store->shop_domain, $store->access_token, SubscriptionAccessService::API_VERSION);
    }

    public static function cents(string $value): int
    {
        abort_unless(preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', $value, $parts), 422, 'Exact decimal money is required.');

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function decimal(int $value): string
    {
        return intdiv($value, 100).'.'.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
    }
}
