<?php

namespace App\Jobs;

use App\Services\Marketing\ShopifyCustomerBirthdaySyncService;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SyncShopifyCustomerBirthdaysJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $storeKey,
        public int $limit = 200,
        public ?string $cursor = null,
        public int $pageSize = 50,
        public bool $dryRun = false,
        public bool $writeBack = false
    ) {
    }

    public function handle(ShopifyCustomerBirthdaySyncService $syncService): void
    {
        $store = ShopifyStores::find($this->storeKey);
        if (! $store) {
            throw new RuntimeException("Shopify store '{$this->storeKey}' is not configured.");
        }

        $syncService->syncStore($store, [
            'limit' => max(1, $this->limit),
            'cursor' => $this->cursor,
            'page_size' => max(1, $this->pageSize),
            'dry_run' => $this->dryRun,
            'write_back' => $this->writeBack,
        ]);
    }
}
