<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            Schema::create('tenant_marketing_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('key');
                $table->json('value')->nullable();
                $table->string('description')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'key'], 'tenant_marketing_settings_tenant_key_unique');
                $table->index('key', 'tenant_marketing_settings_key_index');
            });
        }

        if (! Schema::hasTable('tenant_candle_cash_task_overrides')) {
            Schema::create('tenant_candle_cash_task_overrides', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('candle_cash_task_id')->constrained('candle_cash_tasks')->cascadeOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->decimal('reward_amount', 8, 2)->default(0);
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('display_order')->default(0);
                $table->timestamps();

                $table->unique(['tenant_id', 'candle_cash_task_id'], 'tenant_cc_task_overrides_tenant_task_unique');
                $table->index(['tenant_id', 'display_order'], 'tenant_cc_task_overrides_tenant_order_idx');
            });
        }

        if (! Schema::hasTable('tenant_candle_cash_reward_overrides')) {
            Schema::create('tenant_candle_cash_reward_overrides', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('candle_cash_reward_id')->constrained('candle_cash_rewards')->cascadeOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->unsignedInteger('candle_cash_cost');
                $table->string('reward_value')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'candle_cash_reward_id'], 'tenant_cc_reward_overrides_tenant_reward_unique');
                $table->index(['tenant_id', 'is_active'], 'tenant_cc_reward_overrides_tenant_active_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_candle_cash_reward_overrides');
        Schema::dropIfExists('tenant_candle_cash_task_overrides');
        Schema::dropIfExists('tenant_marketing_settings');
    }
};
