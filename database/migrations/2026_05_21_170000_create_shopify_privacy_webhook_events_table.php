<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopify_privacy_webhook_events')) {
            return;
        }

        Schema::create('shopify_privacy_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('topic', 120);
            $table->string('shop_domain', 190)->nullable();
            $table->string('webhook_id', 190)->nullable();
            $table->string('payload_hash', 64);
            $table->json('payload_summary')->nullable();
            $table->string('status', 60)->default('manual_review_required');
            $table->boolean('action_required')->default(true);
            $table->timestamp('handled_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['webhook_id'], 'spwe_webhook_id_unique');
            $table->index(['topic'], 'spwe_topic_idx');
            $table->index(['shop_domain'], 'spwe_shop_domain_idx');
            $table->index(['status', 'action_required'], 'spwe_status_action_idx');
            $table->index(['topic', 'payload_hash'], 'spwe_topic_payload_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_privacy_webhook_events');
    }
};
