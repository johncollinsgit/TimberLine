<?php

return [
    // Visible by default only for explicitly allowlisted tenants. Execution still
    // requires verified Shopify merge scopes plus an Everbranch owner/admin.
    'enabled' => (bool) env('CUSTOMER_MERGE_ENABLED', true),
    'tenant_slugs' => array_values(array_filter(array_map('trim', explode(',', (string) env('CUSTOMER_MERGE_TENANT_SLUGS', 'modern-forestry'))))),
];
