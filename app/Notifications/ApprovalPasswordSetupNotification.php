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

    public function __construct(protected User $user, protected ?string $preferredHost = null)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $token = Password::broker(config('fortify.passwords'))->createToken($this->user);
        $resetUrl = app(PasswordResetUrlFactory::class)->make($token, (string) $this->user->email, preferredHost: $this->preferredHost);
        $loginUrl = $this->loginUrl();

        return (new MailMessage)
            ->subject('Your Backstage account is approved')
            ->greeting('Good news, '.$this->user->name.'!')
            ->line('Your Backstage account request has been approved.')
            ->line('Next step: set your password to finish activating your login.')
            ->action('Set Your Password', $resetUrl)
            ->line('After setting your password, sign in to access your workspace:')
            ->line($loginUrl)
            ->line('If you did not request access, you can ignore this email.');
    }

    protected function loginUrl(): string
    {
        $path = route('login', absolute: false);

        $host = strtolower(trim((string) ($this->preferredHost ?? '')));
        if ($host === '') {
            return route('login');
        }

        $scheme = parse_url((string) config('app.url', ''), PHP_URL_SCHEME);
        $scheme = strtolower(trim((string) $scheme));
        $scheme = in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';

        return $scheme.'://'.$host.$path;
    }
}
