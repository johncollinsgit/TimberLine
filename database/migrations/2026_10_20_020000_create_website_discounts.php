<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_discounts')) {
            Schema::create('website_discounts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('tenant_site_id');
                $table->string('code', 64);
                $table->string('type', 16);
                $table->unsignedInteger('value');
                $table->unsignedInteger('minimum_subtotal_cents')->default(0);
                $table->unsignedInteger('maximum_issues')->nullable();
                $table->unsignedInteger('issued_count')->default(0);
                $table->boolean('enabled')->default(true);
                $table->json('eligible_variant_ids')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
        // MySQL can retain CREATE TABLE before Laravel's later index/FK DDL.
        if (! Schema::hasIndex('website_discounts', 'wd_site_code_uq')) {
            Schema::table('website_discounts', fn (Blueprint $table) => $table->unique(['tenant_id', 'tenant_site_id', 'code'], 'wd_site_code_uq'));
        }
        if (! Schema::hasIndex('website_discounts', 'wd_site_idx')) {
            Schema::table('website_discounts', fn (Blueprint $table) => $table->index('tenant_site_id', 'wd_site_idx'));
        }
        foreach (['tenant_id' => ['wd_tenant_fk', 'tenants'], 'tenant_site_id' => ['wd_site_fk', 'tenant_sites']] as $column => [$name, $target]) {
            if (! collect(Schema::getForeignKeys('website_discounts'))->contains(fn ($key) => $key['name'] === $name)) {
                Schema::table('website_discounts', fn (Blueprint $table) => $table->foreign($column, $name)->references('id')->on($target)->cascadeOnDelete());
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('website_discounts');
    }
};
