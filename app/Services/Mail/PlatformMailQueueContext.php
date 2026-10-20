<?php

namespace App\Services\Mail;

use App\Models\PlatformEmailMessage;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\Email;

class PlatformMailQueueContext
{
    private ?Job $job = null;

    private ?string $mailJobKey = null;

    public function start(Job $job): void
    {
        $this->reset();
        $this->job = $job;
        $name = data_get($job->payload(), 'data.commandName');
        if (in_array($name, [\Illuminate\Mail\SendQueuedMailable::class, \Illuminate\Notifications\SendQueuedNotifications::class], true) && $job->uuid()) {
            $this->mailJobKey = hash('sha256', 'mail-job:'.$job->uuid());
            if (Schema::hasTable('platform_email_messages') && PlatformEmailMessage::where('group_key', $this->mailJobKey)->exists()) {
                // The rendered message already lives in the durable outbox.
                // Skip before toMail() can regenerate a reset/verification token.
                $job->delete();
            }
        }
    }

    public function reset(): void
    {
        $this->job = null;
        $this->mailJobKey = null;
    }

    public function existingGroup(Email $message): ?string
    {
        if ($this->mailJobKey) {
            return $this->mailJobKey;
        }
        $header = $message->getHeaders()->get('X-Everbranch-Logical-Message-ID')?->getBodyAsString();
        if ($header !== null) {
            if (! preg_match('/^[A-Za-z0-9:_-]{16,200}$/D', $header)) {
                throw new RuntimeException('Invalid platform logical message identity.');
            }

            return hash('sha256', 'explicit:'.$header);
        }

        return null;
    }

    public function group(Email $message): string
    {
        if ($key = $this->existingGroup($message)) {
            return $key;
        }
        if ($this->job !== null) {
            throw new RuntimeException('A business queue job requires an explicit stable platform message ID.');
        }

        return hash('sha256', 'synchronous:'.Str::uuid());
    }
}
