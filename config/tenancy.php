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

$normalizeToken = static function (mixed $value): ?string {
    $token = strtolower(trim((string) $value));

    return $token !== '' ? $token : null;
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

$canonicalBaseDomain = $normalizeHost(env('TENANCY_CANONICAL_BASE_DOMAIN', 'grovebud.com')) ?? 'grovebud.com';
$canonicalPublicHost = $normalizeHost(env('TENANCY_CANONICAL_PUBLIC_HOST', $canonicalBaseDomain)) ?? $canonicalBaseDomain;
$canonicalLandlordHost = $normalizeHost(env('TENANCY_CANONICAL_LANDLORD_HOST', 'app.'.$canonicalBaseDomain)) ?? 'app.'.$canonicalBaseDomain;
$canonicalScheme = strtolower(trim((string) env('TENANCY_CANONICAL_SCHEME', 'https')));
$canonicalScheme = in_array($canonicalScheme, ['http', 'https'], true) ? $canonicalScheme : 'https';

$legacyBaseDomains = $parseHostList((string) env('TENANCY_LEGACY_BASE_DOMAINS', 'forestrybackstage.com'));
$legacyBaseDomains = array_values(array_filter(
    $legacyBaseDomains,
    static fn (string $host): bool => $host !== $canonicalBaseDomain
));

$defaultLegacyPublicHosts = $legacyBaseDomains;
$legacyPublicHosts = $parseHostList((string) env('TENANCY_LEGACY_PUBLIC_HOSTS', implode(',', $defaultLegacyPublicHosts)));
$legacyPublicHosts = array_values(array_filter(
    $legacyPublicHosts,
    static fn (string $host): bool => $host !== $canonicalPublicHost
));

$defaultLegacyLandlordHosts = array_values(array_filter(array_map(
    static fn (string $domain): string => 'app.'.$domain,
    $legacyBaseDomains
)));
$legacyLandlordHosts = $parseHostList((string) env('TENANCY_LEGACY_LANDLORD_HOSTS', implode(',', $defaultLegacyLandlordHosts)));
$legacyLandlordHosts = array_values(array_filter(
    $legacyLandlordHosts,
    static fn (string $host): bool => $host !== $canonicalLandlordHost
));

$defaultLandlordHosts = array_values(array_unique(array_filter(array_merge(
    [$canonicalLandlordHost],
    $legacyLandlordHosts
))));
$landlordHosts = $parseHostList((string) env('TENANCY_LANDLORD_HOSTS', implode(',', $defaultLandlordHosts)));
if ($landlordHosts === []) {
    $landlordHosts = $defaultLandlordHosts !== [] ? $defaultLandlordHosts : [$canonicalLandlordHost];
}
$landlordHosts = array_values(array_unique(array_filter(array_merge(
    $landlordHosts,
    [$canonicalLandlordHost],
    $legacyLandlordHosts
))));

$landlordPrimaryHost = $normalizeHost((string) env('TENANCY_LANDLORD_PRIMARY_HOST', $canonicalLandlordHost)) ?? $canonicalLandlordHost;
if (! in_array($landlordPrimaryHost, $landlordHosts, true)) {
    array_unshift($landlordHosts, $landlordPrimaryHost);
}
$landlordHosts = array_values(array_unique($landlordHosts));
$landlordHosts = array_values(array_filter($landlordHosts, static fn (string $host): bool => $host !== ''));
$landlordAliasHosts = array_values(array_filter(
    $landlordHosts,
    static fn (string $host): bool => $host !== $landlordPrimaryHost
));

$defaultFlagshipHosts = array_values(array_unique(array_filter(array_merge(
    [$canonicalLandlordHost, $canonicalPublicHost],
    $legacyLandlordHosts,
    $legacyPublicHosts,
    $landlordHosts
))));
$flagshipHosts = $parseHostList((string) env('AUTH_FLAGSHIP_HOSTS', implode(',', $defaultFlagshipHosts)));
if ($flagshipHosts === []) {
    $flagshipHosts = $defaultFlagshipHosts;
}

$tenantBaseDomains = $parseHostList((string) env(
    'TENANCY_TENANT_BASE_DOMAINS',
    implode(',', array_values(array_unique(array_merge([$canonicalBaseDomain], $legacyBaseDomains))))
));
if ($tenantBaseDomains === []) {
    $tenantBaseDomains = array_values(array_unique(array_merge([$canonicalBaseDomain], $legacyBaseDomains)));
}

$hostMap = [];
$hostMapRaw = env('AUTH_TENANT_HOST_MAP', '');
if (is_string($hostMapRaw) && trim($hostMapRaw) !== '') {
    $decoded = json_decode($hostMapRaw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $host => $slug) {
            $normalizedHost = $normalizeHost($host);
            $normalizedSlug = $normalizeToken($slug);

            if ($normalizedHost !== null && $normalizedSlug !== null) {
                $hostMap[$normalizedHost] = $normalizedSlug;
            }
        }
    }
}

