<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('field_service_crew_statuses')) {
            return;
        }

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

    public function down(): void
    {
        Schema::dropIfExists('field_service_crew_statuses');
    }
};
