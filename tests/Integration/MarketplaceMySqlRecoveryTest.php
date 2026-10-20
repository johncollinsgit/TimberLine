<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires a disposable MySQL test database.');
    }
});

it('recovers interrupted marketplace table and index DDL without changing encrypted evidence', function (): void {
    $migration = require database_path('migrations/2026_10_20_120000_create_marketplace_tables.php');
    $migration->up();
    $identity = DB::table('marketplace_identities')->insertGetId(['email' => 'recovery@example.test', 'active' => true]);
    $event = DB::table('marketplace_webhook_events')->insertGetId(['connection_id' => 987654321, 'event_id' => 'recovery-event', 'topic' => 'orders/paid', 'payload_hash' => str_repeat('a', 64), 'payload' => 'encrypted-sentinel', 'status' => 'pending']);
    $before = (array) DB::table('marketplace_webhook_events')->find($event);
    Schema::drop('marketplace_audits');
    Schema::table('marketplace_identities', fn (Blueprint $t) => $t->dropUnique('mm_identity_email'));
    Schema::table('marketplace_webhook_events', fn (Blueprint $t) => $t->dropUnique('mm_webhook_event'));
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('marketplace_audits'))->toBeTrue()
        ->and(Schema::hasIndex('marketplace_identities', 'mm_identity_email'))->toBeTrue()
        ->and(Schema::hasIndex('marketplace_webhook_events', 'mm_webhook_event'))->toBeTrue()
        ->and((array) DB::table('marketplace_webhook_events')->find($event))->toBe($before)
        ->and(Schema::getColumnType('marketplace_webhook_events', 'payload'))->toBe('longtext');
    DB::table('marketplace_webhook_events')->where('id', $event)->delete();
    DB::table('marketplace_identities')->where('id', $identity)->delete();
});

it('does not delete conflicting marketplace identities to force a unique key repair', function (): void {
    $migration = require database_path('migrations/2026_10_20_120000_create_marketplace_tables.php');
    $migration->up();
    Schema::table('marketplace_identities', fn (Blueprint $t) => $t->dropUnique('mm_identity_email'));
    DB::table('marketplace_identities')->insert([['email' => 'conflict@example.test'], ['email' => 'conflict@example.test']]);
    try {
        expect(fn () => $migration->up())->toThrow(\Illuminate\Database\QueryException::class);
        expect(DB::table('marketplace_identities')->where('email', 'conflict@example.test')->count())->toBe(2);
    } finally {
        DB::table('marketplace_identities')->where('email', 'conflict@example.test')->delete();
        $migration->up();
    }
});

it('repairs native merchant and payment keys after durable partial DDL without deleting evidence', function (): void {
    $migration = require database_path('migrations/2026_10_20_130000_add_native_marketplace_store.php');
    $migration->up();
    $id = DB::table('marketplace_payment_events')->insertGetId(['provider' => 'stripe', 'event_id' => 'evt_recovery', 'merchant_key' => 'acct_recovery', 'payload_hash' => str_repeat('b', 64), 'payload' => 'encrypted-native-sentinel', 'status' => 'pending']);
    $before = (array) DB::table('marketplace_payment_events')->find($id);
    Schema::table('marketplace_payment_events', fn (Blueprint $t) => $t->dropUnique('mm_payment_event'));
    Schema::drop('marketplace_stock_reservations');
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('marketplace_stock_reservations'))->toBeTrue()->and(Schema::hasIndex('marketplace_payment_events', 'mm_payment_event'))->toBeTrue()->and((array) DB::table('marketplace_payment_events')->find($id))->toBe($before)->and(Schema::hasIndex('marketplace_checkouts', 'mm_native_session'))->toBeTrue();
    DB::table('marketplace_payment_events')->where('id', $id)->delete();
});

it('repairs market participation keys while retaining encrypted maker information', function (): void {
    $migration = require database_path('migrations/2026_10_20_140000_add_marketplace_markets_and_reporting.php');
    $migration->up();
    $id = DB::table('marketplace_markets')->insertGetId(['name' => 'Recovery market', 'slug' => 'recovery-market', 'kind' => 'market', 'status' => 'draft', 'address' => 'Example address', 'timezone' => 'America/New_York', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'created_by' => 1]);
    $before = (array) DB::table('marketplace_markets')->find($id);
    Schema::table('marketplace_markets', fn (Blueprint $t) => $t->dropUnique('mm_market_slug'));
    Schema::drop('marketplace_store_links');
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('marketplace_store_links'))->toBeTrue()->and(Schema::hasIndex('marketplace_markets', 'mm_market_slug'))->toBeTrue()->and((array) DB::table('marketplace_markets')->find($id))->toBe($before)->and(Schema::getColumnType('marketplace_makers', 'private_details'))->toBe('longtext');
    DB::table('marketplace_markets')->where('id', $id)->delete();
});
