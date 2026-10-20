<?php

namespace App\Providers;

use App\Mail\Transport\PlatformSesTransport;
use App\Models\PlatformEmailMessage;
use App\Services\Mail\PlatformMailQueueContext;
use App\Services\Mail\PlatformSesOutbox;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

class PlatformMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PlatformMailQueueContext::class);
    }

    public function boot(): void
    {
        Mail::extend('ses_platform', fn () => $this->transport(false));
        Mail::extend('ses_platform_confirmed', fn () => $this->transport(true));
        $this->registerGuards();
    }

    private function transport(bool $providerAcceptance): PlatformSesTransport
    {
        // Laravel failover runs inside one MessageSending event. Reject
        // nested transport configurations before any outbox side effect.
        foreach ((array) config('mail.mailers') as $name => $mailer) {
            if (in_array($mailer['transport'] ?? '', ['failover', 'roundrobin'], true)
                && $this->containsPlatform((array) ($mailer['mailers'] ?? []), [])) {
                throw new RuntimeException('Platform SES cannot participate in cross-provider failover or rotation.');
            }
        }

        return new PlatformSesTransport($providerAcceptance);
    }

    private function registerGuards(): void
    {
        Queue::before(fn ($event) => app(PlatformMailQueueContext::class)->start($event->job));
        Queue::after(fn () => app(PlatformMailQueueContext::class)->reset());
        Queue::exceptionOccurred(fn () => app(PlatformMailQueueContext::class)->reset());
        Event::listen(MessageSending::class, function (MessageSending $event): ?bool {
            if (! $event->message instanceof Email || ! Schema::hasTable('platform_email_messages')) {
                return null;
            }
            $outbox = app(PlatformSesOutbox::class);
            $envelope = Envelope::create($event->message);
            // This guard remains active on SMTP after a provider rollback.
            foreach ($envelope->getRecipients() as $recipient) {
                if ($outbox->heldAddress($recipient->getAddress())) {
                    throw new RuntimeException('A prior platform mail attempt requires reconciliation.');
                }
            }
            $group = app(PlatformMailQueueContext::class)->existingGroup($event->message);
            if ($group && ($intent = PlatformEmailMessage::where('group_key', $group)->first())) {
                if ($intent->payload_hash !== $outbox->fingerprint($event->message, $envelope)) {
                    throw new RuntimeException('A platform logical message cannot be replaced with different content.');
                }

                return false;
            }

            return null;
        });
    }

    private function containsPlatform(array $names, array $visited): bool
    {
        foreach ($names as $name) {
            if (in_array($name, $visited, true)) {
                continue;
            }
            $mailer = (array) config('mail.mailers.'.$name, []);
            if (in_array($mailer['transport'] ?? '', ['ses_platform', 'ses_platform_confirmed'], true) || $this->containsPlatform((array) ($mailer['mailers'] ?? []), [...$visited, $name])) {
                return true;
            }
        }

        return false;
    }
}
