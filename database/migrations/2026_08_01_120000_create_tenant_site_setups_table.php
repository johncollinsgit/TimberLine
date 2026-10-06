<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_site_setups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants', 'id', 'tenant_site_setups_tenant_fk')->cascadeOnDelete();
            $table->foreignId('tenant_site_id')->unique()->constrained('tenant_sites', 'id', 'tenant_site_setups_site_fk')->cascadeOnDelete();
            $table->string('business_mode', 40)->default('trades');
            $table->string('offering_mode', 40)->default('services');
            $table->json('visitor_actions')->nullable();
            $table->string('design_key', 80)->default('collins-electric');
            $table->string('domain_choice', 40)->default('everbranch_subdomain');
            $table->string('contact_name', 190)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 80)->nullable();
            $table->string('hours', 500)->nullable();
            $table->string('service_area', 500)->nullable();
            $table->json('completed_steps')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', 'id', 'tenant_site_setups_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users', 'id', 'tenant_site_setups_updated_by_fk')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_site_setups');
    }
};
