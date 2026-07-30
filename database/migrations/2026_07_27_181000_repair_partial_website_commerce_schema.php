<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL can retain a partially-created table when a later constraint
        // declaration fails. Repair only the incomplete reservation table left
        // by the original additive migration; never drop or rewrite rows.
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('website_inventory_reservations')) {
            return;
        }

        Schema::table('website_inventory_reservations', function (Blueprint $table): void {
            if (! $this->foreignKeyExists('website_reservations_variant_fk')) {
                $table->foreign('website_product_variant_id', 'website_reservations_variant_fk')
                    ->references('id')->on('website_product_variants')->cascadeOnDelete();
            }

            if (! $this->foreignKeyExists('website_reservations_order_fk')) {
                $table->foreign('website_order_id', 'website_reservations_order_fk')
                    ->references('id')->on('website_orders')->cascadeOnDelete();
            }

            if (! $this->indexExists('website_reservation_order_variant_uq')) {
                $table->unique(['website_order_id', 'website_product_variant_id'], 'website_reservation_order_variant_uq');
            }

            if (! $this->indexExists('website_reservation_variant_status_idx')) {
                $table->index(['tenant_id', 'website_product_variant_id', 'status'], 'website_reservation_variant_status_idx');
            }
        });
    }

    public function down(): void
    {
        // Recovery migration is intentionally additive and must not remove
        // production constraints or indexes during a rollback.
    }

    private function foreignKeyExists(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'website_inventory_reservations')
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private function indexExists(string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'website_inventory_reservations')
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
