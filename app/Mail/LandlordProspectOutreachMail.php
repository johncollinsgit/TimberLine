<?php

namespace App\Mail;

use App\Models\LandlordProspectCommunication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LandlordProspectOutreachMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public LandlordProspectCommunication $communication) {}

    public function build(): self
    {
        $sender = (array) config('landlord_prospecting.sender', []);
        $fromAddress = trim((string) ($sender['email'] ?? 'john@evergrovesoftware.com'));
        $fromName = trim((string) ($sender['name'] ?? 'John Collins'));

        return $this
            ->from($fromAddress, $fromName)
            ->replyTo($fromAddress, $fromName)
            ->subject((string) ($this->communication->subject ?: 'A note from Evergrove Software'))
            ->text('emails.landlord-prospect-outreach');
    }
}
