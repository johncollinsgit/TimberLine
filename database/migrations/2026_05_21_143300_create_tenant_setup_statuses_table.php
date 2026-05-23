<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_setup_statuses')) {
            return;
        }

        Schema::create('tenant_setup_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('business_profile_status', 40)->default('not_started');
            $table->string('import_path', 40)->default('undecided');
            $table->string('shopify_connection_status', 40)->default('not_connected');
            $table->string('square_status', 40)->default('not_requested');
            $table->string('csv_manual_status', 40)->default('not_started');
            $table->json('module_interests')->nullable();
            $table->string('mobile_interest', 40)->default('undecided');
            $table->string('landlord_review_status', 40)->default('pending_review');
            $table->string('next_recommended_action', 500)->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
            $table->index(['import_path', 'mobile_interest']);
            $table->index('landlord_review_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_setup_statuses');
    }
};

