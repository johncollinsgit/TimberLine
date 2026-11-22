<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('restores interrupted Studio booking indexes while retaining issued sponsor evidence', function () {
    if (DB::getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $offers = require database_path('migrations/2026_11_22_110000_create_studio_offers.php');
    $briefs = require database_path('migrations/2026_11_22_110100_create_studio_briefs.php');
    $offers->up();
    $briefs->up();
    $id = DB::table('studio_offers')->insertGetId(['episode_id' => (string) Str::uuid(), 'tenant_site_id' => 10, 'title' => 'Fixture spot',
        'kind' => 'host_read', 'price_cents' => 5000, 'closes_at' => now()->addDay()]);
    $brief = DB::table('studio_briefs')->insertGetId(['studio_offer_id' => $id, 'request_id' => (string) Str::uuid(),
        'sponsor' => 'Fixture', 'email' => 'demo@example.test', 'brief' => 'Booked read', 'website_order_id' => 928417]);
    $before = (array) DB::table('studio_briefs')->find($brief);
    Schema::table('studio_offers', fn (Blueprint $table) => $table->dropIndex('studio_site_episode'));
    Schema::table('studio_briefs', fn (Blueprint $table) => $table->dropUnique('studio_briefs_request_id_unique'));
    Schema::table('studio_briefs', fn (Blueprint $table) => $table->dropUnique('studio_briefs_website_order_id_unique'));
    foreach ([$offers, $briefs] as $migration) {
        $migration->up();
        $migration->up();
        $migration->down();
    }
    expect(Schema::hasIndex('studio_offers', 'studio_site_episode'))->toBeTrue()
        ->and(Schema::hasIndex('studio_briefs', 'studio_briefs_request_id_unique', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('studio_briefs', 'studio_briefs_website_order_id_unique', 'unique'))->toBeTrue()
        ->and((array) DB::table('studio_briefs')->find($brief))->toBe($before);
    DB::table('studio_briefs')->where('id', $brief)->delete();
    DB::table('studio_offers')->where('id', $id)->delete();
});
