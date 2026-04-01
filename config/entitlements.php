<?php

$catalog = require __DIR__.'/module_catalog.php';

$modules = [];
foreach ((array) ($catalog['modules'] ?? []) as $moduleKey => $definition) {
    if (! is_array($definition)) {
        continue;
    }

    $status = strtolower(trim((string) ($definition['status'] ?? 'disabled')));
    $ctaRouting = strtolower(trim((string) ($definition['cta_routing'] ?? 'none')));

    $modules[$moduleKey] = [
        'label' => (string) ($definition['display_name'] ?? $moduleKey),
        'classification' => (string) ($definition['classification'] ?? 'shared-core'),
        'default_setup_status' => (string) ($definition['default_setup_status'] ?? 'not_started'),
        'coming_soon' => in_array($status, ['placeholder', 'roadmap'], true),
        'supports_upgrade_prompt' => in_array($ctaRouting, ['upgrade_plan', 'add_module'], true),
        'description' => (string) ($definition['description'] ?? ''),
        'status' => $status,
        'channels' => array_values(array_map('strval', (array) ($definition['channels'] ?? []))),
        'included_in_plans' => array_values(array_map('strval', (array) ($definition['included_in_plans'] ?? []))),
        'default_enabled' => (bool) ($definition['default_enabled'] ?? false),
        'dependencies' => array_values(array_map('strval', (array) ($definition['dependencies'] ?? []))),
        'billing_mode' => (string) ($definition['billing_mode'] ?? 'unavailable'),
        'visibility' => is_array($definition['visibility'] ?? null) ? $definition['visibility'] : [],
        'cta_routing' => $ctaRouting,
        'market_state' => (string) ($definition['market_state'] ?? 'INTERNAL_ONLY'),
        'capabilities' => array_values(array_map('strval', (array) ($definition['capabilities'] ?? []))),
    ];
}

$plans = [];
foreach ((array) ($catalog['plans'] ?? []) as $planKey => $definition) {
    if (! is_array($definition)) {
        continue;
    }

    $plans[$planKey] = [
        'label' => (string) ($definition['label'] ?? $definition['display_name'] ?? $planKey),
        'track' => (string) ($definition['track'] ?? 'shopify'),
        'includes' => array_values(array_map('strval', (array) ($definition['included_modules'] ?? []))),
    ];
}

foreach ((array) (($catalog['legacy'] ?? [])['plan_aliases'] ?? []) as $legacyKey => $canonicalKey) {
    $canonical = is_array($plans[$canonicalKey] ?? null) ? $plans[$canonicalKey] : null;
    if ($canonical === null) {
        continue;
    }

    $plans[$legacyKey] = [
        'label' => (string) ($canonical['label'] ?? $canonicalKey).' (Legacy Alias)',
        'track' => str_starts_with((string) $legacyKey, 'direct_')
            ? 'broader-business-systems'
            : (string) ($canonical['track'] ?? 'shopify'),
        'deprecated_alias_of' => (string) $canonicalKey,
        'includes' => (array) ($canonical['includes'] ?? []),
    ];
}

$addons = [];
foreach ((array) ($catalog['addons'] ?? []) as $addonKey => $definition) {
    if (! is_array($definition)) {
        continue;
    }

    $addons[$addonKey] = [
        'label' => (string) ($definition['label'] ?? $definition['display_name'] ?? $addonKey),
        'includes' => array_values(array_unique(array_map(
            'strval',
            array_merge(
                (array) ($definition['modules'] ?? []),
                (array) ($definition['legacy_grants'] ?? [])
            )
        ))),
    ];
}

foreach ((array) (($catalog['legacy'] ?? [])['addon_aliases'] ?? []) as $legacyKey => $canonicalKey) {
    if (isset($addons[$legacyKey])) {
        continue;
    }

    $canonical = is_array($addons[$canonicalKey] ?? null) ? $addons[$canonicalKey] : null;
    if ($canonical === null) {
        continue;
    }

    $addons[$legacyKey] = [
        'label' => (string) ($canonical['label'] ?? $canonicalKey).' (Legacy Alias)',
        'includes' => (array) ($canonical['includes'] ?? []),
    ];
}

return [
    'default_plan' => (string) (($catalog['defaults'] ?? [])['plan'] ?? 'starter'),
    'default_operating_mode' => (string) (($catalog['defaults'] ?? [])['operating_mode'] ?? 'shopify'),
    'modules' => $modules,
    'plans' => $plans,
    'addons' => $addons,
    'setup_statuses' => array_values(array_map(
        'strval',
        (array) (($catalog['defaults'] ?? [])['setup_statuses'] ?? ['not_started', 'in_progress', 'configured', 'blocked'])
    )),
];
