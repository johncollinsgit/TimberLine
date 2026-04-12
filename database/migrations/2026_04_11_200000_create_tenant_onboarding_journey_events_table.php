<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_onboarding_journey_events')) {
            return;
        }

        Schema::create('tenant_onboarding_journey_events', function (Blueprint $table): void {
            $table->id();
            // Provisioned/production tenant (canonical tenant scope column).
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Optional lineage back to the finalized blueprint that provisioned this tenant.
            $table->foreignId('final_blueprint_id')
                ->nullable()
                ->constrained('tenant_onboarding_blueprints')
                ->nullOnDelete();
            $table->string('event_key', 120);
            $table->timestamp('occurred_at');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Idempotency guard (event-specific discriminator hashed to keep unique keys short).
            $table->string('dedupe_key', 64)->unique();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event_key', 'occurred_at'], 'tenant_onboarding_journey_events_tenant_key_idx');
            $table->index(['final_blueprint_id', 'event_key', 'occurred_at'], 'tenant_onboarding_journey_events_blueprint_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_onboarding_journey_events');
    }
};

