<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string,string> */
    private array $tables = [
        'shopify_import_runs' => 'sir_tenant_idx',
        'mapping_exceptions' => 'me_tenant_idx',
        'shopify_import_exceptions' => 'sie_tenant_idx',
        'import_normalizations' => 'in_tenant_idx',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $index) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->unsignedBigInteger('tenant_id')->nullable();
                $blueprint->index('tenant_id', $index);
            });
        }

        $this->backfillFromOrders('mapping_exceptions');
        $this->backfillFromOrders('import_normalizations');

        if (! Schema::hasTable('shopify_stores')) {
            return;
        }

        $storeTenants = DB::table('shopify_stores')
            ->whereNotNull('tenant_id')
            ->pluck('tenant_id', 'store_key');

        foreach ($storeTenants as $storeKey => $tenantId) {
            $tenantId = (int) $tenantId;
            if ($tenantId <= 0) {
                continue;
            }

            $this->backfillByStoreKey('shopify_import_runs', 'store_key', (string) $storeKey, $tenantId);
            $this->backfillByStoreKey('mapping_exceptions', 'store_key', (string) $storeKey, $tenantId);
            $this->backfillByStoreKey('shopify_import_exceptions', 'shop', (string) $storeKey, $tenantId);
            $this->backfillByStoreKey('import_normalizations', 'store_key', (string) $storeKey, $tenantId);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables, true) as $table => $index) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropIndex($index);
                $blueprint->dropColumn('tenant_id');
            });
        }
    }

    private function backfillFromOrders(string $table): void
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'tenant_id')
            || ! Schema::hasColumn($table, 'order_id')
            || ! Schema::hasTable('orders')
            || ! Schema::hasColumn('orders', 'tenant_id')) {
            return;
        }

        DB::table($table)
            ->whereNull('tenant_id')
            ->whereNotNull('order_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table): void {
                $orderIds = collect($rows)->pluck('order_id')->filter()->unique()->values();
                if ($orderIds->isEmpty()) {
                    return;
                }

                $tenantByOrder = DB::table('orders')
                    ->whereIn('id', $orderIds)
                    ->whereNotNull('tenant_id')
                    ->pluck('tenant_id', 'id');

                foreach ($rows as $row) {
                    $tenantId = (int) ($tenantByOrder[$row->order_id] ?? 0);
                    if ($tenantId > 0) {
                        DB::table($table)->where('id', $row->id)->update(['tenant_id' => $tenantId]);
                    }
                }
            });
    }

    private function backfillByStoreKey(string $table, string $column, string $storeKey, int $tenantId): void
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'tenant_id')
            || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->whereNull('tenant_id')
            ->where($column, $storeKey)
            ->update(['tenant_id' => $tenantId]);
    }
};
