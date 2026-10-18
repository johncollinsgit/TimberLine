<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class RetailSignupConfirmationMail extends Mailable
{
    public function __construct(public string $business, public string $reference, public array $offer) {}

    public function build(): static
    {
        return $this->subject('Everbranch Retail — activation request received')
            ->text('emails.retail-signup-confirmation');
    }
}
