<?php

return [
    // This foundation is a local/testing simulator. There is no live adapter.
    'enabled' => env('POS_SIMULATION_ENABLED', false),
    'tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('POS_SIMULATION_TENANT_IDS', ''))))),
    'terminal_enabled' => false,
    'live_payments_enabled' => false,
];
