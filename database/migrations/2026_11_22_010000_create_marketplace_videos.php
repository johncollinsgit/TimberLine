<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_video_invitations')) {
            Schema::create('marketplace_video_invitations', function (Blueprint $table) {
                $table->foreignId('maker_id')->primary()->constrained('marketplace_makers')->cascadeOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('marketplace_videos')) {
            Schema::create('marketplace_videos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('maker_id')->constrained('marketplace_makers');
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->string('format', 10);
                $table->string('status', 16)->default('draft');
                $table->unsignedInteger('revision')->default(1);
                $table->string('source_path');
                $table->string('source_type', 50);
                $table->string('render_path')->nullable();
                $table->longText('captions')->nullable();
                $table->unsignedInteger('trim_start')->default(0);
                $table->unsignedInteger('trim_end')->nullable();
                $table->decimal('duration', 10, 3)->nullable();
                $table->text('review_note')->nullable();
                $table->unsignedBigInteger('reviewer_user_id')->nullable();
                $table->timestamp('publish_at')->nullable();
                $table->timestamp('first_submitted_at')->nullable();
                $table->unsignedInteger('production_minutes')->nullable();
                $table->unsignedInteger('production_cost_cents')->nullable();
                $table->unsignedBigInteger('views')->default(0);
                $table->timestamps();

            });
        }
        if (! Schema::hasTable('marketplace_video_tags')) {
            Schema::create('marketplace_video_tags', function (Blueprint $table) {
                $table->id();
                $table->foreignId('video_id')->constrained('marketplace_videos');
                $table->string('product_key', 145);
                $table->string('label');
                $table->unsignedInteger('start_seconds')->nullable();
                $table->unsignedInteger('end_seconds')->nullable();
                $table->boolean('active')->default(true);
                $table->unsignedBigInteger('clicks')->default(0);
                $table->timestamps();

            });
        }
        foreach ([
            ['marketplace_video_invitations', 'maker_id', 'marketplace_makers'],
            ['marketplace_videos', 'maker_id', 'marketplace_makers'],
            ['marketplace_video_tags', 'video_id', 'marketplace_videos'],
        ] as [$table, $column, $parent]) {
            $exists = collect(Schema::getForeignKeys($table))->contains(fn ($key) => $key['columns'] === [$column] && $key['foreign_table'] === $parent);
            if (! $exists) {
                Schema::table($table, function (Blueprint $definition) use ($table, $column, $parent) {
                    $foreign = $definition->foreign($column)->references('id')->on($parent);
                    if ($table === 'marketplace_video_invitations') {
                        $foreign->cascadeOnDelete();
                    }
                });
            }
        }
        if (! Schema::hasIndex('marketplace_videos', 'mm_video_publication')) {
            Schema::table('marketplace_videos', fn (Blueprint $table) => $table->index(['status', 'publish_at'], 'mm_video_publication'));
        }
        if (! Schema::hasIndex('marketplace_video_tags', 'mm_video_product_key')) {
            Schema::table('marketplace_video_tags', fn (Blueprint $table) => $table->unique(['video_id', 'product_key'], 'mm_video_product_key'));
        }
    }

    public function down(): void
    {
        // Retain uploaded video metadata, editorial decisions and sale attribution.
    }
};
