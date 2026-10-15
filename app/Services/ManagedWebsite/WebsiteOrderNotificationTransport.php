<?php

namespace App\Services\ManagedWebsite;

use App\Mail\WebsiteOrderUpdateMail;
use App\Models\WebsiteOrderNotification;
use Illuminate\Support\Facades\Mail;

/** Explicit native transactional transport; never resolves marketing or Shopify accounts. */
class WebsiteOrderNotificationTransport
{
    public function prepare(WebsiteOrderNotification $notification): WebsiteOrderUpdateMail
    {
        $snapshot = $notification->snapshot;
        $messageId = 'website-order-'.$notification->id.'.'.$notification->transition_key.'@'.substr(strrchr($snapshot['from_address'], '@'), 1);
        $mail = new WebsiteOrderUpdateMail($snapshot, $messageId);
        $preview = clone $mail;
        $preview->render(); // Hydration mutates Mailable callbacks; do not render the send instance twice.
        Mail::mailer($snapshot['mailer']);

        return $mail;
    }

    public function send(WebsiteOrderNotification $notification, WebsiteOrderUpdateMail $mail): string
    {
        $result = Mail::mailer($notification->snapshot['mailer'])->to($notification->snapshot['recipient'])->send($mail);
        if ($result) {
            return (string) $result->getMessageId();
        }
        if (app()->environment('testing') && Mail::getFacadeRoot() instanceof \Illuminate\Support\Testing\Fakes\MailFake) {
            return 'test-native-order-'.$notification->id;
        }

        throw new \RuntimeException('The transactional transport did not confirm acceptance.');
    }
}
