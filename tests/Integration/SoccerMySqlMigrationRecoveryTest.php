<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);
it('resumes soccer schema creation after MySQL retains early tables', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_10_13_220000_create_soccer_workspace.php');
    $migration->up();
    Schema::dropIfExists('soccer_groupme_connections');
    Schema::dropIfExists('soccer_deliveries');
    expect(Schema::hasTable('soccer_families'))->toBeTrue();
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('soccer_deliveries'))->toBeTrue()->and(Schema::hasIndex('soccer_deliveries', 'soc_delivery_dedupe'))->toBeTrue();
});
