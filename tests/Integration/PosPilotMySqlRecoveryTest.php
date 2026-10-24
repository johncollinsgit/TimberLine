<?php

use App\Services\Pos\PosPilotSchema;
use App\Services\Pos\PosSchema;
use App\Services\Pos\PosStripeSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Disposable POS MySQL only.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName()) || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing unsafe recovery database.');
    }
    PosSchema::install();
    PosStripeSchema::install();
    PosPilotSchema::install();
    $this->id = bin2hex(random_bytes(16));
    $this->evidence = app('encrypter')->encryptString('Synthetic retained original request');
    DB::table('pos_live_stripe_operations')->insert(['id' => $this->id, 'runtime_id' => 'recovery_fixture', 'tenant_id' => 900000001, 'reader_binding_id' => str_repeat('a', 32), 'connection_id' => str_repeat('b', 32),
        'reader_id' => 'tmr_fixture', 'request_id' => (string) Illuminate\Support\Str::uuid(), 'payload' => $this->evidence, 'payload_hash' => str_repeat('c', 64), 'created_at' => now()]);
});

afterEach(function (): void {
    if (isset($this->id)) {
        DB::table('pos_live_stripe_operations')->where('id', $this->id)->delete();
    }
});

it('recovers provider and pilot DDL without rewriting retained uncertainty or PIN identities', function (): void {
    $before = (array) DB::table('pos_live_stripe_operations')->where('id', $this->id)->first();
    $operator = bin2hex(random_bytes(16));
    $hash = password_hash('654321', PASSWORD_BCRYPT);
    DB::table('pos_live_operators')->insert(['id' => $operator, 'runtime_id' => 'recovery_fixture', 'tenant_id' => 900000001, 'name' => 'Fictional staff', 'role' => 'cashier', 'pin_hash' => $hash, 'created_at' => now()]);
    try {
        Schema::table('pos_live_stripe_operations', fn ($t) => $t->dropUnique('posstripe_request_uq'));
        Schema::table('pos_live_operators', fn ($t) => $t->dropUnique('posop_user_uq'));
        Schema::drop('pos_live_company_settings');
        Schema::table('pos_live_baskets', fn ($t) => $t->dropColumn('claimed_device_id'));
        Schema::table('pos_live_stock_counts', fn ($t) => $t->dropColumn('context_hash'));
        foreach (['2026_10_24_050000_create_pos_stripe_adapter.php', '2026_10_24_060000_create_pos_pilot_operations.php'] as $file) {
            $m = require database_path('migrations/'.$file);
            $m->up();
            $m->up();
            $m->down();
        }
        expect((array) DB::table('pos_live_stripe_operations')->where('id', $this->id)->first())->toBe($before)
            ->and(DB::table('pos_live_operators')->where('id', $operator)->value('pin_hash'))->toBe($hash)
            ->and(Schema::hasColumn('pos_live_baskets', 'claimed_device_id'))->toBeTrue()
            ->and(Schema::hasColumn('pos_live_stock_counts', 'context_hash'))->toBeTrue();
        config()->set(['enterprise.runtime' => true, 'enterprise.pos_enabled' => true, 'pos_live.runtime_id' => 'enterprise_fixture']);
        $tables = Schema::getTableListing();
        $m = require base_path('apps/enterprise/database/pos-migrations/2026_10_24_070000_install_pos_pilot_schema.php');
        $m->up();
        $m->up();
        $m->down();
        expect(Schema::getTableListing())->toBe($tables)->and(DB::table('pos_live_stripe_operations')->where('id', $this->id)->value('payload'))->toBe($this->evidence);
    } finally {
        DB::table('pos_live_operators')->where('id', $operator)->delete();
    }
});

it('refuses altered named reader and operator indexes without discarding evidence', function (): void {
    foreach ([['pos_live_stripe_operations', 'posstripe_request_uq', PosStripeSchema::class], ['pos_live_operators', 'posop_user_uq', PosPilotSchema::class]] as [$table, $name, $installer]) {
        Schema::table($table, fn ($t) => $t->dropUnique($name));
        Schema::table($table, fn ($t) => $t->index('tenant_id', $name));
        try {
            expect(fn () => $installer::install())->toThrow(RuntimeException::class);
        } finally {
            Schema::table($table, fn ($t) => $t->dropIndex($name));
            $installer::install();
        }
    }
    expect(DB::table('pos_live_stripe_operations')->where('id', $this->id)->value('payload'))->toBe($this->evidence);
});
