<?php

namespace App\Proposals;

use App\Models\TeamOperationRecord;
use Illuminate\Http\Request;

/** Durable quarantined attachment stage. It never marks bytes clean or exposes a download/preview. */
final class ReviewerWorkspaceFiles
{
    public function __construct(private ReviewerWorkspaceAttachmentsAccess $access, private ReviewerWorkspaceAttachmentsStore $store, private ReviewerWorkspaceAttachmentBlobs $blobs) {}

    private function request(array $input, array $extra): void
    {
        ReviewerWorkspaceSchema::exact($input, array_merge(['binding', 'selection', 'target'], $extra));
        abort_unless(is_array($input['binding']) && is_array($input['selection']) && is_array($input['target']), 422);
    }

    private function metadata(array $input): array
    {
        abort_unless(ReviewerWorkspaceSchema::uuid($input['request_id']) && ReviewerWorkspaceSchema::version($input['expected_target_version'])
            && ReviewerWorkspaceSchema::text($input['expected_reviewer_name'], 80) && ReviewerWorkspaceSchema::text($input['name'], 120)
            && ! preg_match('/[\x00-\x1f\x7f\/\\\\]/', $input['name'])
            && is_int($input['size']) && $input['size'] >= 1 && $input['size'] <= ReviewerWorkspaceAttachmentsStore::MAX_BYTES
            && in_array($input['mime'], ['image/jpeg', 'image/png', 'application/pdf'], true) && ReviewerWorkspaceSchema::hash($input['sha256']), 422);
        $extension = strtolower(pathinfo($input['name'], PATHINFO_EXTENSION));
        abort_unless(in_array($extension, match ($input['mime']) {
            'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], default => ['pdf']
        }, true), 422);

        return array_intersect_key($input, array_flip(['name', 'size', 'mime', 'sha256']));
    }

    private function rows(array $current)
    {
        return $this->store->query()->where('title', 'Private shared upload:'.$current['row']->id)->orderBy('id');
    }

    private function view(TeamOperationRecord $row, array $current): array
    {
        $this->store->validate($row);
        $p = $row->data['payload'];

        return ['id' => $row->id, 'name' => $p['name'], 'mime' => $p['mime'], 'size' => $p['size'], 'sha256' => $p['sha256'],
            'status' => $row->status, 'revision' => $row->version, 'target' => $row->data['target'],
            'mine' => $p['uploader_capability'] === $current['scope']['capability_hash'],
            'reservation' => $p['uploader_capability'] === $current['scope']['capability_hash'] ? ['request_id' => $p['request_id'], 'digest' => $p['begin_digest']] : null, 'received' => $p['chunks'],
            'chunk_bytes' => ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES, 'busy' => $p['claim'] !== null && now()->lt($p['claim']['until']),
            'preview_available' => false, 'download_available' => false, 'scan_status' => 'not_admitted'];
    }

    public function show(Request $request, string $token, array $input): array
    {
        $this->request($input, []);
        $current = $this->access->resolve($request, $token, $input['binding'], $input['selection'], $input['target']);
        $files = [];
        foreach ($this->rows($current)->cursor() as $row) {
            $this->store->validate($row);
            if ($row->data['binding'] === $current['attachment_binding'] && ReviewerWorkspaceSchema::sameTarget($row->data['target'], $current['target'])
                && ! in_array($row->status, ['cancelled', 'expired'], true)) {
                $files[] = $this->view($row, $current);
            }
        }
        abort_if(count($files) > 6, 503);
        $this->access->unchanged($current, $request, $token, $input['binding']);

        return ['workspace_id' => $current['row']->id, 'target' => $current['target'], 'target_version' => $current['record']['version'],
            'can_upload' => $current['can_upload'], 'files' => $files, 'expires_at' => $current['attachment_expiry'],
            'preview_available' => false, 'scanner_available' => false];
    }

