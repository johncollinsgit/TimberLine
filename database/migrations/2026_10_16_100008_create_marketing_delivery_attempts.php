<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_delivery_attempts')) {
            Schema::create('marketing_delivery_attempts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('md_9_tenant_idx');
                $table->unsignedBigInteger('marketing_profile_id');
                $table->unsignedBigInteger('workflow_id')->nullable();
                $table->string('idempotency_key', 64);
                $table->string('channel', 24);
                $table->string('status', 24);
                $table->string('reason', 100)->nullable();
                $table->unsignedBigInteger('campaign_id')->nullable();
                $table->unsignedBigInteger('recipient_id')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->unique(['tenant_id', 'idempotency_key'], 'md_delivery_attempt_unique');
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('marketing_delivery_attempts', 'md_9_tenant_idx')) {
            Schema::table('marketing_delivery_attempts', fn (Blueprint $table) => $table->index('tenant_id', 'md_9_tenant_idx'));
        }
        if (! Schema::hasIndex('marketing_delivery_attempts', 'md_delivery_attempt_unique')) {
            Schema::table('marketing_delivery_attempts', fn (Blueprint $table) => $table->unique(['tenant_id', 'idempotency_key'], 'md_delivery_attempt_unique'));
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_delivery_attempts');
    }
};
