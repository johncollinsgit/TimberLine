<?php

namespace App\Mail;

use App\Models\ModernForestryMobileBagSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ModernForestryBagReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $settings
     */
    public function __construct(
        public ModernForestryMobileBagSnapshot $snapshot,
        public array $settings = []
    ) {
    }

    public function build(): self
    {
        $subject = (string) ($this->settings['subject'] ?? 'Your Modern Forestry bag is still waiting');

        return $this->subject($subject)
            ->view('emails.modern-forestry-bag-reminder');
    }
}
