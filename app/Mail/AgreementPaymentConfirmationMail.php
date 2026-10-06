<?php

namespace App\Mail;

use App\Models\Agreement;
use App\Models\TenantBillingReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

class AgreementPaymentConfirmationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Agreement $agreement, public TenantBillingReceipt $receipt, public string $proposalUrl)
    {
        if ($receipt->status !== 'paid' || ! $receipt->paid_at || ! filled($receipt->source_event_id)
            || (int) $receipt->tenant_id !== (int) $agreement->tenant_id
            || (int) $receipt->billingOrder?->agreement_id !== (int) $agreement->id) {
            throw new InvalidArgumentException('Payment confirmation requires a confirmed receipt for this agreement.');
        }
    }

    public function build(): self
    {
        return $this->subject('Payment confirmed — Everbranch')
            ->view('emails.agreement-payment-confirmation')
            ->text('emails.agreement-payment-confirmation-text');
    }
}
