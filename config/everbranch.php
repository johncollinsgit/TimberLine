<?php

$defaultBrandAssetVersion = (static function (): string {
    $assets = [
        'brand/everbranch-mark.png',
        'brand/everbranch-mark.svg',
        'brand/everbranch-lockup.svg',
        'brand/everbranch-auth.svg',
        'brand/everbranch-favicon.svg',
        'favicon.png',
        'favicon.ico',
        'apple-touch-icon.png',
        'og-image.png',
    ];

    $latestTimestamp = 0;

    foreach ($assets as $asset) {
        $resolved = public_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $asset));

        if (is_file($resolved)) {
            $latestTimestamp = max($latestTimestamp, (int) filemtime($resolved));
        }
    }

    return $latestTimestamp > 0 ? 'eb'.$latestTimestamp : 'eb1';
})();

return [
    'operator_alert_phone' => env('EVERBRANCH_OPERATOR_ALERT_PHONE', '8646165468'),
    'operator_report_email' => env('EVERBRANCH_OPERATOR_REPORT_EMAIL', 'johncollinsemail@gmail.com'),
    'product_name' => env('EVERBRANCH_PRODUCT_NAME', 'Everbranch'),
    'company_name' => env('EVERBRANCH_COMPANY_NAME', 'Evergrove'),
    'ecosystem_name' => env('EVERBRANCH_ECOSYSTEM_NAME', 'Evergrove'),
    'support_email' => env('EVERBRANCH_SUPPORT_EMAIL', 'support@theeverbranch.com'),
    'bud' => [
        'support_email' => env('EVERBRANCH_BUD_SUPPORT_EMAIL', 'johncollinsemail@gmail.com'),
    ],
    'landlord_portal_name' => env('EVERBRANCH_LANDLORD_PORTAL_NAME', 'Everbranch Admin'),
    'legacy_internal_name' => env('EVERBRANCH_LEGACY_INTERNAL_NAME', 'Everbranch'),
    'flagship_tenant_name' => env('EVERBRANCH_FLAGSHIP_TENANT_NAME', 'Modern Forestry'),
    'brand_assets' => [
        'cache_tag' => env('EVERBRANCH_BRAND_ASSET_VERSION', $defaultBrandAssetVersion),
        'mark' => 'brand/everbranch-mark.svg',
        'lockup' => 'brand/everbranch-lockup.svg',
        'auth' => 'brand/everbranch-auth.svg',
        'favicon_svg' => 'brand/everbranch-favicon.svg',
        'favicon_png' => 'favicon.png',
        'favicon_ico' => 'favicon.ico',
        'apple_touch_icon' => 'apple-touch-icon.png',
        'og_image' => 'og-image.png',
    ],
    'brand_tokens' => [
        'font_display' => env('EVERBRANCH_FONT_DISPLAY', 'Fraunces'),
        'font_ui' => env('EVERBRANCH_FONT_UI', 'Inter'),
        'colors' => [
            'ink' => '#0f1c1f',
            'deep_green' => '#123c43',
            'evergreen' => '#1e5a63',
            'mist' => '#f4f7f6',
            'surface' => '#ffffff',
            'border' => '#dbe4e3',
        ],
        'radius' => [
            'large' => '0.95rem',
            'medium' => '0.72rem',
            'small' => '0.55rem',
        ],
        'motion' => [
            'duration_fast' => '160ms',
            'duration_base' => '220ms',
            'easing' => 'cubic-bezier(0.22, 1, 0.36, 1)',
        ],
    ],
    'display_language' => [
        'tenant_slug' => 'workspace address',
        'account_mode' => 'workspace type',
        'operating_mode' => 'how this workspace runs',
        'provisioning' => 'setup',
        'entitlement' => 'access',
        'blueprint' => 'setup plan',
        'metadata' => 'details',
        'lifecycle' => 'status',
        'commercial_intent' => 'plan interest',
    ],
];
