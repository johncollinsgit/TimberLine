<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAgreement;
use Illuminate\Support\Facades\Http;

/** Exact owned-store reads for agreement terms and original payment mapping. */
class SubscriptionBillingShopifySource
{
    public const SANDBOX_QUERY = 'query SubscriptionSandboxStore { shop { myshopifyDomain plan { partnerDevelopment } } }';

    public const SOURCE_QUERY = <<<'GRAPHQL'
query OriginalSubscriptionPayment($id: ID!) {
  shop { myshopifyDomain }
  order(id: $id) {
    id updatedAt processedAt createdAt test cancelledAt displayFinancialStatus customer { id }
    customAttributes { key value }
    totalPriceSet { shopMoney { amount currencyCode } }
    totalShippingPriceSet { shopMoney { amount currencyCode } }
    totalTaxSet { shopMoney { amount currencyCode } }
    shippingAddress { firstName lastName company address1 address2 city provinceCode countryCodeV2 zip phone }
    lineItems(first: 25) { nodes { variant { id } quantity originalUnitPriceSet { shopMoney { amount currencyCode } } } pageInfo { hasNextPage } }
  }
}
GRAPHQL;

    public function originalPayment(SubscriptionBillingAgreement $row, string $orderId, string $transactionId): array
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,19}\z/', $orderId), 422);
        $terms = SubscriptionBillingAuthorization::original($row)['terms'];
        $data = $this->read($row, self::SOURCE_QUERY, ['id' => 'gid://shopify/Order/'.$orderId]);
        $order = $data['order'] ?? [];
        $attributes = collect($order['customAttributes'] ?? [])->pluck('value', 'key')->all();
        $subscriptions = array_values(array_filter(array_map('trim', explode(',', (string) ($attributes['rc_subscription_ids'] ?? '')))));
        $expected = array_values(array_unique(array_column($terms['lines'], 'subscription_id')));
        sort($subscriptions);
        sort($expected);
        abort_unless(($order['id'] ?? null) === 'gid://shopify/Order/'.$orderId && ($order['test'] ?? null) === ($row->method_binding['environment'] === 'sandbox')
            && empty($order['cancelledAt']) && ($order['displayFinancialStatus'] ?? null) === 'PAID'
            && data_get($order, 'customer.id') === 'gid://shopify/Customer/'.$terms['shopify_customer_id']
            && ($attributes['rc_payment_transaction_id'] ?? null) === $transactionId && $subscriptions === $expected && $expected !== []
            && data_get($order, 'lineItems.pageInfo.hasNextPage') === false, 409, 'The original paid order does not identify this exact source subscription payment.');
        foreach (['totalPriceSet' => 'amount_cents', 'totalShippingPriceSet' => 'shipping_cents', 'totalTaxSet' => 'tax_cents'] as $field => $term) {
            abort_unless(data_get($order, $field.'.shopMoney.currencyCode') === $terms['currency']
                && SubscriptionPilotShopifyBridge::cents((string) data_get($order, $field.'.shopMoney.amount')) === $terms[$term], 409, 'Original products, shipping or tax differ from the reviewed agreement.');
        }
        $actual = collect(data_get($order, 'lineItems.nodes', []))->map(fn ($line) => [data_get($line, 'variant.id'), $line['quantity'],
            SubscriptionPilotShopifyBridge::cents((string) data_get($line, 'originalUnitPriceSet.shopMoney.amount'))])->sort()->values()->all();
        $lines = collect($terms['lines'])->map(fn ($line) => ['gid://shopify/ProductVariant/'.$line['variant_id'], $line['quantity'], $line['unit_price_cents']])->sort()->values()->all();
        $address = $order['shippingAddress'] ?? [];
        $address['countryCode'] = $address['countryCodeV2'] ?? null;
        unset($address['countryCodeV2']);
        abort_unless($actual === $lines && self::address($address) === self::address($terms['shipping_address']), 409, 'Original subscription products or delivery address need review.');

        return ['source_order_id' => $orderId, 'source_transaction_id' => $transactionId, 'shop_domain' => $terms['shop_domain'],
            'shopify_customer_id' => $terms['shopify_customer_id'], 'subscription_ids' => $subscriptions,
            'processed_at' => $order['processedAt'] ?? $order['createdAt'], 'source_updated_at' => $order['updatedAt'], 'checked_at' => now()->toISOString()];
    }

    public function currentTerms(SubscriptionBillingAgreement $row): void
    {
        SubscriptionBillingAuthorization::assertCurrent($row);
        if ($row->terms_hash !== data_get($row->authorization_evidence, 'original_authorization.terms_hash')) {
            app(SubscriptionAmendmentQuote::class)->verifyCurrent($row, \App\Models\User::findOrFail($row->activated_by));
        }
        $terms = $row->terms;
        if ($row->method_binding['environment'] === 'sandbox') {
            $sandbox = $this->read($row, self::SANDBOX_QUERY, []);
            abort_unless(data_get($sandbox, 'shop.plan.partnerDevelopment') === true, 423, 'Use an isolated Shopify development store for sandbox billing and inventory acceptance.');
        }
        $data = $this->read($row, SubscriptionPilotShopifyBridge::QUOTE_QUERY,
            ['customer' => 'gid://shopify/Customer/'.$terms['shopify_customer_id'], 'variants' => array_map(fn ($line) => 'gid://shopify/ProductVariant/'.$line['variant_id'], $terms['lines'])]);
        abort_unless(data_get($data, 'shop.currencyCode') === $terms['currency']
            && data_get($data, 'customer.id') === 'gid://shopify/Customer/'.$terms['shopify_customer_id']
            && count($data['nodes'] ?? []) === count($terms['lines']), 409, 'The original subscription customer, currency or products changed.');
        foreach ($terms['lines'] as $i => $line) {
            abort_unless(data_get($data, 'nodes.'.$i.'.id') === 'gid://shopify/ProductVariant/'.$line['variant_id']
                && data_get($data, 'nodes.'.$i.'.product.status') === 'ACTIVE'
                && data_get($data, 'nodes.'.$i.'.inventoryItem.requiresShipping') === true, 409, 'A reviewed subscription product is no longer available for delivery.');
        }
        app(SubscriptionPilotShopifyBridge::class)->assertWriteReady(app(SubscriptionReplacementAccess::class)->member($row));
    }

    private function read(SubscriptionBillingAgreement $row, string $document, array $variables): array
    {
        app(SubscriptionReplacementAccess::class)->member($row);
        $store = ShopifyStore::query()->forTenantId($row->tenant_id)->findOrFail($row->terms['shopify_store_id']);
        abort_unless(preg_match('/\A[a-z0-9-]+\.myshopify\.com\z/', $store->shop_domain) && filled($store->access_token), 409);
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $store->access_token, 'Accept' => 'application/json'])
                ->withoutRedirecting()->connectTimeout(3)->timeout(12)->post('https://'.$store->shop_domain.'/admin/api/2026-10/graphql.json', ['query' => $document, 'variables' => $variables]);
            abort_unless($response->successful() && $response->header('X-Shopify-API-Version') === '2026-10' && strlen($response->body()) <= 1048576, 502);
            $body = $response->json();
            abort_unless(is_array($body) && empty($body['errors']) && is_array($body['data'] ?? null)
                && data_get($body, 'data.shop.myshopifyDomain') === $store->shop_domain, 409);
        } catch (\Throwable) {
            abort(502, 'The original Shopify subscription evidence could not be verified.');
        }
        $current = $store->fresh();
        app(SubscriptionReplacementAccess::class)->member($row->fresh());
        abort_unless($current && $current->tenant_id === $row->tenant_id && $current->shop_domain === $store->shop_domain
            && hash_equals((string) $current->access_token, (string) $store->access_token), 409, 'The original Shopify credential changed during verification.');

        return $body['data'];
    }

    public static function address(array $address): string
    {
        return SubscriptionEvidence::canonical(array_filter($address, fn ($value) => $value !== null && $value !== ''));
    }
}
