<?php

namespace App\Mail;

use App\Models\TenantBillingReceipt;
use Illuminate\Mail\Mailable;

class TenantUsageReceiptMail extends Mailable
{
    public function __construct(public TenantBillingReceipt $receipt) {}

    public function build(): self
    {
        return $this->subject('Payment received — Everbranch usage statement')
            ->view('emails.tenant-usage-receipt')->text('emails.tenant-usage-receipt-text');
    }
}
