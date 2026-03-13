<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_birthday_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_profile_id')
                ->constrained('marketing_profiles')
                ->cascadeOnDelete()
                ->unique();
            $table->unsignedTinyInteger('birth_month')->nullable()->index();
            $table->unsignedTinyInteger('birth_day')->nullable()->index();
            $table->unsignedSmallInteger('birth_year')->nullable()->index();
            $table->date('birthday_full_date')->nullable()->index();
            $table->string('source', 120)->nullable()->index();
            $table->timestamp('source_captured_at')->nullable()->index();
            $table->timestamp('reward_last_issued_at')->nullable()->index();
            $table->unsignedSmallInteger('reward_last_issued_year')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['birth_month', 'birth_day'], 'cbp_month_day_idx');
        });

        Schema::create('customer_birthday_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_birthday_profile_id')
                ->constrained('customer_birthday_profiles')
                ->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')
                ->nullable()
                ->constrained('marketing_profiles')
                ->nullOnDelete();
            $table->string('action', 80)->index();
            $table->string('source', 120)->nullable()->index();
            $table->boolean('is_uncertain')->default(false)->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('birthday_reward_issuances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_birthday_profile_id')
                ->constrained('customer_birthday_profiles')
                ->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')
                ->constrained('marketing_profiles')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle_year')->index();
            $table->string('reward_type', 40)->index();
            $table->string('status', 40)->default('issued')->index();
            $table->integer('points_awarded')->nullable();
            $table->string('reward_code', 120)->nullable()->index();
            $table->timestamp('claim_window_starts_at')->nullable()->index();
            $table->timestamp('claim_window_ends_at')->nullable()->index();
            $table->timestamp('issued_at')->nullable()->index();
            $table->timestamp('claimed_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['marketing_profile_id', 'cycle_year', 'reward_type'],
                'birthday_reward_issuance_cycle_unique'
            );
        });

        $now = now();

        DB::table('marketing_settings')->upsert([
            [
                'key' => 'birthday_reward_config',
                'value' => json_encode([
                    'enabled' => true,
                    'reward_type' => 'points',
                    'points_amount' => 50,
                    'discount_code_prefix' => 'BDAY',
                    'free_shipping_code_prefix' => 'BDAYSHIP',
                    'claim_window_days_before' => 0,
                    'claim_window_days_after' => 14,
                ]),
                'description' => 'Birthday reward defaults for yearly issuance.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'birthday_capture_config',
                'value' => json_encode([
                    'year_optional' => true,
                ]),
                'description' => 'Birthday capture mode controls for month/day-only support.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['key'], ['value', 'description', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('marketing_settings')->whereIn('key', [
            'birthday_reward_config',
            'birthday_capture_config',
        ])->delete();

        Schema::dropIfExists('birthday_reward_issuances');
        Schema::dropIfExists('customer_birthday_audits');
        Schema::dropIfExists('customer_birthday_profiles');
    }
};
