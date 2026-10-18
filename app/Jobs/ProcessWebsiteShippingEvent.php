<?php

namespace App\Jobs;

use App\Services\ManagedWebsite\WebsiteShipmentLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebsiteShippingEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 75;

    public function __construct(public readonly int $eventId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(WebsiteShipmentLifecycleService $shipping): void
    {
        $shipping->processEvent($this->eventId);
    }
}
