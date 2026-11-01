<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('recovers hold indexes while preserving annual evidence and rejects altered definitions', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('POS_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing birthday recovery outside local disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_11_01_170000_create_pos_birthday_annual_holds.php');
    $migration->up();
    $tenant = random_int(1000000, 9999999);
    $id = DB::table('pos_birthday_annual_holds')->insertGetId(['runtime_id' => 'fixture_runtime', 'tenant_id' => $tenant, 'coverage_id' => $tenant,
        'sale_id' => str_repeat('a', 32), 'state' => 'held', 'amount_minor' => 500, 'policy_version' => str_repeat('b', 64),
        'authorization_reference' => str_repeat('c', 64), 'created_at' => now(), 'updated_at' => now()]);
    $before = (array) DB::table('pos_birthday_annual_holds')->where('id', $id)->first();
    Schema::table('pos_birthday_annual_holds', fn (Blueprint $table) => $table->dropUnique('pos_birthday_annual_hold_cycle_uq'));
    try {
        $migration->up();
        $migration->up();
        $migration->down();
        expect((array) DB::table('pos_birthday_annual_holds')->where('id', $id)->first())->toBe($before);
        expect(fn () => DB::table('pos_birthday_annual_holds')->insert([...$before, 'id' => $id + 1, 'sale_id' => str_repeat('d', 32)]))->toThrow(\Illuminate\Database\QueryException::class);
        Schema::table('pos_birthday_annual_holds', function (Blueprint $table): void {
            $table->dropUnique('pos_birthday_annual_hold_cycle_uq');
            $table->unique(['tenant_id', 'sale_id'], 'pos_birthday_annual_hold_cycle_uq');
        });
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Birthday annual hold index');
        expect((array) DB::table('pos_birthday_annual_holds')->where('id', $id)->first())->toBe($before);
    } finally {
        Schema::table('pos_birthday_annual_holds', function (Blueprint $table): void {
            $table->dropUnique('pos_birthday_annual_hold_cycle_uq');
            $table->unique(['tenant_id', 'coverage_id'], 'pos_birthday_annual_hold_cycle_uq');
        });
        DB::table('pos_birthday_annual_holds')->where('id', $id)->delete();
    }
});
