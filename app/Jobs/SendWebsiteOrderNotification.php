<?php

namespace App\Jobs;

use App\Services\ManagedWebsite\WebsiteOrderNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWebsiteOrderNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $notificationId) {}

    public function handle(WebsiteOrderNotificationService $notifications): void
    {
        $notifications->deliver($this->notificationId);
    }
}
