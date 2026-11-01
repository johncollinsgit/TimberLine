<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_commerce_rule_sets')) {
            Schema::create('tenant_commerce_rule_sets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->json('rules');
                $table->char('version', 64);
                $table->unsignedBigInteger('updated_by');
                $table->timestamps();
                $table->unique('tenant_id', 'tenant_commerce_rules_tenant_unique');
            });
        }
    }

    public function down(): void
    {
        // Keep merchant configuration and audit evidence on rollback.
    }
};
