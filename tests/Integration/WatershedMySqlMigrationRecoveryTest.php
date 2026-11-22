<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);
it('resumes Watershed creation with retained encrypted records and missing later tables', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Disposable MySQL required.');
    }
    $migration = require database_path('migrations/2026_11_21_220000_create_watershed_registration.php');
    $migration->up();
    $id = (string) \Illuminate\Support\Str::uuid();
    DB::table('watershed_terms')->insert(['id' => $id, 'tenant_id' => 987654, 'term_key' => 'recovery-fixture', 'name' => 'Recovery fixture', 'school_year' => 2026, 'created_at' => now(), 'updated_at' => now()]);
    Schema::dropIfExists('watershed_deliveries');
    Schema::dropIfExists('watershed_imports');
    $migration->up();
    $migration->up();
    $migration->down();
    expect(Schema::hasIndex('watershed_deliveries', 'ws_delivery_key'))->toBeTrue()->and(Schema::hasTable('watershed_imports'))->toBeTrue()->and(DB::table('watershed_terms')->where('id', $id)->value('name'))->toBe('Recovery fixture');
    DB::table('watershed_terms')->where('id', $id)->delete();
});
