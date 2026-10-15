<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_catalog_source_mappings')) {
            Schema::create('website_catalog_source_mappings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('tenant_site_id');
                $table->string('provider', 24);
                $table->string('source_shop', 253);
                $table->string('source_type', 16);
                $table->string('source_gid', 190);
                $table->unsignedBigInteger('website_product_id')->nullable();
                $table->unsignedBigInteger('website_product_variant_id')->nullable();
                $table->unsignedBigInteger('website_collection_id')->nullable();
                $table->char('source_fingerprint', 64);
                $table->char('target_fingerprint', 64);
                $table->longText('provenance');
                $table->timestamps();
            });
        }
        // CREATE and each following ALTER can commit separately on MySQL.
        foreach (['tenant_id' => ['wcs_tenant_fk', 'tenants', 'cascade'],
            'tenant_site_id' => ['wcs_site_fk', 'tenant_sites', 'cascade'],
            'website_product_id' => ['wcs_product_fk', 'website_products', 'set null'],
            'website_product_variant_id' => ['wcs_variant_fk', 'website_product_variants', 'set null'],
            'website_collection_id' => ['wcs_collection_fk', 'website_collections', 'set null']] as $column => [$name, $target, $delete]) {
            $present = collect(Schema::getForeignKeys('website_catalog_source_mappings'))->contains(function (array $key) use ($name, $column, $target, $delete): bool {
                $same = $key['columns'] === [$column] && $key['foreign_table'] === $target && $key['foreign_columns'] === ['id'] && strtolower($key['on_delete']) === $delete;
                if (($key['name'] ?? null) === $name && ! $same) {
                    throw new RuntimeException('Unexpected catalog source foreign key definition.');
                }

                return $same && (($key['name'] ?? null) === $name || Schema::getConnection()->getDriverName() !== 'mysql');
            });
            if (! $present) {
                Schema::table('website_catalog_source_mappings', fn (Blueprint $table) => $table->foreign($column, $name)->references('id')->on($target)->onDelete($delete));
            }
        }
        foreach (['wcs_source_uq' => [['tenant_id', 'tenant_site_id', 'provider', 'source_shop', 'source_type', 'source_gid'], true],
            'wcs_product_idx' => [['tenant_id', 'website_product_id'], false],
            'wcs_variant_idx' => [['tenant_id', 'website_product_variant_id'], false],
            'wcs_collection_idx' => [['tenant_id', 'website_collection_id'], false]] as $name => [$columns, $unique]) {
            $existing = collect(Schema::getIndexes('website_catalog_source_mappings'))->firstWhere('name', $name);
            if ($existing && ($existing['columns'] !== $columns || $existing['unique'] !== $unique || $existing['primary'])) {
                throw new RuntimeException('Unexpected catalog source index definition.');
            }
            if (! $existing) {
                Schema::table('website_catalog_source_mappings', function (Blueprint $table) use ($columns, $unique, $name): void {
                    $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        // Source identity and historical provenance survive a code rollback.
    }
};
