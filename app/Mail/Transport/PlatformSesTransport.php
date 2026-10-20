<?php

namespace App\Mail\Transport;

use App\Services\Mail\PlatformSesOutbox;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

class PlatformSesTransport extends AbstractTransport
{
    public function __construct(private bool $providerAcceptance = false)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (! $message->getOriginalMessage() instanceof Email) {
            throw new TransportException('Platform SES requires a rendered email.');
        }
        try {
            if ($this->providerAcceptance && (\Illuminate\Support\Facades\DB::transactionLevel() !== 0 || count($message->getEnvelope()->getRecipients()) !== 1)) {
                throw new \RuntimeException('Provider acceptance requires one recipient outside a caller transaction.');
            }
            $outbox = app(PlatformSesOutbox::class);
            $ids = $outbox->enqueue($message->getOriginalMessage(), $message->getEnvelope(), ! $this->providerAcceptance);
            if ($this->providerAcceptance) {
                if ($outbox->submit($ids[0], true) !== 'accepted') {
                    throw new \RuntimeException('SES acceptance requires reconciliation.');
                }
                $receipt = \App\Models\PlatformEmailDelivery::findOrFail($ids[0])->provider_message_id;
                if (! filled($receipt)) {
                    throw new \RuntimeException('No durable provider receipt is available.');
                }
                $message->setMessageId($receipt);
                $message->getOriginalMessage()->getHeaders()->addTextHeader('X-Everbranch-Mail-State', 'accepted');

                return;
            }
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
        return $this->providerAcceptance ? 'ses-platform-confirmed' : 'ses-platform-outbox';
    }
}
