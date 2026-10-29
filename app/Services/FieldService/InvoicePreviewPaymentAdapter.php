<?php

namespace App\Services\FieldService;

/** A deliberately non-networked payment rail placeholder for invoice previews. */
class InvoicePreviewPaymentAdapter
{
    /** @return array<string,mixed> */
    public function preview(string $invoiceProvider, int $amountCents): array
    {
        return [
            'processor' => $invoiceProvider === 'everbranch_pay' ? 'stripe' : null,
            'label' => $invoiceProvider === 'everbranch_pay'
                ? 'Stripe through Everbranch Pay'
                : 'Payment processing not selected in this preview',
            'amount_cents' => $amountCents,
            'status' => $invoiceProvider === 'everbranch_pay' ? 'setup_unverified' : 'not_selected',
            'payment_link_created' => false,
        ];
    }
}
