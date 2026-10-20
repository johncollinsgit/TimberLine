<?php

return [
    'enabled' => filter_var(env('PRODUCT_VISUALIZATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('PRODUCT_VISUALIZATION_TENANT_IDS', ''))))),
    'hand_enabled' => filter_var(env('PRODUCT_VISUALIZATION_HAND_ENABLED', false), FILTER_VALIDATE_BOOL),
];
