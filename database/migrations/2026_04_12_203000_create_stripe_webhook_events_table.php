<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stripe_webhook_events')) {
            return;
        }

        Schema::create('stripe_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 190);
            $table->string('event_type', 190);
            $table->string('status', 40)->default('received');
            $table->boolean('livemode')->default(false);
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('checkout_session_id', 190)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['event_id'], 'swe_event_id_unique');
            $table->index(['tenant_id'], 'swe_tenant_idx');
            $table->index(['event_type'], 'swe_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};

