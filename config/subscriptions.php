<?php

return [
    // Explicit on-demand queries only. Neither this gate nor a verified
    // installation enables mutations, billing, purchasing or scheduled reads.
    'provider_reads_enabled' => (bool) env('SUBSCRIPTIONS_PROVIDER_READS_ENABLED', false),
    'provider_read_tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('SUBSCRIPTIONS_PROVIDER_READ_TENANT_IDS', ''))), fn (int $id) => $id > 0)),
    'background_reads_enabled' => false,
    'recharge_reads_enabled' => (bool) env('SUBSCRIPTIONS_RECHARGE_READS_ENABLED', false),
    'recharge_read_tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('SUBSCRIPTIONS_RECHARGE_READ_TENANT_IDS', ''))), fn (int $id) => $id > 0)),
    'freshness_minutes' => 60,
    // Independent controlled-pilot rails. Connection/preparation never enables collection.
    'pilot_setup_enabled' => (bool) env('SUBSCRIPTIONS_PILOT_SETUP_ENABLED', false),
    'pilot_collection_enabled' => (bool) env('SUBSCRIPTIONS_PILOT_COLLECTION_ENABLED', false),
    'pilot_handoff_enabled' => (bool) env('SUBSCRIPTIONS_PILOT_HANDOFF_ENABLED', false),
    'pilot_tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('SUBSCRIPTIONS_PILOT_TENANT_IDS', ''))), fn (int $id) => $id > 0)),
    'pilot_webhook_secret' => env('SUBSCRIPTIONS_PILOT_STRIPE_WEBHOOK_SECRET'),
    'pilot_live_webhooks_verified' => (bool) env('SUBSCRIPTIONS_PILOT_LIVE_WEBHOOKS_VERIFIED', false),
    // Setup, servicing, activation, collection and handoff each require exact member rollout.
    'replacement_setup_enabled' => (bool) env('SUBSCRIPTIONS_REPLACEMENT_SETUP_ENABLED', false),
    'replacement_servicing_enabled' => (bool) env('SUBSCRIPTIONS_REPLACEMENT_SERVICING_ENABLED', false),
    'replacement_activation_enabled' => (bool) env('SUBSCRIPTIONS_REPLACEMENT_ACTIVATION_ENABLED', false),
    'replacement_collection_enabled' => (bool) env('SUBSCRIPTIONS_REPLACEMENT_COLLECTION_ENABLED', false),
    'replacement_handoff_enabled' => (bool) env('SUBSCRIPTIONS_REPLACEMENT_HANDOFF_ENABLED', false),
    'replacement_braintree_collection_enabled' => (bool) env('SUBSCRIPTIONS_REPLACEMENT_BRAINTREE_COLLECTION_ENABLED', false),
    'replacement_braintree_live_acceptance_verified' => (bool) env('SUBSCRIPTIONS_REPLACEMENT_BRAINTREE_LIVE_ACCEPTANCE_VERIFIED', false),
    'replacement_tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('SUBSCRIPTIONS_REPLACEMENT_TENANT_IDS', ''))), fn (int $id) => $id > 0)),
    'replacement_member_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('SUBSCRIPTIONS_REPLACEMENT_MEMBER_IDS', ''))), fn (int $id) => $id > 0)),
];
