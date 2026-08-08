<?php

return [
    // Bud Core never calls a generative-model provider. It is the included,
    // deterministic workspace guide and must remain useful without metered AI.
    'core_enabled' => (bool) env('EVERBRANCH_BUD_CORE_ENABLED', true),
    'ai_enabled' => (bool) env('EVERBRANCH_BUD_AI_ENABLED', false),
    'voice_enabled' => (bool) env('EVERBRANCH_BUD_VOICE_ENABLED', false),
    'provider' => env('EVERBRANCH_BUD_AI_PROVIDER', 'openai'),
    // Bud AI stays globally disabled until an operator intentionally supplies
    // a provider credential. Tenant approval and this cap are both required.
    'provider_configured' => trim((string) env('EVERBRANCH_BUD_AI_API_KEY', '')) !== '',
    'monthly_workspace_budget_cents' => max(0, (int) env('EVERBRANCH_BUD_AI_MONTHLY_BUDGET_CENTS', 0)),
    'require_explicit_delivery_confirmation' => true,
];
