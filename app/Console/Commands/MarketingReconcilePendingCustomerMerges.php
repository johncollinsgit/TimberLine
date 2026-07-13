<?php

namespace App\Console\Commands;

use App\Models\CustomerMergeOperation;
use App\Services\Marketing\CustomerMergeCoordinator;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Console\Command;

class MarketingReconcilePendingCustomerMerges extends Command
{
    protected $signature = 'marketing:reconcile-pending-customer-merges {--limit=25}';

    protected $description = 'Poll and safely resume pending Shopify customer merge operations.';

    public function handle(CustomerMergeCoordinator $coordinator): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $operations = CustomerMergeOperation::query()
            ->where('status', 'processing')
            ->where('updated_at', '<=', now()->subMinute())
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
        $completed = 0;
        $attention = 0;

        foreach ($operations as $operation) {
            $store = ShopifyStores::find((string) $operation->store_key);
            if (! is_array($store) || (int) ($store['tenant_id'] ?? 0) !== (int) $operation->tenant_id) {
                $operation->forceFill([
                    'status' => 'reconciliation_required',
                    'errors' => [['code' => 'store_context_missing', 'message' => 'The tenant Shopify store could not be resolved while resuming this merge.']],
                ])->save();
                $attention++;

                continue;
            }

            $result = $coordinator->advance($operation, $store);
            $result->status === 'completed' ? $completed++ : $attention++;
        }

        $this->line("processed={$operations->count()}");
        $this->line("completed={$completed}");
        $this->line("attention_required={$attention}");

        return self::SUCCESS;
    }
}
