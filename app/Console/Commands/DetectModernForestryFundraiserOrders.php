<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Shopify\ModernForestryFundraiserShopifyOrderService;
use Illuminate\Console\Command;

class DetectModernForestryFundraiserOrders extends Command
{
    protected $signature = 'modern-forestry:detect-fundraiser-orders';

    protected $description = 'Queue new BSF-tagged Shopify orders for label-cost and invoice review.';

    public function handle(ModernForestryFundraiserShopifyOrderService $orders): int
    {
        $tenant = Tenant::query()->where('slug', 'modern-forestry')->firstOrFail();
        $this->line('orders_queued='.$orders->discover($tenant));

        return self::SUCCESS;
    }
}
