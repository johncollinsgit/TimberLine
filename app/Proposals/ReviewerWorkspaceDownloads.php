<?php

namespace App\Proposals;

use Illuminate\Http\Request;

/** Current-grant exact original PDF bytes only. No inline renderer, native file grant or public URL. */
final class ReviewerWorkspaceDownloads
{
    public function __construct(private ReviewerWorkspaceDownloadsAccess $access, private ReviewerWorkspaceDownloadsPolicy $policy,
        private ReviewerWorkspaceDownloadsStore $downloads, private ReviewerWorkspaceAttachmentsStore $files,
        private ReviewerWorkspaceAttachmentBlobs $blobs) {}

    private function current(Request $request, string $token, array $input, array $extra): array
    {
        ReviewerWorkspaceSchema::exact($input, array_merge(['binding', 'selection', 'target'], $extra));
        abort_unless(is_array($input['binding']) && is_array($input['selection']) && is_array($input['target']), 422);

        return $this->access->recipient($request, $token, $input['binding'], $input['selection'], $input['target']);
    }

    private function bytes($upload, $scan): string
    {
        $p = $scan->data['payload'];
        $bytes = $this->blobs->read($upload, 'original');
        abort_unless(strlen($bytes) === $p['size'] && strlen($bytes) <= ReviewerWorkspaceAttachmentsStore::MAX_BYTES
            && hash_equals($p['original_sha256'], hash('sha256', $bytes)) && $p['mime'] === 'application/pdf'
            && str_starts_with($bytes, '%PDF-') && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === 'application/pdf', 503);

        return $bytes;
    }

    public function metadata(Request $request, string $token, array $input): array
    {
        $current = $this->current($request, $token, $input, []);
        $out = $proofs = [];
        foreach ($this->files->query()->where('title', 'Private shared upload:'.$current['row']->id)->orderBy('id')->cursor() as $upload) {
            $this->files->validate($upload);
            if ($upload->data['binding'] !== $current['attachment_binding'] || ! ReviewerWorkspaceSchema::sameTarget($upload->data['target'], $current['target'])
                || $upload->data['payload']['mime'] !== 'application/pdf' || in_array($upload->status, ['cancelled', 'expired'], true)) {
                continue;
            }
            $scan = $this->downloads->row($current, $upload->id);
            $ready = $this->downloads->ready($current, $upload, $scan);
            if ($ready) {
                $this->bytes($upload, $scan);
            }
            $proofs[$upload->id] = ['revision' => $upload->version, 'status' => $upload->status];
            $rejected = $this->downloads->contentRejected($current, $upload->data['payload']['sha256']);
            $out[] = ['id' => $upload->id, 'name' => $upload->data['payload']['name'], 'mime' => 'application/pdf', 'size' => $upload->data['payload']['size'],
                'original_sha256' => $upload->data['payload']['sha256'], 'state' => $ready ? 'ready' : ($rejected ? 'rejected' : 'held'),
                'download_available' => $ready, 'scan_expires_at' => $ready ? $scan->data['payload']['expires_at'] : null,
                'attestation_id' => $ready ? $scan->id : null, 'attestation_revision' => $ready ? $scan->version : null];
        }
        abort_if(count($out) > 6, 503);
        $fresh = $this->access->unchanged($current, $request, $token, $input['binding']);
        foreach ($out as $file) {
            $upload = $this->files->row($fresh, $file['id']);
            abort_unless($upload->version === $proofs[$file['id']]['revision'] && $upload->status === $proofs[$file['id']]['status'], 409);
            if ($file['state'] !== 'ready') {
                continue;
            }
            $scan = $this->downloads->row($fresh, $file['id']);
            abort_unless($scan !== null && $scan->id === $file['attestation_id'] && $scan->version === $file['attestation_revision']
                && $this->downloads->ready($fresh, $upload, $scan), 409);
            $this->policy->assertTime($fresh['scope']['version'], $fresh, $file['scan_expires_at']);
        }
        $this->policy->assertTime($fresh['scope']['version'], $fresh);

        return ['workspace_id' => $fresh['row']->id, 'workspace_revision' => $fresh['row']->version, 'workspace_digest' => ReviewerWorkspaceSchema::digest($fresh['graph']),
            'target' => $fresh['target'], 'target_version' => $fresh['record']['version'], 'files' => $out, 'expires_at' => $fresh['download_feature']['expires_at']];
    }

    public function download(Request $request, string $token, array $input): array
    {
        $current = $this->current($request, $token, $input, ['upload_id', 'attestation_id', 'attestation_revision']);
        abort_unless(ReviewerWorkspaceSchema::uuid($input['upload_id']) && ReviewerWorkspaceSchema::uuid($input['attestation_id'])
            && ReviewerWorkspaceSchema::version($input['attestation_revision']), 422);
        $upload = $this->access->upload($current, $input['upload_id']);
        $scan = $this->downloads->row($current, $upload->id);
        abort_unless($scan !== null && $scan->id === $input['attestation_id'] && $scan->version === $input['attestation_revision']
            && $this->downloads->ready($current, $upload, $scan), 404);
        $bytes = $this->bytes($upload, $scan);
        $fresh = $this->access->unchanged($current, $request, $token, $input['binding']);
        $freshUpload = $this->access->upload($fresh, $upload->id);
        $freshScan = $this->downloads->row($fresh, $upload->id);
        abort_unless($freshScan !== null && $freshScan->id === $scan->id && $freshScan->version === $scan->version
            && $freshUpload->version === $upload->version && $this->downloads->ready($fresh, $freshUpload, $freshScan), 409);
        $this->policy->assertTime($fresh['scope']['version'], $fresh, $freshScan->data['payload']['expires_at']);

        return ['bytes' => $bytes, 'size' => strlen($bytes), 'mime' => 'application/pdf', 'filename' => $freshUpload->data['payload']['name'],
            'workspace_id' => $fresh['row']->id, 'version_hash' => $fresh['scope']['version']->content_hash, 'upload_id' => $upload->id,
            'original_sha256' => $upload->data['payload']['sha256'], 'attestation_id' => $scan->id,
            'attestation_revision' => $scan->version, 'expires_at' => $freshScan->data['payload']['expires_at']];
    }
}
