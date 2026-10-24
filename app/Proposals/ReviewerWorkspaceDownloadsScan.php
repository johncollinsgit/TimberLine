<?php

namespace App\Proposals;

use App\Services\Team\TeamFileScanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Preview-first REAL OWNER service only. No recipient scan route, automatic scheduler or runtime activation. */
final class ReviewerWorkspaceDownloadsScan
{
    public function __construct(private ReviewerWorkspaceDownloadsAccess $access, private ReviewerWorkspaceDownloadsPolicy $policy,
        private ReviewerWorkspaceDownloadsStore $downloads, private ReviewerWorkspaceStore $workspace, private ReviewerWorkspaceAttachmentBlobs $blobs,
        private ReviewerWorkspaceDownloadEvidence $evidence, private TeamFileScanner $scanner) {}

    private function validate(array $input): void
    {
        ReviewerWorkspaceSchema::exact($input, ['request_id', 'expected_workspace_revision', 'expected_target_version', 'expected_upload_revision', 'expected_attestation_revision']);
        abort_unless(ReviewerWorkspaceSchema::uuid($input['request_id']) && ReviewerWorkspaceSchema::version($input['expected_workspace_revision'])
            && ReviewerWorkspaceSchema::version($input['expected_target_version']) && ReviewerWorkspaceSchema::version($input['expected_upload_revision'])
            && is_int($input['expected_attestation_revision']) && $input['expected_attestation_revision'] >= 0, 422);
    }

    private function receipt($row, bool $replayed): array
    {
        return ['status' => $row->status, 'replayed' => $replayed, 'attestation_id' => $row->id, 'revision' => $row->version,
            'request_id' => $row->data['payload']['request_id'], 'intent_sha256' => $row->data['payload']['intent_sha256'], 'preview_available' => false, 'read_current_metadata' => true];
    }

    private function assertDispatch(array $fresh, array $claim): void
    {
        abort_unless(config('service_proposals.reviewer_workspace.downloads.scan_enabled') === true, 403);
        abort_unless($this->policy->scanner($fresh['download_policy']['scan_config_sha256']) === $fresh['scanner'], 409);
        $this->policy->assertTime($fresh['scope']['version'], $fresh, $claim['until']);
    }

    private function verify(string $bytes, array $p): void
    {
        abort_unless(strlen($bytes) === $p['size'] && strlen($bytes) <= ReviewerWorkspaceAttachmentsStore::MAX_BYTES
            && hash_equals($p['sha256'], hash('sha256', $bytes)) && $p['mime'] === 'application/pdf'
            && str_starts_with($bytes, '%PDF-') && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === 'application/pdf', 503);
    }

