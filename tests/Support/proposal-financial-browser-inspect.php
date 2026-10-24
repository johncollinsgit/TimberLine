<?php

// Reuse the exact existing disposable loopback/testing kernel; never inspect a real runtime.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$app = require __DIR__.'/proposal-review-browser-bootstrap.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$out = [];
foreach (['service_proposal_assets', 'service_proposal_drafts', 'service_proposal_versions', 'service_proposal_reviews', 'service_proposal_comments', 'tenant_user'] as $table) {
    $out[$table] = hash('sha256', \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson());
}
echo json_encode($out, JSON_THROW_ON_ERROR);
