<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAgreement;
use Carbon\CarbonImmutable;

/** Version-pinned contract calculation/adoption protocol, separate from historical shadow reads. */
class SubscriptionShopifyContractProtocol
{
    public static function calculationId(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('#\Agid://shopify/SubscriptionContractCalculation(?:Pending|Success|Failure)?/[A-Za-z0-9_-]{1,190}\z#', $id);
    }

    public const TERMS_FIELDS = <<<'GRAPHQL'
currencyCode customer { id } customerPaymentMethod(showRevoked: true) { id revokedAt }
customAttributes { key value }
billingPolicy { interval intervalCount minCycles maxCycles anchors { type day month cutoffDay } }
deliveryPolicy { interval intervalCount anchors { type day month cutoffDay } multiFulfillment { numberOfFulfillments } }
deliveryPrice { amount currencyCode }
deliveryMethod { __typename ... on SubscriptionDeliveryMethodShipping { address { firstName lastName company address1 address2 city provinceCode countryCodeV2 zip phone } } }
lines(first: 25) { nodes { id variantId quantity currentPrice { amount currencyCode } requiresShipping customAttributes
  { key value } discountAllocations { __typename } pricingPolicy { basePrice { amount currencyCode } cycleDiscounts { __typename } } } pageInfo { hasNextPage } }
discounts(first: 1) { nodes { __typename } pageInfo { hasNextPage } }
GRAPHQL;

    public const CALCULATION_FIELDS = '__typename ... on SubscriptionContractCalculationPending { id } ... on SubscriptionContractCalculationFailure { id errors { code field } } ... on SubscriptionContractCalculationSuccess { id withMerchandiseCustomizations warnings { code field } projectedOrderTotals { subtotal { amount currencyCode } total { amount currencyCode } totalDelivery { amount currencyCode } totalDeliveryDiscounts { amount currencyCode } totalMerchandiseDiscounts { amount currencyCode } totalTax { amount currencyCode } } calculatedContract { '.self::TERMS_FIELDS.' } }';

    public const CONTRACT_FIELDS = 'id app { id } status nextBillingDate '.self::TERMS_FIELDS;

    public static function document(string $operation): string
    {
        $identity = SubscriptionShopifyBillingTransport::IDENTITY_FIELDS;

        return match ($operation) {
            'capabilities' => 'query ReplacementContractCapabilities { '.$identity.' mutationType: __type(name: "Mutation") { fields { name } } queryType: __type(name: "QueryRoot") { fields { name } } deliveryPolicyType: __type(name: "SubscriptionDeliveryPolicy") { fields { name } } createInputType: __type(name: "SubscriptionContractCalculationContractCreateInput") { inputFields { name } } }',
            'calculation' => 'query ReplacementContractCalculation($id: ID!) { '.$identity.' subscriptionContractCalculation(id: $id) { '.self::CALCULATION_FIELDS.' } }',
            'contract' => 'query ReplacementOwnedContract($id: ID!) { '.$identity.' subscriptionContract(id: $id) { '.self::CONTRACT_FIELDS.' } }',
            'customer_contracts' => 'query ReplacementOriginalCustomerContracts($id: ID!) { '.$identity.' customer(id: $id) { id subscriptionContracts(first: 100) { nodes { '.self::CONTRACT_FIELDS.' } pageInfo { hasNextPage } } } }',
            'calculate' => 'mutation ReplacementCalculateContract($input: SubscriptionContractCalculationContractCreateInput!) { subscriptionContractCreateCalculate(contractCreateInput: $input) { subscriptionContractCalculation { '.self::CALCULATION_FIELDS.' } userErrors { code field } } }',
            'commit' => 'mutation ReplacementCommitContract($id: ID!) { subscriptionContractCalculationCommit(id: $id) { contract { __typename ... on SubscriptionContract { '.self::CONTRACT_FIELDS.' } } userErrors { code field } } }',
            'pause' => 'mutation ReplacementPauseAdoptedContract($id: ID!) { subscriptionContractPause(subscriptionContractId: $id, actor: MERCHANT) { contract { id status } userErrors { code field } } }',
            'first_date' => 'mutation ReplacementSetOriginalBillingDate($id: ID!, $date: DateTime!) { subscriptionContractSetNextBillingDate(contractId: $id, date: $date) { contract { id nextBillingDate } userErrors { code field } } }',
            default => throw new \InvalidArgumentException('Unsupported replacement contract protocol.'),
        };
    }

