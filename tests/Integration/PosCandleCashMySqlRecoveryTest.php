<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable POS MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing reward recovery outside local disposable MySQL.');
    }
});

it('restores missing indexes and preserves held evidence through replay and rollback', function (): void {
    $migration = require database_path('migrations/2026_11_01_110000_create_pos_candle_cash_reservations.php');
    $migration->up();
    $id = (string) Str::uuid();
    $row = ['id' => $id, 'runtime_id' => 'recovery_fixture', 'tenant_id' => 900000001,
        'sale_id' => bin2hex(random_bytes(16)), 'marketing_profile_id' => 900000002, 'amount_minor' => 500,
        'policy_version' => 'test-v1', 'authorization_reference' => 'proof-test', 'state' => 'held',
        'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_candle_cash_reservations')->insert($row);
    try {
        $before = (array) DB::table('pos_candle_cash_reservations')->where('id', $id)->first();
        Schema::table('pos_candle_cash_reservations', function (Blueprint $table): void {
            $table->dropUnique('pos_reward_sale_unique');
            $table->dropIndex('pos_reward_customer_index');
        });
        $migration->up();
        $migration->down();
        $migration->up();
        expect((array) DB::table('pos_candle_cash_reservations')->where('id', $id)->first())->toBe($before)
            ->and(Schema::hasIndex('pos_candle_cash_reservations', 'pos_reward_sale_unique'))->toBeTrue()
            ->and(Schema::hasIndex('pos_candle_cash_reservations', 'pos_reward_customer_index'))->toBeTrue();
        $duplicate = $row;
        $duplicate['id'] = (string) Str::uuid();
        expect(fn () => DB::table('pos_candle_cash_reservations')->insert($duplicate))->toThrow(QueryException::class);
        Schema::table('pos_candle_cash_reservations', function (Blueprint $table): void {
            $table->dropUnique('pos_reward_sale_unique');
            $table->index('tenant_id', 'pos_reward_sale_unique');
        });
        try {
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'POS reward index definition changed');
            expect(DB::table('pos_candle_cash_reservations')->where('id', $id)->value('state'))->toBe('held');
        } finally {
            Schema::table('pos_candle_cash_reservations', fn (Blueprint $table) => $table->dropIndex('pos_reward_sale_unique'));
            $migration->up();
        }
    } finally {
        DB::table('pos_candle_cash_reservations')->where('id', $id)->delete();
    }
});
