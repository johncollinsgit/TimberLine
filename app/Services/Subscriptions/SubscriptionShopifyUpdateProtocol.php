<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAgreement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Pure native update intent/quote validation. This class never dispatches, commits or changes local billing. */
class SubscriptionShopifyUpdateProtocol
{
    public static function calculationId(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('#\Agid://shopify/SubscriptionContractCalculation(?:Pending|Success|Failure)?/[A-Za-z0-9_-]{1,190}\z#', $id);
    }

    public static function document(string $operation): string
    {
        $identity = SubscriptionShopifyBillingTransport::IDENTITY_FIELDS;
        $terms = SubscriptionShopifyContractProtocol::TERMS_FIELDS;
        $terms .= ' note lines(first: 25) { nodes { sellingPlanId sellingPlanName } }';
        $contract = 'id app { id } status nextBillingDate updatedAt '.$terms;
        $calculation = '__typename ... on SubscriptionContractCalculationPending { id } ... on SubscriptionContractCalculationFailure { id errors { code field } } ... on SubscriptionContractCalculationSuccess { id withMerchandiseCustomizations warnings { code field } projectedOrderTotals { subtotal { amount currencyCode } total { amount currencyCode } totalDelivery { amount currencyCode } totalDeliveryDiscounts { amount currencyCode } totalMerchandiseDiscounts { amount currencyCode } totalTax { amount currencyCode } } calculatedContract { '.$terms.' } }';

        return match ($operation) {
            'capabilities' => 'query ReplacementUpdateCapabilities { '.$identity.' mutationType: __type(name: "Mutation") { fields { name } } queryType: __type(name: "QueryRoot") { fields { name } } updateInputType: __type(name: "SubscriptionContractCalculationContractUpdateInput") { inputFields { name } } lineInputType: __type(name: "SubscriptionContractCalculationProductVariantLineInput") { inputFields { name } } }',
            'catalog' => 'query ReplacementUpdateCatalog($customer: ID!, $variants: [ID!]!) { '.$identity.' customer(id: $customer) { id } nodes(ids: $variants) { ... on ProductVariant { id title product { title status } inventoryItem { requiresShipping } } } }',
            'contract' => 'query ReplacementUpdateOriginalContract($id: ID!) { '.$identity.' subscriptionContract(id: $id) { '.$contract.' } }',
            'calculate' => 'mutation ReplacementCalculateUpdate($id: ID!, $actor: SubscriptionActor!, $input: SubscriptionContractCalculationContractUpdateInput!) { subscriptionContractUpdateCalculate(contractId: $id, actor: $actor, contractUpdateInput: $input) { subscriptionContractCalculation { '.$calculation.' } userErrors { code field } } }',
            'calculation' => 'query ReplacementUpdateCalculation($id: ID!) { '.$identity.' subscriptionContractCalculation(id: $id) { '.$calculation.' } }',
            'commit' => 'mutation ReplacementCommitUpdate($id: ID!) { subscriptionContractCalculationCommit(id: $id) { contract { __typename ... on SubscriptionContract { '.$contract.' } } userErrors { code field } } }',
            default => throw new \InvalidArgumentException('Unsupported original Shopify update operation.'),
        };
    }

