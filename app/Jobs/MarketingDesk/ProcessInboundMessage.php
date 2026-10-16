<?php

namespace App\Jobs\MarketingDesk;

use App\Services\Marketing\Desk\ReplyAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $tenantId, public int $messageId) {}

    public function handle(ReplyAutomationService $replies): void
    {
        $replies->process($this->tenantId, $this->messageId);
    }
}
