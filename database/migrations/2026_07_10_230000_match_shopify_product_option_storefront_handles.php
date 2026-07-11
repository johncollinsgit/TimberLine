<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopify_product_option_rulesets') || ! Schema::hasTable('shopify_product_option_assignments')) {
            return;
        }

        $tenantId = DB::table('tenants')->where('slug', 'modern-forestry')->value('id');
        if (! is_numeric($tenantId)) {
            return;
        }

        $assignments = [
            'Buy 2 Get 1 Free' => [
                '8oz-3-soy-candle-bundle-save-on-three-soy-candle-by-modern-forestry',
            ],
            'Teacher Candles' => ['teacher-candles'],
            'Bulk Discount Bundles - 12 options' => [
                'bulk-discount-4oz-soy-candles-case-of-12-modern-forestry-soy-candles-in-greenville-sc',
                'bulk-discount-8oz-soy-candles',
                'bulk-discount-16oz-soy-candles-case-of-12',
            ],
            'Wax Melt Bundle - 5 options' => ['5-wax-melts-bundle'],
            'Bundles with 3 options' => [
                '4oz-3-soy-candle-bundle-save-on-three-soy-candle-by-modern-forestry',
            ],
        ];

        DB::table('shopify_product_option_assignments')
            ->where('tenant_id', (int) $tenantId)
            ->where('product_handle', '4oz-3-soy-candle-bundle')
            ->delete();

        foreach ($assignments as $rulesetName => $handles) {
            $rulesetId = DB::table('shopify_product_option_rulesets')
                ->where('tenant_id', (int) $tenantId)
                ->where('name', $rulesetName)
                ->value('id');

            if (! is_numeric($rulesetId)) {
                continue;
            }

            foreach ($handles as $handle) {
                DB::table('shopify_product_option_assignments')->updateOrInsert(
                    [
                        'tenant_id' => (int) $tenantId,
                        'ruleset_id' => (int) $rulesetId,
                        'product_handle' => $handle,
                    ],
                    [
                        'product_url' => 'https://theforestrystudio.com/products/'.$handle,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        // Storefront assignments are intentionally preserved on rollback.
    }
};
