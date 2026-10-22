<?php

return [
    'enabled' => env('SOFTWARE_SUPPORT_ENABLED', false),
    // Reviewed native Tenant IDs only. Names, domains and email matches grant nothing.
    'owner_tenant_id' => null,
    'owner_user_ids' => [],
    // client ID => ['departments' => ['key' => ['name' => 'Department', 'user_ids' => []]]]
    'clients' => [],
    // Private server configuration; never serialized into a page, ticket or log.
    'owner_sms' => env('SOFTWARE_SUPPORT_OWNER_SMS'),
    'owner_email' => env('SOFTWARE_SUPPORT_OWNER_EMAIL'),
    'simulation_hourly_limit' => 10,
];
