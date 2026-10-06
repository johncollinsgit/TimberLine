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
        'eyebrow' => 'Custom software, websites, and automation',
        'headline' => 'Software built around the way your business actually works.',
        'summary' => 'Evergrove designs and builds custom websites, internal apps, client portals, and practical automations for businesses that have outgrown scattered tools. Everbranch is one of the products we have built for teams that need their day-to-day work in one place.',
    ],
    'services' => [
        [
            'title' => 'Websites and ecommerce that work harder',
            'summary' => 'Design and build websites, Shopify storefronts, and conversion paths that give customers a clear next step.',
        ],
        [
            'title' => 'Custom internal apps and portals',
            'summary' => 'Build business-specific tools for customers, jobs, orders, approvals, reporting, and the workflows your team uses every day.',
        ],
        [
            'title' => 'Connected systems and automation',
            'summary' => 'Connect the systems you already rely on, remove repeated handoffs, and keep important work visible in one place.',
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
