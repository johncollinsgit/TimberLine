<?php

// Every provider/configuration value belongs to this isolated runtime's ENV.
// No fallback to retail POS variables or credentials is permitted.
return [
    'enabled' => (bool) env('ENTERPRISE_POS_ENABLED', false),
    'collection_enabled' => (bool) env('ENTERPRISE_POS_COLLECTION_ENABLED', false),
    'servicing_enabled' => (bool) env('ENTERPRISE_POS_SERVICING_ENABLED', false),
    'runtime_id' => env('ENTERPRISE_POS_RUNTIME_ID'),
    'native_client_enabled' => (bool) env('ENTERPRISE_POS_NATIVE_CLIENT_ENABLED', false),
    'square_application_id' => env('ENTERPRISE_POS_SQUARE_APPLICATION_ID'),
    'square_application_secret' => env('ENTERPRISE_POS_SQUARE_APPLICATION_SECRET'),
    'square_oauth_redirect_uri' => env('ENTERPRISE_POS_SQUARE_OAUTH_REDIRECT_URI'),
    'square_webhook_signature_key' => env('ENTERPRISE_POS_SQUARE_WEBHOOK_SIGNATURE_KEY'),
    'square_webhook_url' => env('ENTERPRISE_POS_SQUARE_WEBHOOK_URL'),
    'square_api_version' => '2026-09-16',
    'square_environment' => env('ENTERPRISE_POS_SQUARE_ENVIRONMENT', 'sandbox'),
    'apple_team_id' => env('ENTERPRISE_POS_APPLE_TEAM_ID'),
    'bundle_id' => env('ENTERPRISE_POS_BUNDLE_ID', 'com.everbranch.pos'),
    'distribution_verified' => (bool) env('ENTERPRISE_POS_DISTRIBUTION_VERIFIED', false),
    'tap_entitlement_verified' => (bool) env('ENTERPRISE_POS_TAP_ENTITLEMENT_VERIFIED', false),
    'square_signature_verified' => (bool) env('ENTERPRISE_POS_SQUARE_SIGNATURE_VERIFIED', false),
    'webhooks_verified' => (bool) env('ENTERPRISE_POS_WEBHOOKS_VERIFIED', false),
    'tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('ENTERPRISE_POS_TENANT_IDS', ''))))),
];
