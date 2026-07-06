<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EverbranchWorkMagicLinkNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly ?string $tenantName = null
    ) {}

    /**
     * @return array<int,string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->tenantName !== null && trim($this->tenantName) !== ''
            ? ' for '.$this->tenantName
            : '';

        return (new MailMessage)
            ->subject('Sign in to Everbranch Work')
            ->greeting('Sign in to Everbranch Work')
            ->line('Use this secure link to open your workspace'.$workspace.'.')
            ->action('Open Everbranch Work', $this->url)
            ->line('This link expires in 20 minutes. If you did not request it, you can ignore this email.');
    }
}
