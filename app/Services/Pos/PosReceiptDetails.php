<?php

namespace App\Services\Pos;

use App\Services\PaymentsCore\Contract;

/** Receipt display only. No card fingerprints, identity matching or marketing enrichment. */
final class PosReceiptDetails
{
    public function __construct(private PosStore $store) {}

    public function observe(object $sale, array $payment): void
    {
        $card = $payment['card_details']['card'] ?? [];
        $details = $payment['card_details'] ?? [];
        $fields = ['cardholder_name' => $card['cardholder_name'] ?? null, 'card_brand' => $card['card_brand'] ?? null,
            'last_four' => $card['last_4'] ?? null, 'entry_method' => $details['entry_method'] ?? null,
            'application_identifier' => $details['application_identifier'] ?? null, 'application_name' => $details['application_name'] ?? null];
        foreach ($fields as $key => $value) {
            if (! is_string($value) || $value === '') {
                unset($fields[$key]);
            } else {
                $fields[$key] = mb_substr(preg_replace('/[\p{C}]/u', '', $value), 0, 150);
            }
        }
        if (isset($fields['last_four']) && ! preg_match('/^[0-9]{4}$/D', $fields['last_four'])) {
            unset($fields['last_four']);
        }
        if (! $fields) {
            return;
        }
        $prior = $this->forSale($sale);
        if ($prior === $fields) {
            return;
        }
        $this->store->event((int) $sale->tenant_id, $sale->id, 'provider_receipt_observed', ['payment_id' => $payment['id'],
            'fields' => $fields, 'hash' => Contract::hash($fields)]);
    }

    public function forSale(object $sale): array
    {
        $row = $this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('code', 'provider_receipt_observed')->orderByDesc('id')->first();

        return $row ? ($this->store->decrypt($row->details)['fields'] ?? []) : [];
    }
}