    /** Accepted attributes must come from retained signed provider evidence, never client data. */
    public function intent(SubscriptionBillingAgreement $row, array $before, array $change, array $catalog, string $uuid,
        string $actor = 'MERCHANT', ?array $acceptedAttributes = null): array
    {
        SubscriptionBillingAuthorization::assertCurrent($row);
        $member = app(SubscriptionReplacementAccess::class)->member($row);
        abort_unless($row->processor === 'shopify' && $row->activated_at && $row->method_verified_at && $member->billing_owner === 'everbranch'
            && in_array($row->status, ['active', 'paused'], true) && Str::isUuid($uuid) && in_array($actor, ['MERCHANT', 'CUSTOMER'], true), 409, 'Review the individually activated original Shopify membership.');
        SubscriptionShopifyBillingProtocol::owned($row, $before);
        $acceptedAttributes ??= SubscriptionShopifyUpdateHistory::attributes($row);
        SubscriptionShopifyContractProtocol::assertTerms($row, $before, data_get($row->authorization_evidence, 'shopify_adoption.original.request_uuid', ''), $acceptedAttributes);
        abort_unless(array_key_exists('note', $before) && is_string($before['updatedAt'] ?? null)
            && in_array($before['status'] ?? null, ['ACTIVE', 'PAUSED'], true)
            && (($row->status === 'paused') === ($before['status'] === 'PAUSED'))
            && is_string($before['nextBillingDate'] ?? null)
            && ($row->status === 'paused' || SubscriptionShopifyBillingProtocol::sameInstant($before['nextBillingDate'],
                SubscriptionShopifyBillingProtocol::instant($row->terms, $row->next_billing_date->toDateString()))), 409, 'The original provider schedule, note or version needs review.');
        abort_unless(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $before['nextBillingDate'])
            && CarbonImmutable::parse($before['nextBillingDate'])->format('Y-m-d\\TH:i:s') === substr($before['nextBillingDate'], 0, 19), 409, 'Retain a valid provider billing date.');
        $proposal = app(SubscriptionAmendmentProposal::class)->review($row, $change);
        $terms = $proposal['terms'];
        $context = app(SubscriptionShopifyBillingTransport::class)->context($row);
        $this->catalog($row, $terms, $catalog, $context);
        $proposal['terms'] = $terms;
        $lines = $before['lines']['nodes'];
        $this->ids($lines);
        foreach ($lines as $line) {
            abort_unless(array_key_exists('sellingPlanId', $line) && array_key_exists('sellingPlanName', $line), 409, 'Retain the original line selling-plan metadata.');
        }
        $input = ['withMerchandiseCustomizations' => false, 'discountCodes' => [], 'customAttributes' => $this->attributes($before['customAttributes'], strtolower($uuid))];
        if (SubscriptionBillingShopifySource::address($terms['shipping_address']) !== SubscriptionBillingShopifySource::address($row->terms['shipping_address'])) {
            $input['deliveryMethod'] = ['shipping' => ['address' => $terms['shipping_address'],
                'deliveryPrice' => ['amount' => SubscriptionPilotShopifyBridge::decimal($terms['shipping_cents']), 'currencyCode' => $terms['currency']]]];
        }
        $replacements = [];
        $bindings = [];
        foreach ($terms['lines'] as $index => $line) {
            $original = $row->terms['lines'][$index];
            if ($line['variant_id'] === $original['variant_id']) {
                continue;
            }
            $matches = array_values(array_filter($lines, fn ($current) => ($current['variantId'] ?? null) === 'gid://shopify/ProductVariant/'.$original['variant_id']
                && ($current['quantity'] ?? null) === $original['quantity'] && SubscriptionShopifyContractProtocol::money($current['currentPrice'], $terms['currency']) === $original['unit_price_cents']));
            abort_unless(count($matches) === 1, 409, 'The original source line has ambiguous provider line IDs. Review its exact binding before swapping.');
            $bindings[$line['subscription_id']] = $matches[0]['id'];
            abort_if(isset($replacements[$matches[0]['id']]), 409, 'More than one source line resolves to the same provider line.');
            $replacements[$matches[0]['id']] = 'gid://shopify/ProductVariant/'.$line['variant_id'];
        }
        if ($replacements) {
            $input['lines'] = array_map(function ($line) use ($replacements, $terms) {
                $amount = SubscriptionShopifyContractProtocol::money($line['currentPrice'], $terms['currency']);

                return ['productVariantLine' => ['id' => $line['id'], 'productVariantId' => $replacements[$line['id']] ?? $line['variantId'], 'quantity' => $line['quantity'],
                    'priceOverride' => ['amount' => SubscriptionPilotShopifyBridge::decimal($amount), 'currencyCode' => $terms['currency']],
                    'customAttributes' => $line['customAttributes'], 'discounts' => [],
                    'originSellingPlanId' => $line['sellingPlanId'], 'sellingPlanName' => $line['sellingPlanName'],
                    'appManagedPricingPolicy' => ['basePrice' => $line['pricingPolicy']['basePrice'], 'cyclePriceAdjustments' => []]]];
            }, $lines);
        }
        $intent = ['version' => 1, 'subject' => $proposal['subject'], 'context' => $context, 'method_binding_hash' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($row->method_binding)),
            'local_state' => app(SubscriptionBillingAgreementService::class)->state($row),
            'before' => $before, 'proposal' => $proposal, 'line_bindings' => $bindings,
            'variables' => ['id' => $before['id'], 'actor' => $actor, 'input' => $input], 'request_uuid' => strtolower($uuid)];
        $intent['intent_hash'] = $this->hash($intent, 'intent_hash');

        return $intent;
    }

    public function quote(SubscriptionBillingAgreement $row, array $intent, array $calculation): array
    {
        $this->assertIntent($row, $intent);

        return $this->retainedQuote($row, $intent, $calculation);
    }

    /** Historical validation uses frozen before-terms and signed evidence, without live access or recursive history checks. */
    public function retainedQuote(SubscriptionBillingAgreement $row, array $intent, array $calculation): array
    {
        $this->assertRetainedIntent($row, $intent);
        abort_unless(($calculation['__typename'] ?? null) === 'SubscriptionContractCalculationSuccess' && ($calculation['warnings'] ?? null) === []
            && ($calculation['withMerchandiseCustomizations'] ?? null) === false
            && self::calculationId($calculation['id'] ?? null),
            409, 'Review the original update intent, pending calculation or provider warnings.');
        $terms = $intent['proposal']['terms'];
        $totals = $calculation['projectedOrderTotals'] ?? [];
        foreach (['subtotal' => 'subtotal_cents', 'totalDelivery' => 'shipping_cents'] as $field => $term) {
            SubscriptionShopifyContractProtocol::money($totals[$field] ?? [], $terms['currency'], $terms[$term]);
        }
        foreach (['totalDeliveryDiscounts', 'totalMerchandiseDiscounts'] as $field) {
            SubscriptionShopifyContractProtocol::money($totals[$field] ?? [], $terms['currency'], 0);
        }
        $terms['tax_cents'] = SubscriptionShopifyContractProtocol::money($totals['totalTax'] ?? [], $terms['currency']);
        unset($terms['tax_lines']); // Projected aggregate tax is not an itemized tax breakdown.
        $terms['amount_cents'] = $terms['subtotal_cents'] + $terms['shipping_cents'] + $terms['tax_cents'];
        abort_unless($terms['amount_cents'] > 0 && $terms['amount_cents'] <= 10000000, 409);
        SubscriptionShopifyContractProtocol::money($totals['total'] ?? [], $terms['currency'], $terms['amount_cents']);
        $this->result($row, $intent, $terms, $calculation['calculatedContract'] ?? []);
        $quote = ['intent_hash' => $intent['intent_hash'], 'calculation_id' => $calculation['id'], 'terms' => $terms,
            'contract_attributes' => $intent['variables']['input']['customAttributes'], 'tax_is_estimated' => true, 'changes_contract' => false, 'charges_customer' => false];
        $quote['review_hash'] = $this->hash($quote, 'review_hash');

        return $quote;
    }

    public function assertApplied(SubscriptionBillingAgreement $row, array $intent, array $quote, array $contract): void
    {
        $this->assertIntent($row, $intent);
        $this->assertRetainedApplied($row, $intent, $quote, $contract, [$intent['before']['status']]);
    }

    public function assertRetainedApplied(SubscriptionBillingAgreement $row, array $intent, array $quote, array $contract, array $allowedStatuses): void
    {
        $this->assertRetainedIntent($row, $intent);
        abort_unless(($quote['intent_hash'] ?? null) === ($intent['intent_hash'] ?? null) && is_string($quote['review_hash'] ?? null)
            && hash_equals($this->hash($quote, 'review_hash'), $quote['review_hash']), 409, 'Retain the exact accepted update quote.');
        SubscriptionShopifyBillingProtocol::owned($row, $contract);
        $this->result($row, $intent, $quote['terms'], $contract);
        abort_unless(in_array($contract['status'] ?? null, $allowedStatuses, true)
            && (SubscriptionShopifyBillingProtocol::sameInstant($contract['nextBillingDate'] ?? null, $intent['before']['nextBillingDate'])
                || (($contract['status'] ?? null) === 'CANCELLED' && array_key_exists('nextBillingDate', $contract) && $contract['nextBillingDate'] === null))
            && is_string($contract['updatedAt'] ?? null) && CarbonImmutable::parse($contract['updatedAt'])->gte(CarbonImmutable::parse($intent['before']['updatedAt'])),
            409, 'The provider schedule or contract version changed. Preserve later stops and reconcile the original update.');
    }

    private function assertRetainedIntent(SubscriptionBillingAgreement $row, array $intent): void
    {
        abort_unless(($intent['version'] ?? null) === 1 && is_string($intent['intent_hash'] ?? null)
            && hash_equals($this->hash($intent, 'intent_hash'), $intent['intent_hash'])
            && SubscriptionEvidence::canonical($intent['subject'] ?? []) === SubscriptionEvidence::canonical(app(SubscriptionAmendmentProposal::class)->subject($row))
            && ($intent['method_binding_hash'] ?? null) === SubscriptionEvidence::digest(SubscriptionEvidence::canonical($row->method_binding))
            && ($intent['variables']['id'] ?? null) === data_get($row->authorization_evidence, 'shopify_adoption.contract_id')
            && ($intent['local_state']['revision'] ?? null) === $row->revision && ($intent['local_state']['terms_hash'] ?? null) === $row->terms_hash,
            409, 'Retain the signed original native update intent and its frozen before-terms.');
    }

    private function result(SubscriptionBillingAgreement $row, array $intent, array $terms, array $contract): void
    {
        $candidate = clone $row;
        $candidate->terms = $terms;
        SubscriptionShopifyContractProtocol::assertTerms($candidate, $contract, '', $intent['variables']['input']['customAttributes']);
        abort_unless(array_key_exists('note', $contract) && $contract['note'] === $intent['before']['note'], 409, 'The original contract note changed.');
        $lines = $contract['lines']['nodes'];
        $this->ids($lines);
        $before = collect($intent['before']['lines']['nodes'])->keyBy('id');
        abort_unless($before->keys()->sort()->values()->all() === collect($lines)->pluck('id')->sort()->values()->all(), 409, 'The calculated update replaced original provider line IDs.');
        $expected = collect($intent['variables']['input']['lines'] ?? [])->pluck('productVariantLine')->keyBy('id');
        foreach ($lines as $line) {
            $original = $before[$line['id']];
            abort_unless(($line['variantId'] ?? null) === ($expected[$line['id']]['productVariantId'] ?? $original['variantId'])
                && array_key_exists('sellingPlanId', $line) && array_key_exists('sellingPlanName', $line)
                && $line['sellingPlanId'] === $original['sellingPlanId'] && $line['sellingPlanName'] === $original['sellingPlanName'], 409, 'The provider line binding or selling-plan metadata changed.');
        }
    }

    private function catalog(SubscriptionBillingAgreement $row, array &$terms, array $catalog, array $context): void
    {
        abort_unless(data_get($catalog, 'shop.myshopifyDomain') === $terms['shop_domain'] && data_get($catalog, 'shop.currencyCode') === $terms['currency']
            && data_get($catalog, 'customer.id') === 'gid://shopify/Customer/'.$terms['shopify_customer_id']
            && data_get($catalog, 'currentAppInstallation.id') === $context['app_installation_gid']
            && data_get($catalog, 'currentAppInstallation.app.id') === $row->method_binding['app_gid']
            && ($context['environment'] !== 'sandbox' || data_get($catalog, 'shop.plan.partnerDevelopment') === true)
            && count($catalog['nodes'] ?? []) === count($terms['lines']), 409, 'The original update catalog identity differs.');
        foreach ($terms['lines'] as $index => &$line) {
            $variant = $catalog['nodes'][$index];
            abort_unless(($variant['id'] ?? null) === 'gid://shopify/ProductVariant/'.$line['variant_id']
                && data_get($variant, 'product.status') === 'ACTIVE' && data_get($variant, 'inventoryItem.requiresShipping') === true, 409, 'A selected subscription product is not available for delivery.');
            if ($line['variant_id'] !== $row->terms['lines'][$index]['variant_id']) {
                $label = trim((string) data_get($variant, 'product.title').' / '.(string) ($variant['title'] ?? ''));
                abort_unless(strlen($label) <= 255 && preg_match('/\b16\s*oz\b/i', $label), 409, 'Choose an active 16oz Candle Club scent.');
                $line['label'] = $label;
            }
        }
        unset($line);
    }

    private function ids(array $lines): void
    {
        $ids = array_column($lines, 'id');
        abort_unless(count($ids) === count($lines) && count($ids) === count(array_unique($ids)), 409, 'Retain every distinct provider line ID.');
        foreach ($ids as $id) {
            abort_unless(is_string($id) && preg_match('#\Agid://shopify/SubscriptionLine/[A-Za-z0-9_-]{1,190}\z#', $id), 409, 'The original provider line ID is missing.');
        }
    }

    private function attributes(array $before, string $uuid): array
    {
        $attributes = array_values(array_filter($before, fn ($a) => ($a['key'] ?? null) !== 'everbranch_amendment'));
        $attributes[] = ['key' => 'everbranch_amendment', 'value' => $uuid];

        return $attributes;
    }

    private function hash(array $value, string $field): string
    {
        unset($value[$field]);

        return SubscriptionEvidence::digest(SubscriptionEvidence::canonical($value));
    }

    private function assertIntent(SubscriptionBillingAgreement $row, array $intent): void
    {
        SubscriptionBillingAuthorization::assertCurrent($row);
        abort_unless(is_string($intent['intent_hash'] ?? null) && hash_equals($this->hash($intent, 'intent_hash'), $intent['intent_hash'])
            && SubscriptionEvidence::canonical($intent['subject'] ?? []) === SubscriptionEvidence::canonical(app(SubscriptionAmendmentProposal::class)->subject($row))
            && SubscriptionEvidence::canonical($intent['context'] ?? []) === SubscriptionEvidence::canonical(app(SubscriptionShopifyBillingTransport::class)->context($row))
            && ($intent['method_binding_hash'] ?? null) === SubscriptionEvidence::digest(SubscriptionEvidence::canonical($row->method_binding)),
            409, 'The original update intent or provider context changed.');
    }
}
