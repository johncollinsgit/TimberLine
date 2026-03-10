<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('normalized_email')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('normalized_phone')->nullable()->index();
            $table->boolean('accepts_email_marketing')->default(false);
            $table->boolean('accepts_sms_marketing')->default(false);
            $table->timestamp('email_opted_out_at')->nullable();
            $table->timestamp('sms_opted_out_at')->nullable();
            $table->json('source_channels')->nullable();
            $table->decimal('marketing_score', 8, 2)->nullable();
            $table->timestamp('last_marketing_score_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_profile_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_profile_id')
                ->constrained('marketing_profiles')
                ->cascadeOnDelete();
            $table->string('source_type');
            $table->string('source_id');
            $table->json('source_meta')->nullable();
            $table->string('match_method')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->timestamps();

            $table->index(['marketing_profile_id', 'source_type'], 'mpl_profile_source_type_idx');
            $table->unique(['source_type', 'source_id'], 'mpl_source_unique_idx');
        });

        Schema::create('marketing_identity_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('pending')->index();
            $table->foreignId('proposed_marketing_profile_id')
                ->nullable()
                ->constrained('marketing_profiles')
                ->nullOnDelete();
            $table->string('raw_email')->nullable()->index();
            $table->string('raw_phone')->nullable()->index();
            $table->string('raw_first_name')->nullable();
            $table->string('raw_last_name')->nullable();
            $table->string('source_type');
            $table->string('source_id');
            $table->json('conflict_reasons')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'mir_source_lookup_idx');
        });

        Schema::create('marketing_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_event_source_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('source_system');
            $table->string('raw_value')->index();
            $table->string('normalized_value')->nullable()->index();
            $table->foreignId('event_instance_id')
                ->nullable()
                ->constrained('event_instances')
                ->nullOnDelete();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['source_system', 'raw_value'], 'mesm_source_raw_unique_idx');
        });

        $now = now();
        DB::table('marketing_settings')->upsert(
            [
                [
                    'key' => 'sms_default_send_window',
                    'value' => json_encode([
                        'start' => '09:00',
                        'end' => '18:00',
                        'timezone' => 'America/New_York',
                    ]),
                    'description' => 'Default local-time send window for outbound SMS campaigns.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'key' => 'sms_quiet_hours',
                    'value' => json_encode([
                        'start' => '20:00',
                        'end' => '09:00',
                        'timezone' => 'America/New_York',
                    ]),
                    'description' => 'Quiet-hour boundaries used to suppress outbound SMS sends.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'key' => 'attribution_last_touch_days',
                    'value' => json_encode(['days' => 14]),
                    'description' => 'Default last-touch attribution lookback window.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'key' => 'rewards_enabled',
                    'value' => json_encode(['enabled' => false]),
                    'description' => 'Feature flag placeholder for Candle Cash rewards.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'key' => 'reviews_enabled',
                    'value' => json_encode(['enabled' => false]),
                    'description' => 'Feature flag placeholder for marketing review workflows.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            ['key'],
            ['value', 'description', 'updated_at']
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_event_source_mappings');
        Schema::dropIfExists('marketing_settings');
        Schema::dropIfExists('marketing_identity_reviews');
        Schema::dropIfExists('marketing_profile_links');
        Schema::dropIfExists('marketing_profiles');
    }
};
