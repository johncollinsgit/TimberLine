<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modern_forestry_mobile_bag_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('marketing_profile_id');
            $table->string('email')->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('subtotal_amount', 10, 2)->nullable();
            $table->json('items')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamp('next_reminder_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'marketing_profile_id'], 'mf_mobile_bag_snapshots_unique_profile');
            $table->index(['tenant_id', 'is_active', 'next_reminder_at'], 'mf_mobile_bag_snapshots_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modern_forestry_mobile_bag_snapshots');
    }
};
