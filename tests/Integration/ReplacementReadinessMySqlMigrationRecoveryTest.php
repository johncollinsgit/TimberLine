<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('resumes every replacement readiness table migration after mysql retains its table', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    $migrations = [
        '2026_09_21_120000_create_replacement_modules_table.php' => 'replacement_modules',
        '2026_09_21_120100_create_replacement_source_snapshots_table.php' => 'replacement_source_snapshots',
        '2026_09_21_120200_create_replacement_import_batches_table.php' => 'replacement_import_batches',
        '2026_09_21_120300_create_replacement_import_rows_table.php' => 'replacement_import_rows',
        '2026_09_21_120400_create_replacement_evidence_table.php' => 'replacement_evidence',
        '2026_09_21_120500_create_replacement_activation_runs_table.php' => 'replacement_activation_runs',
        '2026_09_21_120600_create_stockist_locator_settings_table.php' => 'stockist_locator_settings',
        '2026_09_21_120700_create_stockist_locations_table.php' => 'stockist_locations',
    ];

    foreach ($migrations as $file => $table) {
        $migration = require database_path('migrations/'.$file);
        $migration->up();
        expect(Schema::hasTable($table))->toBeTrue();

        // Simulate MySQL retaining the completed CREATE TABLE after the release
        // stops before Laravel records the migration batch.
        $migration->up();
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
