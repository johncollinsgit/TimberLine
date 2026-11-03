<?php

use App\Models\WebsiteShopPayAttempt;
use App\Models\WebsiteShopPayConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class);

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('WEBSITE_WALLET_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing Wallet migration recovery outside local disposable MySQL.');
    }
});

it('retains encrypted merchant setup across interrupted unique-index DDL and rollback', function () {
    $migration = require database_path('migrations/2026_11_03_100000_create_website_shop_pay_connections_table.php');
    $migration->up();
    $connection = WebsiteShopPayConnection::create(['tenant_id' => 987654321, 'shop_id' => 987654321,
        'shop_domain' => 'recovery-fictional.myshopify.com', 'client_id' => 'fictional-client', 'environment' => 'test', 'api_version' => '2026-10',
        'storefront_access_token' => 'fictional-private-token', 'admin_access_token' => 'fictional-admin-token']);
    try {
        $before = (array) DB::table('website_shop_pay_connections')->where('id', $connection->id)->first();
        DB::statement('ALTER TABLE website_shop_pay_connections DROP INDEX wsp_connections_shop_domain_uq');
        $migration->up();
        $migration->down();
        $migration->up();
        expect((array) DB::table('website_shop_pay_connections')->where('id', $connection->id)->first())->toBe($before)
            ->and($connection->fresh()->storefront_access_token)->toBe('fictional-private-token');
        expect(fn () => WebsiteShopPayConnection::create(['tenant_id' => 987654322, 'shop_id' => 987654322,
            'shop_domain' => $connection->shop_domain, 'client_id' => 'fixture', 'api_version' => '2026-10',
            'storefront_access_token' => 'fixture', 'admin_access_token' => 'fixture']))->toThrow(QueryException::class);
        DB::statement('ALTER TABLE website_shop_pay_connections DROP INDEX wsp_connections_shop_domain_uq');
        DB::statement('ALTER TABLE website_shop_pay_connections ADD INDEX wsp_connections_shop_domain_uq (shop_domain)');
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Unexpected Wallet index');
    } finally {
        $connection->delete();
        DB::statement('ALTER TABLE website_shop_pay_connections DROP INDEX wsp_connections_shop_domain_uq');
        $migration->up();
    }
});

it('recovers original attempt indexes without changing the encrypted payment request', function () {
    $migration = require database_path('migrations/2026_11_03_100100_create_website_shop_pay_attempts_table.php');
    $migration->up();
    $attempt = WebsiteShopPayAttempt::create(['uuid' => (string) Str::uuid(), 'tenant_id' => 987654321, 'website_cart_id' => 987654321,
        'source_identifier' => 'fictional-mysql-recovery', 'idempotency_key' => (string) Str::uuid(), 'amount_cents' => 1234,
        'cart_fingerprint' => str_repeat('a', 64), 'checkout_state' => ['original_request' => ['paymentMethod' => 'fictional-secret']]]);
    try {
        $before = (array) DB::table('website_shop_pay_attempts')->where('id', $attempt->id)->first();
        DB::statement('ALTER TABLE website_shop_pay_attempts DROP INDEX wsp_attempt_source_uq, DROP INDEX wsp_attempt_tenant_status_idx');
        $migration->up();
        $migration->down();
        $migration->up();
        expect((array) DB::table('website_shop_pay_attempts')->where('id', $attempt->id)->first())->toBe($before)
            ->and($attempt->fresh()->checkout_state['original_request']['paymentMethod'])->toBe('fictional-secret');
        $copy = $attempt->replicate();
        $copy->uuid = (string) Str::uuid();
        $copy->idempotency_key = (string) Str::uuid();
        expect(fn () => $copy->save())->toThrow(QueryException::class);
    } finally {
        $attempt->delete();
    }
});
