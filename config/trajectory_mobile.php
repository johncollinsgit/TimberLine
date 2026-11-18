<?php

return [
    'push_enabled' => (bool) env('TRAJECTORY_WEB_PUSH_ENABLED', false),
    'public_key' => env('TRAJECTORY_VAPID_PUBLIC_KEY'),
    'private_key' => env('TRAJECTORY_VAPID_PRIVATE_KEY'),
    'subject' => env('TRAJECTORY_VAPID_SUBJECT'),
    'allowed_push_hosts' => ['web.push.apple.com', 'fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'wns2-db5p.notify.windows.com', 'notify.windows.com'],
];
