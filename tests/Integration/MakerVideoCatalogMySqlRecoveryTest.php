<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('restores the catalog and attribution indexes while preserving existing maker selections', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $catalog = require database_path('migrations/2026_11_22_100000_create_maker_video_catalog_sources.php');
    $attribution = require database_path('migrations/2026_11_22_100100_add_video_tag_to_marketplace_order_lines.php');
    $catalog->up();
    $attribution->up();
    $table = 'marketplace_video_catalog_sources';
    $id = DB::table($table)->insertGetId(['maker_id' => 918273, 'provider' => 'shopify', 'reference' => uniqid('test-').'.myshopify.com', 'selections' => '{"17":{"label":"Candle","variant":"16 oz"}}']);
    Schema::table($table, fn ($t) => $t->dropUnique('mm_video_catalog_store_unique'));
    Schema::table($table, fn ($t) => $t->dropIndex('mm_video_catalog_maker'));
    Schema::table('marketplace_order_lines', fn ($t) => $t->dropIndex('mm_order_line_video_tag'));
    foreach ([$catalog, $attribution, $catalog, $attribution] as $migration) {
        $migration->up();
    }
    expect(Schema::hasIndex($table, 'mm_video_catalog_store_unique'))->toBeTrue()
        ->and(Schema::hasIndex($table, 'mm_video_catalog_maker'))->toBeTrue()
        ->and(Schema::hasIndex('marketplace_order_lines', 'mm_order_line_video_tag'))->toBeTrue()
        ->and(json_decode(DB::table($table)->where('id', $id)->value('selections'), true)[17]['variant'])->toBe('16 oz');
    DB::table($table)->where('id', $id)->delete();
});
