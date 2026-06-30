<?php

namespace App\Notifications;

use App\Models\CustomerAccessRequest;
use App\Support\Wholesale\WholesaleApplicationInboxUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WholesaleApplicationReviewNotification extends Notification
{
    use Queueable;

    public function __construct(protected CustomerAccessRequest $request)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reviewUrl = app(WholesaleApplicationInboxUrl::class)->detailUrl($this->request);
        $metadata = (array) ($this->request->metadata ?? []);
        $storeType = trim((string) ($metadata['business_type'] ?? ''));
        $website = trim((string) ($metadata['website'] ?? ''));
        $phone = trim((string) ($metadata['phone'] ?? ''));
        $locationParts = array_filter([
            trim((string) ($metadata['city'] ?? '')),
            trim((string) ($metadata['state'] ?? '')),
            trim((string) ($metadata['zip'] ?? '')),
        ], static fn (string $value): bool => $value !== '');
        $location = implode(', ', $locationParts);

        return (new MailMessage)
            ->subject('New wholesale application submitted')
            ->greeting('A new wholesale application just came in.')
            ->line('Use the link below to review and approve the applicant.')
            ->line('Name: '.(string) $this->request->name)
            ->line('Email: '.(string) $this->request->email)
            ->line('Company: '.(string) ($this->request->company ?: 'N/A'))
            ->line('Store type: '.($storeType !== '' ? ucwords($storeType) : 'N/A'))
            ->line('Website: '.($website !== '' ? $website : 'N/A'))
            ->line('Phone: '.($phone !== '' ? $phone : 'N/A'))
            ->line('Location: '.($location !== '' ? $location : 'N/A'))
            ->line('Requested intent: '.ucfirst((string) ($this->request->intent ?: 'production')))
            ->action('Review application', $reviewUrl)
            ->line('The review page keeps the captured application together and links straight into approvals.');
    }
}
