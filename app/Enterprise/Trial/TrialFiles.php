<?php

namespace App\Enterprise\Trial;

use App\Models\TeamOperationRecord;
use App\Services\Team\TeamFileScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Private, small evidence files for a single server-held synthetic task. */
class TrialFiles
{
    public function __construct(private TrialStore $store, private ReviewerTrial $trial) {}

    private function disk()
    {
        abort_unless(config('filesystems.disks.team_private.driver') === 'local' && config('filesystems.disks.team_private.visibility') === 'private'
            && config('filesystems.disks.team_private.serve') === false, 503, 'Private trial file storage is not configured.');

        return Storage::disk('team_private');
    }

    private function dto(TeamOperationRecord $row): array
    {
        return ['id' => $row->id, 'name' => $row->data['name'], 'size' => $row->data['size'], 'status' => $row->status,
            'downloadable' => $row->status === 'clean'];
    }

    public function upload(string $token, string $requestId, UploadedFile $file): array
    {
        abort_unless(Str::isUuid($requestId) && $file->isValid() && $file->getSize() > 0 && $file->getSize() <= 5 * 1024 * 1024, 422, 'Choose one JPEG, PNG or PDF up to 5 MB.');
        $mime = $file->getMimeType();
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true), 422);
        if (str_starts_with($mime, 'image/')) {
            $image = @getimagesize($file->getRealPath());
            abort_unless($image && $image[0] * $image[1] <= 16000000 && ($image['mime'] ?? '') === $mime, 422);
        } else {
            abort_unless(str_starts_with(file_get_contents($file->getRealPath(), false, null, 0, 8), '%PDF-'), 422);
        }
        $sha = hash_file('sha256', $file->getRealPath());
        $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 120);
        $claim = $this->store->locked(function () use ($token, $requestId, $file, $mime, $sha, $name): array {
            [$link, $task] = $this->trial->capability($token);
            $source = hash('sha256', 'upload:'.$task->id.':'.$requestId);
            $existing = TeamOperationRecord::query()->where(['tenant_id' => $task->tenant_id, 'kind' => 'ent_trial_upload', 'source_key' => $source])->first();
            if ($existing) {
                abort_unless($existing->data['task_id'] === $task->id && hash_equals($existing->data['sha256'], $sha) && $existing->data['size'] === $file->getSize(), 409);
                if ($existing->status !== 'storing') {
                    return ['row' => $existing, 'replayed' => true];
                }
                abort_unless(now()->gt(\Carbon\CarbonImmutable::parse($existing->data['claim_expires_at'])), 409, 'This upload is still in progress. Reuse its request key after interruption.');
                $this->store->save($existing, array_replace($existing->data, ['claim_expires_at' => now()->addMinute()->toIso8601String()]));

                return ['row' => $existing, 'replayed' => false];
            }
            $count = 0;
            $total = 0;
            foreach (TeamOperationRecord::query()->where(['tenant_id' => $task->tenant_id, 'kind' => 'ent_trial_upload'])->cursor() as $row) {
                $total += $row->data['size'];
                if ($row->data['task_id'] === $task->id) {
                    $count++;
                }
            }
            abort_if($count >= 6 || $total + $file->getSize() > 50 * 1024 * 1024, 429, 'The bounded trial upload allowance is full.');
            $row = $this->store->create('ent_trial_upload', 'upload:'.$task->id.':'.$requestId, ['task_id' => $task->id, 'session_id' => $link->data['session_id'],
                'name' => $name ?: 'sample-file', 'size' => $file->getSize(), 'mime' => $mime, 'sha256' => $sha,
                'claim_expires_at' => now()->addMinute()->toIso8601String()], 'storing');
            $this->store->audit($row, 'trial.upload_claimed');

            return ['row' => $row, 'replayed' => false];
        });
        $row = $claim['row'];
        if ($claim['replayed']) {
            return $this->dto($row) + ['replayed' => true];
        }
        $path = 'enterprise-trial/'.$row->data['session_id'].'/'.$row->id;
        $disk = $this->disk();
        if (! $disk->exists($path) || ! hash_equals($sha, hash('sha256', $disk->get($path)))) {
            $disk->put($path, file_get_contents($file->getRealPath()), ['visibility' => 'private']);
        }
        abort_unless(hash_equals($sha, hash('sha256', $disk->get($path))), 503, 'The stored upload could not be verified.');
        $scan = app(TeamFileScanner::class)->scan($disk->path($path), $mime, $sha);
        $this->store->locked(function () use ($token, $row, $path, $scan): void {
            $this->trial->capability($token);
            $current = $this->store->row('ent_trial_upload', $row->id);
            $status = $scan === 'clean' ? 'clean' : ($scan === 'rejected' ? 'rejected' : 'quarantined');
            $this->store->save($current, $current->data + ['path' => $path], $status);
            $this->store->audit($current, 'trial.upload_'.$status);
        });

        return $this->dto($this->store->row('ent_trial_upload', $row->id));
    }

    public function download(string $token, string $id)
    {
        [, $task] = $this->trial->capability($token);
        $row = $this->store->row('ent_trial_upload', $id);
        abort_unless($row->data['task_id'] === $task->id && $row->status === 'clean', 404);
        $disk = $this->disk();
        abort_unless($disk->exists($row->data['path']) && hash_equals($row->data['sha256'], hash('sha256', $disk->get($row->data['path']))), 404);

        return $disk->download($row->data['path'], $row->data['name'], ['Content-Type' => $row->data['mime'], 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
