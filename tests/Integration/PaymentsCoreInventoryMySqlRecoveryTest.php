<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\PaymentsCore\InventoryFixture;

require_once __DIR__.'/../Support/PaymentsCore/InventoryFixture.php';
uses(Tests\TestCase::class);
beforeEach(function (): void {
    if (DB::getDriverName() !== 'mysql' || getenv('PAYMENTS_INVENTORY_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires local disposable inventory MySQL.');
    }
    InventoryFixture::guard();
    $this->migration = require database_path('migrations/2026_10_22_200000_add_payment_core_inventory_ownership.php');
    $this->migration->up();
    $this->f = InventoryFixture::make();
    $this->f->provider->base->loseResponse = true;
    $this->reserve = fn () => $this->f->core->startQuoted($this->f->quote(), (string) Str::uuid());
});
afterEach(function (): void {
    if (isset($this->f)) {
        $this->f->cleanup();
    }
});

it('repairs interrupted owner constraints without changing holds or encrypted immutable inventory snapshots', function (): void {
    $this->row = ($this->reserve)();
    $operation = (array) DB::table('payment_core_operations')->where('id', $this->row['id'])->first();
    $hold = (array) DB::table('website_inventory_reservations')->where('payment_core_operation_id', $this->row['id'])->first();
    DB::statement('ALTER TABLE website_inventory_reservations DROP CHECK website_reservation_owner_ck');
    Schema::table('website_inventory_reservations', fn ($table) => $table->dropForeign('website_reservation_payment_fk'));
    Schema::table('website_inventory_reservations', fn ($table) => $table->dropUnique('website_reservation_payment_variant_uq'));
    $this->migration->up();
    $this->migration->up();
    expect((array) DB::table('payment_core_operations')->where('id', $this->row['id'])->first())->toBe($operation)
        ->and((array) DB::table('website_inventory_reservations')->where('id', $hold['id'])->first())->toBe($hold)
        ->and(Schema::hasIndex('website_inventory_reservations', 'website_reservation_payment_variant_uq'))->toBeTrue();
});

it('rejects duplicate retained owner identities without erasing either hold', function (): void {
    $this->row = ($this->reserve)();
    Schema::table('website_inventory_reservations', fn ($table) => $table->dropForeign('website_reservation_payment_fk'));
    Schema::table('website_inventory_reservations', fn ($table) => $table->dropUnique('website_reservation_payment_variant_uq'));
    $hold = (array) DB::table('website_inventory_reservations')->where('payment_core_operation_id', $this->row['id'])->first();
    unset($hold['id']);
    $copy = DB::table('website_inventory_reservations')->insertGetId($hold);
    try {
        expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'owner rows are incompatible')
            ->and(DB::table('website_inventory_reservations')->where('payment_core_operation_id', $this->row['id'])->count())->toBe(2);
    } finally {
        DB::table('website_inventory_reservations')->where('id', $copy)->delete();
        $this->migration->up();
    }
});

it('rejects a same-named cascade foreign key that could erase original payment holds', function (): void {
    $this->row = ($this->reserve)();
    Schema::table('website_inventory_reservations', fn ($table) => $table->dropForeign('website_reservation_payment_fk'));
    Schema::table('website_inventory_reservations', fn ($table) => $table->foreign('payment_core_operation_id', 'website_reservation_payment_fk')
        ->references('id')->on('payment_core_operations')->cascadeOnDelete());
    try {
        expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'foreign key has incompatible semantics');
        expect(DB::table('website_inventory_reservations')->where('payment_core_operation_id', $this->row['id'])->count())->toBe(1);
    } finally {
        Schema::table('website_inventory_reservations', fn ($table) => $table->dropForeign('website_reservation_payment_fk'));
        $this->migration->up();
    }
});

it('rejects weakened XOR ownership checks and preserves unresolved evidence', function (): void {
    $this->row = ($this->reserve)();
    DB::statement('ALTER TABLE website_inventory_reservations DROP CHECK website_reservation_owner_ck');
    DB::statement('ALTER TABLE website_inventory_reservations ADD CONSTRAINT website_reservation_owner_ck CHECK (quantity > 0)');
    try {
        expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'check has incompatible semantics');
        expect($this->f->journal->get($this->row['tenant_id'], $this->row['id'])['state'])->toBe('unknown');
    } finally {
        DB::statement('ALTER TABLE website_inventory_reservations DROP CHECK website_reservation_owner_ck');
        $this->migration->up();
    }
});

it('refuses partial inventory evidence and destructive rollback while an original hold exists', function (): void {
    $this->row = ($this->reserve)();
    $saved = DB::table('payment_core_operations')->where('id', $this->row['id'])->value('inventory_hash');
    DB::table('payment_core_operations')->where('id', $this->row['id'])->update(['inventory_hash' => null]);
    try {
        expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'snapshot is incomplete');
    } finally {
        DB::table('payment_core_operations')->where('id', $this->row['id'])->update(['inventory_hash' => $saved]);
    }
    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, 'retain durable payment evidence');
});

it('rejects a matching but unenforced XOR check without rewriting owned holds', function (): void {
    $this->row = ($this->reserve)();
    DB::statement('ALTER TABLE website_inventory_reservations ALTER CHECK website_reservation_owner_ck NOT ENFORCED');
    try {
        expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'check has incompatible semantics');
        expect(DB::table('website_inventory_reservations')->where('payment_core_operation_id', $this->row['id'])->count())->toBe(1);
    } finally {
        DB::statement('ALTER TABLE website_inventory_reservations ALTER CHECK website_reservation_owner_ck ENFORCED');
    }
});

it('rejects regrouped ownership CHECK on an empty retained table without deleting evidence', function (): void {
    expect(DB::table('website_inventory_reservations')->count())->toBe(0);
    $before = DB::table('payment_core_operations')->orderBy('id')->get()->toJson();
    DB::statement('ALTER TABLE website_inventory_reservations DROP CHECK website_reservation_owner_ck');
    DB::statement('ALTER TABLE website_inventory_reservations ADD CONSTRAINT website_reservation_owner_ck CHECK (website_order_id IS NOT NULL AND payment_core_operation_id IS NULL AND (expires_at IS NOT NULL OR website_order_id IS NULL AND payment_core_operation_id IS NOT NULL AND expires_at IS NULL))');
    try {
        expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'check has incompatible semantics');
        expect(DB::table('payment_core_operations')->orderBy('id')->get()->toJson())->toBe($before)
            ->and(DB::table('website_inventory_reservations')->count())->toBe(0);
    } finally {
        DB::statement('ALTER TABLE website_inventory_reservations DROP CHECK website_reservation_owner_ck');
        $this->migration->up();
    }
});
