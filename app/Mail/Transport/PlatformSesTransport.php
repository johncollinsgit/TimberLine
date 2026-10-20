<?php

namespace App\Mail\Transport;

use App\Services\Mail\PlatformSesOutbox;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

class PlatformSesTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        if (! $message->getOriginalMessage() instanceof Email) {
            throw new TransportException('Platform SES requires a rendered email.');
        }
        try {
            $ids = app(PlatformSesOutbox::class)->enqueue($message->getOriginalMessage(), $message->getEnvelope());
        } catch (\Throwable) {
            throw new TransportException('Platform email was not queued; review its rollout, sender, recipient and durable intent.');
        }
        // This is queue acceptance, never an invented AWS delivery receipt.
        $message->getOriginalMessage()->getHeaders()->addTextHeader('X-Everbranch-Mail-State', 'queued');
        $message->getOriginalMessage()->getHeaders()->addTextHeader('X-Everbranch-Outbox-IDs', implode(',', $ids));
        $message->setMessageId('outbox:'.implode(',', $ids));
    }

    public function __toString(): string
    {
        return 'ses-platform-outbox';
    }
}