    public static function input(SubscriptionBillingAgreement $row, string $requestUuid): array
    {
        $terms = $row->terms;
        $anchors = [['monthday' => ['dayOfMonth' => $row->billing_anchor_date->day]]];
        $money = fn (int $cents) => ['amount' => number_format($cents / 100, 2, '.', ''), 'currencyCode' => $terms['currency']];

        return ['actor' => 'MERCHANT', 'customerId' => 'gid://shopify/Customer/'.$terms['shopify_customer_id'], 'currencyCode' => $terms['currency'],
            'customAttributes' => [['key' => 'everbranch_agreement', 'value' => $row->public_id], ['key' => 'everbranch_adoption', 'value' => $requestUuid]],
            'billingPolicy' => ['cadence' => ['unit' => 'MONTH', 'count' => $terms['billing_months']], 'anchors' => $anchors],
            'deliveryPolicy' => ['anchors' => $anchors],
            'deliveryMethod' => ['shipping' => ['address' => $terms['shipping_address'], 'deliveryPrice' => $money($terms['shipping_cents'])]],
            'paymentMethod' => ['customerPaymentMethod' => ['id' => $row->method_binding['payment_method_gid']]],
            'discountCodes' => [], 'manualDiscounts' => [], 'withMerchandiseCustomizations' => false,
            'lines' => array_map(fn ($line) => ['productVariantLine' => ['productVariantId' => 'gid://shopify/ProductVariant/'.$line['variant_id'],
                'quantity' => $line['quantity'], 'priceOverride' => $money($line['unit_price_cents']), 'customAttributes' => [], 'discounts' => [],
                'appManagedPricingPolicy' => ['basePrice' => $money($line['unit_price_cents']), 'cyclePriceAdjustments' => []]]], $terms['lines'])];
    }

    public static function firstBillingInstant(SubscriptionBillingAgreement $row): string
    {
        return CarbonImmutable::parse($row->terms['first_billing_date'], $row->terms['timezone'])->startOfDay()
            ->setHour($row->terms['billing_hour'])->utc()->toISOString();
    }

