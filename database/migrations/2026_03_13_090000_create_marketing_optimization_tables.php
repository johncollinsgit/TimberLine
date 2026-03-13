<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_variant_performance_snapshots')) {
            Schema::create('marketing_variant_performance_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
                $table->foreignId('variant_id')->nullable()->constrained('marketing_campaign_variants')->nullOnDelete();
                $table->string('channel')->index();
                $table->timestamp('window_start')->nullable()->index();
                $table->timestamp('window_end')->nullable()->index();
                $table->unsignedInteger('recipients_count')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('delivered_count')->default(0);
                $table->unsignedInteger('opened_count')->default(0);
                $table->unsignedInteger('clicked_count')->default(0);
                $table->unsignedInteger('converted_count')->default(0);
                $table->decimal('attributed_revenue', 12, 2)->nullable();
                $table->json('snapshot_meta')->nullable();
                $table->timestamps();

                $table->unique(
                    ['campaign_id', 'variant_id', 'channel', 'window_start', 'window_end'],
                    'mvps_campaign_variant_channel_window_unique'
                );
            });
        }

        if (! Schema::hasTable('marketing_recommendation_runs')) {
            Schema::create('marketing_recommendation_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('type')->index();
                $table->string('status')->default('running')->index();
                $table->json('summary')->nullable();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketing_timing_insights')) {
            Schema::create('marketing_timing_insights', function (Blueprint $table): void {
                $table->id();
                $table->string('channel', 80)->index();
                $table->string('objective', 120)->nullable()->index();
                $table->string('segment_key', 120)->nullable()->index();
                $table->string('event_context', 120)->nullable()->index();
                $table->unsignedTinyInteger('recommended_hour')->nullable();
                $table->string('recommended_daypart')->nullable();
                $table->decimal('confidence', 5, 2)->nullable();
                $table->json('reasons_json')->nullable();
                $table->timestamps();

                $table->unique(
                    ['channel', 'objective', 'segment_key', 'event_context'],
                    'mti_channel_objective_segment_event_unique'
                );
            });
        } else {
            DB::statement("
                ALTER TABLE marketing_timing_insights
                MODIFY channel VARCHAR(80) NOT NULL,
                MODIFY objective VARCHAR(120) NULL,
                MODIFY segment_key VARCHAR(120) NULL,
                MODIFY event_context VARCHAR(120) NULL
            ");

            if (! $this->indexExists('marketing_timing_insights', 'mti_channel_objective_segment_event_unique')) {
                Schema::table('marketing_timing_insights', function (Blueprint $table): void {
                    $table->unique(
                        ['channel', 'objective', 'segment_key', 'event_context'],
                        'mti_channel_objective_segment_event_unique'
                    );
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_timing_insights');
        Schema::dropIfExists('marketing_recommendation_runs');
        Schema::dropIfExists('marketing_variant_performance_snapshots');
    }

    protected function indexExists(string $table, string $index): bool
    {
        return collect(DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]))->isNotEmpty();
    }
};
