<?php

return [
    'market_events_panel' => (bool) env('FEATURE_MARKET_EVENTS_PANEL', true),
    'market_events_sync_enabled' => (bool) env('MARKET_EVENTS_SYNC_ENABLED', true),
    'market_events_sync_cooldown_minutes' => (int) env('MARKET_EVENTS_SYNC_COOLDOWN_MINUTES', 10),
    'customer_electrician_tutorial' => (bool) env('FEATURE_CUSTOMER_ELECTRICIAN_TUTORIAL', false),
    'first_login_modal' => (bool) env('FEATURE_FIRST_LOGIN_MODAL', false),
    'internal_onboarding_harness' => (bool) env('FEATURE_INTERNAL_ONBOARDING_HARNESS', false),
    'internal_onboarding_provisioning' => (bool) env('FEATURE_INTERNAL_ONBOARDING_PROVISIONING', false),
    'onboarding_journey_telemetry' => (bool) env('FEATURE_ONBOARDING_JOURNEY_TELEMETRY', true),
];
