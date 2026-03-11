<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MarketingRebuildCustomers extends Command
{
    protected $signature = 'marketing:rebuild-customers
        {--limit= : Maximum number of orders to process}
        {--since= : Process orders updated on/after this datetime}
        {--order-id= : Process a single order by ID}
        {--shopify-only : Process only Shopify-linked orders}
        {--dry-run : Preview rebuild without writing}';

    protected $description = 'Rebuild the Marketing Customers index from operational orders.';

    public function handle(): int
    {
        $options = [
            '--limit' => $this->option('limit'),
            '--since' => $this->option('since'),
            '--order-id' => $this->option('order-id'),
            '--dry-run' => (bool) $this->option('dry-run'),
            '--shopify-only' => (bool) $this->option('shopify-only'),
        ];

        $filtered = [];
        foreach ($options as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $filtered[$key] = true;
                }

                continue;
            }

            if ($value !== null && trim((string) $value) !== '') {
                $filtered[$key] = $value;
            }
        }

        return $this->call('marketing:sync-profiles', $filtered);
    }
}
