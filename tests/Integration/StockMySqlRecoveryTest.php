<?php

use App\Services\Inventory\StockSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('repairs partial finished-goods DDL and every named index while retaining stock and rejects altered indexes', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('STOCK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Explicit disposable MySQL required.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing non-test stock schema.');
    }
    StockSchema::install();
    $tenant = 900000501;
    $id = bin2hex(random_bytes(16));
    DB::table('commerce_stock_pools')->insert(['id' => $id, 'tenant_id' => $tenant, 'name' => 'Retained fictional show', 'kind' => 'show', 'created_at' => now(), 'updated_at' => now()]);
    try {
        $before = (array) DB::table('commerce_stock_pools')->where('id', $id)->first();
        Schema::drop('commerce_stock_receipt_associations');
        foreach (array_diff(StockSchema::TABLES, ['receipt_associations']) as $table) {
            foreach (Schema::getIndexes('commerce_stock_'.$table) as $index) {
                if (! $index['primary']) {
                    Schema::table('commerce_stock_'.$table, fn ($t) => $t->dropIndex($index['name']));
                }
            }
        }
        StockSchema::install();
        StockSchema::install();
        expect((array) DB::table('commerce_stock_pools')->where('id', $id)->first())->toBe($before)
            ->and(Schema::hasTable('commerce_stock_receipt_associations'))->toBeTrue()
            ->and(Schema::hasIndex('commerce_stock_balances', 'csb_scope_uq'))->toBeTrue();
        (require database_path('migrations/2026_10_31_190000_create_finished_goods_stock_tables.php'))->down();
        expect((array) DB::table('commerce_stock_pools')->where('id', $id)->first())->toBe($before);
        Schema::table('commerce_stock_balances', fn ($t) => $t->dropIndex('csb_scope_uq'));
        Schema::table('commerce_stock_balances', fn ($t) => $t->index('pool_id', 'csb_scope_uq'));
        try {
            expect(fn () => StockSchema::install())->toThrow(RuntimeException::class, 'Stock index definition changed');
        } finally {
            Schema::table('commerce_stock_balances', fn ($t) => $t->dropIndex('csb_scope_uq'));
            StockSchema::install();
        }
    } finally {
        DB::table('commerce_stock_pools')->where('id', $id)->delete();
    }
});
