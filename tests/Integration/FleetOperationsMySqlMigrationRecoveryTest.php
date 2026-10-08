<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('retains fleet operations when MySQL completed table DDL before migration bookkeeping', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }
    foreach (['tenants', 'fleet_tracking_devices'] as $table) {
        if (! Schema::hasTable($table)) {
            Schema::create($table, fn (Blueprint $t) => $t->id());
        }
    }
    $migration = require database_path('migrations/2026_10_08_000000_create_fleet_operation_records.php');
    $migration->up();
    // MySQL retains this table and its data even if migration bookkeeping was
    // interrupted after CREATE TABLE. Retrying must not recreate or erase it.
    $sourceKey = hash('sha256', random_bytes(20));
    DB::table('fleet_operation_records')->insert(['tenant_id' => DB::table('tenants')->insertGetId([]), 'kind' => 'service_log',
        'source_key' => $sourceKey, 'payload' => 'recovery-sentinel', 'created_at' => now(), 'updated_at' => now()]);
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('fleet_operation_records', 'fleet_ops_source_unique'))->toBeTrue()
        ->and(Schema::hasIndex('fleet_operation_records', 'fleet_ops_time_index'))->toBeTrue()
        ->and(Schema::getColumnType('fleet_operation_records', 'payload'))->toBe('longtext')
        ->and(DB::table('fleet_operation_records')->where('source_key', $sourceKey)->value('payload'))->toBe('recovery-sentinel');
});
