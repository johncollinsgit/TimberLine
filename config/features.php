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

    // Enforced tenant isolation backstop (App\Models\Scopes\TenantScope). OFF by
    // default — arm only once every BelongsToTenant model's table is fully
    // backfilled with tenant_id, so no flagship row can silently vanish.
    'enforced_tenant_scope' => (bool) env('FEATURE_ENFORCED_TENANT_SCOPE', false),

    // Platform messaging rolls out independently from the flagship legacy
    // provider path. Provisioning and paid overages remain separately gated.
    'tenant_messaging_platform' => (bool) env('FEATURE_TENANT_MESSAGING_PLATFORM', false),
    'tenant_messaging_provisioning' => (bool) env('FEATURE_TENANT_MESSAGING_PROVISIONING', false),
    'tenant_messaging_credit_checkout' => (bool) env('FEATURE_TENANT_MESSAGING_CREDIT_CHECKOUT', false),
];
