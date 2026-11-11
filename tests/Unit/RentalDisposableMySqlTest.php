<?php

use Illuminate\Support\ConfigurationUrlParser;
use Tests\Support\RentalDisposableMySql;

it('admits only the explicitly disposable local rental database', function (): void {
    $configured = ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'everbranch_ci', 'unix_socket' => ''];
    RentalDisposableMySql::assertSafe($configured, $configured, true, true);

    expect(true)->toBeTrue();
});

it('rejects a remote DB_URL even when the declared DB_HOST is local', function (): void {
    $configured = ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'everbranch_ci',
        'url' => 'mysql://fictional:fictional@remote.example.test/everbranch_ci'];
    $resolved = (new ConfigurationUrlParser)->parseConfiguration($configured);

    expect($configured['host'])->toBe('127.0.0.1')->and($resolved['host'])->toBe('remote.example.test');
    RentalDisposableMySql::assertSafe($resolved, $configured, true, true);
})->throws(RuntimeException::class, 'Refusing rental fixtures');

it('rejects unsafe rental fixture endpoints and missing admission', function (array $overrides, array $routing, bool $testing, bool $optedIn): void {
    $resolved = array_replace(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'everbranch_ci', 'unix_socket' => ''], $overrides);
    RentalDisposableMySql::assertSafe($resolved, $resolved + $routing, $testing, $optedIn);
})->with([
    'remote host' => [['host' => 'remote.example.test'], [], true, true],
    'host list' => [['host' => ['127.0.0.1', 'remote.example.test']], [], true, true],
    'socket endpoint' => [['unix_socket' => '/tmp/fictional-mysql.sock'], [], true, true],
    'read routing' => [[], ['read' => ['host' => 'remote.example.test']], true, true],
    'write routing' => [[], ['write' => ['host' => 'remote.example.test']], true, true],
    'other database' => [['database' => 'customer_data'], [], true, true],
    'other driver' => [['driver' => 'sqlite'], [], true, true],
    'production environment' => [[], [], false, true],
    'no disposable opt-in' => [[], [], true, false],
])->throws(RuntimeException::class, 'Refusing rental fixtures');
