<?php

return [
    'enabled' => (bool) env('CUSTOMER_MERGE_ENABLED', false),
    'tenant_slugs' => array_values(array_filter(array_map('trim', explode(',', (string) env('CUSTOMER_MERGE_TENANT_SLUGS', 'modern-forestry'))))),
];