    public static function assertTerms(SubscriptionBillingAgreement $row, array $contract, string $requestUuid, ?array $acceptedAttributes = null): void
    {
        $terms = $row->terms;
        $attributes = collect($contract['customAttributes'] ?? [])->map(fn ($item) => [$item['key'] ?? null, $item['value'] ?? null])->sort()->values()->all();
        $expectedAttributes = collect($acceptedAttributes ?? self::input($row, $requestUuid)['customAttributes'])->map(fn ($item) => [$item['key'], $item['value']])->sort()->values()->all();
        abort_unless(($contract['currencyCode'] ?? null) === $terms['currency']
            && data_get($contract, 'customer.id') === 'gid://shopify/Customer/'.$terms['shopify_customer_id']
            && data_get($contract, 'customerPaymentMethod.id') === $row->method_binding['payment_method_gid']
            && array_key_exists('revokedAt', $contract['customerPaymentMethod'] ?? []) && $contract['customerPaymentMethod']['revokedAt'] === null
            && $attributes === $expectedAttributes, 409, 'Calculated contract customer, saved method or original adoption identity differs.');
        foreach (['billingPolicy', 'deliveryPolicy'] as $field) {
            $policy = $contract[$field] ?? [];
            abort_unless(($policy['interval'] ?? null) === 'MONTH' && ($policy['intervalCount'] ?? null) === $terms['billing_months']
                && ($policy['anchors'] ?? null) === [['type' => 'MONTHDAY', 'day' => $row->billing_anchor_date->day, 'month' => null, 'cutoffDay' => null]], 409, 'Calculated contract billing or delivery anchor differs.');
        }
        abort_unless(array_key_exists('multiFulfillment', $contract['deliveryPolicy']) && $contract['deliveryPolicy']['multiFulfillment'] === null
            && array_key_exists('minCycles', $contract['billingPolicy']) && $contract['billingPolicy']['minCycles'] === null
            && array_key_exists('maxCycles', $contract['billingPolicy']) && $contract['billingPolicy']['maxCycles'] === null
            && data_get($contract, 'deliveryMethod.__typename') === 'SubscriptionDeliveryMethodShipping'
            && data_get($contract, 'lines.pageInfo.hasNextPage') === false
            && data_get($contract, 'discounts.pageInfo.hasNextPage') === false && data_get($contract, 'discounts.nodes') === [], 409, 'Calculated contract contains unreviewed delivery, commitment or discount terms.');
        self::money($contract['deliveryPrice'] ?? [], $terms['currency'], $terms['shipping_cents']);
        $address = data_get($contract, 'deliveryMethod.address', []);
        $address['countryCode'] = $address['countryCodeV2'] ?? null;
        unset($address['countryCodeV2']);
        abort_unless(SubscriptionBillingShopifySource::address($address) === SubscriptionBillingShopifySource::address($terms['shipping_address']), 409, 'Calculated contract delivery address differs.');
        $actual = collect(data_get($contract, 'lines.nodes', []))->map(function ($line) use ($terms) {
            abort_unless(($line['requiresShipping'] ?? null) === true && ($line['customAttributes'] ?? null) === [] && ($line['discountAllocations'] ?? null) === []
                && data_get($line, 'pricingPolicy.cycleDiscounts') === [], 409, 'Calculated contract line has unreviewed pricing or delivery rules.');
            $amount = self::money($line['currentPrice'] ?? [], $terms['currency']);
            self::money(data_get($line, 'pricingPolicy.basePrice', []), $terms['currency'], $amount);

            return [$line['variantId'] ?? null, $line['quantity'] ?? null, $amount];
        })->sort()->values()->all();
        $expected = collect($terms['lines'])->map(fn ($line) => ['gid://shopify/ProductVariant/'.$line['variant_id'], $line['quantity'], $line['unit_price_cents']])->sort()->values()->all();
        abort_unless($actual === $expected, 409, 'Calculated subscription products, quantities or prices differ.');
    }

    public static function quote(SubscriptionBillingAgreement $row, array $calculation, string $requestUuid): array
    {
        abort_unless(($calculation['__typename'] ?? null) === 'SubscriptionContractCalculationSuccess'
            && self::calculationId($calculation['id'] ?? null)
            && ($calculation['warnings'] ?? null) === [] && ($calculation['withMerchandiseCustomizations'] ?? null) === false, 409, 'Review the pending/failed calculation or provider warnings before adoption.');
        self::assertTerms($row, $calculation['calculatedContract'] ?? [], $requestUuid);
        $totals = $calculation['projectedOrderTotals'] ?? [];
        foreach (['subtotal' => 'subtotal_cents', 'total' => 'amount_cents', 'totalDelivery' => 'shipping_cents', 'totalTax' => 'tax_cents'] as $field => $term) {
            self::money($totals[$field] ?? [], $row->terms['currency'], $row->terms[$term]);
        }
        foreach (['totalDeliveryDiscounts', 'totalMerchandiseDiscounts'] as $field) {
            self::money($totals[$field] ?? [], $row->terms['currency'], 0);
        }

        return ['calculation_id' => $calculation['id'], 'currency' => $row->terms['currency'], 'amount_cents' => $row->terms['amount_cents'],
            'shipping_cents' => $row->terms['shipping_cents'], 'estimated_tax_cents' => $row->terms['tax_cents'], 'first_billing_date' => $row->terms['first_billing_date'],
            'review_hash' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical(['terms_hash' => $row->terms_hash, 'request_uuid' => $requestUuid, 'calculation' => $calculation])),
            'tax_is_estimated' => true, 'creates_order' => false, 'charges_customer' => false];
    }

    public static function money(array $money, string $currency, ?int $expected = null): int
    {
        abort_unless(($money['currencyCode'] ?? null) === $currency && is_string($money['amount'] ?? null)
            && preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $money['amount']), 409, 'The original subscription money evidence is incomplete.');
        $cents = SubscriptionPilotShopifyBridge::cents($money['amount']);
        abort_unless($expected === null || $cents === $expected, 409, 'Calculated products, shipping, tax or total differ from the reviewed subscription.');

        return $cents;
    }
}
