<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionPilotMember;
use Carbon\CarbonImmutable;

/** Fixed native billing protocol. A successful attempt owns its order; no external order-create handoff. */
class SubscriptionShopifyBillingProtocol
{
    public const TRANSACTION_FIELDS = 'id gateway kind status test processedAt amountSet { shopMoney { amount currencyCode } }';

    public const RECEIPT_FIELDS = SubscriptionShopifyBillingTransport::ORDER_FIELDS.' createdAt displayFulfillmentStatus currentTotalPriceSet { shopMoney { amount currencyCode } } totalDiscountsSet { shopMoney { amount currencyCode } } refunds(first: 1) { id } disputes { id status } transactions(first: 50) { '.self::TRANSACTION_FIELDS.' }';

    public const ATTEMPT_FIELDS = 'id idempotencyKey createdAt completedAt originTime respectInventoryPolicy subscriptionContract { id app { id } customer { id } } state { __typename ... on SubscriptionBillingAttemptPendingState { processing } ... on SubscriptionBillingAttemptFailedState { error { __typename } } ... on SubscriptionBillingAttemptActionRequiredState { action { __typename } } ... on SubscriptionBillingAttemptSuccessState { order { '.self::RECEIPT_FIELDS.' } } } transactions(first: 50) { nodes { '.self::TRANSACTION_FIELDS.' } pageInfo { hasNextPage } }';

    public static function document(string $operation): string
    {
        $identity = SubscriptionShopifyBillingTransport::IDENTITY_FIELDS;

        return match ($operation) {
            'billing_capabilities' => 'query ReplacementBillingCapabilities { '.$identity.' mutationType: __type(name: "Mutation") { fields { name } } attemptType: __type(name: "SubscriptionBillingAttempt") { fields { name } } inputType: __type(name: "SubscriptionBillingAttemptInput") { inputFields { name } } stateType: __type(name: "SubscriptionBillingAttemptState") { possibleTypes { name } } }',
            'billing_cycle' => 'query ReplacementBillingCycle($input: SubscriptionBillingCycleInput!) { '.$identity.' subscriptionBillingCycle(billingCycleInput: $input) { billingAttemptExpectedDate cycleIndex cycleStartAt cycleEndAt edited skipped status sourceContract { id app { id } customer { id } } billingAttempts(first: 1) { nodes { id } pageInfo { hasNextPage } } } }',
            'attempt' => 'query ReplacementOriginalBillingAttempt($id: ID!) { '.$identity.' subscriptionBillingAttempt(id: $id) { '.self::ATTEMPT_FIELDS.' } }',
            'attempts' => 'query ReplacementOriginalContractAttempts($id: ID!, $after: String) { '.$identity.' subscriptionContract(id: $id) { id app { id } customer { id } billingAttempts(first: 100, after: $after) { nodes { '.self::ATTEMPT_FIELDS.' } pageInfo { hasNextPage endCursor } } } }',
            'bill' => 'mutation ReplacementBillOriginalCycle($id: ID!, $input: SubscriptionBillingAttemptInput!) { subscriptionBillingAttemptCreate(subscriptionContractId: $id, subscriptionBillingAttemptInput: $input) { subscriptionBillingAttempt { '.self::ATTEMPT_FIELDS.' } userErrors { code field } } }',
            'activate' => 'mutation ReplacementActivateOwnedContract($id: ID!) { subscriptionContractActivate(subscriptionContractId: $id, actor: MERCHANT) { contract { id status } userErrors { code field } } }',
            'cancel' => 'mutation ReplacementCancelOwnedContract($id: ID!) { subscriptionContractCancel(subscriptionContractId: $id, actor: MERCHANT) { contract { id status } userErrors { code field } } }',
            default => throw new \InvalidArgumentException('Unsupported original Shopify billing operation.'),
        };
    }

    public static function instant(array $terms, string $date): string
    {
        return CarbonImmutable::parse($date, $terms['timezone'])->startOfDay()->setHour($terms['billing_hour'])->utc()->toISOString();
    }

