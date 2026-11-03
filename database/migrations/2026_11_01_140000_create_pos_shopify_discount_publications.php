<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_shopify_discount_publications')) {
            Schema::create('pos_shopify_discount_publications', function (Blueprint $table): void {
                $table->uuid('request_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('shopify_store_id');
                $table->string('rule_id', 64);
                $table->char('rule_version', 64);
                $table->string('code', 32);
                $table->char('request_hash', 64);
                $table->string('status', 20);
                $table->string('shopify_discount_id', 255)->nullable();
                $table->string('lookup_state', 32)->nullable();
                $table->string('lookup_discount_id', 255)->nullable();
                $table->timestamp('lookup_at')->nullable();
                $table->unsignedBigInteger('actor_id');
                $table->timestamps();
            });
        }
        foreach ([
            'pos_shopify_discount_code_uq' => ['tenant_id', 'shopify_store_id', 'code'],
            'pos_shopify_discount_rule_uq' => ['tenant_id', 'shopify_store_id', 'rule_id'],
        ] as $name => $columns) {
            $index = collect(Schema::getIndexes('pos_shopify_discount_publications'))->firstWhere('name', $name);
            if ($index && ($index['columns'] !== $columns || ! $index['unique'])) {
                throw new RuntimeException('POS Shopify discount index definition changed: '.$name);
            }
            if (! $index) {
                Schema::table('pos_shopify_discount_publications', fn (Blueprint $table) => $table->unique($columns, $name));
            }
        }
    }

    public function down(): void
    {
        // Keep publication and uncertainty evidence.
    }
};
