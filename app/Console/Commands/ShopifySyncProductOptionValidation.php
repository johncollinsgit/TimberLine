<?php

namespace App\Console\Commands;

use App\Models\ShopifyProductOptionRuleset;
use App\Services\Shopify\ShopifyProductOptionMetafieldSyncService;
use Illuminate\Console\Command;

class ShopifySyncProductOptionValidation extends Command
{
    protected $signature = 'shopify:sync-product-option-validation
        {--tenant-id= : Tenant whose enabled and paused product-option rulesets should be synced}
        {--apply : Write the validation rule metafields to the tenant Shopify products}';

    protected $description = 'Preview or sync Everbranch bundle-scent rules into Shopify product metafields used at checkout.';

    public function handle(ShopifyProductOptionMetafieldSyncService $metafields): int
    {
        $tenantId = filter_var($this->option('tenant-id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (! is_int($tenantId)) {
            $this->error('Pass a positive --tenant-id.');

            return self::FAILURE;
        }

        $rulesets = ShopifyProductOptionRuleset::query()
            ->forTenantId($tenantId)
            ->with('assignments')
            ->orderBy('id')
            ->get();

        if ($rulesets->isEmpty()) {
            $this->warn("No product-option rulesets were found for tenant {$tenantId}.");

            return self::SUCCESS;
        }

        foreach ($rulesets as $ruleset) {
            $handles = $ruleset->assignments
                ->pluck('product_handle')
                ->filter()
                ->implode(', ');
            $this->line(sprintf(
                '%s: %d required scent(s), %s, products: %s',
                $ruleset->name,
                (int) $ruleset->option_count,
                $ruleset->enabled ? 'enabled' : 'paused',
                $handles !== '' ? $handles : 'none'
            ));
        }

        if (! $this->option('apply')) {
            $this->info('Preview only. Re-run with --apply to write Shopify product metafields.');

            return self::SUCCESS;
        }

        $errors = [];
        foreach ($rulesets as $ruleset) {
            $result = $metafields->syncRuleset($ruleset);
            $this->line(sprintf(
                '%s: synced=%d cleared=%d errors=%d',
                $ruleset->name,
                $result['synced'],
                $result['cleared'],
                count($result['errors'])
            ));
            array_push($errors, ...$result['errors']);
        }

        if ($errors !== []) {
            foreach (array_unique($errors) as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info('Shopify checkout-validation product rules are synchronized.');

        return self::SUCCESS;
    }
}
