<?php

use App\Services\Pos\PosSchema;
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
        throw new RuntimeException('Refusing POS recovery fixtures outside local disposable MySQL.');
    }
    PosSchema::install();
    $this->id = bin2hex(random_bytes(16));
    $this->sentinel = app('encrypter')->encryptString('Fictional retained POS payment evidence');
    DB::table('pos_live_sales')->insert(['id' => $this->id, 'runtime_id' => 'recovery_fixture', 'tenant_id' => 900000001,
        'register_id' => str_repeat('a', 32), 'location_id' => 'fixture', 'actor_id' => 900000002, 'request_id' => (string) Str::uuid(),
        'payment_request_id' => (string) Str::uuid(), 'sdk_attempt_id' => (string) Str::uuid(), 'snapshot' => $this->sentinel,
        'snapshot_hash' => str_repeat('b', 64), 'amount_minor' => 1070, 'currency' => 'usd', 'state' => 'unknown',
        'commerce_source' => 'native', 'stock_state' => 'held', 'created_at' => now(), 'expires_at' => now()->addMinutes(5)]);
});

afterEach(function (): void {
    if (isset($this->id)) {
        DB::table('pos_live_sales')->where('id', $this->id)->delete();
    }
});

it('recovers missing trailing tables and every named POS index while retaining encrypted uncertainty', function (): void {
    $tables = ['merchants', 'connections', 'registers', 'assignments', 'items', 'sales', 'holds', 'outbox', 'events'];
    $before = (array) DB::table('pos_live_sales')->where('id', $this->id)->first();
    Schema::drop('pos_live_events');
    foreach (array_diff($tables, ['events']) as $suffix) {
        foreach (Schema::getIndexes('pos_live_'.$suffix) as $index) {
            if (! $index['primary']) {
                Schema::table('pos_live_'.$suffix, fn ($t) => $t->dropIndex($index['name']));
            }
        }
    }
    PosSchema::install();
    PosSchema::install();
    expect((array) DB::table('pos_live_sales')->where('id', $this->id)->first())->toBe($before);
    expect(Schema::hasTable('pos_live_events'))->toBeTrue()->and(Schema::hasIndex('pos_live_sales', 'posls_request_uq'))->toBeTrue();
    (require database_path('migrations/2026_10_24_010000_create_pos_live_tables.php'))->down();
    (require database_path('migrations/2026_10_22_190100_create_payment_core_quotes_table.php'))->down();
    (require database_path('migrations/2026_10_22_190000_create_payment_core_journal_tables.php'))->down();
    expect(Schema::hasTable('payment_core_operations'))->toBeTrue()->and(Schema::hasTable('payment_core_quotes'))->toBeTrue();
    expect(DB::table('pos_live_sales')->where('id', $this->id)->value('snapshot'))->toBe($this->sentinel);
});

it('refuses altered named POS indexes and duplicate source claims without deleting evidence', function (): void {
    Schema::table('pos_live_sales', fn ($t) => $t->dropIndex('posls_request_uq'));
    Schema::table('pos_live_sales', fn ($t) => $t->index('actor_id', 'posls_request_uq'));
    try {
        expect(fn () => PosSchema::install())->toThrow(RuntimeException::class, 'POS index definition changed');
        expect(DB::table('pos_live_sales')->where('id', $this->id)->value('snapshot'))->toBe($this->sentinel);
    } finally {
        Schema::table('pos_live_sales', fn ($t) => $t->dropIndex('posls_request_uq'));
        PosSchema::install();
    }
    Schema::table('pos_live_sales', fn ($t) => $t->dropIndex('posls_source_uq'));
    $duplicate = (array) DB::table('pos_live_sales')->where('id', $this->id)->first();
    DB::table('pos_live_sales')->where('id', $this->id)->update(['active_source_claim' => str_repeat('c', 64)]);
    $duplicate['id'] = bin2hex(random_bytes(16));
    $duplicate['request_id'] = (string) Str::uuid();
    $duplicate['payment_request_id'] = (string) Str::uuid();
    $duplicate['active_source_claim'] = str_repeat('c', 64);
    DB::table('pos_live_sales')->insert($duplicate);
    try {
        expect(fn () => PosSchema::install())->toThrow(Illuminate\Database\QueryException::class);
        expect(DB::table('pos_live_sales')->whereIn('id', [$this->id, $duplicate['id']])->count())->toBe(2);
    } finally {
        DB::table('pos_live_sales')->where('id', $duplicate['id'])->delete();
        PosSchema::install();
    }
});

it('installs only the pinned POS schema in Enterprise and retains all financial evidence on wrapper rollback', function (): void {
    config()->set(['enterprise.runtime' => true, 'enterprise.pos_enabled' => true, 'pos_live.runtime_id' => 'enterprise_recovery_fixture']);
    $before = Schema::getTableListing();
    $migration = require base_path('apps/enterprise/database/pos-migrations/2026_10_24_020000_install_pos_schema.php');
    $migration->up();
    $migration->up();
    $migration->down();
    expect(Schema::getTableListing())->toBe($before)
        ->and(DB::table('pos_live_sales')->where('id', $this->id)->value('snapshot'))->toBe($this->sentinel);
});

it('recovers handoff indexes and retains claimed staff correlation in both runtimes', function (): void {
    \App\Services\Pos\PosHandoffSchema::install();
    $id = bin2hex(random_bytes(16));
    DB::table('pos_live_handoffs')->insert(['id' => $id, 'runtime_id' => 'recovery_fixture', 'tenant_id' => 900000001,
        'register_id' => str_repeat('a', 32), 'actor_id' => 900000002, 'device_id' => 'web-fixture', 'request_id' => (string) Str::uuid(),
        'payload' => $this->sentinel, 'payload_hash' => str_repeat('d', 64), 'state' => 'claimed', 'claimed_by' => 900000003,
        'claimed_device' => 'native-fixture', 'created_at' => now(), 'expires_at' => now()->addMinutes(5)]);
    try {
        $before = (array) DB::table('pos_live_handoffs')->where('id', $id)->first();
        Schema::table('pos_live_handoffs', fn ($t) => $t->dropIndex('poslhf_request_uq'));
        Schema::table('pos_live_handoffs', fn ($t) => $t->dropIndex('poslhf_queue_idx'));
        $migration = require database_path('migrations/2026_10_24_030000_create_pos_live_handoffs.php');
        $migration->up();
        $migration->up();
        $migration->down();
        expect((array) DB::table('pos_live_handoffs')->where('id', $id)->first())->toBe($before);
        Schema::table('pos_live_handoffs', fn ($t) => $t->dropIndex('poslhf_request_uq'));
        Schema::table('pos_live_handoffs', fn ($t) => $t->index('actor_id', 'poslhf_request_uq'));
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'POS handoff index definition changed');
        Schema::table('pos_live_handoffs', fn ($t) => $t->dropIndex('poslhf_request_uq'));
        config()->set(['enterprise.runtime' => true, 'enterprise.pos_enabled' => true, 'pos_live.runtime_id' => 'enterprise_recovery_fixture']);
        $enterprise = require base_path('apps/enterprise/database/pos-migrations/2026_10_24_040000_install_pos_handoff_schema.php');
        $enterprise->up();
        $enterprise->up();
        $enterprise->down();
        expect((array) DB::table('pos_live_handoffs')->where('id', $id)->first())->toBe($before);
    } finally {
        DB::table('pos_live_handoffs')->where('id', $id)->delete();
        \App\Services\Pos\PosHandoffSchema::install();
    }
});
