<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('marketplace_order_lines', 'video_tag_id')) {
            Schema::table('marketplace_order_lines', function (Blueprint $table): void {
                $table->unsignedBigInteger('video_tag_id')->nullable();
            });
        }
        if (! Schema::hasIndex('marketplace_order_lines', 'mm_order_line_video_tag')) {
            Schema::table('marketplace_order_lines', fn (Blueprint $table) => $table->index('video_tag_id', 'mm_order_line_video_tag'));
        }
    }

    public function down(): void
    {
        // Attribution belongs to the historical paid order line.
    }
};
