<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAgreement;
use Illuminate\Support\Str;

/** Calculation-only tax check. Its review marker is never committed to the real contract. */
class SubscriptionShopifyTaxProtocol
{
    public static function intent(SubscriptionBillingAgreement $row, array $before, string $uuid, string $date): array
    {
        SubscriptionBillingAuthorization::assertCurrent($row);
        SubscriptionShopifyBillingProtocol::owned($row, $before);
        $attributes = SubscriptionShopifyUpdateHistory::attributes($row);
        abort_unless(Str::isUuid($uuid) && is_array($attributes) && $row->status === 'active'
            && $row->next_billing_date?->toDateString() === $date && ($before['status'] ?? null) === 'ACTIVE'
            && SubscriptionShopifyBillingProtocol::sameInstant($before['nextBillingDate'] ?? null, SubscriptionShopifyBillingProtocol::instant($row->terms, $date))
            && array_key_exists('note', $before) && is_string($before['updatedAt'] ?? null), 409, 'Review the accepted native terms and current billing date before calculating tax.');
        SubscriptionShopifyContractProtocol::assertTerms($row, $before, '', $attributes);
        $attributes[] = ['key' => 'everbranch_collection_review', 'value' => $uuid];
        $intent = ['version' => 1, 'purpose' => 'native_collection_tax', 'subject' => app(SubscriptionAmendmentProposal::class)->subject($row),
            'date' => $date, 'request_uuid' => $uuid, 'context' => app(SubscriptionShopifyBillingTransport::class)->context($row),
            'method_binding_hash' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($row->method_binding)), 'before' => $before,
            'variables' => ['id' => $before['id'], 'actor' => 'MERCHANT', 'input' => ['withMerchandiseCustomizations' => false, 'discountCodes' => [], 'customAttributes' => $attributes]]];
        $intent['intent_hash'] = self::hash($intent);

        return $intent;
    }

    public static function assertIntent(SubscriptionBillingAgreement $row, array $intent, bool $currentRead = false): void
    {
        SubscriptionBillingAuthorization::assertCurrent($row, $currentRead);
        abort_unless(($intent['version'] ?? null) === 1 && ($intent['purpose'] ?? null) === 'native_collection_tax'
            && is_string($intent['intent_hash'] ?? null) && hash_equals(self::hash($intent), $intent['intent_hash'])
            && SubscriptionEvidence::canonical($intent['subject'] ?? []) === SubscriptionEvidence::canonical(app(SubscriptionAmendmentProposal::class)->subject($row))
            && ($intent['method_binding_hash'] ?? null) === SubscriptionEvidence::digest(SubscriptionEvidence::canonical($row->method_binding))
            && ($intent['context'] ?? null) === app(SubscriptionShopifyBillingTransport::class)->context($row, $currentRead)
            && ($intent['date'] ?? null) === $row->next_billing_date?->toDateString(), 409, 'The native tax review no longer matches its original billing authority.');
    }

    public static function quote(SubscriptionBillingAgreement $row, array $intent, array $calculation, bool $currentRead = false): array
    {
        self::assertIntent($row, $intent, $currentRead);
        abort_unless(($calculation['__typename'] ?? null) === 'SubscriptionContractCalculationSuccess'
            && SubscriptionShopifyUpdateProtocol::calculationId($calculation['id'] ?? null)
            && ($calculation['warnings'] ?? null) === [] && ($calculation['withMerchandiseCustomizations'] ?? null) === false,
            409, 'Review the pending, failed or warning-bearing native tax calculation before billing.');
        app(SubscriptionShopifyUpdateProtocol::class)->assertUnchangedProjection($row, $intent['before'], $calculation['calculatedContract'] ?? [], $intent['variables']['input']['customAttributes']);
        foreach (['subtotal' => 'subtotal_cents', 'totalDelivery' => 'shipping_cents', 'totalTax' => 'tax_cents', 'total' => 'amount_cents'] as $field => $term) {
            SubscriptionShopifyContractProtocol::money($calculation['projectedOrderTotals'][$field] ?? [], $row->terms['currency'], $row->terms[$term]);
        }
        foreach (['totalDeliveryDiscounts', 'totalMerchandiseDiscounts'] as $field) {
            SubscriptionShopifyContractProtocol::money($calculation['projectedOrderTotals'][$field] ?? [], $row->terms['currency'], 0);
        }

        return ['intent_hash' => $intent['intent_hash'], 'calculation_id' => $calculation['id'], 'amount_cents' => $row->terms['amount_cents'],
            'tax_cents' => $row->terms['tax_cents'], 'currency' => $row->terms['currency'], 'changes_contract' => false, 'charges_customer' => false];
    }

    private static function hash(array $intent): string
    {
        unset($intent['intent_hash']);

        return SubscriptionEvidence::digest(SubscriptionEvidence::canonical($intent));
    }
}
