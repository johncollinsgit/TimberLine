<?php

$hostMap = [];
$hostMapRaw = env('AUTH_TENANT_HOST_MAP', '');

if (is_string($hostMapRaw) && trim($hostMapRaw) !== '') {
    $decoded = json_decode($hostMapRaw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $host => $slug) {
            if (is_string($host) && is_string($slug)) {
                $hostMap[$host] = $slug;
            }
        }
    }
}

$flagshipHosts = array_values(array_filter(array_map(
    static fn (string $host): string => strtolower(trim($host)),
    explode(',', (string) env('AUTH_FLAGSHIP_HOSTS', 'backstage.theforestrystudio.com,theforestrystudio.com'))
)));

return [
    'auth' => [
        'flagship_tenant_slug' => env('AUTH_FLAGSHIP_TENANT_SLUG', 'modern-forestry'),
        'flagship_hosts' => $flagshipHosts,
        'host_map' => $hostMap,
        'portal_name' => env('AUTH_PORTAL_NAME', 'Backstage'),
        'fallback_tenant_label' => env('AUTH_FALLBACK_TENANT_LABEL', 'Modern Forestry'),
    ],
];

