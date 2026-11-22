<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class WatershedMail extends Mailable
{
    public function __construct(public string $heading, public string $messageText, public ?string $code = null, public ?string $link = null) {}

    public function build(): self
    {
        return $this->from((string) config('mail.from.address'), 'The Watershed')->subject($this->heading)->view('emails.watershed.message')->text('emails.watershed.message-text')->with(['heading' => $this->heading, 'messageText' => $this->messageText, 'code' => $this->code, 'link' => $this->link ?? config('watershed.origin').'/register']);
    }
}
