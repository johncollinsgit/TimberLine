<?php

return [
    // Tenant IDs bind each workspace to the exact RSS source approved by its operator.
    'feeds' => json_decode((string) env('STUDIO_DISTRIBUTION_FEEDS', '{}'), true) ?: [],
    'google_id' => env('STUDIO_YOUTUBE_CLIENT_ID'),
    'google_secret' => env('STUDIO_YOUTUBE_CLIENT_SECRET'),
    'callback' => env('STUDIO_YOUTUBE_CALLBACK'),
];
