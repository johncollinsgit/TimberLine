<?php

// Render-only, no native SQL, credentials, session grant or provider request.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'enterprise.reviewer_demo_enabled' => true,
    'tenancy.domains.canonical.landlord_host' => 'proposal.example.invalid',
    'service_proposals.campus_review.enabled' => true, 'service_proposals.recipient_hosts' => ['proposal.example.invalid']]);
$base = 'https://proposal.example.invalid/enterprise-owner/service-proposals/FICTIONAL';
$pages = [];
foreach (\App\Proposals\CampusReviewManifest::PAGES as $page => $_) {
    $pages[$page] = $base.'/campus/'.$page;
}
$context = ['page' => 'ask', 'version_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64), 'build_id' => 'synthetic-independent-client-build',
    'pages' => array_map(fn ($id, $title) => ['id' => $id, 'title' => $title], array_keys(\App\Proposals\CampusReviewManifest::PAGES), array_values(\App\Proposals\CampusReviewManifest::PAGES)),
    'reviewer_names' => ['Synthetic Reviewer'], 'reviewer_name' => 'Synthetic Reviewer', 'comments' => [], 'coverage' => ['partial' => false], 'comment_key' => (string) \Illuminate\Support\Str::uuid(), 'writes_enabled' => false,
    'activity_url' => $base.'/activity', 'client_reference' => 'lees-mcrae-college',
    'csrf_token' => 'synthetic-session-csrf', 'version_label' => 'Synthetic private review',
    'urls' => ['current' => $pages['ask'], 'pages' => $pages, 'proposal' => $base, 'pdf' => $base.'/pdf'],
    'reviewer_ai' => ['enabled' => true, 'endpoints' => array_combine(['preview', 'confirm', 'status', 'cancel'],
        array_map(fn ($action) => $pages['ask'].'/ai/'.$action, ['preview', 'confirm', 'status', 'cancel']))]];
\Illuminate\Support\Facades\DB::enableQueryLog();
\Illuminate\Support\Facades\DB::flushQueryLog();
foreach (['data', 'name', 'comment'] as $kind) {
    foreach ($pages as $page => $url) {
        $context['urls'][$kind.'_pages'][$page] = $url.'/'.$kind;
    }
    $context['urls'][$kind] = $context['urls'][$kind.'_pages']['ask'];
}
$response = app(\App\Enterprise\ReviewerPresentation::class)->respond(\Illuminate\Http\Request::create($pages['ask']), false, $context);
if (\Illuminate\Support\Facades\DB::getQueryLog() !== []) {
    throw new RuntimeException('Render-only fixture must not retrieve native records.');
}
echo json_encode(['html' => $response->getContent(), 'csp' => $response->headers->get('Content-Security-Policy'), 'context' => $context], JSON_THROW_ON_ERROR);
