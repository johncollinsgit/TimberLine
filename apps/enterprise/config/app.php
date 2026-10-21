<?php

return [
    'name' => 'Everbranch Enterprise',
    'env' => env('ENTERPRISE_APP_ENV', 'production'),
    'debug' => false,
    'url' => env('ENTERPRISE_APP_URL', 'https://enterprise.invalid'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'cipher' => 'AES-256-CBC',
    'key' => env('ENTERPRISE_APP_KEY'),
    'previous_keys' => [],
    'maintenance' => ['driver' => 'file'],
];
