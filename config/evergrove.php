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
    'positioning' => [
        'eyebrow' => 'AI systems and custom software for practical businesses',
        'headline' => 'Turn scattered operations into useful software.',
        'summary' => 'Evergrove helps small and medium businesses plan, build, and maintain AI-assisted systems, Laravel applications, customer portals, automations, and better internal workflows.',
    ],
    'services' => [
        [
            'title' => 'AI systems consulting',
            'summary' => 'Find the workflows where AI can reduce repeated work without adding noise, risk, or expensive theater.',
        ],
        [
            'title' => 'Laravel and custom software',
            'summary' => 'Design and build business-specific web apps, portals, dashboards, integrations, and operational tools.',
        ],
        [
            'title' => 'Automation and integration',
            'summary' => 'Connect Shopify, spreadsheets, email, internal processes, and reporting so teams stop retyping the same data.',
        ],
        [
            'title' => 'Client portals and work visibility',
            'summary' => 'Give customers a clean place to see status, milestones, deliverables, and what happens next.',
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
