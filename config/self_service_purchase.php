<?php

return [
    'enabled' => filter_var(env('EVERBRANCH_SELF_SERVICE_PURCHASE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'webhook_verified' => filter_var(env('EVERBRANCH_SELF_SERVICE_WEBHOOK_VERIFIED', false), FILTER_VALIDATE_BOOL),
    'automatic_tax_enabled' => filter_var(env('EVERBRANCH_SELF_SERVICE_AUTOMATIC_TAX_ENABLED', true), FILTER_VALIDATE_BOOL),
    'terms_version' => 'everbranch-simple-2026-11-14',
];