    public static function payload(SubscriptionBillingCycle $cycle): array
    {
        $instant = self::instant($cycle->terms, $cycle->cycle_date->toDateString());

        return ['subscriptionContractId' => $cycle->terms['shopify_contract_id'], 'subscriptionBillingAttemptInput' => [
            'actor' => 'PARTNER', 'idempotencyKey' => $cycle->request_uuid, 'originTime' => $instant,
            'billingCycleSelector' => ['date' => $instant], 'inventoryPolicy' => 'PRODUCT_VARIANT_INVENTORY_POLICY',
            'paymentProcessingPolicy' => 'FAIL_UNLESS_VALID_PAYMENT_METHOD',
        ]];
    }

    public static function owned(SubscriptionBillingAgreement $row, array $contract): void
    {
        abort_unless(($contract['id'] ?? null) === data_get($row->authorization_evidence, 'shopify_adoption.contract_id')
            && data_get($contract, 'app.id') === $row->method_binding['app_gid']
            && data_get($contract, 'customer.id') === 'gid://shopify/Customer/'.$row->terms['shopify_customer_id'], 409, 'This is not the original owned Shopify subscription contract.');
    }

    public static function unbilled(SubscriptionBillingAgreement $row, array $cycle, string $date): void
    {
        self::owned($row, $cycle['sourceContract'] ?? []);
        $instant = CarbonImmutable::parse(self::instant($row->terms, $date));
        abort_unless(($cycle['edited'] ?? null) === false && ($cycle['skipped'] ?? null) === false && ($cycle['status'] ?? null) === 'UNBILLED'
            && is_int($cycle['cycleIndex'] ?? null) && $cycle['cycleIndex'] >= 1
            && self::sameInstant($cycle['billingAttemptExpectedDate'] ?? null, $instant->toISOString())
            && is_string($cycle['cycleStartAt'] ?? null) && is_string($cycle['cycleEndAt'] ?? null)
            && $instant->gte(CarbonImmutable::parse($cycle['cycleStartAt'])) && $instant->lt(CarbonImmutable::parse($cycle['cycleEndAt']))
            && data_get($cycle, 'billingAttempts.nodes') === [] && data_get($cycle, 'billingAttempts.pageInfo.hasNextPage') === false,
            409, 'The original Shopify cycle is edited, skipped, billed, attempted or differs from the reviewed billing instant.');
    }

