<?php

namespace App\Jobs;

use App\Services\ManagedWebsite\WebsiteDropshipOutbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWebsiteDropshipDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $outboxId, public readonly string $dispatchToken)
    {
        $this->onConnection('website_dropship_drafts');
        $this->onQueue('website-dropship-drafts');
    }

    public function handle(WebsiteDropshipOutbox $outbox): void
    {
        $outbox->deliver($this->outboxId, $this->dispatchToken);
    }
}
