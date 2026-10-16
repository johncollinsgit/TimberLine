<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_social_account_bindings')) {
            Schema::create('marketing_social_account_bindings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('md_binding_tenant_idx');
                $table->string('provider', 24);
                $table->string('fingerprint', 64);
                $table->timestamps();
                $table->unique(['provider', 'fingerprint'], 'md_social_binding_unique');
            });
        }
        if (! Schema::hasIndex('marketing_social_account_bindings', 'md_binding_tenant_idx')) {
            Schema::table('marketing_social_account_bindings', fn (Blueprint $table) => $table->index('tenant_id', 'md_binding_tenant_idx'));
        }
        if (! Schema::hasIndex('marketing_social_account_bindings', 'md_social_binding_unique')) {
            Schema::table('marketing_social_account_bindings', fn (Blueprint $table) => $table->unique(['provider', 'fingerprint'], 'md_social_binding_unique'));
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_social_account_bindings');
    }
};
