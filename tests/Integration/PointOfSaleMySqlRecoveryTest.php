<?php

use App\Models\PosPaymentAttempt;
use App\Models\PosRefund;
use App\Models\PosRegister;
use App\Models\PosSimulatorObject;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('POS_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing POS recovery fixtures outside local disposable MySQL.');
    }
    $this->posMigration = require database_path('migrations/2026_10_21_160000_create_pos_simulation_tables.php');
    $this->posMigration->up();
    $this->tenant = Tenant::create(['name' => 'Fictional POS recovery', 'slug' => 'pos-recovery-test-'.Str::lower(Str::random(12))]);
    $this->site = TenantSite::create(['tenant_id' => $this->tenant->id, 'subdomain' => $this->tenant->slug, 'status' => 'draft', 'settings' => []]);
    $this->location = WebsiteFulfillmentLocation::create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'name' => 'Fictional counter', 'address' => ['country' => 'US'], 'active' => true]);
    $this->register = PosRegister::create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'website_fulfillment_location_id' => $this->location->id, 'name' => 'Recovery register',
        'reader_id' => 'sim_reader_'.Str::uuid(), 'merchant_id' => 'sim_merchant_'.$this->tenant->id,
        'currency' => 'usd', 'active' => true, 'cashier_user_ids' => [], 'tax_rate_bps' => 625,
        'tax_basis' => 'Fictional simulation rate', 'tax_confirmed_at' => now()]);
});

afterEach(function (): void {
    if (isset($this->tenant)) {
        PosSimulatorObject::where('tenant_id', $this->tenant->id)->delete();
        PosRefund::where('tenant_id', $this->tenant->id)->delete();
        PosPaymentAttempt::where('tenant_id', $this->tenant->id)->delete();
        PosRegister::where('tenant_id', $this->tenant->id)->delete();
        $this->tenant->delete();
    }
});

it('recovers a retained register before the remaining POS simulation tables exist', function (): void {
    Schema::drop('pos_simulator_objects');
    Schema::drop('pos_refunds');
    Schema::drop('pos_payment_attempts');

    $this->posMigration->up();
    $this->posMigration->up();

    expect(PosRegister::findOrFail($this->register->id)->tax_rate_bps)->toBe(625)
        ->and(Schema::hasTable('pos_payment_attempts'))->toBeTrue()
        ->and(Schema::hasTable('pos_refunds'))->toBeTrue()
        ->and(Schema::hasTable('pos_simulator_objects'))->toBeTrue();
});

it('repairs all retained POS indexes and foreign keys without replacing durable encrypted records', function (): void {
    $order = WebsiteOrder::create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'number' => 'SIM-RECOVERY-'.Str::random(12), 'lookup_token' => Str::random(56), 'source' => 'pos_simulation']);
    $attempt = PosPaymentAttempt::create(['tenant_id' => $this->tenant->id, 'pos_register_id' => $this->register->id,
        'website_order_id' => $order->id, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
        'snapshot' => ['amount_cents' => 1063, 'merchant_id' => $this->register->merchant_id], 'status' => 'unknown']);
    $refund = PosRefund::create(['tenant_id' => $this->tenant->id, 'pos_payment_attempt_id' => $attempt->id,
        'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'snapshot' => ['amount_cents' => 200],
        'amount_cents' => 200, 'reason' => 'Fictional recovery refund', 'status' => 'unknown']);
    $object = PosSimulatorObject::create(['tenant_id' => $this->tenant->id, 'object_key' => 'sim_recovery_'.Str::uuid(),
        'kind' => 'payment', 'request_hash' => str_repeat('c', 64), 'payload' => ['status' => 'processing']]);
    $tables = ['pos_registers', 'pos_payment_attempts', 'pos_refunds', 'pos_simulator_objects'];
    $retained = collect($tables)->mapWithKeys(fn ($table) => [$table => [
        'indexes' => Schema::getIndexes($table), 'foreign_keys' => Schema::getForeignKeys($table),
        'rows' => DB::table($table)->where('tenant_id', $this->tenant->id)->orderBy('id')->get()->toJson(),
    ]]);
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

    $this->posMigration->up();
    $this->posMigration->up();

    foreach ($tables as $table) {
        expect(Schema::getIndexes($table))->toBe($retained[$table]['indexes'])
            ->and(Schema::getForeignKeys($table))->toBe($retained[$table]['foreign_keys'])
            ->and(DB::table($table)->where('tenant_id', $this->tenant->id)->orderBy('id')->get()->toJson())->toBe($retained[$table]['rows']);
    }
    expect($attempt->fresh()->snapshot['amount_cents'])->toBe(1063)
        ->and($refund->fresh()->reason)->toBe('Fictional recovery refund')
        ->and($object->fresh()->payload['status'])->toBe('processing');
});
