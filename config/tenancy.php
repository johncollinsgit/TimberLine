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
    explode(',', (string) env('AUTH_FLAGSHIP_HOSTS', 'app.forestrybackstage.com,forestrybackstage.com'))
)));

$landlordHosts = array_values(array_filter(array_map(
    static fn (string $host): string => strtolower(trim($host)),
    explode(',', (string) env('TENANCY_LANDLORD_HOSTS', 'app.forestrybackstage.com'))
)));
$landlordPrimaryHost = $landlordHosts[0] ?? 'app.forestrybackstage.com';
$landlordOperatorRoles = array_values(array_filter(array_map(
    static fn (string $role): string => strtolower(trim($role)),
    explode(',', (string) env('TENANCY_LANDLORD_OPERATOR_ROLES', 'admin'))
)));
$landlordOperatorEmails = array_values(array_filter(array_map(
    static fn (string $email): string => strtolower(trim($email)),
    explode(',', (string) env('TENANCY_LANDLORD_OPERATOR_EMAILS', ''))
)));
$landlordSnapshotRetentionDays = (int) env('TENANCY_LANDLORD_TENANT_OPS_SNAPSHOT_RETENTION_DAYS', 14);
$landlordSnapshotMaxBytes = (int) env('TENANCY_LANDLORD_TENANT_OPS_MAX_SNAPSHOT_BYTES', 1024 * 1024 * 20);

if ($landlordHosts === []) {
    $landlordHosts = [$landlordPrimaryHost];
}

if ($landlordOperatorRoles === []) {
    $landlordOperatorRoles = ['admin'];
}

$landlordSnapshotRetentionDays = max(1, min(365, $landlordSnapshotRetentionDays));
$landlordSnapshotMaxBytes = max(1024 * 100, min(1024 * 1024 * 200, $landlordSnapshotMaxBytes));

return [
    'landlord' => [
        'primary_host' => $landlordPrimaryHost,
        'hosts' => $landlordHosts,
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
];
