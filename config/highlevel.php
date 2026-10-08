<?php

return [
    // Installation can be configured before collection is enabled. No new billing rail.
    'enabled' => (bool) env('HIGHLEVEL_FLEET_ENABLED', false),
    'collection_enabled' => (bool) env('HIGHLEVEL_FLEET_COLLECTION_ENABLED', false),
    'billing_verified' => (bool) env('HIGHLEVEL_FLEET_BILLING_VERIFIED', false),
    // Temporarily free. Restore only after the Marketplace paid plan is ready.
    'subscription_required' => (bool) env('HIGHLEVEL_FLEET_SUBSCRIPTION_REQUIRED', false),
    'pilot_locations' => array_values(array_filter(array_map('trim', explode(',', (string) env('HIGHLEVEL_FLEET_PILOT_LOCATIONS', ''))))),
    'app_id' => env('HIGHLEVEL_APP_ID'),
    'plan_id' => env('HIGHLEVEL_FLEET_PLAN_ID'),
    'client_id' => env('HIGHLEVEL_CLIENT_ID'),
    'client_secret' => env('HIGHLEVEL_CLIENT_SECRET'),
    'shared_secret' => env('HIGHLEVEL_SHARED_SECRET'),
    'api_base' => 'https://services.leadconnectorhq.com',
    'api_version' => '2021-07-28',
    'authorization_url' => 'https://marketplace.gohighlevel.com/oauth/chooselocation',
    'redirect_uri' => env('HIGHLEVEL_REDIRECT_URI', 'https://app.theeverbranch.com/crm/fleet/oauth/callback'),
    'bouncie_redirect_uri' => env('HIGHLEVEL_BOUNCIE_REDIRECT_URI', 'https://app.theeverbranch.com/crm/fleet/bouncie/callback'),
    'bouncie_webhook_key' => env('HIGHLEVEL_BOUNCIE_WEBHOOK_KEY'),
    'parent_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('HIGHLEVEL_PARENT_ORIGINS', 'https://app.gohighlevel.com,https://app.bridgecitymarketing.agency'))))),
    'queue' => env('HIGHLEVEL_QUEUE', 'default'),
    'session_minutes' => 15,
    'failed_payment_grace_days' => 30,
    'vehicle_limit' => 100,
    'map_tile_url' => env('HIGHLEVEL_MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'map_tile_attribution' => env('HIGHLEVEL_MAP_TILE_ATTRIBUTION', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'),
    'monthly_price_cents' => 9900,
    'currency' => 'USD',
    'scopes' => ['locations.readonly', 'users.readonly', 'oauth.readonly', 'oauth.write', 'marketplace-installer-details.readonly', 'calendars.readonly', 'calendars/events.readonly', 'opportunities.readonly'],
    'webhook_public_key' => 'i2HR1srL4o18O8BRa7gVJY7G7bupbN3H9AwJrHCDiOg=',
];
