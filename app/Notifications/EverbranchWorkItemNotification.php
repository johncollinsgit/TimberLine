<?php

namespace App\Notifications;

use App\Models\WorkNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EverbranchWorkItemNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly WorkNotification $workNotification
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
        $message = (new MailMessage)
            ->subject($this->workNotification->title)
            ->greeting($this->workNotification->title);

        $body = trim((string) $this->workNotification->body);
        if ($body !== '') {
            $message->line($body);
        }

        $deepLink = trim((string) $this->workNotification->deep_link);
        if ($deepLink !== '') {
            $message->action('Open in Everbranch Work', $deepLink);
        }

        return $message->line('You received this because work notifications are enabled for your workspace.');
    }
}