    public function begin(Request $request, string $token, array $input): array
    {
        $this->request($input, ['request_id', 'expected_target_version', 'expected_reviewer_name', 'name', 'size', 'mime', 'sha256']);
        $metadata = $this->metadata($input);
        $digest = ReviewerWorkspaceSchema::digest($input);
        $this->access->resolve($request, $token, $input['binding'], $input['selection'], $input['target'], true);

        return $this->store->locked(function () use ($request, $token, $input, $metadata, $digest) {
            $current = $this->access->resolve($request, $token, $input['binding'], $input['selection'], $input['target'], true, true);
            $fingerprint = ReviewerWorkspaceSchema::digest(['capability' => $current['scope']['capability_hash'], 'target' => $current['target']] + array_intersect_key($metadata, array_flip(['size', 'mime', 'sha256'])));
            $used = 0;
            $targetCount = 0;
            $unfinished = 0;
            foreach ($this->rows($current)->cursor() as $row) {
                $this->store->validate($row);
                $p = $row->data['payload'];
                if ($p['uploader_capability'] === $current['scope']['capability_hash'] && $p['request_id'] === $input['request_id']) {
                    abort_unless($row->data['binding'] === $current['attachment_binding'] && ReviewerWorkspaceSchema::sameTarget($row->data['target'], $current['target'])
                        && hash_equals($p['begin_digest'], $digest), 409);

                    return ['status' => 'reserved', 'replayed' => true, 'file' => $this->view($row, $current)];
                }
                if (in_array($row->status, ['cancelled', 'expired'], true)) {
                    continue;
                }
                // Same bytes/target/capability cannot bypass an unknown prior reservation with a fresh request UUID.
                abort_if($p['fingerprint'] === $fingerprint, 409);
                $used += $p['size'];
                $targetCount += ReviewerWorkspaceSchema::sameTarget($row->data['target'], $current['target']) ? 1 : 0;
                $unfinished += $p['uploader_capability'] === $current['scope']['capability_hash']
                    && in_array($row->status, ['uploading', 'assembling'], true) ? 1 : 0;
            }
            abort_if($used + $metadata['size'] > 50 * 1024 * 1024 || $targetCount >= 6 || $unfinished >= 3, 429);
            abort_unless($current['reviewer_name'] === $input['expected_reviewer_name'] && $current['record']['version'] === $input['expected_target_version'], 409);
            $payload = ['request_id' => $input['request_id'], 'begin_digest' => $digest, 'fingerprint' => $fingerprint,
                'uploader_capability' => $current['scope']['capability_hash']] + $metadata + ['target_version' => $input['expected_target_version'], 'chunks' => [], 'claim' => null, 'scan' => null];
            $row = $this->store->create($current, $payload);

            return ['status' => 'reserved', 'replayed' => false, 'file' => $this->view($row, $current)];
        });
    }

    /** Lost begin replies recover by the exact request and digest; status never dispatches or creates rows. */
    public function status(Request $request, string $token, array $input): array
    {
        $this->request($input, ['request_id', 'digest']);
        abort_unless(ReviewerWorkspaceSchema::uuid($input['request_id']) && ReviewerWorkspaceSchema::hash($input['digest']), 422);
        $current = $this->access->resolve($request, $token, $input['binding'], $input['selection'], $input['target']);
        $found = null;
        foreach ($this->rows($current)->cursor() as $row) {
            $this->store->validate($row);
            $p = $row->data['payload'];
            if ($p['uploader_capability'] === $current['scope']['capability_hash'] && $p['request_id'] === $input['request_id']) {
                abort_unless($row->data['binding'] === $current['attachment_binding'] && ReviewerWorkspaceSchema::sameTarget($row->data['target'], $current['target'])
                    && hash_equals($p['begin_digest'], $input['digest']), 404);
                $found = $this->view($row, $current);
                break;
            }
        }
        $this->access->unchanged($current, $request, $token, $input['binding']);

        return $found === null ? ['status' => 'not_observed', 'settled' => false] : ['status' => 'reserved', 'settled' => true, 'file' => $found];
    }

