<?php

return [
    'enabled' => (bool) env('SERVICE_PROPOSALS_ENABLED', false),
    'writes_enabled' => (bool) env('SERVICE_PROPOSALS_WRITES_ENABLED', false),
    'publish_enabled' => (bool) env('SERVICE_PROPOSALS_PUBLISH_ENABLED', false),
    // Explicit local tenant IDs in this runtime/database. Never resolve by name,
    // HighLevel location, email, public host or a client tenant's numeric ID.
    'owner_workspaces' => [], // tenant ID => regular | enterprise
    'recipient_hosts' => [],
    'campus_review' => [
        'enabled' => (bool) env('SERVICE_PROPOSALS_CAMPUS_REVIEW_ENABLED', false),
        'build_id' => env('SERVICE_PROPOSALS_CAMPUS_REVIEW_BUILD_ID', ''),
    ],
];
