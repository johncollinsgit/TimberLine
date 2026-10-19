<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class SoccerRegistrationMail extends Mailable
{
    public function __construct(public string $messageText) {}

    public function build(): self
    {
        return $this->from((string) config('mail.from.address'), 'Wren Family Soccer')
            ->subject('Your Wren Family Soccer registration update')
            ->view('emails.soccer.registration-update')
            ->text('emails.soccer.registration-update-text')
            ->with(['origin' => rtrim((string) config('soccer.origin'), '/')]);
    }
}
