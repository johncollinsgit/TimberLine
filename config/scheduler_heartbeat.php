<?php

return [
    // Master switch for the request-time staleness check (the scheduled pulse always runs).
    'enabled' => env('SCHEDULER_HEARTBEAT_ENABLED', true),

    // Minutes without a heartbeat before the scheduler is considered stalled.
    // The pulse runs every minute, so anything beyond a few minutes means cron is down.
    'threshold_minutes' => (int) env('SCHEDULER_HEARTBEAT_THRESHOLD_MINUTES', 10),

    // How often (seconds) the per-request check is allowed to actually run, so heavy
    // traffic doesn't hammer the health-event store.
    'check_throttle_seconds' => (int) env('SCHEDULER_HEARTBEAT_CHECK_THROTTLE_SECONDS', 300),
];
