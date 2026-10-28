<?php

return [
    'order_notes_enabled' => (bool) env('WHOLESALE_ORDER_NOTES_ENABLED', false),
    'enabled' => (bool) env('WHOLESALE_LABELS_ENABLED', false),
    'disk' => 'team_private',
    'max_bytes' => 20 * 1024 * 1024,
    'shop' => env('WHOLESALE_LABEL_SHOP', 's2vscq-rf.myshopify.com'),
    'setup_variant' => (string) env('WHOLESALE_LABEL_SETUP_VARIANT', '42044203761745'),
    'reuse_variant' => (string) env('WHOLESALE_LABEL_REUSE_VARIANT', '42044189966417'),
    'setup_cents' => 2000,
    'reuse_cents' => 500,
    'vtracer' => env('WHOLESALE_LABEL_VTRACER', storage_path('app/label-tools/vtracer-0.6.5')),
    'node' => env('WHOLESALE_LABEL_NODE', 'node'),
    'ghostscript' => env('WHOLESALE_LABEL_GS', '/usr/bin/gs'),
];
