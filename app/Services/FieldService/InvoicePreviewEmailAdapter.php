<?php

namespace App\Services\FieldService;

/** Produces a delivery mockup without resolving a tenant mail provider. */
class InvoicePreviewEmailAdapter
{
    /** @return array<string,mixed> */
    public function preview(?string $recipient, string $businessName, string $total): array
    {
        return [
            'recipient' => $recipient,
            'subject' => 'Invoice preview from '.$businessName,
            'body_summary' => 'Invoice total: '.$total,
            'status' => 'not_sent',
            'message_id' => null,
        ];
    }
}
