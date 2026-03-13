<?php

namespace App\Jobs;

use App\Services\Marketing\ShopifyCustomerMetafieldSyncService;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SyncShopifyCustomerMetafieldsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $storeKey,
        public ?int $limit = null,
        public ?string $cursor = null,
        public int $pageSize = 50,
        public bool $dryRun = false
    ) {}

    public function handle(ShopifyCustomerMetafieldSyncService $syncService): void
    {
        $store = ShopifyStores::find($this->storeKey);
        if (! $store) {
            throw new RuntimeException("Shopify store '{$this->storeKey}' is not configured.");
        }

        $syncService->syncStore($store, [
            'limit' => $this->limit !== null ? max(1, $this->limit) : null,
            'cursor' => $this->cursor,
            'page_size' => max(1, $this->pageSize),
            'dry_run' => $this->dryRun,
        ]);
    }
}
