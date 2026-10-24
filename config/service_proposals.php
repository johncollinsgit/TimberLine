<?php

$reviewerApprovalText = env('SERVICE_PROPOSALS_REVIEWER_AI_APPROVAL_JSON', '');
$reviewerApproval = is_string($reviewerApprovalText) && strlen($reviewerApprovalText) <= 12000 ? json_decode($reviewerApprovalText, true, 16) : null;
$reviewerApproval = is_array($reviewerApproval) && ! array_is_list($reviewerApproval) ? $reviewerApproval : [];

return [
    'enabled' => (bool) env('SERVICE_PROPOSALS_ENABLED', false),
    'writes_enabled' => (bool) env('SERVICE_PROPOSALS_WRITES_ENABLED', false),
    'publish_enabled' => (bool) env('SERVICE_PROPOSALS_PUBLISH_ENABLED', false),
    // Explicit local tenant IDs in this runtime/database. Never resolve by name,
    // HighLevel location, email, public host or a client tenant's numeric ID.
    'owner_workspaces' => [], // tenant ID => regular | enterprise
    'recipient_hosts' => [],
    // Private proposal demo AI is independently configured; no inherited key or automatic activation.
    'reviewer_ai' => array_replace(['tenant_id' => null, 'area_id' => null, 'ledger_id' => null,
        'prior_spend_verified' => false, 'prior_spend_microusd' => null, 'prior_spend_evidence_hash' => null, 'inventory_evidence_hash' => null],
        $reviewerApproval, ['ui_enabled' => (bool) env('SERVICE_PROPOSALS_REVIEWER_AI_UI_ENABLED', false),
            'enabled' => (bool) env('SERVICE_PROPOSALS_REVIEWER_AI_ENABLED', false), 'api_key' => env('ENTERPRISE_PROPOSAL_REVIEW_AI_API_KEY', '')]),
    // Explicit reviewed descriptor/policy/anchor only. No inherited AI/Trial/native grants.
    'reviewer_workspace' => ['enabled' => false, 'writes_enabled' => false, 'initialization_enabled' => false,
        'financial_enabled' => false, 'maintenance_enabled' => false,
        'scheduled_cleanup' => ['enabled' => false, 'pins_sha256' => null],
        'owner_preparation' => ['enabled' => false, 'approvals' => []],
        'owner_initialization' => ['enabled' => false, 'approvals' => []], 'anchor_area_id' => null, 'anchor_marker_sha256' => null, 'policies' => [],
        'collaboration' => ['enabled' => false, 'comments_write_enabled' => false, 'policies' => []],
        'attachments' => ['enabled' => false, 'write_enabled' => false, 'policies' => []],
        'image_access' => ['enabled' => false, 'scan_enabled' => false, 'policies' => [], 'scanners' => []],
        'downloads' => ['enabled' => false, 'scan_enabled' => false, 'policies' => [], 'scanners' => []]],
    // Ordinary runtime never reads capture ENV. Only the separately bound Enterprise overlay can admit metadata.
    // No browser capture until one exact existing browser-only edition is explicitly approved.
    'legacy_capture' => ['enabled' => false, 'approvals' => []],
    'campus_review' => [
        'enabled' => (bool) env('SERVICE_PROPOSALS_CAMPUS_REVIEW_ENABLED', false),
        'build_id' => env('SERVICE_PROPOSALS_CAMPUS_REVIEW_BUILD_ID', ''),
        // Trusted runtime-only exact Version ID => tenant_id/content_hash/renderer. Never request controlled.
        'legacy_versions' => [],
    ],
];
