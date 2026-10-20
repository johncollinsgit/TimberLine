<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class SoccerRegistrationSummaryMail extends Mailable
{
    public function __construct(public array $summary) {}

    public function build(): self
    {
        return $this->from((string) config('mail.from.address'), 'Wren Family Soccer')
            ->subject($this->summary['headline'].' · '.$this->summary['parent'].' · '.$this->summary['season'])
            ->view('emails.soccer.registration-summary')
            ->text('emails.soccer.registration-summary-text');
    }
}
