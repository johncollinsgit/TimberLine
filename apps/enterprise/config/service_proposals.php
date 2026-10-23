<?php

$config = require base_path('config/service_proposals.php');
$tenant = (string) env('SERVICE_PROPOSALS_OWNER_TENANT_ID', '');
$hosts = json_decode((string) env('SERVICE_PROPOSALS_RECIPIENT_HOSTS_JSON', '[]'), true);

// These identify an independently provisioned owner silo, never a client department.
$config['runtime_purpose'] = env('SERVICE_PROPOSALS_RUNTIME_PURPOSE', '');
$config['owner_client_id'] = env('SERVICE_PROPOSALS_OWNER_CLIENT_ID', '');
$config['owner_silo_id'] = env('SERVICE_PROPOSALS_OWNER_SILO_ID', '');
$config['owner_workspaces'] = filter_var($tenant, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
    ? [(int) $tenant => 'enterprise'] : [];
$config['recipient_hosts'] = is_array($hosts) && array_is_list($hosts)
    && count($hosts) <= 10 && count(array_filter($hosts, fn ($host) => is_string($host)
        && preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D', $host))) === count($hosts) ? array_values(array_unique($hosts)) : [];

return $config;
