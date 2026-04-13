<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_billing_fulfillments')) {
            return;
        }

        Schema::create('tenant_billing_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('provider', 40)->default('stripe');
            $table->string('provider_customer_reference', 190)->nullable();
            $table->string('provider_subscription_reference', 190)->nullable();
            $table->string('provider_checkout_session_id', 190)->nullable();
            $table->string('state_hash', 80);
            $table->string('desired_plan_key', 120);
            $table->json('desired_addon_keys')->nullable();
            $table->string('desired_operating_mode', 80)->nullable();
            $table->string('status', 40)->default('attempted');
            $table->text('message')->nullable();
            $table->string('source_event_id', 190)->nullable();
            $table->string('source_event_type', 190)->nullable();
            $table->string('triggered_by', 60)->default('webhook');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider'], 'tbf_tenant_provider_idx');
            $table->index(['provider_subscription_reference'], 'tbf_subscription_idx');
            $table->index(['status'], 'tbf_status_idx');
            $table->unique(['tenant_id', 'provider', 'state_hash'], 'tbf_unique_state_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_fulfillments');
    }
};

