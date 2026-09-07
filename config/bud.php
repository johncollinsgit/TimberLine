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
    'ai_api_key' => trim((string) env('EVERBRANCH_BUD_AI_API_KEY', env('OPENAI_API_KEY', ''))),
    'provider_configured' => trim((string) env('EVERBRANCH_BUD_AI_API_KEY', env('OPENAI_API_KEY', ''))) !== '',
    'monthly_workspace_budget_cents' => max(0, (int) env('EVERBRANCH_BUD_AI_MONTHLY_BUDGET_CENTS', 0)),
    'require_explicit_delivery_confirmation' => true,

    // A narrow, server-to-server exception for the Level Foundations editor.
    // It is disabled by default, accepts only an integration bearer token, and
    // does not grant a Level Foundations user access to Everbranch data.
    'level_foundations_transcription_enabled' => (bool) env('LEVEL_FOUNDATIONS_TRANSCRIPTION_ENABLED', false),
    'level_foundations_transcription_token' => trim((string) env('LEVEL_FOUNDATIONS_TRANSCRIPTION_TOKEN', '')),
];
