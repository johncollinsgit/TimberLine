<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAgreement;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/** Calculation only: never creates a draft, order, payment, customer or saved address. */
class SubscriptionAmendmentQuote
{
    public const CATALOG = <<<'GRAPHQL'
query ReplacementAmendmentCatalog($customer: ID!, $variants: [ID!]!) {
  shop { myshopifyDomain currencyCode plan { partnerDevelopment } }
  currentAppInstallation { id app { id } accessScopes { handle } }
  customer(id: $customer) { id }
  nodes(ids: $variants) { ... on ProductVariant { id title product { title status } inventoryItem { requiresShipping } } }
}
GRAPHQL;

    public const CALCULATE = <<<'GRAPHQL'
mutation ReplacementAmendmentCalculate($input: DraftOrderInput!) {
  draftOrderCalculate(input: $input) {
    userErrors { field message }
    calculatedDraftOrder {
      customer { id } currencyCode presentmentCurrencyCode taxesIncluded allVariantPricesOverridden
      alerts { __typename } warnings { __typename } appliedDiscount { __typename } discountCodes platformDiscounts { __typename } deposit { __typename }
      subtotalPriceSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } }
      totalPriceSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } }
      totalShippingPriceSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } }
      totalTaxSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } }
      totalDiscountsSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } }
      taxLines { title rate priceSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } } }
      lineItems {
        variant { id } quantity requiresShipping custom isGiftCard components { __typename } appliedDiscount { __typename }
        priceOverride { amount currencyCode }
        originalUnitPriceSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } }
        discountedTotalSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } }
      }
    }
  }
}
GRAPHQL;

    public function calculate(SubscriptionBillingAgreement $row, array $input, User $actor): array
    {
        $this->available($row, $actor);
        $proposal = app(SubscriptionAmendmentProposal::class)->review($row, $input);

        return $this->calculateProposal($row, $proposal, $actor);
    }

    public function verifyCurrent(SubscriptionBillingAgreement $row, User $actor): void
    {
        SubscriptionBillingAuthorization::assertCurrent($row);
        $action = \App\Models\SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->where('kind', 'terms_amended')->latest('id')->firstOrFail();
        $proposal = ['terms' => $row->terms, 'subject' => app(SubscriptionAmendmentProposal::class)->subject($row),
            'policy_review' => $action->request['quote']['policy_review']];
        $quote = $this->calculateProposal($row, $proposal, $actor, true);
        abort_unless(SubscriptionEvidence::canonical($quote['terms']) === SubscriptionEvidence::canonical($row->terms),
            409, 'Current tax or delivery totals differ from the accepted future terms. Review a fresh amendment before collection.');
    }

    private function calculateProposal(SubscriptionBillingAgreement $row, array $proposal, User $actor, bool $forCollection = false): array
    {
        $this->available($row, $actor, $forCollection);
        $terms = $proposal['terms'];
        $store = ShopifyStore::query()->forTenantId($row->tenant_id)->findOrFail($row->terms['shopify_store_id']);
        abort_unless($store->shop_domain === $row->terms['shop_domain'] && preg_match('/\A[a-z0-9-]+\.myshopify\.com\z/', $store->shop_domain)
            && filled($store->access_token), 409, 'The original Shopify store is unavailable.');
        $context = ['shopify_store_id' => $store->id, 'shop_domain' => $store->shop_domain,
            'credential_fingerprint' => SubscriptionEvidence::digest((string) $store->access_token), 'api_version' => SubscriptionAccessService::API_VERSION];
        $data = $this->request($row, $store, self::CATALOG, ['customer' => 'gid://shopify/Customer/'.$row->terms['shopify_customer_id'],
            'variants' => array_map(fn ($line) => 'gid://shopify/ProductVariant/'.$line['variant_id'], $terms['lines'])], $actor, $forCollection);
        abort_unless(data_get($data, 'shop.myshopifyDomain') === $store->shop_domain && data_get($data, 'shop.currencyCode') === $row->terms['currency']
            && data_get($data, 'customer.id') === 'gid://shopify/Customer/'.$row->terms['shopify_customer_id']
            && collect(data_get($data, 'currentAppInstallation.accessScopes', []))->pluck('handle')->contains('write_draft_orders')
            && preg_match('#\Agid://shopify/AppInstallation/[1-9][0-9]*\z#', (string) data_get($data, 'currentAppInstallation.id'))
            && preg_match('#\Agid://shopify/App/[1-9][0-9]*\z#', (string) data_get($data, 'currentAppInstallation.app.id'))
            && ($row->method_binding['environment'] !== 'sandbox' || data_get($data, 'shop.plan.partnerDevelopment') === true)
            && count($data['nodes'] ?? []) === count($terms['lines']), 409, 'The original store, calculation permission, customer or test environment needs review.');
        foreach ($terms['lines'] as $index => &$line) {
            $variant = $data['nodes'][$index] ?? [];
            abort_unless(($variant['id'] ?? null) === 'gid://shopify/ProductVariant/'.$line['variant_id']
                && data_get($variant, 'product.status') === 'ACTIVE' && data_get($variant, 'inventoryItem.requiresShipping') === true, 409, 'The selected scent is not available for delivery.');
            if ($line['variant_id'] !== $row->terms['lines'][$index]['variant_id']) {
                $label = trim((string) data_get($variant, 'product.title').' / '.(string) ($variant['title'] ?? ''));
                abort_unless(strlen($label) <= 255 && preg_match('/\b16\s*oz\b/i', $label), 409, 'Choose an active 16oz Candle Club scent.');
                $line['label'] = $label;
            }
        }
        unset($line);
        $input = ['purchasingEntity' => ['customerId' => 'gid://shopify/Customer/'.$row->terms['shopify_customer_id']],
            'presentmentCurrencyCode' => $terms['currency'], 'shippingAddress' => $terms['shipping_address'], 'useCustomerDefaultAddress' => false,
            'acceptAutomaticDiscounts' => false, 'allowDiscountCodesInCheckout' => false, 'discountCodes' => [],
            'shippingLine' => ['title' => 'Subscription shipping', 'priceWithCurrency' => self::money($terms['shipping_cents'], $terms['currency'])],
            'lineItems' => array_map(fn ($line) => ['variantId' => 'gid://shopify/ProductVariant/'.$line['variant_id'], 'quantity' => $line['quantity'],
                'priceOverride' => self::money($line['unit_price_cents'], $terms['currency'])], $terms['lines'])];
        $response = $this->request($row, $store, self::CALCULATE, ['input' => $input], $actor, $forCollection);
        $result = $response['draftOrderCalculate'] ?? [];
        abort_unless(($result['userErrors'] ?? null) === [] && is_array($result['calculatedDraftOrder'] ?? null), 409, 'Shopify could not calculate these future delivery terms.');
        $calculated = $result['calculatedDraftOrder'];
        abort_unless(data_get($calculated, 'customer.id') === $input['purchasingEntity']['customerId']
            && ($calculated['currencyCode'] ?? null) === $terms['currency'] && ($calculated['presentmentCurrencyCode'] ?? null) === $terms['currency']
            && ($calculated['taxesIncluded'] ?? null) === false && ($calculated['allVariantPricesOverridden'] ?? null) === true
            && ($calculated['alerts'] ?? null) === [] && ($calculated['warnings'] ?? null) === []
            && array_key_exists('appliedDiscount', $calculated) && $calculated['appliedDiscount'] === null
            && array_key_exists('deposit', $calculated) && $calculated['deposit'] === null
            && ($calculated['discountCodes'] ?? null) === [] && ($calculated['platformDiscounts'] ?? null) === [], 409, 'Review provider warnings, tax mode, pricing, currency or discounts before accepting this change.');
        abort_unless(is_array($calculated['lineItems'] ?? null) && count($calculated['lineItems']) === count($terms['lines']), 409);
        foreach ($terms['lines'] as $index => $line) {
            $actual = $calculated['lineItems'][$index];
            abort_unless(data_get($actual, 'variant.id') === 'gid://shopify/ProductVariant/'.$line['variant_id'] && ($actual['quantity'] ?? null) === $line['quantity']
                && ($actual['requiresShipping'] ?? null) === true && ($actual['custom'] ?? null) === false && ($actual['isGiftCard'] ?? null) === false
                && ($actual['components'] ?? null) === [] && array_key_exists('appliedDiscount', $actual) && $actual['appliedDiscount'] === null, 409, 'Calculated products or quantities differ from the requested membership.');
            SubscriptionShopifyContractProtocol::money($actual['priceOverride'] ?? [], $terms['currency'], $line['unit_price_cents']);
            $this->bag($actual['originalUnitPriceSet'] ?? [], $terms['currency'], $line['unit_price_cents']);
            $this->bag($actual['discountedTotalSet'] ?? [], $terms['currency'], $line['unit_price_cents'] * $line['quantity']);
        }
        $this->bag($calculated['subtotalPriceSet'] ?? [], $terms['currency'], $terms['subtotal_cents']);
        $this->bag($calculated['totalShippingPriceSet'] ?? [], $terms['currency'], $terms['shipping_cents']);
        $this->bag($calculated['totalDiscountsSet'] ?? [], $terms['currency'], 0);
        $terms['tax_cents'] = $this->bag($calculated['totalTaxSet'] ?? [], $terms['currency']);
        abort_unless(is_array($calculated['taxLines'] ?? null) && count($calculated['taxLines']) <= 25, 409);
        $terms['tax_lines'] = array_map(function ($tax) use ($terms) {
            abort_unless(is_string($tax['title'] ?? null) && strlen($tax['title']) <= 255 && (is_float($tax['rate'] ?? null) || is_int($tax['rate'] ?? null))
                && $tax['rate'] >= 0 && $tax['rate'] <= 1, 409, 'Calculated tax evidence is incomplete.');

            $rate = (float) $tax['rate'];

            return ['title' => $tax['title'], 'rate' => $rate === floor($rate) ? (int) $rate : $rate,
                'price_cents' => $this->bag($tax['priceSet'] ?? [], $terms['currency'])];
        }, $calculated['taxLines']);
        abort_unless(array_sum(array_column($terms['tax_lines'], 'price_cents')) === $terms['tax_cents'], 409, 'Calculated tax lines and total differ.');
        $terms['amount_cents'] = $terms['subtotal_cents'] + $terms['shipping_cents'] + $terms['tax_cents'];
        abort_unless($terms['amount_cents'] > 0 && $terms['amount_cents'] <= 10000000, 409);
        $this->bag($calculated['totalPriceSet'] ?? [], $terms['currency'], $terms['amount_cents']);
        $context += ['app_installation_id' => data_get($data, 'currentAppInstallation.id'), 'app_id' => data_get($data, 'currentAppInstallation.app.id')];

        return ['terms' => $terms, 'subject' => $proposal['subject'], 'policy_review' => $proposal['policy_review'], 'context' => $context, 'checked_at' => now()->toISOString(),
            'quote_hash' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical(['input' => $input, 'terms' => $terms, 'context' => $context,
                'subject' => $proposal['subject'], 'policy_review' => $proposal['policy_review']])),
            'tax_is_estimated' => true, 'creates_order' => false, 'charges_customer' => false];
    }

    private function request(SubscriptionBillingAgreement $row, ShopifyStore $store, string $document, array $variables, User $actor, bool $forCollection): array
    {
        $this->unchanged($row, $store, $actor, $forCollection);
        $response = Http::withHeaders(['X-Shopify-Access-Token' => $store->access_token, 'Accept' => 'application/json'])->withoutRedirecting()
            ->connectTimeout(3)->timeout(12)->post('https://'.$store->shop_domain.'/admin/api/'.SubscriptionAccessService::API_VERSION.'/graphql.json', ['query' => $document, 'variables' => $variables]);
        abort_unless($response->successful() && $response->header('X-Shopify-API-Version') === SubscriptionAccessService::API_VERSION && strlen($response->body()) <= 1048576, 502, 'The original store calculation could not be verified.');
        $body = $response->json();
        abort_unless(is_array($body) && empty($body['errors']) && is_array($body['data'] ?? null), 409, 'The original store calculation requires review.');
        $this->unchanged($row, $store, $actor, $forCollection);

        return $body['data'];
    }

    private function unchanged(SubscriptionBillingAgreement $row, ShopifyStore $store, User $actor, bool $forCollection): void
    {
        $current = $store->fresh();
        $agreement = $row->fresh();
        $this->available($agreement, $actor, $forCollection);
        SubscriptionBillingAuthorization::assertCurrent($agreement);
        abort_unless($current && $current->tenant_id === $row->tenant_id && $current->shop_domain === $store->shop_domain
            && hash_equals((string) $current->access_token, (string) $store->access_token) && $agreement->revision === $row->revision
            && $agreement->status === $row->status && $agreement->terms_hash === $row->terms_hash
            && SubscriptionEvidence::canonical($agreement->terms) === SubscriptionEvidence::canonical($row->terms),
            409, 'The original store, billing terms or schedule changed during calculation.');
    }

    private function available(SubscriptionBillingAgreement $row, User $actor, bool $forCollection = false): void
    {
        app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor->fresh(), $row->member_id, $forCollection ? 'collection' : 'servicing');
        app(SubscriptionReplacementAccess::class)->member($row);
        abort_unless($row->processor === 'braintree' && ($forCollection || config('subscriptions.replacement_amendments_enabled') === true)
            && in_array($row->tenant_id, config('subscriptions.replacement_tenant_ids', []), true)
            && in_array($row->member_id, config('subscriptions.replacement_member_ids', []), true), 423, 'Future delivery changes are not enabled for this membership.');
        abort_unless(in_array($row->status, ['prepared', 'active', 'paused'], true), 409, 'This subscription has no future delivery to change.');
    }

    private function bag(array $bag, string $currency, ?int $expected = null): int
    {
        $amount = SubscriptionShopifyContractProtocol::money($bag['shopMoney'] ?? [], $currency, $expected);
        SubscriptionShopifyContractProtocol::money($bag['presentmentMoney'] ?? [], $currency, $amount);

        return $amount;
    }

    private static function money(int $amount, string $currency): array
    {
        return ['amount' => SubscriptionPilotShopifyBridge::decimal($amount), 'currencyCode' => $currency];
    }
}
