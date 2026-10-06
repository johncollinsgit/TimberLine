<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_service_crew_statuses')) {
            Schema::create('field_service_crew_statuses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('field_service_job_id')->nullable()->constrained('field_service_jobs')->nullOnDelete();
                $table->string('status', 30)->default('available');
                $table->string('note', 240)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'user_id'], 'fs_crew_status_tenant_user_unique');
                $table->index(['tenant_id', 'status'], 'fs_crew_status_tenant_state_idx');
            });
        }

        if (! Schema::hasTable('tenant_ai_usage_events')) {
            Schema::create('tenant_ai_usage_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_direct_invoice_id')->nullable()->constrained('tenant_direct_invoices')->nullOnDelete();
                $table->uuid('client_uuid');
                $table->string('provider_request_id', 120)->nullable();
                $table->string('feature', 80);
                $table->string('context', 30);
                $table->string('model', 80);
                $table->string('status', 20)->default('reserved');
                $table->unsignedSmallInteger('duration_seconds');
                $table->unsignedBigInteger('provider_cost_micros');
                $table->unsignedBigInteger('buyer_charge_micros');
                $table->timestamp('occurred_at');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'client_uuid'], 'tenant_ai_usage_tenant_client_unique');
                $table->index(['tenant_id', 'status', 'occurred_at'], 'tenant_ai_usage_tenant_period_idx');
                $table->index(['tenant_id', 'user_id', 'occurred_at'], 'tenant_ai_usage_tenant_user_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_usage_events');
        Schema::dropIfExists('field_service_crew_statuses');
    }
};
