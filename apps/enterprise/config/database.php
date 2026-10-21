<?php

$driver = env('ENTERPRISE_DB_DRIVER', 'mysql');

return [
    'default' => 'enterprise',
    'connections' => ['enterprise' => $driver === 'sqlite' ? [
        'driver' => 'sqlite',
        'database' => env('ENTERPRISE_DB_DATABASE', ''),
        'prefix' => '',
        'foreign_key_constraints' => true,
        'transaction_mode' => 'DEFERRED',
    ] : [
        'driver' => $driver === 'mysql' ? 'mysql' : 'unconfigured',
        'host' => env('ENTERPRISE_DB_HOST', ''),
        'port' => env('ENTERPRISE_DB_PORT', '3306'),
        'database' => env('ENTERPRISE_DB_DATABASE', ''),
        'username' => env('ENTERPRISE_DB_USERNAME', ''),
        'password' => env('ENTERPRISE_DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
        'options' => [],
    ]],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
