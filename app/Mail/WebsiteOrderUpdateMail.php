<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class WebsiteOrderUpdateMail extends Mailable
{
    public function __construct(public readonly array $update, private readonly string $messageId) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->update['from_address'], $this->update['from_name']), subject: $this->update['subject']);
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->messageId);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.website-order-update', text: 'emails.website-order-update-text');
    }
}
