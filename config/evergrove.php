<?php

$normalizeHost = static function (mixed $value): ?string {
    $host = strtolower(trim((string) $value));
    if ($host === '') {
        return null;
    }

    $host = preg_replace('#^https?://#', '', $host);
    $host = explode('/', (string) $host)[0] ?? '';
    $host = explode(':', (string) $host)[0] ?? '';
    $host = trim((string) $host, '.');

    return $host !== '' ? $host : null;
};

$parseHostList = static function (string $value) use ($normalizeHost): array {
    $hosts = [];

    foreach (explode(',', $value) as $candidate) {
        $normalized = $normalizeHost($candidate);
        if ($normalized !== null) {
            $hosts[] = $normalized;
        }
    }

    return array_values(array_unique($hosts));
};

$canonicalHost = $normalizeHost(env('EVERGROVE_CANONICAL_HOST', 'evergrovesoftware.com')) ?? 'evergrovesoftware.com';
$publicHosts = $parseHostList((string) env('EVERGROVE_PUBLIC_HOSTS', $canonicalHost.',www.'.$canonicalHost));
if ($publicHosts === []) {
    $publicHosts = [$canonicalHost, 'www.'.$canonicalHost];
}

return [
    'name' => env('EVERGROVE_NAME', 'Evergrove'),
    'canonical_host' => $canonicalHost,
    'hosts' => $publicHosts,
    'contact_email' => env('EVERGROVE_CONTACT_EMAIL', 'hello@evergrovesoftware.com'),
    'booking_url' => env('EVERGROVE_BOOKING_URL', ''),
    'brand_assets' => [
        'cache_tag' => env('EVERGROVE_BRAND_ASSET_VERSION', 'eg3'),
        'mark' => 'brand/evergrove-mark.svg',
        'lockup' => 'brand/evergrove-logo.png',
        'favicon_svg' => 'brand/evergrove-favicon.svg',
        'favicon_png' => 'favicon.png',
        'favicon_ico' => 'favicon.ico',
        'apple_touch_icon' => 'apple-touch-icon.png',
        'og_image' => 'og-image.png',
    ],
    'positioning' => [
        'eyebrow' => 'Practical software for small businesses',
        'headline' => 'We build the software small businesses wish already existed.',
        'summary' => 'Evergrove creates practical apps, portals, automations, and software products for small businesses that have outgrown sticky notes, spreadsheets, and scattered tools.',
    ],
    'services' => [
        [
            'title' => 'Workflow audits and software plans',
            'summary' => 'Map the messy parts of the business, decide what should be fixed first, and turn the next step into a clear build plan.',
        ],
        [
            'title' => 'Custom internal apps',
            'summary' => 'Build business-specific tools for customers, jobs, orders, materials, approvals, reporting, and daily team workflows.',
        ],
        [
            'title' => 'Portals and connected systems',
            'summary' => 'Create customer portals, Shopify-connected systems, dashboards, and integrations that keep important work in one place.',
        ],
        [
            'title' => 'AI-assisted admin tools',
            'summary' => 'Use AI where it is actually useful: repeated admin, summaries, follow-ups, drafts, and decisions that still need human review.',
        ],
    ],
    'tools' => [
        'project_estimate' => [
            'slug' => 'project-estimate',
            'title' => 'Website and software project estimate',
            'summary' => 'Estimate a realistic planning range for a website, custom portal, Laravel app, or automation build.',
            'result_label' => 'Estimated build range',
        ],
        'ai_roi' => [
            'slug' => 'ai-roi',
            'title' => 'AI opportunity ROI calculator',
            'summary' => 'Turn repeated weekly work into a first-pass savings estimate before deciding what should be automated.',
            'result_label' => 'Estimated monthly value',
        ],
        'automation_savings' => [
            'slug' => 'automation-savings',
            'title' => 'Automation savings calculator',
            'summary' => 'Compare the cost of manual handoffs against a focused automation or integration project.',
            'result_label' => 'Estimated annual savings',
        ],
    ],
    'timeline_options' => [
        'asap' => 'ASAP',
        '30_days' => 'Within 30 days',
        '60_90_days' => '60-90 days',
        'researching' => 'Just researching',
    ],
    'budget_ranges' => [
        'not_sure' => 'Not sure yet',
        'under_2500' => 'Under $2,500',
        '2500_7500' => '$2,500-$7,500',
        '7500_15000' => '$7,500-$15,000',
        '15000_plus' => '$15,000+',
    ],
    'business_sizes' => [
        '1_5' => '1-5 people',
        '6_20' => '6-20 people',
        '21_50' => '21-50 people',
        '51_plus' => '51+ people',
    ],
];
