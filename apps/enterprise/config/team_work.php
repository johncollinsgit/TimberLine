<?php

// Separate Enterprise credentials/rollout must never inherit TEAM_WORK_* flags.
return [
    'enabled' => (bool) env('ENTERPRISE_ENABLED', false),
    'tenant_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENTERPRISE_TENANT_ALLOWLIST', ''))))),
    'premium_enabled' => true,
    'asana_enabled' => false,
    'mobile_enabled' => false,
    'file_max_bytes' => 10 * 1024 * 1024,
    'file_tenant_quota_bytes' => 64 * 1024 * 1024,
    'file_user_active_limit' => 3,
    'scanner' => ['enabled' => (bool) env('ENTERPRISE_FILE_SCANNER_ENABLED', false),
        'host' => env('ENTERPRISE_CLAMD_HOST', '127.0.0.1'), 'port' => (int) env('ENTERPRISE_CLAMD_PORT', 3310)],
];
