<?php

return [
    'voice_enabled' => env('CAMPUS_FACILITIES_VOICE_ENABLED', true),
    'openai_api_key' => env('CAMPUS_FACILITIES_OPENAI_API_KEY'),
    'enabled' => env('CAMPUS_FACILITIES_DEMO_ENABLED', true),
    'mail_enabled' => env('CAMPUS_FACILITIES_MAIL_ENABLED', true),
    'mail_from' => env('CAMPUS_FACILITIES_MAIL_FROM', 'notifications@theeverbranch.com'),
    'storage_path' => storage_path('app/campus-facilities-demo'),
];
