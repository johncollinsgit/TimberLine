<?php

namespace App\Jobs;

use App\Services\Marketing\EmbeddedMessagingCampaignDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMessagingCampaignRecipientJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $messageJobId)
    {
    }

    public function handle(EmbeddedMessagingCampaignDispatchService $dispatchService): void
    {
        $dispatchService->processJob($this->messageJobId);
    }
}
