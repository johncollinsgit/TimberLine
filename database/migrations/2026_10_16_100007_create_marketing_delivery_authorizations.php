<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_delivery_authorizations')) {
            Schema::create('marketing_delivery_authorizations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('md_8_tenant_idx');
                $table->unsignedBigInteger('workflow_id');
                $table->string('definition_hash', 64);
                $table->string('audience', 24);
                $table->string('store_key', 80)->default('');
                $table->unsignedBigInteger('approved_by');
                $table->timestamp('approved_at');
                $table->boolean('active')->default(true);
                $table->unique(['workflow_id', 'definition_hash'], 'md_delivery_approval_unique');
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('marketing_delivery_authorizations', 'md_8_tenant_idx')) {
            Schema::table('marketing_delivery_authorizations', fn (Blueprint $table) => $table->index('tenant_id', 'md_8_tenant_idx'));
        }
        if (! Schema::hasIndex('marketing_delivery_authorizations', 'md_delivery_approval_unique')) {
            Schema::table('marketing_delivery_authorizations', fn (Blueprint $table) => $table->unique(['workflow_id', 'definition_hash'], 'md_delivery_approval_unique'));
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_delivery_authorizations');
    }
};
