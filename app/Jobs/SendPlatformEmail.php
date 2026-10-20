<?php

namespace App\Jobs;

use App\Services\Mail\PlatformSesOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

class SendPlatformEmail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 86400;

    public function __construct(public int $deliveryId)
    {
        $this->onConnection((string) config('ses_migration.platform_queue_connection', 'database'));
        $this->onQueue((string) config('ses_migration.platform_queue', 'ses-platform'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'platform-email:'.$this->deliveryId;
    }

    public function handle(PlatformSesOutbox $outbox): void
    {
        $state = $outbox->submit($this->deliveryId);
        if ($state === 'rate_limited') {
            $this->release(30);
        } elseif (in_array($state, ['unknown', 'submitting', 'rejected', 'held'], true)) {
            $this->fail(new RuntimeException('Platform SES delivery is held for reviewed event reconciliation.'));
        }
    }
}
