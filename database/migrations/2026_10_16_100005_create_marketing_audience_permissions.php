<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_audience_permissions')) {
            Schema::create('marketing_audience_permissions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('md_6_tenant_idx');
                $table->unsignedBigInteger('marketing_profile_id');
                $table->string('audience', 24);
                $table->string('channel', 24);
                $table->string('store_key', 80)->default('');
                $table->string('proof_key', 150);
                $table->timestamp('granted_at');
                $table->timestamp('revoked_at')->nullable();
                $table->unique(['tenant_id', 'marketing_profile_id', 'audience', 'channel', 'store_key'], 'md_audience_permission_unique');
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('marketing_audience_permissions', 'md_6_tenant_idx')) {
            Schema::table('marketing_audience_permissions', fn (Blueprint $table) => $table->index('tenant_id', 'md_6_tenant_idx'));
        }
        if (! Schema::hasIndex('marketing_audience_permissions', 'md_audience_permission_unique')) {
            Schema::table('marketing_audience_permissions', fn (Blueprint $table) => $table->unique(['tenant_id', 'marketing_profile_id', 'audience', 'channel', 'store_key'], 'md_audience_permission_unique'));
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_audience_permissions');
    }
};
