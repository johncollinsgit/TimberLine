<?php

return [
    'sync_stale_after_days' => (int) env('SHOPIFY_EMBEDDED_SYNC_STALE_AFTER_DAYS', 3),
    'perf_profiling_enabled' => (bool) env('SHOPIFY_EMBEDDED_PERF_PROFILING_ENABLED', false),
    'perf_slow_query_ms' => max(0, (float) env('SHOPIFY_EMBEDDED_PERF_SLOW_QUERY_MS', 25)),
    'deep_profile_enabled' => (bool) env('SHOPIFY_EMBEDDED_DEEP_PROFILE_ENABLED', false),
    'journey_cache_ttl_seconds' => max(0, (int) env('SHOPIFY_EMBEDDED_JOURNEY_CACHE_TTL_SECONDS', 60)),
    'development_notes' => [
        'allowed_shop_domains' => array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', (string) env('SHOPIFY_EMBEDDED_DEV_NOTES_ALLOWED_SHOPS', 'the-tableview.myshopify.com'))
        ))),
        'allowed_admin_emails' => array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', (string) env('SHOPIFY_EMBEDDED_DEV_NOTES_ALLOWED_EMAILS', 'tableviewfarms@gmail.com'))
        ))),
        'allowed_shopify_admin_user_ids' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('SHOPIFY_EMBEDDED_DEV_NOTES_ALLOWED_SHOPIFY_ADMIN_IDS', ''))
        ))),
        'strict_email_identity' => (bool) env('SHOPIFY_EMBEDDED_DEV_NOTES_STRICT_EMAIL_IDENTITY', true),
    ],
];
