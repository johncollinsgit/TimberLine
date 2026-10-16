<?php

return [
    'assistant_enabled' => (bool) env('EVERBRANCH_AI_ASSISTANT_ENABLED', false),
    'assistant_finance_enabled' => (bool) env('EVERBRANCH_AI_ASSISTANT_FINANCE_ENABLED', false),
    'assistant_reports_enabled' => (bool) env('EVERBRANCH_AI_ASSISTANT_REPORTS_ENABLED', false),
    'assistant_attention_enabled' => (bool) env('EVERBRANCH_AI_ASSISTANT_ATTENTION_ENABLED', false),
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
