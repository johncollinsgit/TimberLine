<?php

return [
    'market_events_panel' => (bool) env('FEATURE_MARKET_EVENTS_PANEL', true),
    'market_events_sync_enabled' => (bool) env('MARKET_EVENTS_SYNC_ENABLED', true),
    'market_events_sync_cooldown_minutes' => (int) env('MARKET_EVENTS_SYNC_COOLDOWN_MINUTES', 10),
];
