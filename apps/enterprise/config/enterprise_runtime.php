<?php

return [
    'isolated' => (bool) env('ENTERPRISE_RUNTIME_ISOLATED', false),
    'base_path' => env('ENTERPRISE_BASE_PATH', ''),
    'trial_mount_only' => (bool) env('ENTERPRISE_TRIAL_MOUNT_ONLY', false),
];