    public static function attempt(SubscriptionBillingCycle $cycle, array $attempt): array
    {
        $terms = $cycle->terms;
        abort_unless($cycle->processor === 'shopify' && $cycle->payment_started_at
            && $cycle->amount_cents === $terms['amount_cents'] && $cycle->currency === $terms['currency']
            && $cycle->provider_request === self::payload($cycle)
            && preg_match('#\Agid://shopify/SubscriptionBillingAttempt/[1-9][0-9]*\z#', (string) ($attempt['id'] ?? ''))
            && (! $cycle->provider_reference || $cycle->provider_reference === $attempt['id'])
            && ($attempt['idempotencyKey'] ?? null) === $cycle->request_uuid
            && ($attempt['respectInventoryPolicy'] ?? null) === true
            && self::sameInstant($attempt['originTime'] ?? null, self::instant($terms, $cycle->cycle_date->toDateString()))
            && data_get($attempt, 'subscriptionContract.id') === $terms['shopify_contract_id']
            && data_get($attempt, 'subscriptionContract.app.id') === $cycle->method_binding['app_gid']
            && data_get($attempt, 'subscriptionContract.customer.id') === 'gid://shopify/Customer/'.$terms['shopify_customer_id']
            && is_string($attempt['createdAt'] ?? null)
            && CarbonImmutable::parse($attempt['createdAt'])->betweenIncluded($cycle->payment_started_at->subSeconds(30), $cycle->payment_started_at->addMinutes(5))
            && data_get($attempt, 'transactions.pageInfo.hasNextPage') === false && is_array(data_get($attempt, 'transactions.nodes')),
            409, 'The billing attempt does not identify the immutable original cycle and contract.');
        $transactions = data_get($attempt, 'transactions.nodes');
        $state = data_get($attempt, 'state.__typename');
        $status = match ($state) {
            'SubscriptionBillingAttemptPendingState' => 'processing',
            'SubscriptionBillingAttemptActionRequiredState' => 'requires_action',
            'SubscriptionBillingAttemptFailedState' => 'declined',
            'SubscriptionBillingAttemptSuccessState' => 'succeeded',
            default => abort(409, 'The original billing attempt state is unsupported or incomplete.'),
        };
        if ($status !== 'succeeded') {
            abort_unless(collect($transactions)->every(fn ($t) => ! in_array($t['kind'] ?? null, ['SALE', 'CAPTURE', 'REFUND'], true)
                || ($t['status'] ?? null) === 'FAILURE'), 409, 'Payment evidence conflicts with the pending, challenged or failed attempt.');
            abort_unless($status !== 'processing' || data_get($attempt, 'state.processing') === true, 409);

            return ['status' => $status, 'attempt_id' => $attempt['id'], 'checked_at' => now()->toISOString()];
        }
        $order = data_get($attempt, 'state.order');
        abort_unless(is_array($order) && preg_match('#\Agid://shopify/Order/[1-9][0-9]*\z#', (string) ($order['id'] ?? ''))
            && (! $cycle->shopify_order_id || $order['id'] === 'gid://shopify/Order/'.$cycle->shopify_order_id)
            && data_get($order, 'customer.id') === 'gid://shopify/Customer/'.$terms['shopify_customer_id']
            && ($order['test'] ?? null) === ($cycle->method_binding['environment'] === 'sandbox')
            && array_key_exists('cancelledAt', $order) && $order['cancelledAt'] === null
            && data_get($order, 'lineItems.pageInfo.hasNextPage') === false && is_array($order['refunds'] ?? null) && is_array($order['disputes'] ?? null), 409, 'The original billing attempt has no complete owned order receipt.');
        foreach (['totalPriceSet' => 'amount_cents', 'totalShippingPriceSet' => 'shipping_cents', 'totalTaxSet' => 'tax_cents'] as $field => $term) {
            SubscriptionShopifyContractProtocol::money(data_get($order, $field.'.shopMoney', []), $cycle->currency, $terms[$term]);
        }
        SubscriptionShopifyContractProtocol::money(data_get($order, 'totalDiscountsSet.shopMoney', []), $cycle->currency, 0);
        $lines = collect(data_get($order, 'lineItems.nodes', []))->map(fn ($line) => [data_get($line, 'variant.id'), $line['quantity'] ?? null,
            SubscriptionShopifyContractProtocol::money(data_get($line, 'originalUnitPriceSet.shopMoney', []), $cycle->currency)])->sort()->values()->all();
        $expected = collect($terms['lines'])->map(fn ($line) => ['gid://shopify/ProductVariant/'.$line['variant_id'], $line['quantity'], $line['unit_price_cents']])->sort()->values()->all();
        $address = $order['shippingAddress'] ?? [];
        $address['countryCode'] = $address['countryCodeV2'] ?? null;
        unset($address['countryCodeV2'], $address['province']);
        abort_unless($lines === $expected && SubscriptionBillingShopifySource::address($address) === SubscriptionBillingShopifySource::address($terms['shipping_address']), 409, 'The original Shopify receipt products or shipping address differ.');
        $receipt = ['attempt_id' => $attempt['id'], 'order_id' => basename($order['id']), 'checked_at' => now()->toISOString(), 'updated_at' => $order['updatedAt'] ?? null,
            'receipt_digest' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($order))];
        if ($order['disputes'] !== []) {
            return $receipt + ['status' => 'disputed'];
        }
        if ($order['refunds'] !== [] || in_array($order['displayFinancialStatus'] ?? null, ['REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
            return $receipt + ['status' => 'refunded'];
        }
        abort_unless(($order['displayFinancialStatus'] ?? null) === 'PAID' && is_string($attempt['completedAt'] ?? null)
            && is_string($order['processedAt'] ?? null) && is_string($order['updatedAt'] ?? null)
            && CarbonImmutable::parse($attempt['completedAt'])->betweenIncluded(CarbonImmutable::parse($attempt['createdAt']), now()->addSeconds(5))
            && CarbonImmutable::parse($order['processedAt'])->betweenIncluded($cycle->payment_started_at->subSeconds(30), now()->addSeconds(5)), 409, 'The original attempt has not confirmed a paid order.');
        SubscriptionShopifyContractProtocol::money(data_get($order, 'currentTotalPriceSet.shopMoney', []), $cycle->currency, $cycle->amount_cents);
        $payments = self::payments($transactions, $cycle);
        abort_unless($payments === self::payments($order['transactions'] ?? [], $cycle) && count($payments) === 1, 409, 'Order and attempt payment coverage differs or contains multiple collections.');

        return $receipt + ['status' => 'succeeded', 'paid_at' => $attempt['completedAt'], 'payment_ids' => array_keys($payments)];
    }

    private static function payments(array $transactions, SubscriptionBillingCycle $cycle): array
    {
        abort_unless(count($transactions) < 50 && array_is_list($transactions), 409, 'Shopify payment coverage is incomplete.');
        $payments = [];
        foreach ($transactions as $t) {
            abort_unless(is_string($t['id'] ?? null) && in_array($t['status'] ?? null, ['SUCCESS', 'FAILURE'], true)
                && ($t['test'] ?? null) === ($cycle->method_binding['environment'] === 'sandbox'), 409, 'An original payment is pending or its environment is unknown.');
            if (($t['status'] ?? null) === 'FAILURE' || in_array($t['kind'] ?? null, ['AUTHORIZATION', 'VOID'], true)) {
                continue;
            }
            abort_unless(in_array($t['kind'] ?? null, ['SALE', 'CAPTURE'], true) && ($t['gateway'] ?? null) === 'shopify_payments'
                && ! isset($payments[$t['id']]), 409, 'The original receipt includes an unexpected payment, refund or gateway.');
            $payments[$t['id']] = SubscriptionShopifyContractProtocol::money(data_get($t, 'amountSet.shopMoney', []), $cycle->currency, $cycle->amount_cents);
        }
        ksort($payments);

        return $payments;
    }

    /** Exclude only a freshly verified native receipt of this immutable original attempt, never an arbitrary order ID. */
    public static function excludes(SubscriptionBillingCycle $original, SubscriptionPilotMember $member, string $date, array $order): bool
    {
        $cycle = SubscriptionBillingCycle::query()->forTenantId($member->tenant_id)->findOrFail($original->id);
        if (($order['id'] ?? null) !== 'gid://shopify/Order/'.$cycle->shopify_order_id) {
            return false;
        }
        $agreement = SubscriptionBillingAgreement::query()->forTenantId($member->tenant_id)->findOrFail($cycle->agreement_id);
        $receipt = data_get($cycle->reconciliation, 'latest_shopify_receipt', []);
        abort_unless($cycle->processor === 'shopify' && $cycle->payment_started_at && $agreement->member_id === $member->id
            && $cycle->cycle_date->toDateString() === $date && $cycle->method_binding === $agreement->method_binding
            && ($receipt['attempt_id'] ?? null) === $cycle->provider_reference && ($receipt['order_id'] ?? null) === $cycle->shopify_order_id
            && ($receipt['status'] ?? null) === 'succeeded' && is_string($receipt['checked_at'] ?? null)
            && CarbonImmutable::parse($receipt['checked_at'])->betweenIncluded(now()->subMinute(), now()->addSeconds(5))
            && ($receipt['updated_at'] ?? null) === ($order['updatedAt'] ?? null)
            && ($order['test'] ?? null) === ($cycle->method_binding['environment'] === 'sandbox')
            && data_get($order, 'customer.id') === 'gid://shopify/Customer/'.$member->shopify_customer_id
            && ($order['displayFinancialStatus'] ?? null) === 'PAID' && array_key_exists('cancelledAt', $order) && $order['cancelledAt'] === null,
            409, 'The native order exclusion is not a fresh verified receipt of this original cycle.');
        SubscriptionShopifyContractProtocol::money(data_get($order, 'totalPriceSet.shopMoney', []), $cycle->currency, $cycle->amount_cents);
        $payments = collect($order['transactions'] ?? [])->filter(fn ($t) => ($t['gateway'] ?? null) === 'shopify_payments'
            && ($t['status'] ?? null) === 'SUCCESS' && in_array($t['kind'] ?? null, ['SALE', 'CAPTURE'], true));
        abort_unless($payments->count() === 1 && $payments->pluck('id')->all() === ($receipt['payment_ids'] ?? null), 409, 'The native order payment changed during source coverage.');
        foreach ($payments as $payment) {
            SubscriptionShopifyContractProtocol::money(data_get($payment, 'amountSet.shopMoney', []), $cycle->currency, $cycle->amount_cents);
        }

        return true;
    }

    public static function sameInstant(mixed $actual, string $expected): bool
    {
        return is_string($actual) && CarbonImmutable::parse($actual)->equalTo(CarbonImmutable::parse($expected));
    }
}
