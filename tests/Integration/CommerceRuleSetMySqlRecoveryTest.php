<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('COMMERCE_RULES_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires an explicitly authorized disposable MySQL database.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing commerce-rule recovery outside local disposable MySQL.');
    }
});

it('replays rule-set creation and rollback without deleting existing tenant rules', function (): void {
    $migration = require database_path('migrations/2026_11_01_100000_create_tenant_commerce_rule_sets.php');
    $migration->up();
    $tenant = 987654321;
    DB::table('tenant_commerce_rule_sets')->where('tenant_id', $tenant)->delete();
    try {
        DB::table('tenant_commerce_rule_sets')->insert(['tenant_id' => $tenant, 'rules' => '[]', 'version' => str_repeat('a', 64),
            'updated_by' => 12, 'created_at' => now(), 'updated_at' => now()]);
        $before = (array) DB::table('tenant_commerce_rule_sets')->where('tenant_id', $tenant)->first();
        $migration->up(); $migration->down(); $migration->up();
        expect((array) DB::table('tenant_commerce_rule_sets')->where('tenant_id', $tenant)->first())->toBe($before)
            ->and(Schema::hasIndex('tenant_commerce_rule_sets', 'tenant_commerce_rules_tenant_unique'))->toBeTrue();
        expect(fn () => DB::table('tenant_commerce_rule_sets')->insert(['tenant_id' => $tenant, 'rules' => '[]',
            'version' => str_repeat('a', 64), 'updated_by' => 12, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
    } finally {
        DB::table('tenant_commerce_rule_sets')->where('tenant_id', $tenant)->delete();
    }
});
