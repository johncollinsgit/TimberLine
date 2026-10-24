<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PaymentsCore\MySqlFixture;

require_once __DIR__.'/../Support/PaymentsCore/MySqlFixture.php';

uses(Tests\TestCase::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('PAYMENTS_CORE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly opted-in local disposable MySQL.');
    }
    MySqlFixture::guard();
    $this->journalMigration = require database_path('migrations/2026_10_22_190000_create_payment_core_journal_tables.php');
    $this->journalMigration->down();
    $this->journalMigration->up();
    $this->fixtureTenant = MySqlFixture::tenant();
});

afterEach(function (): void {
    if (isset($this->fixtureTenant)) {
        MySqlFixture::purgeTenant($this->fixtureTenant->id);
    }
});

it('recovers retained admissions and immutable encrypted binding before operation and event tables', function (): void {
    $journal = MySqlFixture::journal();
    $row = $journal->reservePayment(MySqlFixture::binding($this->fixtureTenant->id), MySqlFixture::sale(), 'retained_request');
    DB::table('payment_core_events')->where('operation_id', $row['id'])->delete();
    DB::table('payment_core_operations')->where('id', $row['id'])->delete();
    $before = DB::table('payment_core_bindings')->get()->toJson();
    Schema::drop('payment_core_events');
    Schema::drop('payment_core_operations');
    $this->journalMigration->up();
    $this->journalMigration->up();

    expect(DB::table('payment_core_bindings')->get()->toJson())->toBe($before)
        ->and(Schema::hasTable('payment_core_operations'))->toBeTrue()
        ->and(Schema::hasTable('payment_core_events'))->toBeTrue();
});

it('repairs partial index and foreign key DDL without rewriting encrypted attempts or refund evidence', function (): void {
    $tenant = $this->fixtureTenant->id;
    $core = MySqlFixture::core($tenant);
    $payment = $core->start(MySqlFixture::binding($tenant), MySqlFixture::sale(), 'retained_paid');
    $refund = $core->refund(MySqlFixture::binding($tenant), $payment['id'], 'retained_refund', 100, 'other');
    expect($payment['state'])->toBe('succeeded')->and($refund['state'])->toBe('succeeded');
    $tables = ['payment_core_admissions', 'payment_core_bindings', 'payment_core_operations', 'payment_core_events'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()]);
    foreach (array_reverse($tables) as $table) {
        foreach (Schema::getForeignKeys($table) as $key) {
            Schema::table($table, fn ($blueprint) => $blueprint->dropForeign($key['name']));
        }
    }
    foreach ($tables as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            if (! $index['primary']) {
                Schema::table($table, fn ($blueprint) => $blueprint->dropIndex($index['name']));
            }
        }
    }
    $this->journalMigration->up();
    $this->journalMigration->up();
    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
    expect(Schema::hasIndex('payment_core_operations', 'pc_op_effect_uq'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('payment_core_operations'))->pluck('name')->all())
        ->toContain('pc_op_tenant_fk', 'pc_op_binding_fk', 'pc_op_original_fk');
});

it('rejects duplicate retained identities before repairing any constraint and keeps both records', function (): void {
    $journal = MySqlFixture::journal();
    $row = $journal->reservePayment(MySqlFixture::binding($this->fixtureTenant->id), MySqlFixture::sale(), 'duplicate_request');
    Schema::table('payment_core_operations', fn ($table) => $table->dropUnique('pc_op_request_uq'));
    Schema::table('payment_core_operations', fn ($table) => $table->dropUnique('pc_op_sale_uq'));
    $copy = (array) DB::table('payment_core_operations')->where('id', $row['id'])->first();
    $copy['id'] = bin2hex(random_bytes(16));
    DB::table('payment_core_operations')->insert($copy);
    $before = DB::table('payment_core_operations')->orderBy('id')->get()->toJson();
    try {
        expect(fn () => $this->journalMigration->up())->toThrow(RuntimeException::class, 'duplicate immutable identities');
        expect(DB::table('payment_core_operations')->orderBy('id')->get()->toJson())->toBe($before)
            ->and(Schema::hasIndex('payment_core_operations', 'pc_op_request_uq'))->toBeFalse();
    } finally {
        DB::table('payment_core_operations')->where('id', $copy['id'])->delete();
        $this->journalMigration->up();
    }
});

it('rejects retained cross-tenant binding ownership without changing rows', function (): void {
    $other = MySqlFixture::tenant();
    $journal = MySqlFixture::journal();
    $row = $journal->reservePayment(MySqlFixture::binding($this->fixtureTenant->id), MySqlFixture::sale(), 'wrong_owner');
    DB::table('payment_core_operations')->where('id', $row['id'])->update(['tenant_id' => $other->id]);
    $before = DB::table('payment_core_operations')->where('id', $row['id'])->first();
    try {
        expect(fn () => $this->journalMigration->up())->toThrow(RuntimeException::class, 'ownership or money invariants');
        expect((array) DB::table('payment_core_operations')->where('id', $row['id'])->first())->toBe((array) $before);
    } finally {
        DB::table('payment_core_operations')->where('id', $row['id'])->update(['tenant_id' => $this->fixtureTenant->id]);
        $other->delete();
    }
});

it('rejects a same-named index with weakened uniqueness instead of treating its name as proof', function (): void {
    Schema::table('payment_core_operations', fn ($table) => $table->dropUnique('pc_op_request_uq'));
    Schema::table('payment_core_operations', fn ($table) => $table->index(['tenant_id', 'request_id'], 'pc_op_request_uq'));
    try {
        expect(fn () => $this->journalMigration->up())->toThrow(RuntimeException::class, 'incompatible definition');
    } finally {
        Schema::table('payment_core_operations', fn ($table) => $table->dropIndex('pc_op_request_uq'));
        $this->journalMigration->up();
    }
});

it('rejects a same-named foreign key that could cascade-delete financial evidence', function (): void {
    Schema::table('payment_core_events', fn ($table) => $table->dropForeign('pc_event_operation_fk'));
    Schema::table('payment_core_events', fn ($table) => $table->foreign('operation_id', 'pc_event_operation_fk')
        ->references('id')->on('payment_core_operations')->cascadeOnDelete());
    try {
        expect(fn () => $this->journalMigration->up())->toThrow(RuntimeException::class, 'incompatible definition');
    } finally {
        Schema::table('payment_core_events', fn ($table) => $table->dropForeign('pc_event_operation_fk'));
        $this->journalMigration->up();
    }
});
