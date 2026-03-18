<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_automation_events')) {
            return;
        }

        Schema::create('marketing_automation_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->string('trigger_key', 120)->index();
            $table->string('channel', 40)->nullable()->index();
            $table->string('status', 40)->default('queued_intent')->index();
            $table->string('store_key', 80)->nullable()->index();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->index(
                ['tenant_id', 'marketing_profile_id', 'trigger_key', 'channel', 'occurred_at'],
                'marketing_automation_dedupe_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_automation_events');
    }
};
