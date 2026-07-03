<?php

namespace App\Mail;

use App\Models\ServiceInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PublicBudConversationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, string>>  $transcript
     */
    public function __construct(
        public ServiceInquiry $inquiry,
        public string $question,
        public string $reply,
        public array $context,
        public array $transcript
    ) {
    }

    public function build(): self
    {
        $scenario = trim((string) ($this->context['scenario'] ?? ''));
        $subjectSuffix = $scenario !== '' ? ' - '.ucfirst($scenario) : '';

        return $this->subject('New Bud support conversation'.$subjectSuffix)
            ->view('emails.public-bud-conversation')
            ->with([
                'inquiry' => $this->inquiry,
                'question' => $this->question,
                'reply' => $this->reply,
                'context' => $this->context,
                'transcript' => $this->transcript,
            ]);
    }
}
