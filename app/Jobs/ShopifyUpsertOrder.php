<?php

namespace App\Jobs;

use App\Services\Shopify\ShopifyOrderIngestor;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ShopifyUpsertOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $orderData
     */
    public function __construct(
        public string $storeKey,
        public array $orderData
    ) {
    }

    public function handle(ShopifyOrderIngestor $ingestor): void
    {
        $store = ShopifyStores::find($this->storeKey);
        if (!$store) {
            return;
        }

        $ingestor->ingest($store, $this->orderData);
    }
}
