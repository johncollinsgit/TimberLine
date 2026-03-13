<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_storefront_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 120)->index();
            $table->string('status', 40)->default('ok')->index();
            $table->string('issue_type', 80)->nullable()->index();
            $table->string('source_surface', 80)->nullable()->index();
            $table->string('endpoint', 160)->nullable()->index();
            $table->string('request_key', 190)->nullable()->index();
            $table->string('signature_mode', 40)->nullable()->index();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->foreignId('event_instance_id')->nullable()->constrained('event_instances')->nullOnDelete();
            $table->foreignId('candle_cash_redemption_id')->nullable()->constrained('candle_cash_redemptions')->nullOnDelete();
            $table->string('source_type', 120)->nullable()->index();
            $table->string('source_id', 180)->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('resolution_status', 40)->default('open')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'issue_type'], 'mse_status_issue_idx');
            $table->index(['marketing_profile_id', 'occurred_at'], 'mse_profile_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_storefront_events');
    }
};

