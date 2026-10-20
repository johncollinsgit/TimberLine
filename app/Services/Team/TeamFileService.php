<?php

namespace App\Services\Team;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TeamFileService
{
    public const CHUNK_BYTES = 262144;

    public const MIMES = ['text/plain', 'image/jpeg', 'image/png', 'application/pdf'];

    public function __construct(private TeamWorkAccess $access, private TeamWorkRepository $repository, private WorkGraph $graph) {}

    private function authorize(Tenant $tenant, User $user, string $taskId, bool $write): array
    {
        $actor = $this->access->context($user, $tenant);
        abort_unless($this->graph->can($this->repository->state($tenant->id), $actor, 'task', $taskId, $write), 404);

        return $actor;
    }

    private function locked(Tenant $tenant, callable $callback): mixed
    {
        return DB::transaction(function () use ($tenant, $callback) {
            DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $tenant->id]);
            DB::table('team_work_locks')->where('tenant_id', $tenant->id)->lockForUpdate()->first();

            return $callback();
        }, 3);
    }

    private function upload(Tenant $tenant, User $user, string $id, bool $write): object
    {
        $row = DB::table('team_file_uploads')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($row, 404);
        $this->authorize($tenant, $user, $row->task_id, $write);
        abort_if($write && (int) $row->uploader_user_id !== (int) $user->id, 404);

        return $row;
    }

    private function prefix(object $upload): string
    {
        abort_unless(Str::isUuid($upload->id) && (int) $upload->tenant_id > 0, 500);

        return 'uploads/'.(int) $upload->tenant_id.'/'.$upload->id;
    }

    public function dto(object $row, bool $verifyStoredChunks = false): array
    {
        $chunks = DB::table('team_file_chunks')->where('tenant_id', $row->tenant_id)->where('upload_id', $row->id)->orderBy('chunk_index')->get();
        if ($verifyStoredChunks && $row->status === 'uploading') {
            $disk = Storage::disk('team_private');
            $chunks = $chunks->filter(function ($chunk) use ($disk, $row) {
                $key = $this->prefix($row).'/chunks/'.$chunk->chunk_index;

                return $disk->exists($key) && $disk->size($key) === (int) $chunk->size && hash_equals($chunk->sha256, hash('sha256', $disk->get($key)));
            });
        }

        return ['id' => $row->id, 'task_id' => $row->task_id, 'filename' => $row->filename, 'mime_type' => $row->mime_type,
            'size' => (int) $row->expected_size, 'sha256' => $row->sha256, 'status' => $row->status,
            'received_bytes' => (int) $chunks->sum('size'), 'received_chunks' => $chunks->pluck('chunk_index')->map(fn ($i) => (int) $i)->all(),
            'chunk_bytes' => self::CHUNK_BYTES, 'downloadable' => $row->status === 'ready'];
    }

    public function begin(Tenant $tenant, User $user, string $taskId, array $input): array
    {
        return $this->locked($tenant, function () use ($tenant, $user, $taskId, $input): array {
            $this->authorize($tenant, $user, $taskId, true);
            $filename = trim($input['filename']);
            abort_if($filename === '' || preg_match('/[\x00-\x1f\x7f\\\\\/]/u', $filename), 422, 'Use a filename without paths or control characters.');
            abort_unless(in_array($input['mime_type'], self::MIMES, true), 422, 'This file type is not supported.');
            $extensions = ['text/plain' => ['txt'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'application/pdf' => ['pdf']];
            abort_unless(in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $extensions[$input['mime_type']], true), 422, 'The filename extension does not match the file type.');
            abort_if($input['size'] < 1 || $input['size'] > config('team_work.file_max_bytes'), 422, 'This file exceeds the upload limit.');
            $existing = DB::table('team_file_uploads')->where('tenant_id', $tenant->id)->where('uploader_user_id', $user->id)->where('client_key', $input['client_key'])->first();
            if ($existing) {
                abort_unless($existing->task_id === $taskId && $existing->filename === $filename && $existing->mime_type === $input['mime_type']
                    && (int) $existing->expected_size === $input['size'] && hash_equals($existing->sha256, $input['sha256']), 409, 'This upload key belongs to different content.');

                return $this->dto($existing, true);
            }
            $active = DB::table('team_file_uploads')->where('tenant_id', $tenant->id)->where('uploader_user_id', $user->id)->whereIn('status', ['uploading', 'quarantined'])->count();
            abort_if($active >= config('team_work.file_user_active_limit'), 422, 'Finish or cancel an existing upload first.');
            $reserved = DB::table('team_file_uploads')->where('tenant_id', $tenant->id)->where('status', '!=', 'cancelled')->sum('expected_size');
            $reserved += app(TeamFileQuota::class)->operationBytes($tenant->id);
            abort_if($reserved + $input['size'] > config('team_work.file_tenant_quota_bytes'), 422, 'The workspace file quota is full.');
            $id = (string) Str::uuid();
            DB::table('team_file_uploads')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'task_id' => $taskId, 'uploader_user_id' => $user->id,
                'client_key' => $input['client_key'], 'filename' => $filename, 'mime_type' => $input['mime_type'], 'expected_size' => $input['size'],
                'sha256' => $input['sha256'], 'status' => 'uploading', 'storage_key' => null, 'created_at' => now(), 'updated_at' => now()]);

            return $this->dto(DB::table('team_file_uploads')->where('id', $id)->where('tenant_id', $tenant->id)->first());
        });
    }

    public function chunk(Tenant $tenant, User $user, string $id, int $index, string $bytes): array
    {
        return $this->locked($tenant, function () use ($tenant, $user, $id, $index, $bytes): array {
            $row = $this->upload($tenant, $user, $id, true);
            $count = (int) ceil($row->expected_size / self::CHUNK_BYTES);
            abort_if($index < 0 || $index >= $count, 422, 'Invalid upload chunk.');
            $expected = min(self::CHUNK_BYTES, $row->expected_size - $index * self::CHUNK_BYTES);
            abort_unless(strlen($bytes) === $expected, 422, 'Chunk size does not match the upload.');
            $digest = hash('sha256', $bytes);
            $prior = DB::table('team_file_chunks')->where('tenant_id', $tenant->id)->where('upload_id', $id)->where('chunk_index', $index)->first();
            abort_if($prior && (! hash_equals($prior->sha256, $digest) || (int) $prior->size !== strlen($bytes)), 409, 'A different chunk was already received.');
            if ($row->status !== 'uploading') {
                abort_unless($prior && in_array($row->status, ['quarantined', 'ready'], true), 409, 'This upload is closed.');

                return $this->dto($row);
            }
            $key = $this->prefix($row).'/chunks/'.$index;
            $disk = Storage::disk('team_private');
            // A same-content retry repairs a truncated/missing storage write after interruption.
            $disk->put($key, $bytes);
            abort_unless(hash_equals($digest, hash('sha256', $disk->get($key))), 503, 'The chunk was not stored. Retry it.');
            if (! $prior) {
                DB::table('team_file_chunks')->insert(['tenant_id' => $tenant->id, 'upload_id' => $id, 'chunk_index' => $index, 'size' => strlen($bytes), 'sha256' => $digest]);
            }

            return $this->dto($row);
        });
    }

    public function complete(Tenant $tenant, User $user, string $id): array
    {
        return $this->locked($tenant, function () use ($tenant, $user, $id): array {
            $row = $this->upload($tenant, $user, $id, true);
            if ($row->status === 'ready') {
                return $this->dto($row);
            }
            abort_unless(in_array($row->status, ['uploading', 'quarantined'], true), 409, 'This upload is closed.');
            $disk = Storage::disk('team_private');
            $bytes = '';
            $chunks = DB::table('team_file_chunks')->where('tenant_id', $tenant->id)->where('upload_id', $id)->orderBy('chunk_index')->get();
            abort_unless($chunks->count() === (int) ceil($row->expected_size / self::CHUNK_BYTES), 409, 'Upload is incomplete. Resume the missing chunks.');
            foreach ($chunks as $index => $chunk) {
                $key = $this->prefix($row).'/chunks/'.$index;
                abort_unless((int) $chunk->chunk_index === $index && $disk->exists($key), 409, 'A chunk is missing. Retry that chunk.');
                $part = $disk->get($key);
                abort_unless(strlen($part) === (int) $chunk->size && hash_equals($chunk->sha256, hash('sha256', $part)), 409, 'A chunk is incomplete. Retry that chunk.');
                $bytes .= $part;
            }
            abort_unless(strlen($bytes) === (int) $row->expected_size && hash_equals($row->sha256, hash('sha256', $bytes)), 422, 'The file checksum does not match. Start a new upload.');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $valid = $mime === $row->mime_type && in_array($mime, self::MIMES, true);
            if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
                $image = @getimagesizefromstring($bytes);
                $valid = $valid && $image && $image[0] * $image[1] <= 20000000;
            }
            if ($mime === 'text/plain') {
                $valid = $valid && preg_match('//u', $bytes) === 1;
            }
            $key = $this->prefix($row).'/original';
            $disk->put($key, $bytes);
            try {
                $scan = $valid ? app(TeamFileScanner::class)->scan($disk->path($key), $mime, $row->sha256) : 'rejected';
            } catch (\Throwable) {
                $scan = 'unavailable';
            }
            if (! $disk->exists($key) || $disk->size($key) !== (int) $row->expected_size || ! hash_equals($row->sha256, hash_file('sha256', $disk->path($key)))) {
                $scan = 'rejected';
            }
            $this->authorize($tenant, $user, $row->task_id, true); // Recheck after scan; an upload cannot restore removed access.
            $status = match ($scan) {
                'clean' => 'ready', 'rejected' => 'rejected', default => 'quarantined'
            };
            if ($status === 'ready') {
                $state = $this->repository->state($tenant->id);
                $this->repository->execute($tenant->id, fn () => $this->access->context($user, $tenant), ['kind' => 'task.attachment', 'id' => $row->task_id,
                    'attachment_id' => $id, 'expected_version' => $state['tasks'][$row->task_id]['version'], 'idempotency_key' => $id]);
            }
            DB::table('team_file_uploads')->where('tenant_id', $tenant->id)->where('id', $id)->update(['status' => $status, 'storage_key' => $key, 'updated_at' => now()]);

            return $this->dto($this->upload($tenant, $user, $id, true));
        });
    }

    public function cancel(Tenant $tenant, User $user, string $id): array
    {
        return $this->locked($tenant, function () use ($tenant, $user, $id): array {
            $row = $this->upload($tenant, $user, $id, true);
            if ($row->status === 'cancelled') {
                return ['id' => $id, 'status' => 'cancelled'];
            }
            abort_if($row->status === 'ready', 409, 'A completed attachment cannot be cancelled.');
            $disk = Storage::disk('team_private');
            abort_if($disk->exists($this->prefix($row)) && ! $disk->deleteDirectory($this->prefix($row)), 503, 'Upload cleanup failed. Retry cancellation.');
            DB::table('team_file_uploads')->where('tenant_id', $tenant->id)->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);

            return ['id' => $id, 'status' => 'cancelled'];
        });
    }

    public function listing(Tenant $tenant, User $user, string $taskId): array
    {
        $actor = $this->authorize($tenant, $user, $taskId, false);
        $canWrite = $this->graph->can($this->repository->state($tenant->id), $actor, 'task', $taskId, true);

        return DB::table('team_file_uploads')->where('tenant_id', $tenant->id)->where('task_id', $taskId)
            ->where(function ($query) use ($user) {
                $query->where('status', 'ready')->orWhere('uploader_user_id', $user->id);
            })
            ->where('status', '!=', 'cancelled')->orderBy('created_at')->get()->map(fn ($row) => $this->dto($row)
                + ['can_manage' => $canWrite && (int) $row->uploader_user_id === (int) $user->id]
                + ((int) $row->uploader_user_id === (int) $user->id ? ['client_key' => $row->client_key] : []))->all();
    }

    public function download(Tenant $tenant, User $user, string $id)
    {
        $row = $this->upload($tenant, $user, $id, false);
        abort_unless($row->status === 'ready' && $row->storage_key === $this->prefix($row).'/original', 404);
        $disk = Storage::disk('team_private');
        abort_unless($disk->exists($row->storage_key) && $disk->size($row->storage_key) === (int) $row->expected_size
            && hash_equals($row->sha256, hash_file('sha256', $disk->path($row->storage_key))), 503, 'Attachment is unavailable.');

        return response()->download($disk->path($row->storage_key), $row->filename, ['Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }
}
