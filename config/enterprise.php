<?php

return [
    'runtime' => false, // Set only by the dedicated Enterprise bootstrap/provider.
    'enabled' => (bool) env('ENTERPRISE_ENABLED', false),
    'writes_enabled' => (bool) env('ENTERPRISE_WRITES_ENABLED', false),
    'collaboration_enabled' => (bool) env('ENTERPRISE_COLLABORATION_ENABLED', false),
    'planning_enabled' => (bool) env('ENTERPRISE_PLANNING_ENABLED', false),
    'ask_enabled' => (bool) env('ENTERPRISE_ASK_ENABLED', false),
    'email_drafts_enabled' => (bool) env('ENTERPRISE_EMAIL_DRAFTS_ENABLED', false),
    'tenant_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENTERPRISE_TENANT_ALLOWLIST', ''))))),
];
