<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('repairs publication indexes and retains uncertain evidence across replay and rollback', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable POS MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing publication recovery outside local disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_11_01_140000_create_pos_shopify_discount_publications.php');
    $migration->up();
    $id = (string) Str::uuid();
    $row = ['request_id' => $id, 'tenant_id' => 900000001, 'shopify_store_id' => 900000002,
        'rule_id' => 'fictional', 'rule_version' => str_repeat('a', 64), 'code' => 'FIXTURE5',
        'request_hash' => str_repeat('b', 64), 'status' => 'review', 'actor_id' => 900000003,
        'lookup_state' => 'found_unverified', 'lookup_discount_id' => 'gid://shopify/DiscountCodeNode/1',
        'created_at' => now(), 'updated_at' => now()];
    DB::table('pos_shopify_discount_publications')->insert($row);
    try {
        $before = (array) DB::table('pos_shopify_discount_publications')->where('request_id', $id)->first();
        Schema::table('pos_shopify_discount_publications', function (Blueprint $table): void {
            $table->dropUnique('pos_shopify_discount_rule_uq');
            $table->dropUnique('pos_shopify_discount_code_uq');
        });
        $migration->up();
        $migration->down();
        $migration->up();
        expect((array) DB::table('pos_shopify_discount_publications')->where('request_id', $id)->first())->toBe($before)
            ->and(Schema::hasIndex('pos_shopify_discount_publications', 'pos_shopify_discount_rule_uq'))->toBeTrue()
            ->and(Schema::hasIndex('pos_shopify_discount_publications', 'pos_shopify_discount_code_uq'))->toBeTrue();
        expect(fn () => DB::table('pos_shopify_discount_publications')->insert([...$row, 'request_id' => (string) Str::uuid()]))->toThrow(QueryException::class);
        Schema::table('pos_shopify_discount_publications', function (Blueprint $table): void {
            $table->dropUnique('pos_shopify_discount_rule_uq');
            $table->index('tenant_id', 'pos_shopify_discount_rule_uq');
        });
        try {
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'POS Shopify discount index definition changed');
            expect(DB::table('pos_shopify_discount_publications')->where('request_id', $id)->value('status'))->toBe('review');
        } finally {
            Schema::table('pos_shopify_discount_publications', fn (Blueprint $table) => $table->dropIndex('pos_shopify_discount_rule_uq'));
            $migration->up();
        }
    } finally {
        DB::table('pos_shopify_discount_publications')->where('request_id', $id)->delete();
    }
});
