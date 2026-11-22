<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('restores interrupted video indexes and missing invitation table while preserving media and historical product attribution', function () {
    if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires a disposable MySQL test database.');
    }
    $migration = require database_path('migrations/2026_11_22_010000_create_marketplace_videos.php');
    $migration->up();
    $maker = DB::table('marketplace_makers')->insertGetId(['name' => 'Recovery maker', 'slug' => 'video-recovery', 'city' => 'Easley', 'story' => 'A fictional maker.']);
    $video = DB::table('marketplace_videos')->insertGetId(['maker_id' => $maker, 'title' => 'Private draft', 'format' => 'short', 'source_path' => 'private/source.mp4', 'source_type' => 'video/mp4']);
    $tag = DB::table('marketplace_video_tags')->insertGetId(['video_id' => $video, 'product_key' => 'marketplace:123', 'label' => 'Retained product', 'clicks' => 5, 'active' => false]);
    $beforeVideo = DB::table('marketplace_videos')->find($video);
    $beforeTag = DB::table('marketplace_video_tags')->find($tag);
    try {
        Schema::drop('marketplace_video_invitations');
        Schema::table('marketplace_videos', fn (Blueprint $table) => $table->dropIndex('mm_video_publication'));
        Schema::table('marketplace_video_tags', fn (Blueprint $table) => $table->dropForeign(['video_id']));
        Schema::table('marketplace_video_tags', fn (Blueprint $table) => $table->dropUnique('mm_video_product_key'));
        $migration->up();
        $migration->up();
        expect(Schema::hasTable('marketplace_video_invitations'))->toBeTrue()
            ->and(Schema::hasIndex('marketplace_videos', 'mm_video_publication'))->toBeTrue()
            ->and(Schema::hasIndex('marketplace_video_tags', 'mm_video_product_key'))->toBeTrue()
            ->and(collect(Schema::getForeignKeys('marketplace_video_tags'))->pluck('foreign_table')->all())->toContain('marketplace_videos')
            ->and((array) DB::table('marketplace_videos')->find($video))->toBe((array) $beforeVideo)
            ->and((array) DB::table('marketplace_video_tags')->find($tag))->toBe((array) $beforeTag);
    } finally {
        DB::table('marketplace_video_tags')->where('id', $tag)->delete();
        DB::table('marketplace_videos')->where('id', $video)->delete();
        DB::table('marketplace_makers')->where('id', $maker)->delete();
    }
});
