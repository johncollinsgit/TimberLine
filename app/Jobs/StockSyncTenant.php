<?php

namespace App\Jobs;

use App\Services\Inventory\StockOnlineInbox;
use App\Services\Inventory\StockShopifyPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class StockSyncTenant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $tenantId) {}

    public function handle(StockOnlineInbox $inbox, StockShopifyPublisher $publisher): void
    {
        if (config('pos_live.owned_inventory_enabled') !== true) {
            return;
        }
        $inbox->drain($this->tenantId, 100);
        $publisher->drain($this->tenantId, 5);
    }
}
