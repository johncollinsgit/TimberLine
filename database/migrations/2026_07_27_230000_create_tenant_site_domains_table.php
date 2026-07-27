<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_site_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_site_id')->constrained('tenant_sites')->cascadeOnDelete();
            $table->string('hostname', 253)->unique('site_domains_host_uq');
            $table->string('status', 24)->default('pending')->index('site_domains_status_idx');
            $table->boolean('is_primary')->default(false);
            $table->text('verification_token');
            $table->timestamp('verification_checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'tenant_site_id'], 'site_domains_tenant_site_idx');
            $table->index(['tenant_site_id', 'is_primary'], 'site_domains_site_primary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_site_domains');
    }
};
