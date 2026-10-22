<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('recovers retained software support rows before MySQL index DDL', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_10_22_180000_create_software_support_records.php');
    $migration->up();
    $id = (string) Str::uuid();
    DB::table('software_support_records')->insert([
        'id' => $id, 'owner_tenant_id' => 1, 'client_tenant_id' => 2, 'ticket_id' => 1, 'created_by' => 1,
        'department' => 'fictional', 'kind' => 'event', 'request_key' => hash('sha256', $id), 'payload' => 'recovery sentinel',
    ]);
    foreach (['ssr_replay_uq', 'ssr_scope_idx'] as $name) {
        Schema::table('software_support_records', fn ($table) => $table->dropIndex($name));
    }
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('software_support_records', 'ssr_replay_uq'))->toBeTrue()
        ->and(Schema::hasIndex('software_support_records', 'ssr_scope_idx'))->toBeTrue()
        ->and(DB::table('software_support_records')->where('id', $id)->value('payload'))->toBe('recovery sentinel');
    DB::table('software_support_records')->where('id', $id)->delete();
});
