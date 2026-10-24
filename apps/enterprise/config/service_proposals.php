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

// An explicitly reviewed owner-runtime map only. No content hash or tenant is
// inferred. Invalid supplied configuration remains invalid, rather than falling
// back to current rendering for a registered legacy edition.
$legacyText = env('SERVICE_PROPOSALS_CAMPUS_REVIEW_LEGACY_VERSIONS_JSON', '');
$legacy = $legacyText === '' ? [] : (is_string($legacyText) && strlen($legacyText) <= 12000 ? json_decode($legacyText, true, 8) : null);
$validLegacy = is_array($legacy) && ($legacy === [] || ! array_is_list($legacy)) && count($legacy) <= 10;
if ($validLegacy && $legacy !== []) {
    $validLegacy = $config['runtime_purpose'] === 'proposal_owner' && count($config['owner_workspaces']) === 1;
    foreach ($legacy as $versionId => $row) {
        $keys = is_array($row) ? array_keys($row) : [];
        sort($keys, SORT_STRING);
        $validLegacy = $validLegacy && is_string($versionId)
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $versionId)
            && $keys === ['content_hash', 'renderer', 'tenant_id'] && is_int($row['tenant_id'])
            && array_key_exists($row['tenant_id'], $config['owner_workspaces'])
            && is_string($row['content_hash']) && preg_match('/^[a-f0-9]{64}$/D', $row['content_hash'])
            && $row['renderer'] === 'lees-mcrae-v5';
    }
}
$config['campus_review']['legacy_versions'] = $validLegacy ? $legacy : null;

// Exact reviewed nonsecret anchor/policies, independent from every activation
// flag. Missing/invalid input closes all workspace gates, including writes.
$workspaceText = env('SERVICE_PROPOSALS_REVIEWER_WORKSPACE_APPROVAL_JSON', '');
$workspaceApproval = $workspaceText === '' ? null : \App\Proposals\ReviewerWorkspaceRuntimeConfiguration::parse($workspaceText);
if ($workspaceApproval !== null && $config['runtime_purpose'] === 'proposal_owner' && count($config['owner_workspaces']) === 1) {
    $config['reviewer_workspace']['anchor_area_id'] = $workspaceApproval['anchor_area_id'];
    $config['reviewer_workspace']['anchor_marker_sha256'] = $workspaceApproval['anchor_marker_sha256'];
    $config['reviewer_workspace']['policies'] = $workspaceApproval['policies'];
    $config['reviewer_workspace']['collaboration']['policies'] = $workspaceApproval['collaboration_policies'];
    foreach (['enabled', 'writes_enabled', 'initialization_enabled', 'financial_enabled', 'maintenance_enabled'] as $gate) {
        $config['reviewer_workspace'][$gate] = env('SERVICE_PROPOSALS_REVIEWER_WORKSPACE_'.strtoupper($gate), false) === true;
    }
    foreach (['enabled', 'comments_write_enabled'] as $gate) {
        $config['reviewer_workspace']['collaboration'][$gate] = env('SERVICE_PROPOSALS_REVIEWER_COLLABORATION_'.strtoupper($gate), false) === true;
    }
}

// Restrictive reviewed current-edit evidence only; this never captures/imports/publishes data.
$initialization = \App\Proposals\ReviewerWorkspaceInitialization::parseApprovals(
    env('SERVICE_PROPOSALS_REVIEWER_WORKSPACE_INITIALIZATION_APPROVALS_JSON', ''));
if ($workspaceApproval !== null && $initialization !== null && $initialization !== []
    && $config['runtime_purpose'] === 'proposal_owner' && count($config['owner_workspaces']) === 1) {
    $config['reviewer_workspace']['owner_initialization'] = ['approvals' => $initialization,
        'enabled' => env('SERVICE_PROPOSALS_REVIEWER_WORKSPACE_OWNER_INITIALIZATION_ENABLED', false) === true];
}

return $config;
