<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        Schema::create('marketing_recommendation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->string('status')->default('running')->index();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('marketing_timing_insights', function (Blueprint $table): void {
            $table->id();
            $table->string('channel')->index();
            $table->string('objective')->nullable()->index();
            $table->string('segment_key')->nullable()->index();
            $table->string('event_context')->nullable()->index();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_timing_insights');
        Schema::dropIfExists('marketing_recommendation_runs');
        Schema::dropIfExists('marketing_variant_performance_snapshots');
    }
};

