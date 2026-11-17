<?php

namespace App\Jobs;

use App\Services\ManagedWebsite\WebsiteOrderNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWebsiteOrderNotification implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 90;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

    public function __construct(public readonly int $notificationId)
    {
        $queue = trim((string) config('managed_website.order_notifications_queue', 'website-order-notifications'));
        $connection = trim((string) config('managed_website.order_notifications_connection', 'website_order_notifications'));
        if ($queue === '' || $connection === '') {
            throw new \LogicException('Native order notifications require a dedicated queue and connection.');
        }

        $this->onConnection($connection);
        $this->onQueue($queue);
    }

    public function handle(WebsiteOrderNotificationService $notifications): void
    {
        $notifications->deliver($this->notificationId);
    }
}
