<?php

return [
    'enabled' => (bool) env('RENTALS_ENABLED', false),
    'tenant_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('RENTALS_TENANT_ALLOWLIST', ''))))),
    'max_file_bytes' => 5 * 1024 * 1024,
    'property_file_quota_bytes' => 64 * 1024 * 1024,
];
