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

    public function __construct(public Agreement $agreement, public string $proposalUrl, public string $password = '') {}

    public function build(): self
    {
        return $this->subject('Everbranch: your agreement for '.$this->agreement->tenant->name)
            ->view('emails.agreement-proposal')
            ->text('emails.agreement-proposal-text');
    }
}
