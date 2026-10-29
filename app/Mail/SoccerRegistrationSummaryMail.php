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
                // SendGrid's tracking rewrite path can relabel UTF-8 parts as Latin-1.
                // Keep private organizer summaries intact without changing account defaults.
                $message->getHeaders()->addTextHeader('X-SMTPAPI', json_encode([
                    'filters' => [
                        'clicktrack' => ['settings' => ['enable' => 0, 'enable_text' => false]],
                        'opentrack' => ['settings' => ['enable' => 0]],
                    ],
                ], JSON_THROW_ON_ERROR));
                // Keep UTF-8 names and punctuation intact through SMTP intermediaries.
                $message->setBody(new AlternativePart(
                    new TextPart($message->getTextBody(), 'utf-8', 'plain', 'base64'),
                    new TextPart($message->getHtmlBody(), 'utf-8', 'html', 'base64'),
                ));
            });
    }
}
