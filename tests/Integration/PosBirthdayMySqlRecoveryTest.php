<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('retains annual claim evidence across interrupted creation and rejects a changed unique index', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('POS_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing birthday recovery outside local disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_11_01_150000_create_pos_birthday_cycle_fences.php');
    $migration->up();
    if (DB::table('pos_birthday_authorizations')->exists()) {
        throw new RuntimeException('Refusing to drop a birthday authorization table containing evidence.');
    }
    $tenant = random_int(1000000, 9999999);
    $operation = (string) Str::uuid();
    DB::table('pos_birthday_cycle_fences')->insert(['tenant_id' => $tenant, 'coverage_id' => $tenant,
        'issuance_id' => $tenant, 'state' => 'review', 'operation_id' => $operation, 'created_at' => now(), 'updated_at' => now()]);
    Schema::drop('pos_birthday_authorizations');
    $migration->up();
    $migration->up();
    $migration->down();
    expect(Schema::hasTable('pos_birthday_authorizations'))->toBeTrue()
        ->and(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant)->value('operation_id'))->toBe($operation);
    Schema::table('pos_birthday_cycle_fences', fn ($table) => $table->dropUnique('pos_birthday_cycle_fence_unique'));
    try {
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Birthday fence index');
        expect(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant)->value('operation_id'))->toBe($operation);
    } finally {
        Schema::table('pos_birthday_cycle_fences', fn ($table) => $table->unique(['tenant_id', 'coverage_id'], 'pos_birthday_cycle_fence_unique'));
        DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant)->delete();
    }
});
