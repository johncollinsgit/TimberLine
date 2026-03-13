<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_campaign_recipient_id')->nullable()->constrained('marketing_campaign_recipients')->nullOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->string('sendgrid_message_id')->nullable()->index();
            $table->string('email')->index();
            $table->string('status')->default('queued')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['marketing_campaign_recipient_id', 'status'], 'med_recipient_status_idx');
        });

        Schema::create('candle_cash_balances', function (Blueprint $table): void {
            $table->foreignId('marketing_profile_id')->primary()->constrained('marketing_profiles')->cascadeOnDelete();
            $table->integer('balance')->default(0);
            $table->timestamps();
        });

        Schema::create('candle_cash_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_profile_id')->constrained('marketing_profiles')->cascadeOnDelete();
            $table->string('type')->index(); // earn|redeem|adjust
            $table->integer('points');
            $table->string('source')->index(); // order|reward|admin|consent|campaign
            $table->string('source_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('candle_cash_rewards', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('points_cost');
            $table->string('reward_type')->index();
            $table->string('reward_value')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('candle_cash_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_profile_id')->constrained('marketing_profiles')->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained('candle_cash_rewards')->cascadeOnDelete();
            $table->unsignedInteger('points_spent');
            $table->string('platform')->nullable()->index(); // shopify|square
            $table->string('redemption_code')->unique();
            $table->timestamp('redeemed_at')->nullable()->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('candle_cash_rewards')->insert([
            [
                'name' => '$10 coupon',
                'description' => 'Redeem for a $10 discount coupon.',
                'points_cost' => 100,
                'reward_type' => 'coupon',
                'reward_value' => '10USD',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Free wax melt',
                'description' => 'Redeem for one free wax melt item.',
                'points_cost' => 60,
                'reward_type' => 'product',
                'reward_value' => 'wax_melt',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => '15% off candle',
                'description' => 'Redeem for 15% off one candle purchase.',
                'points_cost' => 150,
                'reward_type' => 'percent_discount',
                'reward_value' => '15%',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('marketing_settings')->upsert(
            [[
                'key' => 'candle_cash_consent_bonus_points',
                'value' => json_encode(['points' => 0]),
                'description' => 'Optional bonus Candle Cash points for confirmed SMS consent capture events.',
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['key'],
            ['value', 'description', 'updated_at']
        );
    }

    public function down(): void
    {
        DB::table('marketing_settings')->where('key', 'candle_cash_consent_bonus_points')->delete();

        Schema::dropIfExists('candle_cash_redemptions');
        Schema::dropIfExists('candle_cash_rewards');
        Schema::dropIfExists('candle_cash_transactions');
        Schema::dropIfExists('candle_cash_balances');
        Schema::dropIfExists('marketing_email_deliveries');
    }
};
