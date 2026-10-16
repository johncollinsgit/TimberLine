<?php

return [
    // Explicit on-demand queries only. Neither this gate nor a verified
    // installation enables mutations, billing, purchasing or scheduled reads.
    'provider_reads_enabled' => (bool) env('SUBSCRIPTIONS_PROVIDER_READS_ENABLED', false),
    'provider_read_tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('SUBSCRIPTIONS_PROVIDER_READ_TENANT_IDS', ''))), fn (int $id) => $id > 0)),
    'background_reads_enabled' => false,
    'freshness_minutes' => 60,
];
