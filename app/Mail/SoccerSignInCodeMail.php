<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SoccerSignInCodeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public string $code) {}

    public function build(): self
    {
        return $this->from((string) config('mail.from.address'), 'Wren Family Soccer')
            ->subject('Your Wren Family Soccer sign-in code')
            ->view('emails.soccer.sign-in-code')
            ->text('emails.soccer.sign-in-code-text')
            ->with([
                'code' => $this->code,
                'origin' => rtrim((string) config('soccer.origin'), '/'),
            ]);
    }
}
