<?php

namespace App\Console\Commands;

use App\Models\CustomerMergeOperation;
use App\Services\Marketing\CustomerMergeCoordinator;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Console\Command;

class MarketingReconcileCustomerMerge extends Command
{
    protected $signature = 'marketing:reconcile-customer-merge
        {operation : Customer merge operation id}
        {--apply : Resume the idempotent reconciliation}';

    protected $description = 'Preview or safely resume an interrupted customer merge reconciliation.';

    public function handle(CustomerMergeCoordinator $coordinator): int
    {
        $operation = CustomerMergeOperation::query()->with('members')->find((int) $this->argument('operation'));
        if (! $operation) {
            $this->error('Customer merge operation not found.');

            return self::FAILURE;
        }

        $this->line((string) json_encode([
            'mode' => $this->option('apply') ? 'apply' : 'preview',
            'operation_id' => (int) $operation->id,
            'tenant_id' => (int) $operation->tenant_id,
            'store_key' => (string) $operation->store_key,
            'status' => (string) $operation->status,
            'profile_ids' => $operation->members->pluck('marketing_profile_id')->map('intval')->all(),
            'shopify_job_id' => $operation->shopify_job_id,
            'shopify_kept_customer_gid' => $operation->shopify_kept_customer_gid,
            'errors' => $operation->errors,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! $this->option('apply')) {
            $this->warn('Preview only. Re-run with --apply after reviewing the operation and its Shopify result.');

            return self::SUCCESS;
        }

        $store = ShopifyStores::find((string) $operation->store_key);
        if (! is_array($store) || (int) ($store['tenant_id'] ?? 0) !== (int) $operation->tenant_id) {
            $this->error('The operation store could not be resolved inside its tenant.');

            return self::FAILURE;
        }

        $result = $coordinator->advance($operation, $store);
        $this->info('Reconciliation status: '.$result->status);

        return $result->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