    private function claim(Request $request, string $token, array $input, string $kind, string $sha, ?int $index = null, ?callable $validate = null): array
    {
        $this->access->resolve($request, $token, $input['binding'], $input['selection'], $input['target'], $kind !== 'cancel');

        return $this->store->locked(function () use ($request, $token, $input, $kind, $sha, $index, $validate) {
            $current = $this->access->resolve($request, $token, $input['binding'], $input['selection'], $input['target'], $kind !== 'cancel', true);
            $row = $this->store->row($current, $input['upload_id'], true);
            $p = $row->data['payload'];
            if ($kind === 'cancel' && in_array($row->status, ['cancelled', 'expired'], true)) {
                return [$current, $row, null];
            }
            if ($kind === 'cancel') {
                abort_if(app(ReviewerWorkspaceFileScanLeases::class)->active($row), 409);
            }
            if ($kind !== 'cancel') {
                abort_unless($current['record']['version'] === $p['target_version'] && in_array($row->status, ['uploading', 'assembling'], true), 409);
            }
            abort_if($p['claim'] !== null && now()->lt($p['claim']['until']), 409);
            if ($kind !== 'cancel' && $p['claim'] !== null) {
                abort_unless($p['claim']['kind'] === $kind && $p['claim']['index'] === $index && $p['claim']['sha256'] === $sha, 409);
            }
            if ($validate !== null) {
                $validate($p);
            }
            $p['claim'] = ['kind' => $kind, 'index' => $index, 'sha256' => $sha, 'nonce' => bin2hex(random_bytes(32)),
                'until' => now()->addSeconds(90)->utc()->format('Y-m-d\TH:i:s\Z')];
            $this->store->save($row, $p, $kind === 'assemble' ? 'assembling' : ($kind === 'cancel' ? 'cleanup_pending' : null));

            return [$current, $row, $p['claim']];
        });
    }

    private function finish(Request $request, string $token, array $input, array $old, array $claim, callable $operation, bool $write = true): array
    {
        return $this->store->locked(function () use ($request, $token, $input, $old, $claim, $operation, $write) {
            $fresh = $this->access->resolve($request, $token, $input['binding'], $input['selection'], $input['target'], $write, true);
            abort_unless($fresh['attachment_binding'] === $old['attachment_binding'] && $fresh['scope']['capability_hash'] === $old['scope']['capability_hash']
                && $fresh['row']->version === $old['row']->version && $fresh['record']['version'] === $old['record']['version']
                && $fresh['can_upload'] === $old['can_upload'], 409);
            $row = $this->store->row($fresh, $input['upload_id'], true);
            abort_unless($row->data['payload']['claim'] === $claim && now()->lt($claim['until']), 409);
            $operation($row);

            return ['status' => $row->status, 'file' => $this->view($row, $fresh)];
        });
    }

    public function chunk(Request $request, string $token, array $input): array
    {
        $this->request($input, ['upload_id', 'index', 'sha256', 'bytes']);
        abort_unless(ReviewerWorkspaceSchema::uuid($input['upload_id']) && is_int($input['index']) && $input['index'] >= 0 && $input['index'] < 20
            && ReviewerWorkspaceSchema::hash($input['sha256']) && is_string($input['bytes']) && strlen($input['bytes']) <= 349528, 422);
        $bytes = base64_decode($input['bytes'], true);
        abort_unless(is_string($bytes) && strlen($bytes) > 0 && strlen($bytes) <= ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES
            && base64_encode($bytes) === $input['bytes'] && hash_equals($input['sha256'], hash('sha256', $bytes)), 422);
        [$current, $row, $claim] = $this->claim($request, $token, $input, 'chunk', $input['sha256'], $input['index'], function ($p) use ($input, $bytes) {
            abort_unless($input['index'] < (int) ceil($p['size'] / ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES)
                && strlen($bytes) === min(ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES, $p['size'] - $input['index'] * ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES), 422);
            $existing = collect($p['chunks'])->firstWhere('index', $input['index']);
            abort_if($existing !== null && $existing['sha256'] !== $input['sha256'], 409);
        });
        $this->access->unchanged($current, $request, $token, $input['binding'], true);
        $this->blobs->put($row, 'chunk-'.$input['index'], $bytes);

        return $this->finish($request, $token, $input, $current, $claim, function ($row) use ($input, $bytes) {
            $p = $row->data['payload'];
            $p['chunks'] = array_values(array_filter($p['chunks'], fn ($c) => $c['index'] !== $input['index']));
            $p['chunks'][] = ['index' => $input['index'], 'size' => strlen($bytes), 'sha256' => $input['sha256']];
            usort($p['chunks'], fn ($a, $b) => $a['index'] <=> $b['index']);
            $p['claim'] = null;
            $this->store->save($row, $p, 'uploading');
        });
    }

