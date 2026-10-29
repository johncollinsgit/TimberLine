<?php

return [
    'search_enabled' => (bool) env('TEAM_WORK_SEARCH_ENABLED', false),
    'search_index_enabled' => (bool) env('TEAM_WORK_SEARCH_INDEX_ENABLED', false),
    'premium_enabled' => (bool) env('TEAM_WORK_PREMIUM_ENABLED', false),
    'asana_enabled' => (bool) env('TEAM_WORK_ASANA_ENABLED', false),
    'asana' => [
        'client_id' => env('TEAM_ASANA_CLIENT_ID'),
        'client_secret' => env('TEAM_ASANA_CLIENT_SECRET'),
    ],
    'enabled' => (bool) env('TEAM_WORK_ENABLED', false),
    'mobile_enabled' => (bool) env('TEAM_WORK_MOBILE_ENABLED', false),
    'tenant_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('TEAM_WORK_TENANT_ALLOWLIST', ''))))),
    'file_max_bytes' => 10 * 1024 * 1024,
    'file_tenant_quota_bytes' => 64 * 1024 * 1024,
    'file_user_active_limit' => 3,
    'scanner' => ['enabled' => (bool) env('TEAM_FILE_SCANNER_ENABLED', false), 'host' => env('TEAM_CLAMD_HOST', '127.0.0.1'), 'port' => (int) env('TEAM_CLAMD_PORT', 3310)],
];
