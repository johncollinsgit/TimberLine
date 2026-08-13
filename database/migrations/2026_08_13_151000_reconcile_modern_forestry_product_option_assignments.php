<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants')
            || ! Schema::hasTable('shopify_product_option_rulesets')
            || ! Schema::hasTable('shopify_product_option_assignments')) {
            return;
        }

        $tenantId = DB::table('tenants')->where('slug', 'modern-forestry')->value('id');
        if (! is_numeric($tenantId)) {
            return;
        }

        // These handles were verified against the current legacy option sets.
        // They replace the screenshot-era mappings, including the previous 8oz
        // duplicate which could resolve as the incorrect "Buy 2 Get 1" ruleset.
        $assignments = [
            'Room Spray Bundle' => ['three-room-sprays-for-30'],
            'Buy 2 Get 1 Free' => ['buy-2-get-1-free-4oz-sale'],
            'Teacher Candles' => ['teacher-candles'],
            'Build Your Own Flight' => ['build-your-own-flight'],
            'Bulk Discount Bundles - 12 options' => [
                'bulk-discount-4oz-soy-candles-case-of-12-modern-forestry-soy-candles-in-greenville-sc',
                'bulk-discount-8oz-soy-candles',
                'bulk-discount-16oz-soy-candles-case-of-12',
            ],
            'Wax Melt Bundle - 5 options' => ['5-wax-melts-bundle'],
            'Bundles with 3 options' => [
                '4oz-3-soy-candle-bundle-save-on-three-soy-candle-by-modern-forestry',
                '8oz-3-soy-candle-bundle-save-on-three-soy-candle-by-modern-forestry',
                'wax-melt-bundle-soy-tarts-wax-tarts-by-modern-forestry',
                'bundle',
            ],
        ];

        $handles = collect($assignments)->flatten()->values()->all();

        DB::table('shopify_product_option_assignments')
            ->where('tenant_id', (int) $tenantId)
            ->where(function ($query) use ($handles): void {
                $query->whereIn('product_handle', $handles)
                    // Apple has fixed fragrances and must never expose custom choices.
                    ->orWhere('product_handle', 'apple-candle-bundle');
            })
            ->delete();

        $now = now();

        foreach ($assignments as $rulesetName => $ruleHandles) {
            $rulesetId = DB::table('shopify_product_option_rulesets')
                ->where('tenant_id', (int) $tenantId)
                ->where('name', $rulesetName)
                ->value('id');

            if (! is_numeric($rulesetId)) {
                continue;
            }

            foreach ($ruleHandles as $handle) {
                DB::table('shopify_product_option_assignments')->insert([
                    'tenant_id' => (int) $tenantId,
                    'ruleset_id' => (int) $rulesetId,
                    'product_handle' => $handle,
                    'product_url' => 'https://theforestrystudio.com/products/'.$handle,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Reconciled product ownership is intentionally retained on rollback.
    }
};
