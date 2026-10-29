<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\TextPart;

class SoccerRegistrationSummaryMail extends Mailable
{
    public function __construct(public array $summary) {}

    public function build(): self
    {
        return $this->from((string) config('mail.from.address'), 'Wren Family Soccer')
            ->subject($this->summary['headline'].' · '.$this->summary['parent'].' · '.$this->summary['season'])
            ->view('emails.soccer.registration-summary')
            ->text('emails.soccer.registration-summary-text')
            ->withSymfonyMessage(function (Email $message) {
                // Keep UTF-8 names and punctuation intact through SMTP intermediaries.
                $message->setBody(new AlternativePart(
                    new TextPart($message->getTextBody(), 'utf-8', 'plain', 'base64'),
                    new TextPart($message->getHtmlBody(), 'utf-8', 'html', 'base64'),
                ));
            });
    }
}
