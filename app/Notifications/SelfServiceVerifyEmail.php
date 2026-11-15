<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

class SelfServiceVerifyEmail extends VerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        // The parent retains the canonical, signed confirmation URL factory.
        $message = parent::toMail($notifiable);

        return $message->withSymfonyMessage(static function (Email $email) use ($message): void {
            // Auth links remain direct; marketing/account tracking is untouched.
            $email->getHeaders()->addTextHeader('X-SMTPAPI', json_encode([
                'filters' => [
                    'clicktrack' => ['settings' => ['enable' => 0, 'enable_text' => false]],
                    'opentrack' => ['settings' => ['enable' => 0]],
                ],
            ], JSON_THROW_ON_ERROR));
            $email->text(view('emails.self-service-verification-text', [
                'actionUrl' => $message->actionUrl,
                'expiresInMinutes' => (int) config('auth.verification.expire', 60),
            ])->render());
        });
    }
}
