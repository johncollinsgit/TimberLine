<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Auth\PasswordResetUrlFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class ApprovalPasswordSetupNotification extends Notification
{
    use Queueable;

    public function __construct(protected User $user)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $token = Password::broker(config('fortify.passwords'))->createToken($this->user);
        $resetUrl = app(PasswordResetUrlFactory::class)->make($token, (string) $this->user->email);

        return (new MailMessage)
            ->subject('Your Backstage account is approved')
            ->greeting('Good news, '.$this->user->name.'!')
            ->line('Your Backstage account request has been approved.')
            ->line('Next step: set your password to finish activating your login.')
            ->action('Set Your Password', $resetUrl)
            ->line('After setting your password, return to the login page and sign in.')
            ->action('Go to Login', route('login'))
            ->line('If you did not request access, you can ignore this email.');
    }
}
