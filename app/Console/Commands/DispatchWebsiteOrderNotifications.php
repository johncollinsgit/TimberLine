<?php

namespace App\Console\Commands;

use App\Services\ManagedWebsite\WebsiteOrderNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DispatchWebsiteOrderNotifications extends Command
{
    protected $signature = 'website:dispatch-order-notifications {--limit=100 : Maximum native notifications to enqueue}';

    protected $description = 'Recover due native Website transactional updates without enabling notifications';

    public function handle(WebsiteOrderNotificationService $notifications): int
    {
        $result = $notifications->scan(max(1, min(500, (int) $this->option('limit'))));
        Cache::put('website:notifications:last_scan', ['at' => now()->timestamp, 'counts' => $result], now()->addDay());
        $this->info('Enqueued '.$result['enqueued'].' native updates; '.$result['unknown'].' expired send leases require review; '.$result['deferred'].' queue handoffs deferred.');

        return $result['deferred'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
