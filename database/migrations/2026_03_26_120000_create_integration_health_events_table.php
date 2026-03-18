<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_health_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('shopify_store_id')->nullable()->index();
            $table->string('store_key')->nullable()->index();
            $table->string('provider', 60)->index();
            $table->string('event_type', 120)->index();
            $table->string('severity', 20)->default('info')->index();
            $table->string('status', 20)->default('open')->index();
            $table->string('dedupe_key', 64)->nullable()->index();
            $table->string('related_model_type')->nullable();
            $table->unsignedBigInteger('related_model_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('shopify_store_id')
                ->references('id')
                ->on('shopify_stores')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_health_events');
    }
};

