<?php

return [
    'enabled' => (bool) env('TEAM_OPERATIONS_ENABLED', false),
    'send_enabled' => (bool) env('TEAM_OPERATIONS_SEND_ENABLED', false),
    'asana_create_enabled' => (bool) env('TEAM_OPERATIONS_ASANA_CREATE_ENABLED', false),
    'sheet_ids_enabled' => (bool) env('TEAM_OPERATIONS_SHEET_IDS_ENABLED', false),
    'ai_enabled' => (bool) env('TEAM_OPERATIONS_AI_ENABLED', false),
    'sync_pages_per_run' => max(1, min(20, (int) env('TEAM_OPERATIONS_SYNC_PAGES', 5))),
    'google' => ['client_id' => env('TEAM_GOOGLE_CLIENT_ID'), 'client_secret' => env('TEAM_GOOGLE_CLIENT_SECRET'), 'pubsub_topic' => env('TEAM_GOOGLE_PUBSUB_TOPIC'), 'push_service_account' => env('TEAM_GOOGLE_PUSH_SERVICE_ACCOUNT'), 'push_audience' => env('TEAM_GOOGLE_PUSH_AUDIENCE')],
    'weather' => ['enabled' => (bool) env('TEAM_WEATHER_ENABLED', false), 'commercial' => (bool) env('TEAM_WEATHER_COMMERCIAL', false), 'api_key' => env('TEAM_WEATHER_API_KEY')],
    'profiles' => [
        'events' => ['name' => 'Events', 'stages' => ['research', 'applied', 'accepted', 'staffing', 'ready', 'completed', 'cancelled'], 'views' => ['overview', 'events', 'calendar', 'applications', 'staffing', 'documents', 'communications', 'templates', 'statistics', 'automations', 'connections'], 'groups' => ['Plan & prepare' => ['overview', 'events', 'calendar', 'applications'], 'Run the event' => ['staffing', 'documents', 'communications'], 'Review & improve' => ['statistics', 'templates', 'automations', 'connections']]],
        'wholesale' => ['name' => 'Wholesale', 'stages' => ['discovery', 'qualified', 'outreach', 'negotiation', 'converted', 'retention', 'reactivation', 'lost'], 'views' => ['overview', 'accounts', 'prospects', 'pipeline', 'orders', 'followups', 'communications', 'templates', 'campaigns', 'statistics', 'automations', 'connections'], 'groups' => ['Relationships' => ['overview', 'accounts', 'prospects', 'pipeline'], 'Orders & follow-up' => ['orders', 'followups', 'communications'], 'Grow & improve' => ['templates', 'campaigns', 'automations', 'statistics', 'connections']]],
    ],
    'presets' => ['modern-forestry' => [
        'events' => ['asana_workspace' => '1200605651567450', 'asana_project' => '1201541082238924', 'mailbox' => 'modernforestryevents@gmail.com', 'spreadsheet_id' => '1V9FAzTg6FA7tzEnGyDQDQ-OYgHqbxiBot9PWlj7txDw', 'tabs' => ['2024', '2025', '2026', 'Payment Criteria']],
        'wholesale' => ['asana_workspace' => '17375094536655', 'mailbox' => 'modernforestryteam@gmail.com', 'asana_account_model' => 'projects'],
    ]],
];
