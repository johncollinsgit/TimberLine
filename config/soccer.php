<?php

return [
    'enabled' => (bool) env('SOCCER_ENABLED', false),
    'tenant_slug' => 'wren-family-soccer',
    'origin' => 'https://easleyfamilysoccer.com',
    'backend_origin' => 'https://app.theeverbranch.com',
    'bridge_secret' => env('WREN_SOCCER_BRIDGE_SECRET'),
    'stripe_webhook_secret' => env('WREN_SOCCER_STRIPE_WEBHOOK_SECRET'),
    'sms_enabled' => (bool) env('WREN_SOCCER_SMS_ENABLED', false),
    'notification_mode' => env('WREN_SOCCER_NOTIFICATION_MODE', 'sms'),
    'groupme_notifications_enabled' => (bool) env('WREN_SOCCER_GROUPME_NOTIFICATIONS_ENABLED', false),
    'admin_notification_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('WREN_SOCCER_ADMIN_NOTIFICATION_EMAILS', ''))))),
    'help_ai_enabled' => (bool) env('WREN_SOCCER_HELP_AI_ENABLED', true),
    'help_ai_model' => env('WREN_SOCCER_HELP_AI_MODEL', 'gpt-6-luna'),
    'sms_recipient' => '+16786441829',
    'venmo' => '@WrenFamilySoccer',
    'venmo_url' => 'https://venmo.com/u/WrenFamilySoccer',
    'teams' => [
        'green-bears' => ['name' => 'Green Bears', 'min_age' => 3, 'max_age' => 4, 'public' => 25, 'reserved' => 5, 'color' => '#28744d'],
        'blue-dragons' => ['name' => 'Blue Dragons', 'min_age' => 5, 'max_age' => 7, 'public' => 44, 'reserved' => 6, 'color' => '#2377b5'],
        'red-falcons' => ['name' => 'Red Falcons', 'min_age' => 8, 'max_age' => null, 'public' => 35, 'reserved' => 5, 'color' => '#c74740'],
    ],
];
