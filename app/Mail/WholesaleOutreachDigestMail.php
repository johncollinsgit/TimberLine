<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class WholesaleOutreachDigestMail extends Mailable
{
    public function __construct(public array $report, public int $deliveryId) {}

    public function build(): self
    {
        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            $message->getHeaders()->addTextHeader('X-Everbranch-Logical-Message-ID', 'wholesale-outreach:'.$this->deliveryId);
            $message->getHeaders()->addTextHeader('X-SMTPAPI', json_encode(['unique_args' => [
                'marketing_email_delivery_id' => (string) $this->deliveryId,
                'tenant_id' => (string) $this->report['tenant']['id'],
            ]], JSON_THROW_ON_ERROR));
        });

        return $this->from((string) config('mail.from.address'), 'Everbranch')
            ->subject('Your wholesale outreach list · '.$this->report['tenant']['name'].' · '.$this->report['week'])
            ->view('emails.wholesale.outreach-digest')->text('emails.wholesale.outreach-digest-text');
    }
}
