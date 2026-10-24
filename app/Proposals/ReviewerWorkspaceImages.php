<?php

namespace App\Proposals;

use Illuminate\Http\Request;

/** Private read-only clean attestation projection. Every byte remains subject to current real recipient authority. */
final class ReviewerWorkspaceImages
{
    public function __construct(private ReviewerWorkspaceImagesAccess $access, private ReviewerWorkspaceImagesPolicy $policy,
        private ReviewerWorkspaceImagesStore $images, private ReviewerWorkspaceAttachmentsStore $files,
        private ReviewerWorkspaceAttachmentBlobs $blobs) {}

    private function current(Request $request, string $token, array $input, array $extra): array
    {
        ReviewerWorkspaceSchema::exact($input, array_merge(['binding', 'selection', 'target'], $extra));
        abort_unless(is_array($input['binding']) && is_array($input['selection']) && is_array($input['target']), 422);

        return $this->access->recipient($request, $token, $input['binding'], $input['selection'], $input['target']);
    }

    private function authenticatedBytes($upload, $scan, string $kind): array
    {
        $p = $scan->data['payload'];
        // Original must remain exact even when a derivative is requested.
        $original = $this->blobs->read($upload, 'original');
        abort_unless(strlen($original) === $p['size'] && hash_equals($p['original_sha256'], hash('sha256', $original))
            && (new \finfo(FILEINFO_MIME_TYPE))->buffer($original) === $p['mime'], 503);
        if ($kind === 'preview') {
            return ['bytes' => $original, 'mime' => $p['mime'], 'sha256' => $p['original_sha256']];
        }
        $thumb = $this->blobs->read($upload, 'thumbnail');
        abort_unless(strlen($thumb) === $p['thumbnail']['size'] && strlen($thumb) <= 262144
            && hash_equals($p['thumbnail']['sha256'], hash('sha256', $thumb))
            && (new \finfo(FILEINFO_MIME_TYPE))->buffer($thumb) === $p['thumbnail']['mime'], 503);

        return ['bytes' => $thumb, 'mime' => $p['thumbnail']['mime'], 'sha256' => $p['thumbnail']['sha256']];
    }

    public function metadata(Request $request, string $token, array $input): array
    {
        $current = $this->current($request, $token, $input, []);
        $out = [];
        foreach ($this->files->query()->where('title', 'Private shared upload:'.$current['row']->id)->orderBy('id')->cursor() as $upload) {
            $this->files->validate($upload);
            if ($upload->data['binding'] !== $current['attachment_binding'] || ! ReviewerWorkspaceSchema::sameTarget($upload->data['target'], $current['target'])
                || in_array($upload->status, ['cancelled', 'expired'], true)) {
                continue;
            }
            $scan = $this->images->row($current, $upload->id);
            $ready = $this->images->ready($current, $upload, $scan);
            if ($ready) {
                $this->authenticatedBytes($upload, $scan, 'thumbnail');
            }
            $out[] = ['id' => $upload->id, 'name' => $upload->data['payload']['name'], 'mime' => $upload->data['payload']['mime'],
                'size' => $upload->data['payload']['size'], 'original_sha256' => $upload->data['payload']['sha256'],
                'state' => $ready ? 'ready' : 'held', 'preview_available' => $ready,
                'thumbnail' => $ready ? $scan->data['payload']['thumbnail'] : null,
                'scan_expires_at' => $ready ? $scan->data['payload']['expires_at'] : null,
                'attestation_id' => $ready ? $scan->id : null, 'attestation_revision' => $ready ? $scan->version : null];
        }
        abort_if(count($out) > 6, 503);
        $fresh = $this->access->unchanged($current, $request, $token, $input['binding']);
        foreach ($out as $file) {
            if ($file['state'] === 'ready') {
                $upload = $this->access->upload($fresh, $file['id']);
                $scan = $this->images->row($fresh, $file['id']);
                abort_unless($scan !== null && $scan->id === $file['attestation_id'] && $scan->version === $file['attestation_revision']
                    && $this->images->ready($fresh, $upload, $scan), 409);
                $this->policy->assertTime($fresh['scope']['version'], $fresh, $file['scan_expires_at']);
            }
        }
        $this->policy->assertTime($fresh['scope']['version'], $fresh);

        return ['workspace_id' => $fresh['row']->id, 'workspace_revision' => $fresh['row']->version, 'workspace_digest' => ReviewerWorkspaceSchema::digest($fresh['graph']), 'target' => $fresh['target'], 'target_version' => $fresh['record']['version'],
            'files' => $out, 'expires_at' => $fresh['image_feature']['expires_at']];
    }

    public function binary(Request $request, string $token, array $input, string $kind): array
    {
        abort_unless(in_array($kind, ['thumbnail', 'preview'], true), 422);
        $current = $this->current($request, $token, $input, ['upload_id', 'attestation_id', 'attestation_revision']);
        abort_unless(ReviewerWorkspaceSchema::uuid($input['upload_id']) && ReviewerWorkspaceSchema::uuid($input['attestation_id'])
            && ReviewerWorkspaceSchema::version($input['attestation_revision']), 422);
        $upload = $this->access->upload($current, $input['upload_id']);
        $scan = $this->images->row($current, $upload->id);
        abort_unless($scan !== null && $scan->id === $input['attestation_id'] && $scan->version === $input['attestation_revision']
            && $this->images->ready($current, $upload, $scan), 404);
        $value = $this->authenticatedBytes($upload, $scan, $kind);
        $fresh = $this->access->unchanged($current, $request, $token, $input['binding']);
        $freshUpload = $this->access->upload($fresh, $upload->id);
        $freshScan = $this->images->row($fresh, $upload->id);
        abort_unless($freshScan !== null && $freshScan->id === $scan->id && $freshScan->version === $scan->version
            && $freshUpload->version === $upload->version && $this->images->ready($fresh, $freshUpload, $freshScan), 409);
        $this->policy->assertTime($fresh['scope']['version'], $fresh, $freshScan->data['payload']['expires_at']);

        return $value + ['workspace_id' => $fresh['row']->id, 'version_hash' => $fresh['scope']['version']->content_hash,
            'upload_id' => $upload->id, 'original_sha256' => $upload->data['payload']['sha256'],
            'attestation_id' => $scan->id, 'attestation_revision' => $scan->version, 'kind' => $kind,
            'expires_at' => $freshScan->data['payload']['expires_at']];
    }
}