    private function verify(string $bytes, array $p): void
    {
        abort_unless(strlen($bytes) === $p['size'] && hash_equals($p['sha256'], hash('sha256', $bytes)), 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless($mime === $p['mime'], 422);
        if ($mime === 'application/pdf') {
            abort_unless(str_starts_with($bytes, '%PDF-'), 422);
        } else {
            $size = @getimagesizefromstring($bytes);
            abort_unless(is_array($size) && ($size['mime'] ?? null) === $mime && $size[0] > 0 && $size[1] > 0
                && $size[0] <= 16000000 && $size[1] <= 16000000 && $size[0] * $size[1] <= 16000000, 422);
            abort_unless(function_exists('imagecreatefromstring'), 503);
            $decoded = @imagecreatefromstring($bytes);
            abort_unless($decoded !== false, 422);
            try {
                abort_unless(imagesx($decoded) === $size[0] && imagesy($decoded) === $size[1], 422);
            } finally {
                unset($decoded);
            }
        }
    }

    public function complete(Request $request, string $token, array $input): array
    {
        $this->request($input, ['upload_id']);
        abort_unless(ReviewerWorkspaceSchema::uuid($input['upload_id']), 422);
        // An already quarantined result is an idempotent persisted result, never a scan retry.
        $current = $this->access->resolve($request, $token, $input['binding'], $input['selection'], $input['target'], true);
        $row = $this->store->row($current, $input['upload_id'], true);
        if ($row->status === 'quarantined') {
            $this->access->unchanged($current, $request, $token, $input['binding'], true);

            return ['status' => 'quarantined', 'file' => $this->view($row, $current)];
        }
        [$current, $row, $claim] = $this->claim($request, $token, $input, 'assemble', $row->data['payload']['sha256'], null, function ($p) {
            abort_unless(count($p['chunks']) === (int) ceil($p['size'] / ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES), 409);
        });
        $p = $row->data['payload'];
        $bytes = '';
        foreach ($p['chunks'] as $index => $chunk) {
            abort_unless($index === $chunk['index'], 503);
            $part = $this->blobs->read($row, 'chunk-'.$index);
            abort_unless(strlen($part) === $chunk['size'] && hash_equals($chunk['sha256'], hash('sha256', $part)), 503);
            $bytes .= $part;
        }
        $this->verify($bytes, $p);
        $this->access->unchanged($current, $request, $token, $input['binding'], true);
        $this->blobs->put($row, 'original', $bytes);

        // Chunks remain encrypted and reserved until explicit cancel/expiry cleanup; no deletion races another lease.
        return $this->finish($request, $token, $input, $current, $claim, function ($row) {
            $p = $row->data['payload'];
            $p['claim'] = null;
            $p['scan'] = null;
            $this->store->save($row, $p, 'quarantined');
            $this->store->audit($row, 'upload.quarantined');
        });
    }

    public function cancel(Request $request, string $token, array $input): array
    {
        $this->request($input, ['upload_id']);
        abort_unless(ReviewerWorkspaceSchema::uuid($input['upload_id']), 422);
        [$current, $row, $claim] = $this->claim($request, $token, $input, 'cancel', hash('sha256', 'cancel'));
        if ($claim === null) {
            $this->access->unchanged($current, $request, $token, $input['binding']);

            return ['status' => $row->status, 'file' => $this->view($row, $current)];
        }
        $this->access->unchanged($current, $request, $token, $input['binding']);
        $this->blobs->remove($row);

        return $this->finish($request, $token, $input, $current, $claim, function ($row) {
            $p = $row->data['payload'];
            $p['claim'] = null;
            $p['chunks'] = [];
            $p['scan'] = null;
            $p['name'] = 'Removed attachment';
            $this->store->save($row, $p, 'cancelled');
            $this->store->audit($row, 'upload.cancelled');
        }, false);
    }
}
