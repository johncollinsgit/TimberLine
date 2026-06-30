<?php

namespace App\Jobs;

use App\Services\Mobile\ModernForestryMobileProductCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshModernForestryMobileHomeCache implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public string $cacheKey)
    {
    }

    public function handle(ModernForestryMobileProductCatalogService $catalog): void
    {
        $catalog->handleQueuedHomeCacheRefresh($this->cacheKey);
    }
}
