<?php

return [
    'direct_enabled' => (bool) env('ENTERPRISE_MAIL_DIRECT_ENABLED', false),
    'submission_host' => env('ENTERPRISE_MAIL_SUBMISSION_HOST'),
    'submission_port' => (int) env('ENTERPRISE_MAIL_SUBMISSION_PORT', 587),
];
