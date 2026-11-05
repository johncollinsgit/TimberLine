<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('resumes stockist creation after retained settings and preserves publication on rollback', function () {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('STOCKIST_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires an explicitly authorized disposable MySQL database.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing stockist recovery outside local disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_11_05_140000_create_stockist_locator_tables.php');
    // This dedicated disposable database is used only by this recovery test.
    Schema::dropIfExists('stockist_locations');
    Schema::dropIfExists('stockist_locator_settings');
    $migration->up();
    DB::table('tenants')->where('slug', 'stockist-recovery-fictional')->delete();
    $tenant = DB::table('tenants')->insertGetId(['name' => 'Fictional stockist recovery', 'slug' => 'stockist-recovery-fictional', 'created_at' => now(), 'updated_at' => now()]);
    $store = DB::table('shopify_stores')->insertGetId(['tenant_id' => $tenant, 'store_key' => 'stockist-recovery', 'store_role' => 'retail', 'access_token' => 'synthetic-recovery-token', 'shop_domain' => 'stockist-recovery.myshopify.com', 'created_at' => now(), 'updated_at' => now()]);
    $id = DB::table('stockist_locator_settings')->insertGetId(['tenant_id' => $tenant, 'shopify_store_id' => $store, 'status' => 'published', 'configuration' => json_encode(['published' => ['locations' => [['name' => 'Retained retailer']]]]), 'source_checksum' => str_repeat('a', 64)]);
    $before = (array) DB::table('stockist_locator_settings')->find($id);
    Schema::drop('stockist_locations');
    $migration->up();
    $migration->up();
    $migration->down();
    expect((array) DB::table('stockist_locator_settings')->find($id))->toBe($before)
        ->and(Schema::hasTable('stockist_locations'))->toBeTrue();
    $row = ['tenant_id' => $tenant, 'shopify_store_id' => $store, 'source_key' => 'fictional-source', 'name' => 'Retained retailer'];
    DB::table('stockist_locations')->insert($row);
    expect(fn () => DB::table('stockist_locations')->insert($row))->toThrow(QueryException::class);
    DB::table('tenants')->where('id', $tenant)->delete();
});
