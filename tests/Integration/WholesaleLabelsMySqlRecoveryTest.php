<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('recovers a partial private label library without replacing originals, receipts or assignments', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('WHOLESALE_LABELS_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly isolated disposable MySQL.');
    }
    if (! app()->environment('testing') || ! in_array(DB::connection()->getDatabaseName(), ['everbranch_ci', 'everbranch_labels_ci_20261027'], true) || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing label migration recovery outside disposable local MySQL.');
    }
    $migration = require database_path('migrations/2026_10_27_220000_create_wholesale_label_library.php');
    $migration->up();
    $id = (string) \Illuminate\Support\Str::uuid();
    $evidence = ['id' => $id, 'tenant_id' => 991, 'shop_domain' => 'fictional.example.myshopify.com', 'customer_id' => '101', 'name' => 'Fictional retained artwork'];
    DB::table('wholesale_label_designs')->insert($evidence);
    Schema::drop('wholesale_label_events');
    Schema::drop('wholesale_label_assignments');
    Schema::table('wholesale_label_requests', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->dropUnique('wl_request_once'));
    $migration->up();
    $migration->up();
    expect(DB::table('wholesale_label_designs')->where('id', $id)->value('name'))->toBe('Fictional retained artwork')
        ->and(Schema::hasIndex('wholesale_label_requests', 'wl_request_once', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('wholesale_label_assignments', 'wl_order_line_once', 'unique'))->toBeTrue();
    DB::table('wholesale_label_designs')->where('id', $id)->delete();
});
