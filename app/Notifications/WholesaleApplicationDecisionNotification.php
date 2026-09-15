<?php

namespace App\Notifications;

use App\Models\CustomerAccessRequest;
use App\Services\Shopify\ShopifyWholesaleAccountLinkService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WholesaleApplicationDecisionNotification extends Notification
{
    public function __construct(public CustomerAccessRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting('Hello '.$this->request->name.',');
        if ($this->request->status === 'approved') {
            return $mail->subject('Your wholesale application is approved')
                ->line('Welcome! Your shop is approved to order from our wholesale store.')
                ->line('Use the link below to activate your store account or sign in with the email you used to apply.')
                ->action('Open your wholesale account', app(ShopifyWholesaleAccountLinkService::class)->forApplication($this->request));
        }

        return $mail->subject('An update on your wholesale application')
            ->line('Thank you for your interest and for taking the time to tell us about your business.')
            ->line('After reviewing your application, we are unable to offer a wholesale account at this time.')
            ->line('If you have questions, contact '.app(\App\Services\Onboarding\WholesaleApplicationReviewInboxResolver::class)->resolve($this->request).'.');
    }
}
