<?php

return [
    'enabled' => (bool) env('POS_LIVE_ENABLED', false),
    'collection_enabled' => (bool) env('POS_LIVE_COLLECTION_ENABLED', false),
    'servicing_enabled' => (bool) env('POS_LIVE_SERVICING_ENABLED', false),
    'runtime_id' => env('POS_RUNTIME_ID'),
    'onboarding_enabled' => (bool) env('POS_NATIVE_ONBOARDING_ENABLED', false),
    'onboarding_build' => env('POS_NATIVE_ONBOARDING_BUILD'),
    'native_client_enabled' => (bool) env('POS_NATIVE_CLIENT_ENABLED', false),
    'square_application_id' => env('POS_SQUARE_APPLICATION_ID'),
    'square_application_secret' => env('POS_SQUARE_APPLICATION_SECRET'),
    'square_oauth_redirect_uri' => env('POS_SQUARE_OAUTH_REDIRECT_URI'),
    'square_webhook_signature_key' => env('POS_SQUARE_WEBHOOK_SIGNATURE_KEY'),
    'square_webhook_url' => env('POS_SQUARE_WEBHOOK_URL'),
    'square_api_version' => '2026-09-16',
    'square_environment' => env('POS_SQUARE_ENVIRONMENT', 'sandbox'),
    'apple_team_id' => env('POS_APPLE_TEAM_ID'),
    'bundle_id' => env('POS_BUNDLE_ID', 'com.everbranch.pos'),
    'distribution_verified' => (bool) env('POS_DISTRIBUTION_VERIFIED', false),
    'tap_entitlement_verified' => (bool) env('POS_TAP_ENTITLEMENT_VERIFIED', false),
    'square_signature_verified' => (bool) env('POS_SQUARE_SIGNATURE_VERIFIED', false),
    'webhooks_verified' => (bool) env('POS_WEBHOOKS_VERIFIED', false),
    // No pilot merchant, actor, device, credential or tax rate is inferred.
    'tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('POS_LIVE_TENANT_IDS', ''))))),
];
