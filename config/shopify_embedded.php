<?php

return [
    'sync_stale_after_days' => (int) env('SHOPIFY_EMBEDDED_SYNC_STALE_AFTER_DAYS', 3),
    'perf_profiling_enabled' => (bool) env('SHOPIFY_EMBEDDED_PERF_PROFILING_ENABLED', false),
    'journey_cache_ttl_seconds' => max(0, (int) env('SHOPIFY_EMBEDDED_JOURNEY_CACHE_TTL_SECONDS', 60)),
];
