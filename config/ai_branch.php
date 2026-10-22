<?php

return [
    // October 20 owner-approved offer; caps/opt-in and paid access remain separate.
    'standard_enabled' => (bool) env('EVERBRANCH_AI_STANDARD_ENABLED', true),
    'existing_tenants' => [1 => 'modern-forestry', 2 => 'modern-forestry-team', 3 => 'collins-electric', 4 => 'front-yard-foods',
        5 => 'everbranch-review', 6 => 'green-shield-pest-control', 7 => 'levelfoundations', 8 => 'carolina-barrel-co',
        9 => 'trajectory-household-1', 10 => 'evergrove-software', 11 => 'sawyer-naturals', 12 => 'everbranch-fleet-test-client-a-fleet',
        13 => 'wren-family-soccer', 15 => 'avery-knifeworks'], // #14 retains its separately audited pilot.
    'models' => [
        'gpt-6-luna' => ['label' => 'Luna · economical', 'input_rate' => 100000, 'cached_input_rate' => 10000, 'output_rate' => 500000, 'max_output' => 1024, 'reasoning' => 'none'],
        'gpt-6.1-sol' => ['label' => 'Sol · capable', 'input_rate' => 2000000, 'cached_input_rate' => 100000, 'output_rate' => 10000000, 'max_output' => 4096, 'reasoning' => 'low'],
        'gpt-6-astra' => ['label' => 'Astra · advanced', 'input_rate' => 10000000, 'cached_input_rate' => 1000000, 'output_rate' => 50000000, 'max_output' => 4096, 'reasoning' => 'low'],
    ],
    'rates_version' => 'openai-standard-2026-10-20',
    'rates_valid_until' => '2026-11-20T00:00:00Z',
    // No grant means disabled; this emergency switch also revokes existing pilot grants.
    'fictional_pilot_disabled' => (bool) env('EVERBRANCH_AI_FICTIONAL_PILOT_DISABLED', false),
    'assistant_enabled' => (bool) env('EVERBRANCH_AI_ASSISTANT_ENABLED', false),
    'assistant_finance_enabled' => (bool) env('EVERBRANCH_AI_ASSISTANT_FINANCE_ENABLED', false),
    'assistant_reports_enabled' => (bool) env('EVERBRANCH_AI_ASSISTANT_REPORTS_ENABLED', false),
    'assistant_attention_enabled' => (bool) env('EVERBRANCH_AI_ASSISTANT_ATTENTION_ENABLED', false),
    'assistant_phone_practice_enabled' => (bool) env('EVERBRANCH_AI_ASSISTANT_PHONE_PRACTICE_ENABLED', false),
    'assistant_commitments_enabled' => (bool) env('EVERBRANCH_AI_ASSISTANT_COMMITMENTS_ENABLED', false),
    'workspace_workflows_enabled' => (bool) env('EVERBRANCH_AI_WORKSPACE_WORKFLOWS_ENABLED', false),
    'workspace_history_days' => 365,
    'workspace_agents_enabled' => (bool) env('EVERBRANCH_AI_WORKSPACE_AGENTS_ENABLED', false),
    'workspace_audio_enabled' => (bool) env('EVERBRANCH_AI_WORKSPACE_AUDIO_ENABLED', false),
    'audio_rates_version' => 'openai-audio-standard-2026-10-22',
    'audio_rates_valid_until' => '2026-11-20T00:00:00Z',
    // USD micros, verified against official model/pricing cards on October 22.
    'audio_rates' => ['transcription_model' => 'gpt-transcribe', 'transcription_per_minute_micros' => 4500,
        'speech_model' => 'gpt-audio-mini-2025-12-15', 'speech_text_input_rate' => 600000,
        'speech_text_output_rate' => 2400000, 'speech_audio_output_rate' => 20000000],
    'assistant_tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('EVERBRANCH_AI_ASSISTANT_TENANT_IDS', ''))))),
    'purchase_enabled' => (bool) env('EVERBRANCH_AI_PURCHASE_ENABLED', false),
    'purchase_tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('EVERBRANCH_AI_PURCHASE_TENANT_IDS', ''))))),
    'enabled' => (bool) env('EVERBRANCH_AI_BRANCH_ENABLED', false),
    'tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('EVERBRANCH_AI_BRANCH_TENANT_IDS', ''))))),
    'workspace_context_enabled' => (bool) env('EVERBRANCH_AI_WORKSPACE_CONTEXT_ENABLED', false),
    'workspace_context_tenant_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('EVERBRANCH_AI_WORKSPACE_CONTEXT_TENANT_IDS', ''))))),
    'actions_enabled' => (bool) env('EVERBRANCH_AI_BRANCH_ACTIONS_ENABLED', false),
    // Transport interfaces are reserved; these flags cannot activate a transport.
    'voice_enabled' => false,
    'phone_enabled' => false,
    'api_key' => env('EVERBRANCH_AI_BRANCH_API_KEY', ''),
    'model' => env('EVERBRANCH_AI_BRANCH_MODEL', ''),
    'input_micros_per_million' => (int) env('EVERBRANCH_AI_BRANCH_INPUT_MICROS_PER_MILLION', 0),
    'output_micros_per_million' => (int) env('EVERBRANCH_AI_BRANCH_OUTPUT_MICROS_PER_MILLION', 0),
    'max_input_bytes' => 24000,
    'max_output_tokens' => 1024,
    'history_days' => 30,
    'memory_days' => 90,
];
