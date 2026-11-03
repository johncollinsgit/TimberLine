<?php

namespace App\Console\Commands;

use App\Jobs\StockSyncTenant;
use App\Services\Inventory\StockLedger;
use Illuminate\Console\Command;

final class StockSyncCommand extends Command
{
    protected $signature = 'stock:sync {--tenant= : Limit to one tenant}';

    protected $description = 'Queue bounded finished-goods inbox and inventory publication recovery';

    public function handle(StockLedger $stock): int
    {
        if (config('pos_live.owned_inventory_enabled') !== true) {
            return self::SUCCESS;
        }
        $query = $stock->db->table('commerce_stock_bindings')->where('active', true)->where('channel', 'shopify');
        if ($tenant = $this->option('tenant')) {
            if (! ctype_digit((string) $tenant) || (int) $tenant < 1) {
                return self::INVALID;
            }
            $query->where('tenant_id', (int) $tenant);
        }
        foreach ($query->distinct()->pluck('tenant_id') as $tenantId) {
            StockSyncTenant::dispatch((int) $tenantId);
        }

        return self::SUCCESS;
    }
}
