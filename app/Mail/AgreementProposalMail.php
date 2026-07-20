<?php

namespace App\Mail;

use App\Models\Agreement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AgreementProposalMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Agreement $agreement, public string $proposalUrl, public string $password) {}

    public function build(): self
    {
        return $this->subject('Action requested: '.$this->agreement->title)
            ->view('emails.agreement-proposal');
    }
}
