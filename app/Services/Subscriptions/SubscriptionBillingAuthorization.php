<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAgreement;

/** Original continuing authority is evidence, never a projection of editable current terms. */
class SubscriptionBillingAuthorization
{
    public static function original(SubscriptionBillingAgreement $row): array
    {
        $snapshot = data_get($row->authorization_evidence, 'original_authorization');
        abort_unless(is_array($snapshot) && ($snapshot['version'] ?? null) === 1
            && is_array($snapshot['terms'] ?? null) && is_array($snapshot['authorization'] ?? null)
            && is_string($snapshot['terms_hash'] ?? null), 409, 'The original reviewed authorization snapshot is missing. Do not reconstruct it from current terms.');
        $hash = SubscriptionEvidence::digest(SubscriptionEvidence::canonical([
            'terms' => $snapshot['terms'], 'binding' => $row->method_binding, 'authorization' => $snapshot['authorization'],
        ]));
        abort_unless(hash_equals($hash, $snapshot['terms_hash']), 409, 'The original reviewed authorization no longer matches its retained evidence.');
        foreach (['reference', 'continuing_authorization_reviewed_by', 'source_terms_reviewed_by'] as $field) {
            abort_unless(($snapshot['authorization'][$field] ?? null) === data_get($row->authorization_evidence, $field), 409, 'The original authorization review changed.');
        }
        foreach (['version', 'member_id', 'shopify_store_id', 'shop_domain', 'shopify_customer_id', 'recharge_customer_id',
            'currency', 'billing_months', 'first_billing_date', 'billing_hour', 'timezone', 'candle_cash_allowed'] as $field) {
            abort_unless(array_key_exists($field, $snapshot['terms']) && ($row->terms[$field] ?? null) === $snapshot['terms'][$field], 409, 'The original customer, currency or billing schedule cannot be replaced by a terms edit.');
        }
        abort_unless(($snapshot['terms']['member_id'] ?? null) === $row->member_id
            && $snapshot['terms']['first_billing_date'] === $row->billing_anchor_date?->toDateString(), 409, 'The original agreement binding or anchor changed.');

        return $snapshot;
    }

    /** Accepted amendments carry separate immutable evidence; arbitrary JSON edits never do. */
    public static function assertCurrent(SubscriptionBillingAgreement $row, bool $currentRead = false): void
    {
        $original = self::original($row);
        SubscriptionBillingTermsHistory::assertCurrent($row, $original, $currentRead);
    }
}