$landlordOperatorRoles = array_values(array_filter(array_map(
    static fn (string $role): string => strtolower(trim($role)),
    explode(',', (string) env('TENANCY_LANDLORD_OPERATOR_ROLES', 'admin'))
)));
$landlordOperatorEmails = array_values(array_filter(array_map(
    static fn (string $email): string => strtolower(trim($email)),
    explode(',', (string) env('TENANCY_LANDLORD_OPERATOR_EMAILS', ''))
)));

$legacyPublicRedirectEnabled = filter_var(
    env('TENANCY_LEGACY_PUBLIC_REDIRECT_ENABLED', true),
    FILTER_VALIDATE_BOOL,
    FILTER_NULL_ON_FAILURE
);
$legacyPublicRedirectEnabled = $legacyPublicRedirectEnabled ?? true;
$legacyPublicRedirectStatus = (int) env('TENANCY_LEGACY_PUBLIC_REDIRECT_STATUS', 301);
if (! in_array($legacyPublicRedirectStatus, [301, 302, 307, 308], true)) {
    $legacyPublicRedirectStatus = 301;
}

$landlordSnapshotRetentionDays = (int) env('TENANCY_LANDLORD_TENANT_OPS_SNAPSHOT_RETENTION_DAYS', 14);
$landlordSnapshotMaxBytes = (int) env('TENANCY_LANDLORD_TENANT_OPS_MAX_SNAPSHOT_BYTES', 1024 * 1024 * 20);

if ($landlordOperatorRoles === []) {
    $landlordOperatorRoles = ['admin'];
}

$landlordSnapshotRetentionDays = max(1, min(365, $landlordSnapshotRetentionDays));
$landlordSnapshotMaxBytes = max(1024 * 100, min(1024 * 1024 * 200, $landlordSnapshotMaxBytes));

return [
    'domains' => [
        'canonical' => [
            'scheme' => $canonicalScheme,
            'base_domain' => $canonicalBaseDomain,
            'public_host' => $canonicalPublicHost,
            'landlord_host' => $landlordPrimaryHost,
        ],
        'legacy' => [
            'base_domains' => $legacyBaseDomains,
            'public_hosts' => $legacyPublicHosts,
            'landlord_hosts' => $legacyLandlordHosts,
        ],
        'base_domains' => array_values(array_unique(array_merge([$canonicalBaseDomain], $legacyBaseDomains))),
        'tenant_base_domains' => array_values(array_unique($tenantBaseDomains)),
        'public_redirect' => [
            'enabled' => $legacyPublicRedirectEnabled,
            'status' => $legacyPublicRedirectStatus,
        ],
    ],
    'landlord' => [
        'primary_host' => $landlordPrimaryHost,
        'hosts' => $landlordHosts,
        'alias_hosts' => $landlordAliasHosts,
        'operator_roles' => $landlordOperatorRoles,
        'operator_emails' => $landlordOperatorEmails,
        'tenant_ops' => [
            'snapshot_retention_days' => $landlordSnapshotRetentionDays,
            'max_snapshot_bytes' => $landlordSnapshotMaxBytes,
        ],
    ],
    'auth' => [
        'flagship_tenant_slug' => env('AUTH_FLAGSHIP_TENANT_SLUG', 'modern-forestry'),
        'flagship_hosts' => $flagshipHosts,
        'host_map' => $hostMap,
        'portal_name' => env('AUTH_PORTAL_NAME', 'Backstage'),
        'fallback_tenant_label' => env('AUTH_FALLBACK_TENANT_LABEL', 'Modern Forestry'),
    ],
    'onboarding' => [
        'demo_tenant_slug' => env('TENANCY_DEMO_TENANT_SLUG', 'demo'),
    ],
];