    public function scan(Request $request, string $versionId, string $uploadId, array $input, bool $dryRun = true): array
    {
        $this->validate($input);
        if (! $dryRun) {
            abort_unless(config('service_proposals.reviewer_workspace.downloads.scan_enabled') === true, 403);
        }
        $current = $this->access->owner($request, $versionId, $uploadId, requireScanner: ! $dryRun);
        $digest = ReviewerWorkspaceSchema::digest(['version_id' => $versionId, 'upload_id' => $uploadId, 'intent' => $input,
            'binding' => $current['download_binding'], 'config_sha256' => $current['download_policy']['scan_config_sha256']]);
        $prior = $this->downloads->row($current, $uploadId);
        if ($dryRun) {
            return ['dry_run' => true, 'will_dispatch' => false, 'version_id' => $versionId, 'workspace_id' => $current['row']->id,
                'target' => $current['target'], 'upload_id' => $uploadId, 'workspace_revision' => $current['row']->version,
                'target_version' => $current['record']['version'], 'upload_revision' => $current['upload']->version,
                'attestation_revision' => $prior?->version ?? 0, 'current_result' => $prior?->status, 'content_rejected' => $this->downloads->contentRejected($current, $current['upload']->data['payload']['sha256']),
                'intent_sha256' => $digest, 'requires_authentic_engine_definition_evidence' => true];
        }
        $reserved = $this->workspace->locked(function () use ($request, $versionId, $uploadId, $input, $digest) {
            $current = $this->access->owner($request, $versionId, $uploadId, true);
            $row = $this->downloads->row($current, $uploadId);
            if ($row !== null && $row->data['payload']['request_id'] === $input['request_id']) {
                abort_unless(hash_equals($row->data['payload']['intent_sha256'], $digest), 409);
                if ($row->status !== 'scanning') {
                    return ['receipt' => $this->receipt($row, true)];
                }
                abort_unless(now()->gte($row->data['payload']['claim']['until']), 409);
            } else {
                abort_unless($current['row']->version === $input['expected_workspace_revision'] && $current['record']['version'] === $input['expected_target_version']
                    && $current['upload']->version === $input['expected_upload_revision'] && ($row?->version ?? 0) === $input['expected_attestation_revision'], 409);
                abort_if($row !== null && ($row->status === 'scanning' || $row->status === 'cleanup_pending' || $row->data['payload']['rejected_permanently'] || $this->downloads->ready($current, $current['upload'], $row)), 409);
                abort_if($row !== null && now()->lt($row->updated_at->addSeconds(300)), 429);
            }
            abort_if($this->downloads->contentRejected($current, $current['upload']->data['payload']['sha256']), 409);
            $old = $row?->data['payload'];
            $window = $old !== null && now()->lt(CarbonImmutable::parse($old['window_started_at'])->addDay()) ? $old['window_started_at'] : now()->utc()->format('Y-m-d\TH:i:s\Z');
            $attempts = $old !== null && $window === $old['window_started_at'] ? $old['attempts'] + 1 : 1;
            abort_if($attempts > 3, 429);
            $u = $current['upload']->data['payload'];
            $p = ['upload_id' => $uploadId, 'upload_revision' => $current['upload']->version, 'original_sha256' => $u['sha256'], 'size' => $u['size'], 'mime' => $u['mime'],
                'workspace_revision' => $current['row']->version, 'target_version' => $current['record']['version'], 'config_sha256' => $current['download_policy']['scan_config_sha256'],
                'request_id' => $input['request_id'], 'intent_sha256' => $digest, 'window_started_at' => $window, 'attempts' => $attempts, 'rejected_permanently' => false,
                'claim' => ['nonce' => bin2hex(random_bytes(32)), 'until' => now()->addSeconds(90)->utc()->format('Y-m-d\TH:i:s\Z')],
                'scanned_at' => null, 'expires_at' => null, 'evidence' => null];
            $this->policy->assertTime($current['scope']['version'], $current);
            $row = $this->downloads->save($current, $row, $p, 'scanning');

            return ['current' => $current, 'row' => $row, 'claim' => $p['claim']];
        });
        if (isset($reserved['receipt'])) {
            return $reserved['receipt'];
        }
        ['current' => $current, 'row' => $row, 'claim' => $claim] = $reserved;
        $u = $current['upload']->data['payload'];
        $bytes = $this->blobs->read($current['upload'], 'original');
        $this->verify($bytes, $u);
        $fresh = $this->access->ownerUnchanged($current, $request);
        $this->assertDispatch($fresh, $claim);
        $before = $this->evidence->parse($this->evidence->version($fresh['scanner']), $fresh['scanner']);
        $result = 'unavailable';
        $after = null;
        if ($before !== null) {
            $fresh = $this->access->ownerUnchanged($current, $request);
            $this->assertDispatch($fresh, $claim);
            $path = $this->blobs->scanTemporary($current['upload'], $bytes, $claim['nonce']);
            try {
                // Final authority follows temporary IO and immediately precedes the existing actual scanner.
                $fresh = $this->access->ownerUnchanged($current, $request);
                $this->assertDispatch($fresh, $claim);
                $status = $this->scanner->scan($path, $u['mime'], $u['sha256']);
                if ($status === 'rejected') {
                    $result = 'rejected';
                } elseif ($status === 'clean') {
                    $fresh = $this->access->ownerUnchanged($current, $request);
                    $this->assertDispatch($fresh, $claim);
                    $after = $this->evidence->parse($this->evidence->version($fresh['scanner']), $fresh['scanner']);
                    if ($after !== null && $before === $after) {
                        $result = 'clean';
                    }
                }
            } finally {
                $this->blobs->removeScanTemporary($current['upload'], $claim['nonce']);
            }
        }
        // Authenticate actual stored original again outside every database lock.
        abort_unless(hash_equals($u['sha256'], hash('sha256', $this->blobs->read($current['upload'], 'original'))), 503);

        return $this->workspace->locked(function () use ($request, $current, $claim, $row, $result, $after) {
            $fresh = $this->access->ownerUnchanged($current, $request, true);
            $stored = $this->downloads->row($fresh, $current['upload']->id);
            abort_unless($stored !== null && $stored->id === $row->id && $stored->data['payload']['claim'] === $claim && now()->lt($claim['until']), 409);
            $p = $stored->data['payload'];
            $p['claim'] = null;
            $p['rejected_permanently'] = $result === 'rejected';
            if ($result === 'clean') {
                $p['scanned_at'] = now()->utc()->format('Y-m-d\TH:i:s\Z');
                $p['expires_at'] = CarbonImmutable::parse($p['scanned_at'])->addSeconds($fresh['download_policy']['max_scan_age_seconds'])
                    ->min(CarbonImmutable::parse($fresh['download_feature']['expires_at']))
                    ->min(CarbonImmutable::parse($after['database_at'])->addSeconds($fresh['scanner']['maximum_definition_age_seconds']))->utc()->format('Y-m-d\TH:i:s\Z');
                abort_unless(now()->lt($p['expires_at']), 410);
                $p['evidence'] = $after;
            }
            $this->assertDispatch($fresh, $claim);
            $saved = $this->downloads->save($fresh, $stored, $p, $result);

            return $this->receipt($saved, false);
        });
    }
}
