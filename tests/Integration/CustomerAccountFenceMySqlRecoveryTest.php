<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('repairs a durable fence table missing its primary key and preserves rows through retry and rollback', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Explicit disposable MySQL');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName()) || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Unsafe database');
    }
    $migration = require database_path('migrations/2026_11_06_220000_create_customer_account_fences.php');
    $migration->up();
    DB::table('customer_account_fences')->insertOrIgnore(['tenant_id' => 987654321]);
    Schema::table('customer_account_fences', fn (Blueprint $table) => $table->dropPrimary());
    $migration->up();
    $migration->down();
    $migration->up();
    expect(DB::table('customer_account_fences')->where('tenant_id', 987654321)->exists())->toBeTrue();
    expect(collect(Schema::getIndexes('customer_account_fences'))->firstWhere('primary', true)['columns'])->toBe(['tenant_id']);
    DB::table('customer_account_fences')->where('tenant_id', 987654321)->delete();
});
