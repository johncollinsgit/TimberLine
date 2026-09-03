<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAsset;
use App\Models\WorkspaceAssetUpload;
use App\Services\Integrations\QuickBooks\QuickBooksOnlineClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class WorkspaceAssetService
{
    public const CHUNK_SIZE = 512 * 1024;

    public const MAX_ACTIVE_UPLOADS_PER_USER = 5;

    public const MAX_ACTIVE_UPLOADS_PER_TENANT = 20;

    public const LEGACY_PDF_UPLOAD_BYTES = 25 * 1024 * 1024;

    protected const COMPLETION_LEASE_MINUTES = 10;

    protected const PDF_EDGE_BYTES = 64 * 1024;

    public function __construct(
        protected WorkspaceAssetAuditService $audit,
        protected FieldServiceAccessService $access,
    ) {}

    /** @var array<int,string> */
    protected array $allowedMimes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/tiff', 'image/heic', 'image/heif', 'application/pdf', 'text/plain', 'text/csv',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** @param array<int,int> $jobIds */
    public function storeUpload(Tenant $tenant, User $user, UploadedFile $file, array $jobIds, string $visibility, ?string $caption = null, array $tags = [], array $metadata = []): WorkspaceAsset
    {
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        abort_unless(in_array($mime, $this->allowedMimes, true), 422, 'This file type is not supported.');
        abort_if((int) ($file->getSize() ?: 0) > $this->byteLimitForMime($mime), 422, 'This file exceeds the workspace upload limit.');
        $bytes = (string) file_get_contents($file->getRealPath());

        return $this->storeBytes(
            $tenant,
            $bytes,
            $file->getClientOriginalName(),
            $mime,
            'upload',
            null,
            $visibility,
            $caption,
            (int) $user->id,
            $jobIds,
            $tags,
            $metadata,
        );
    }

    /** @param array<string,mixed> $attachable */
    public function importQuickBooksAttachable(Tenant $tenant, QuickBooksOnlineClient $client, array $attachable): ?WorkspaceAsset
    {
        $externalId = trim((string) ($attachable['Id'] ?? ''));
        $fileName = trim((string) ($attachable['FileName'] ?? ''));
        $mime = trim((string) ($attachable['ContentType'] ?? ''));
        $size = (int) ($attachable['Size'] ?? 0);
        if ($externalId === '' || $fileName === '' || ! in_array($mime, $this->allowedMimes, true) || $size > $this->byteLimitForMime($mime)) {
            return null;
        }

        $existing = WorkspaceAsset::query()->forTenantId((int) $tenant->id)
            ->where('source', 'quickbooks')->where('external_id', $externalId)->first();
        if ($existing) {
            return $existing;
        }

        $url = $client->attachmentDownloadUrl($externalId);
        if (! str_starts_with($url, 'https://')) {
            return null;
        }
        $response = Http::timeout(30)->retry(2, 250)->get($url)->throw();
        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) > $this->byteLimitForMime($mime)) {
            return null;
        }

        $asset = $this->storeBytes($tenant, $bytes, $fileName, $mime, 'quickbooks', $externalId, 'owner', $attachable['Note'] ?? null);
        foreach ((array) ($attachable['AttachableRef'] ?? []) as $reference) {
            $type = strtolower(trim((string) data_get($reference, 'EntityRef.type', '')));
            $documentId = trim((string) data_get($reference, 'EntityRef.value', ''));
            $document = FieldServiceFinancialDocument::query()->forTenantId((int) $tenant->id)
                ->where('source', 'quickbooks')->where('document_type', $type)->where('external_id', $documentId)->first();
            if (! $document) {
                continue;
            }
            $asset->financialDocuments()->syncWithoutDetaching([(int) $document->id => ['tenant_id' => (int) $tenant->id]]);
            if ($document->field_service_job_id) {
                $asset->jobs()->syncWithoutDetaching([(int) $document->field_service_job_id => ['tenant_id' => (int) $tenant->id]]);
            }
        }

        return $asset;
    }

    /**
     * Store a durable tenant-owned copy of an internally selected HTTPS image.
     *
     * @param  array<int,int>  $jobIds
     * @param  array<int,string>  $tags
     * @param  array<string,mixed>  $metadata
     */
    public function importRemoteImage(
        Tenant $tenant,
        User $user,
        string $url,
        string $externalId,
        string $fileName,
        array $jobIds,
        string $caption,
        array $tags = [],
        array $metadata = [],
    ): WorkspaceAsset {
        abort_unless(str_starts_with($url, 'https://'), 422, 'Remote images must use HTTPS.');

        $existing = WorkspaceAsset::query()
            ->forTenantId((int) $tenant->id)
            ->where('source', 'demo_seed')
            ->where('external_id', $externalId)
            ->first();
        if ($existing) {
            $validJobIds = $tenant->fieldServiceJobs()->whereIn('id', $jobIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $existing->jobs()->syncWithoutDetaching(collect($validJobIds)->mapWithKeys(fn (int $id): array => [$id => [
                'tenant_id' => (int) $tenant->id,
                'linked_by_user_id' => (int) $user->id,
            ]])->all());

            return $existing;
        }

        $response = Http::withHeaders([
            'User-Agent' => 'Everbranch/2.0 (+https://theeverbranch.com; john@evergrovesoftware.com)',
        ])->timeout(30)->retry(2, 250)->get($url)->throw();
        $bytes = $response->body();
        abort_if($bytes === '' || strlen($bytes) > 25 * 1024 * 1024, 422, 'The remote image is empty or too large.');
        $mime = strtolower(trim((string) strtok((string) $response->header('Content-Type'), ';')));
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true), 422, 'The remote file is not a supported image.');

        return $this->storeBytes(
            $tenant,
            $bytes,
            $fileName,
            $mime,
            'demo_seed',
            $externalId,
            'team',
            $caption,
            (int) $user->id,
            $jobIds,
            $tags,
            $metadata,
        );
    }

    /** @param array<int,int> $jobIds */
    protected function storeBytes(
        Tenant $tenant,
        string $bytes,
        string $fileName,
        string $mime,
        string $source,
        ?string $externalId,
        string $visibility,
        ?string $caption = null,
        ?int $uploadedBy = null,
        array $jobIds = [],
        array $tags = [],
        array $metadata = [],
    ): WorkspaceAsset {
        abort_if($bytes === '' || strlen($bytes) > $this->byteLimitForMime($mime), 422, 'This file exceeds the workspace upload limit.');
        if ($mime === 'application/pdf') {
            $this->assertValidPdfBytes($bytes);
        }
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $safeName = Str::uuid().($extension !== '' ? '.'.$extension : '');
        $path = 'workspace-assets/'.$tenant->id.'/'.$safeName;
        $disk = (string) config('filesystems.workspace_asset_disk', 'local');
        abort_unless(array_key_exists($disk, (array) config('filesystems.disks')), 500, 'The workspace asset disk is not configured.');
        abort_unless(Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private']), 503, 'The file could not be stored.');
        [$thumbnailDisk, $thumbnailPath] = $this->storeThumbnail($disk, $path, $bytes, $mime);
        $tags = collect($tags)->map(fn (mixed $tag): string => trim((string) $tag))->filter()->unique()->take(30)->values()->all();
        $extracted = in_array($mime, ['text/plain', 'text/csv'], true)
            ? trim((string) preg_replace('/[^\P{C}\n\r\t]+/u', '', mb_substr($bytes, 0, 100000)))
            : '';
        $asset = WorkspaceAsset::query()->create([
            'tenant_id' => (int) $tenant->id,
            'uploaded_by_user_id' => $uploadedBy,
            'source' => $source,
            'external_id' => $externalId,
            'visibility' => $visibility === 'owner' ? 'owner' : 'team',
            'storage_disk' => $disk,
            'storage_path' => $path,
            'thumbnail_disk' => $thumbnailDisk,
            'thumbnail_path' => $thumbnailPath,
            'file_name' => Str::limit(basename($fileName), 255, ''),
            'mime_type' => $mime,
            'file_size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'caption' => $caption ? Str::limit(trim($caption), 255, '') : null,
            'tags' => $tags,
            'search_text' => trim(implode(' ', array_filter([$fileName, $caption, implode(' ', $tags), $extracted]))),
            'metadata' => $metadata,
        ]);
        $validJobIds = $tenant->fieldServiceJobs()->whereIn('id', $jobIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($validJobIds !== []) {
            $asset->jobs()->sync(collect($validJobIds)->mapWithKeys(fn (int $id): array => [$id => [
                'tenant_id' => (int) $tenant->id,
                'linked_by_user_id' => $uploadedBy,
            ]])->all());
        }

        $this->audit->record($tenant, $asset, $uploadedBy ? User::query()->find($uploadedBy) : null, $source === 'quickbooks' ? 'quickbooks_imported' : 'uploaded', [
            'visibility' => $asset->visibility,
            'job_ids' => $validJobIds,
            'checksum' => $asset->checksum,
        ]);

        return $asset;
    }

    public function readableDisk(WorkspaceAsset $asset): ?string
    {
        $primary = trim((string) $asset->storage_disk) ?: 'local';
        if (Storage::disk($primary)->exists($asset->storage_path)) {
            return $primary;
        }

        return $primary !== 'local' && Storage::disk('local')->exists($asset->storage_path) ? 'local' : null;
    }

    /** @return array{disk:string,path:string}|null */
    public function thumbnailLocation(WorkspaceAsset $asset): ?array
    {
        if (! str_starts_with((string) $asset->mime_type, 'image/')) {
            return null;
        }
        if ($asset->thumbnail_disk && $asset->thumbnail_path && Storage::disk($asset->thumbnail_disk)->exists($asset->thumbnail_path)) {
            return ['disk' => $asset->thumbnail_disk, 'path' => $asset->thumbnail_path];
        }

        $sourceDisk = $this->readableDisk($asset);
        if (! $sourceDisk) {
            return null;
        }
        $bytes = Storage::disk($sourceDisk)->get($asset->storage_path);
        [$thumbnailDisk, $thumbnailPath] = $this->storeThumbnail($sourceDisk, $asset->storage_path, $bytes, (string) $asset->mime_type);
        if (! $thumbnailDisk || ! $thumbnailPath) {
            return null;
        }
        $asset->forceFill(['thumbnail_disk' => $thumbnailDisk, 'thumbnail_path' => $thumbnailPath])->save();

        return ['disk' => $thumbnailDisk, 'path' => $thumbnailPath];
    }

    public function maxUploadBytes(): int
    {
        return (int) config('filesystems.workspace_asset_max_upload_mb', 50) * 1024 * 1024;
    }

    public function legacyPdfUploadBytes(): int
    {
        return min($this->maxUploadBytes(), self::LEGACY_PDF_UPLOAD_BYTES);
    }

    /** @return array{upload:WorkspaceAssetUpload,token:string,url:?string,headers:array<string,string>,mode:string,chunk_size:?int,max_file_size:int,replayed:bool} */
    public function initializeSignedUpload(Tenant $tenant, User $user, string $fileName, string $mime, int $fileSize, ?int $jobId, string $visibility, ?string $caption, ?string $idempotencyKey = null): array
    {
        abort_unless(in_array($mime, $this->allowedMimes, true), 422, 'This file type is not supported.');
        $maximumBytes = $this->byteLimitForMime($mime);
        abort_if($fileSize < 1 || $fileSize > $maximumBytes, 422, 'This file exceeds the workspace upload limit.');
        $disk = (string) config('filesystems.workspace_asset_disk', 'local');
        abort_if($disk === 'local' && $mime !== 'application/pdf', 422, 'Resumable local uploads currently accept PDF documents.');
        $fileName = Str::limit(basename($fileName), 255, '');
        $visibility = $visibility === 'owner' ? 'owner' : 'team';
        $caption = $caption ? Str::limit(trim($caption), 255, '') : null;
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $path = $disk === 'local'
            ? 'workspace-upload-staging/'.$tenant->id.'/'.Str::uuid().'/assembled.part'
            : 'workspace-assets/'.$tenant->id.'/'.Str::uuid().($extension ? '.'.$extension : '');
        $token = $this->initializationToken($tenant, $user, $idempotencyKey);
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addHours(2);
        [$upload, $replayed] = DB::transaction(function () use ($tenant, $user, $jobId, $tokenHash, $disk, $path, $fileName, $mime, $fileSize, $visibility, $caption, $expiresAt, $idempotencyKey): array {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($idempotencyKey !== null) {
                $existing = WorkspaceAssetUpload::query()->forTenantId((int) $tenant->id)->where('token_hash', $tokenHash)->first();
                if ($existing) {
                    abort_unless(
                        (int) $existing->uploaded_by_user_id === (int) $user->id
                        && (int) ($existing->field_service_job_id ?? 0) === (int) ($jobId ?? 0)
                        && hash_equals((string) $existing->file_name, $fileName)
                        && hash_equals((string) $existing->mime_type, $mime)
                        && (int) $existing->max_file_size === $fileSize
                        && hash_equals((string) $existing->visibility, $visibility)
                        && hash_equals((string) ($existing->caption ?? ''), (string) ($caption ?? ''))
                        && hash_equals((string) $existing->storage_disk, $disk),
                        409,
                        'This upload idempotency key was already used for different file details.',
                    );
                    abort_unless($existing->status === 'initialized' && $existing->expires_at->isFuture(), 409, 'This upload idempotency key is no longer active.');

                    return [$existing, true];
                }
            }
            $active = WorkspaceAssetUpload::query()
                ->forTenantId((int) $tenant->id)
                ->whereIn('status', ['initialized', 'completing'])
                ->where('expires_at', '>', now());
            abort_if((clone $active)->where('uploaded_by_user_id', $user->id)->count() >= self::MAX_ACTIVE_UPLOADS_PER_USER, 429, 'Finish or wait for an active upload before starting another.');
            abort_if((clone $active)->count() >= self::MAX_ACTIVE_UPLOADS_PER_TENANT, 429, 'This workspace has too many active uploads. Try again shortly.');
            $reservedBytes = (int) (clone $active)->sum('max_file_size');
            abort_if($reservedBytes + $fileSize > $this->activeUploadReservationBytes(), 422, 'Active uploads have reached the workspace staging limit. Finish an upload or try again after it expires.');

            return [WorkspaceAssetUpload::query()->create([
                'tenant_id' => (int) $tenant->id, 'uploaded_by_user_id' => (int) $user->id, 'field_service_job_id' => $jobId,
                'token_hash' => $tokenHash, 'storage_disk' => $disk, 'storage_path' => $path,
                'file_name' => $fileName, 'mime_type' => $mime, 'max_file_size' => $fileSize,
                'visibility' => $visibility, 'caption' => $caption,
                'status' => 'initialized', 'expires_at' => $expiresAt,
            ]), false];
        });

        if ($disk === 'local') {
            return [
                'upload' => $upload,
                'token' => $token,
                'url' => null,
                'headers' => [],
                'mode' => 'chunked',
                'chunk_size' => self::CHUNK_SIZE,
                'max_file_size' => $maximumBytes,
                'replayed' => $replayed,
            ];
        }

        try {
            $signed = Storage::disk($disk)->temporaryUploadUrl($upload->storage_path, $upload->expires_at, [
                'ContentType' => $mime,
                'ContentLength' => $fileSize,
            ]);
        } catch (Throwable) {
            abort(503, 'Object storage could not initialize this upload.');
        }

        return [
            'upload' => $upload,
            'token' => $token,
            'url' => $signed['url'],
            'headers' => (array) ($signed['headers'] ?? []),
            'mode' => 'direct',
            'chunk_size' => null,
            'max_file_size' => $maximumBytes,
            'replayed' => $replayed,
        ];
    }

    /** @return array{upload:WorkspaceAssetUpload,replayed:bool,next_offset:int,received_bytes:int} */
    public function storeChunk(Tenant $tenant, User $user, int $uploadId, int $index, int $offset, string $token, string $contentsBase64, string $checksumSha256): array
    {
        $upload = $this->authorizedUpload($tenant, $user, $uploadId, $token);
        abort_unless($upload->storage_disk === 'local' && $upload->mime_type === 'application/pdf', 409, 'This upload does not accept resumable chunks.');
        abort_unless($upload->status === 'initialized' && $upload->expires_at->isFuture(), 409, 'This upload is expired or already completed.');
        $contents = base64_decode($contentsBase64, true);
        abort_unless(is_string($contents) && $contents !== '', 422, 'The upload chunk could not be decoded.');
        abort_if(strlen($contents) > self::CHUNK_SIZE, 422, 'The upload chunk is larger than allowed.');
        abort_unless(hash_equals(strtolower($checksumSha256), hash('sha256', $contents)), 422, 'The upload chunk checksum did not match.');
        abort_unless($index >= 0 && $offset >= 0 && $offset === $index * self::CHUNK_SIZE, 422, 'The upload chunk offset is invalid.');
        abort_if($offset + strlen($contents) > (int) $upload->max_file_size, 422, 'The upload chunk exceeds the declared file size.');
        abort_unless(strlen($contents) === self::CHUNK_SIZE || $offset + strlen($contents) === (int) $upload->max_file_size, 422, 'Only the final upload chunk may be smaller than the chunk size.');

        return $this->withUploadLock($upload->id, 30, function () use ($tenant, $user, $uploadId, $index, $offset, $token, $contents): array {
            $upload = $this->authorizedUpload($tenant, $user, $uploadId, $token);
            abort_unless($upload->status === 'initialized' && $upload->expires_at->isFuture(), 409, 'This upload is expired or already completed.');
            $storage = Storage::disk($upload->storage_disk);
            $currentSize = $this->receivedChunkBytes($upload);
            $chunkPath = $this->chunkPath($upload, $index);

            if ($storage->exists($chunkPath)) {
                abort_unless((int) $storage->size($chunkPath) === strlen($contents), 409, 'A different chunk was already stored at this offset.');
                $storedChecksum = hash_file('sha256', $storage->path($chunkPath));
                abort_unless(is_string($storedChecksum) && hash_equals(hash('sha256', $contents), $storedChecksum), 409, 'A different chunk was already stored at this offset.');

                return ['upload' => $upload, 'replayed' => true, 'next_offset' => $currentSize, 'received_bytes' => $currentSize];
            }

            abort_unless($offset === $currentSize, 409, 'The next upload chunk must start at byte '.$currentSize.'.');
            $this->writeLocalFileAtomically($storage, $chunkPath, $contents);
            $nextOffset = $offset + strlen($contents);

            return ['upload' => $upload, 'replayed' => false, 'next_offset' => $nextOffset, 'received_bytes' => $nextOffset];
        });
    }

    /** @return array{asset:WorkspaceAsset,replayed:bool} */
    public function completeSignedUpload(Tenant $tenant, User $user, string $token, ?string $checksumSha256 = null): array
    {
        $upload = WorkspaceAssetUpload::query()->forTenantId((int) $tenant->id)->where('token_hash', hash('sha256', $token))->firstOrFail();
        $upload = $this->uploadForToken($tenant, $user, (int) $upload->id, $token);
        $this->authorizeUploadRecord($tenant, $user, $upload);
        abort_if($upload->storage_disk === 'local' && $checksumSha256 === null, 422, 'A checksum is required for resumable uploads.');

        return $this->withUploadLock($upload->id, self::COMPLETION_LEASE_MINUTES * 60, function () use ($tenant, $user, $upload, $token, $checksumSha256): array {
            $upload = $this->authorizedUpload($tenant, $user, (int) $upload->id, $token);
            $existing = $this->completedUploadAsset($tenant, $upload);
            if ($upload->status === 'completed' && $existing) {
                abort_if($checksumSha256 !== null && ! hash_equals((string) $existing->checksum, strtolower($checksumSha256)), 409, 'This upload was completed with different contents.');
                $this->deleteUploadStaging($upload);

                return ['asset' => $existing, 'replayed' => true];
            }

            abort_unless($upload->status === 'initialized' && $upload->expires_at->isFuture(), 409, 'This upload is expired or already completed.');
            $originalExpiry = $upload->expires_at->copy();
            $upload->forceFill([
                'status' => 'completing',
                'expires_at' => now()->addMinutes(self::COMPLETION_LEASE_MINUTES),
            ])->save();

            try {
                $storage = Storage::disk($upload->storage_disk);
                $source = $upload->storage_disk === 'local' ? 'resumable_upload' : 'signed_upload';
                $finalPath = $upload->storage_disk === 'local' ? $this->finalUploadPath($upload) : $upload->storage_path;
                if ($upload->storage_disk === 'local' && ! $storage->exists($upload->storage_path) && ! $storage->exists($finalPath)) {
                    abort_unless($this->receivedChunkBytes($upload) === (int) $upload->max_file_size, 422, 'The uploaded object does not match its declared size.');
                    $this->assembleLocalChunks($upload);
                }
                $validationPath = $storage->exists($upload->storage_path) ? $upload->storage_path : $finalPath;
                abort_unless($storage->exists($validationPath), 422, 'The uploaded object was not found.');
                $size = (int) $storage->size($validationPath);
                abort_unless($size === (int) $upload->max_file_size && $size <= $this->byteLimitForMime((string) $upload->mime_type), 422, 'The uploaded object does not match its declared size.');

                $temporaryPdfPath = null;
                try {
                    if ($upload->mime_type === 'application/pdf' && config('filesystems.disks.'.$upload->storage_disk.'.driver') !== 'local') {
                        $temporaryPdfPath = tempnam(sys_get_temp_dir(), 'everbranch-pdf-validate-');
                        abort_unless(is_string($temporaryPdfPath), 503, 'The PDF could not be prepared for validation.');
                    }
                    [$actualChecksum] = $this->streamChecksumAndEdges($storage->readStream($validationPath), $temporaryPdfPath);
                    abort_if($checksumSha256 !== null && ! hash_equals(strtolower($checksumSha256), $actualChecksum), 422, 'The completed file checksum did not match.');
                    if ($upload->mime_type === 'application/pdf') {
                        $this->assertValidPdfPath($temporaryPdfPath ?: $storage->path($validationPath));
                    }
                } finally {
                    if ($temporaryPdfPath !== null) {
                        @unlink($temporaryPdfPath);
                    }
                }

                if ($upload->storage_disk === 'local' && $validationPath !== $finalPath) {
                    $storage->makeDirectory(dirname($finalPath));
                    abort_unless($storage->move($validationPath, $finalPath), 503, 'The completed file could not be promoted from staging.');
                }

                // The final path is deterministic. If the process stops after the
                // atomic move but before this transaction commits, a retry finds
                // and verifies the same file instead of losing the upload.
                $asset = DB::transaction(function () use ($tenant, $user, $upload, $source, $finalPath, $size, $actualChecksum): WorkspaceAsset {
                    $lockedUpload = WorkspaceAssetUpload::query()->forTenantId((int) $tenant->id)->lockForUpdate()->findOrFail($upload->id);
                    $existing = $this->completedUploadAsset($tenant, $lockedUpload);
                    if ($existing) {
                        return $existing;
                    }
                    abort_unless($lockedUpload->status === 'completing', 409, 'This upload is no longer being completed.');

                    $asset = WorkspaceAsset::query()->create([
                        'tenant_id' => (int) $tenant->id,
                        'uploaded_by_user_id' => (int) $user->id,
                        'source' => $source,
                        'external_id' => (string) $lockedUpload->id,
                        'visibility' => $lockedUpload->visibility,
                        'storage_disk' => $lockedUpload->storage_disk,
                        'storage_path' => $finalPath,
                        'file_name' => $lockedUpload->file_name,
                        'mime_type' => $lockedUpload->mime_type,
                        'file_size' => $size,
                        'checksum' => $actualChecksum,
                        'caption' => $lockedUpload->caption,
                        'tags' => $lockedUpload->mime_type === 'application/pdf' ? ['drawing', 'pdf', $source] : ['direct-upload'],
                        'search_text' => trim($lockedUpload->file_name.' '.$lockedUpload->caption),
                        'metadata' => ['upload_id' => (int) $lockedUpload->id],
                    ]);
                    if ($lockedUpload->field_service_job_id) {
                        $asset->jobs()->sync([(int) $lockedUpload->field_service_job_id => [
                            'tenant_id' => (int) $tenant->id,
                            'linked_by_user_id' => (int) $user->id,
                        ]]);
                    }
                    $this->audit->record($tenant, $asset, $user, 'uploaded', [
                        'surface' => $source,
                        'job_ids' => array_filter([$lockedUpload->field_service_job_id]),
                        'checksum' => $actualChecksum,
                    ]);
                    $lockedUpload->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

                    return $asset;
                });
            } catch (Throwable $exception) {
                $recoverableUpload = WorkspaceAssetUpload::query()->forTenantId((int) $tenant->id)->find($upload->id);
                if ($recoverableUpload?->status === 'completing') {
                    $recoverableUpload->forceFill([
                        'status' => 'initialized',
                        'expires_at' => $originalExpiry->isFuture() ? $originalExpiry : now()->addMinutes(self::COMPLETION_LEASE_MINUTES),
                    ])->save();
                }

                throw $exception;
            }

            $this->deleteUploadStaging($upload);

            return ['asset' => $asset, 'replayed' => false];
        });
    }

    public function pruneExpiredUploads(int $limit = 100): int
    {
        $pruned = 0;
        WorkspaceAssetUpload::query()
            ->whereIn('status', ['initialized', 'failed', 'canceled', 'completed', 'completing'])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->get()
            ->each(function (WorkspaceAssetUpload $upload) use (&$pruned): void {
                $lock = Cache::lock('workspace-asset-upload:'.$upload->id, 10);
                if (! $lock->get()) {
                    return;
                }
                try {
                    $upload = WorkspaceAssetUpload::query()->forTenantId((int) $upload->tenant_id)->find($upload->id);
                    if (! $upload || ! in_array($upload->status, ['initialized', 'failed', 'canceled', 'completed', 'completing'], true) || $upload->expires_at->isFuture()) {
                        return;
                    }
                    if (! $this->deleteUploadFiles($upload, $upload->status === 'completed')) {
                        return;
                    }
                    $upload->delete();
                    $pruned++;
                } finally {
                    $lock->release();
                }
            });

        return $pruned;
    }

    /** @return array{replayed:bool} */
    public function cancelUpload(Tenant $tenant, User $user, int $uploadId, string $token): array
    {
        $upload = $this->uploadForToken($tenant, $user, $uploadId, $token);

        return $this->withUploadLock($upload->id, 30, function () use ($tenant, $user, $uploadId, $token): array {
            $upload = $this->uploadForToken($tenant, $user, $uploadId, $token);
            if ($upload->status === 'canceled') {
                return ['replayed' => true];
            }
            abort_unless(in_array($upload->status, ['initialized', 'failed'], true), 409, 'An upload being completed or already completed cannot be canceled.');
            abort_unless($this->deleteUploadFiles($upload, false), 503, 'The upload could not be canceled because its staged files could not be removed.');
            $upload->forceFill(['status' => 'canceled'])->save();

            return ['replayed' => false];
        });
    }

    protected function authorizedUpload(Tenant $tenant, User $user, int $uploadId, string $token): WorkspaceAssetUpload
    {
        $upload = $this->uploadForToken($tenant, $user, $uploadId, $token);
        $this->authorizeUploadRecord($tenant, $user, $upload);

        return $upload;
    }

    protected function uploadForToken(Tenant $tenant, User $user, int $uploadId, string $token): WorkspaceAssetUpload
    {
        $upload = WorkspaceAssetUpload::query()->forTenantId((int) $tenant->id)->findOrFail($uploadId);
        abort_unless(hash_equals((string) $upload->token_hash, hash('sha256', $token)), 404);
        abort_unless((int) $upload->uploaded_by_user_id === (int) $user->id, 404);

        return $upload;
    }

    protected function authorizeUploadRecord(Tenant $tenant, User $user, WorkspaceAssetUpload $upload): void
    {
        if ($upload->field_service_job_id) {
            $job = FieldServiceJob::query()->forTenantId((int) $tenant->id)->find($upload->field_service_job_id);
            abort_unless($job && $this->access->canUpdateProgress($user, $tenant, $job), 403);
        }
    }

    protected function chunkPath(WorkspaceAssetUpload $upload, int $index): string
    {
        return dirname($upload->storage_path).'/chunks/'.$index.'.part';
    }

    protected function finalUploadPath(WorkspaceAssetUpload $upload): string
    {
        $extension = $upload->mime_type === 'application/pdf' ? 'pdf' : strtolower(pathinfo($upload->file_name, PATHINFO_EXTENSION));

        return 'workspace-assets/'.$upload->tenant_id.'/resumable-'.$upload->id.($extension !== '' ? '.'.$extension : '');
    }

    protected function receivedChunkBytes(WorkspaceAssetUpload $upload): int
    {
        $storage = Storage::disk($upload->storage_disk);
        $received = 0;
        $index = 0;
        while ($received < (int) $upload->max_file_size && $storage->exists($this->chunkPath($upload, $index))) {
            $size = (int) $storage->size($this->chunkPath($upload, $index));
            $expected = min(self::CHUNK_SIZE, (int) $upload->max_file_size - $received);
            abort_unless($size === $expected, 422, 'A stored upload chunk has an invalid size.');
            $received += $size;
            $index++;
        }

        return $received;
    }

    protected function writeLocalFileAtomically(mixed $storage, string $path, string $contents): void
    {
        $storage->makeDirectory(dirname($path));
        $absolutePath = $storage->path($path);
        $temporaryPath = $absolutePath.'.'.Str::random(16).'.tmp';
        $stream = fopen($temporaryPath, 'xb');
        abort_unless(is_resource($stream), 503, 'The upload chunk could not be staged.');
        $promoted = false;
        try {
            $written = 0;
            while ($written < strlen($contents)) {
                $count = fwrite($stream, substr($contents, $written));
                abort_unless(is_int($count) && $count > 0, 503, 'The upload chunk could not be stored.');
                $written += $count;
            }
            abort_unless(fflush($stream), 503, 'The upload chunk could not be finalized.');
            if (function_exists('fsync')) {
                abort_unless(fsync($stream), 503, 'The upload chunk could not be finalized.');
            }
            fclose($stream);
            $stream = null;
            abort_unless(rename($temporaryPath, $absolutePath), 503, 'The upload chunk could not be promoted.');
            $promoted = true;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (! $promoted && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    protected function assembleLocalChunks(WorkspaceAssetUpload $upload): void
    {
        $storage = Storage::disk($upload->storage_disk);
        $contents = '';
        $assembledPath = $storage->path($upload->storage_path);
        $storage->makeDirectory(dirname($upload->storage_path));
        $temporaryPath = $assembledPath.'.'.Str::random(16).'.tmp';
        $output = fopen($temporaryPath, 'xb');
        abort_unless(is_resource($output), 503, 'The PDF could not be assembled.');
        $promoted = false;
        try {
            $chunkCount = (int) ceil((int) $upload->max_file_size / self::CHUNK_SIZE);
            foreach (range(0, $chunkCount - 1) as $index) {
                $input = $storage->readStream($this->chunkPath($upload, $index));
                abort_unless(is_resource($input), 422, 'An upload chunk is missing.');
                try {
                    while (! feof($input)) {
                        $contents = fread($input, self::CHUNK_SIZE);
                        abort_unless(is_string($contents) && ($contents !== '' || feof($input)), 503, 'An upload chunk could not be read.');
                        if ($contents === '') {
                            continue;
                        }
                        $written = 0;
                        while ($written < strlen($contents)) {
                            $count = fwrite($output, substr($contents, $written));
                            abort_unless(is_int($count) && $count > 0, 503, 'The PDF could not be assembled.');
                            $written += $count;
                        }
                    }
                } finally {
                    fclose($input);
                }
            }
            abort_unless(fflush($output), 503, 'The PDF could not be finalized.');
            if (function_exists('fsync')) {
                abort_unless(fsync($output), 503, 'The PDF could not be finalized.');
            }
            fclose($output);
            $output = null;
            abort_unless(rename($temporaryPath, $assembledPath), 503, 'The PDF could not be promoted from staging.');
            $promoted = true;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (! $promoted && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    protected function deleteUploadStaging(WorkspaceAssetUpload $upload): bool
    {
        if (! $this->isLocalStagingUpload($upload)) {
            return true;
        }

        try {
            $storage = Storage::disk($upload->storage_disk);
            $directory = dirname($upload->storage_path);
            if (! $storage->directoryExists($directory)) {
                return true;
            }

            $deleted = $storage->deleteDirectory($directory) && ! $storage->directoryExists($directory);
        } catch (Throwable $exception) {
            $this->reportUploadCleanupFailure($upload, $exception);

            return false;
        }
        if (! $deleted) {
            $this->reportUploadCleanupFailure($upload);
        }

        return $deleted;
    }

    protected function deleteUploadFiles(WorkspaceAssetUpload $upload, bool $preserveCompletedFile): bool
    {
        try {
            $storage = Storage::disk($upload->storage_disk);
            if ($this->isLocalStagingUpload($upload)) {
                if (! $this->deleteUploadStaging($upload)) {
                    return false;
                }
                $finalPath = $this->finalUploadPath($upload);
                if (! $preserveCompletedFile && $storage->exists($finalPath)) {
                    $deleted = $storage->delete($finalPath) && ! $storage->exists($finalPath);
                    if (! $deleted) {
                        $this->reportUploadCleanupFailure($upload);
                    }

                    return $deleted;
                }

                return true;
            }

            if ($preserveCompletedFile || ! $storage->exists($upload->storage_path)) {
                return true;
            }

            $deleted = $storage->delete($upload->storage_path) && ! $storage->exists($upload->storage_path);
        } catch (Throwable $exception) {
            $this->reportUploadCleanupFailure($upload, $exception);

            return false;
        }
        if (! $deleted) {
            $this->reportUploadCleanupFailure($upload);
        }

        return $deleted;
    }

    protected function isLocalStagingUpload(WorkspaceAssetUpload $upload): bool
    {
        if ($upload->storage_disk !== 'local') {
            return false;
        }

        return preg_match(
            '#^workspace-upload-staging/'.preg_quote((string) $upload->tenant_id, '#').'/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/assembled\.part$#i',
            (string) $upload->storage_path,
        ) === 1;
    }

    protected function reportUploadCleanupFailure(WorkspaceAssetUpload $upload, ?Throwable $exception = null): void
    {
        Log::warning('workspace_asset_upload.cleanup_failed', [
            'upload_id' => (int) $upload->id,
            'tenant_id' => (int) $upload->tenant_id,
            'storage_disk' => (string) $upload->storage_disk,
            'status' => (string) $upload->status,
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    protected function withUploadLock(int $uploadId, int $seconds, callable $callback): mixed
    {
        try {
            return Cache::lock('workspace-asset-upload:'.$uploadId, $seconds)->block(10, $callback);
        } catch (LockTimeoutException) {
            abort(409, 'This upload is already being updated. Retry shortly.');
        }
    }

    protected function completedUploadAsset(Tenant $tenant, WorkspaceAssetUpload $upload): ?WorkspaceAsset
    {
        $source = $upload->storage_disk === 'local' ? 'resumable_upload' : 'signed_upload';

        return WorkspaceAsset::query()
            ->forTenantId((int) $tenant->id)
            ->where('source', $source)
            ->where('external_id', (string) $upload->id)
            ->first();
    }

    protected function byteLimitForMime(string $mime): int
    {
        return $mime === 'application/pdf' ? $this->maxUploadBytes() : 25 * 1024 * 1024;
    }

    protected function activeUploadReservationBytes(): int
    {
        return (int) config('filesystems.workspace_asset_active_upload_reservation_mb', 500) * 1024 * 1024;
    }

    protected function initializationToken(Tenant $tenant, User $user, ?string $idempotencyKey): string
    {
        if ($idempotencyKey === null) {
            return Str::random(64);
        }

        $secret = (string) config('app.key');
        abort_if($secret === '', 500, 'The upload idempotency service is not configured.');

        return hash_hmac('sha256', implode('|', [
            'workspace-asset-upload-v1',
            (string) $tenant->id,
            (string) $user->id,
            strtolower($idempotencyKey),
        ]), $secret);
    }

    protected function assertValidPdfBytes(string $bytes): void
    {
        $path = tempnam(sys_get_temp_dir(), 'everbranch-pdf-validate-');
        abort_unless(is_string($path), 503, 'The PDF could not be prepared for validation.');
        try {
            abort_unless(file_put_contents($path, $bytes, LOCK_EX) === strlen($bytes), 503, 'The PDF could not be prepared for validation.');
            $this->assertValidPdfPath($path);
        } finally {
            @unlink($path);
        }
    }

    protected function assertValidPdfPath(string $path): void
    {
        $stream = @fopen($path, 'rb');
        abort_unless(is_resource($stream), 503, 'The PDF could not be read for validation.');
        try {
            $size = filesize($path);
            abort_unless(is_int($size) && $size > 0, 422, 'The uploaded file is not a valid PDF document.');
            $prefix = fread($stream, min(self::PDF_EDGE_BYTES, $size));
            abort_unless(is_string($prefix), 503, 'The PDF could not be read for validation.');
            abort_unless(fseek($stream, max(0, $size - self::PDF_EDGE_BYTES)) === 0, 503, 'The PDF could not be read for validation.');
            $suffix = stream_get_contents($stream);
            abort_unless(is_string($suffix), 503, 'The PDF could not be read for validation.');

            preg_match('/%PDF-(?:1\.[0-7]|2\.0)/', substr($prefix, 0, 1024), $header, PREG_OFFSET_CAPTURE);
            abort_unless(isset($header[0][1]), 422, 'The uploaded file is not a valid PDF document.');
            $lastEof = strrpos($suffix, '%%EOF');
            abort_unless($lastEof !== false, 422, 'The uploaded file is not a valid PDF document.');
            $tailThroughEof = substr($suffix, 0, $lastEof + strlen('%%EOF'));
            preg_match_all('/startxref[\x00\x09\x0A\x0C\x0D\x20]+([0-9]+)/', $tailThroughEof, $startXrefs);
            $offsets = $startXrefs[1] ?? [];
            abort_unless($offsets !== [], 422, 'The uploaded file is not a valid PDF document.');
            $xrefOffset = (int) end($offsets);
            abort_unless($xrefOffset >= 0 && $xrefOffset < $size, 422, 'The uploaded file is not a valid PDF document.');

            $windowStart = max(0, $xrefOffset - 32);
            abort_unless(fseek($stream, $windowStart) === 0, 503, 'The PDF could not be read for validation.');
            $xrefWindow = fread($stream, 8192);
            abort_unless(is_string($xrefWindow), 503, 'The PDF could not be read for validation.');
            $xrefTarget = ltrim(substr($xrefWindow, $xrefOffset - $windowStart), "\x00\x09\x0A\x0C\x0D\x20");
            $traditionalXref = preg_match('/^xref(?:[\x00\x09\x0A\x0C\x0D\x20]|$)/', $xrefTarget) === 1;
            $xrefStream = preg_match('/^[0-9]+[\x00\x09\x0A\x0C\x0D\x20]+[0-9]+[\x00\x09\x0A\x0C\x0D\x20]+obj\b/', $xrefTarget) === 1
                && preg_match('#/Type[\x00\x09\x0A\x0C\x0D\x20]*/XRef\b#', substr($xrefTarget, 0, 8192)) === 1;
            abort_unless($traditionalXref || $xrefStream, 422, 'The uploaded file is not a valid PDF document.');
            if ($traditionalXref) {
                $trailerOffset = strrpos($tailThroughEof, 'trailer');
                abort_unless($trailerOffset !== false, 422, 'The uploaded file is not a valid PDF document.');
                $trailer = substr($tailThroughEof, $trailerOffset);
            } else {
                $trailer = substr($xrefTarget, 0, 8192);
            }
            abort_unless(preg_match('#/Root[\x00\x09\x0A\x0C\x0D\x20]+[0-9]+[\x00\x09\x0A\x0C\x0D\x20]+[0-9]+[\x00\x09\x0A\x0C\x0D\x20]+R\b#', $trailer) === 1, 422, 'The uploaded file is not a valid PDF document.');

            rewind($stream);
            $hasObject = false;
            $carry = '';
            while (! feof($stream) && ! $hasObject) {
                $chunk = fread($stream, 1024 * 1024);
                abort_unless(is_string($chunk) && ($chunk !== '' || feof($stream)), 503, 'The PDF could not be read for validation.');
                $scan = $carry.$chunk;
                $hasObject = preg_match('/(?:^|[\x00\x09\x0A\x0C\x0D\x20])[0-9]+[\x00\x09\x0A\x0C\x0D\x20]+[0-9]+[\x00\x09\x0A\x0C\x0D\x20]+obj\b/', $scan) === 1;
                $carry = substr($scan, -128);
            }
            abort_unless($hasObject, 422, 'The uploaded file is not a valid PDF document.');
        } finally {
            fclose($stream);
        }

        $validator = trim((string) config('filesystems.workspace_asset_pdf_validator_binary'));
        if ($validator === '' || ! is_executable($validator)) {
            return;
        }

        $process = new Process([
            $validator,
            '-q',
            '-dSAFER',
            '-dBATCH',
            '-dNOPAUSE',
            '-dPDFSTOPONERROR',
            '-dNumRenderingThreads=1',
            '-sDEVICE=nullpage',
            '-o',
            '/dev/null',
            $path,
        ]);
        $process->disableOutput();
        $process->setTimeout((float) config('filesystems.workspace_asset_pdf_validator_timeout_seconds', 120));
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            abort(503, 'PDF validation timed out. Retry the upload.');
        } catch (Throwable) {
            abort(503, 'PDF validation is temporarily unavailable. Retry the upload.');
        }
        abort_unless($process->isSuccessful(), 422, 'The uploaded file is not a valid PDF document.');
    }

    /** @param resource|false $stream @return array{0:string,1:string,2:string} */
    protected function streamChecksumAndEdges(mixed $stream, ?string $copyPath = null): array
    {
        abort_unless(is_resource($stream), 503, 'The uploaded object could not be read.');
        $hash = hash_init('sha256');
        $prefix = '';
        $suffix = '';
        $copy = $copyPath !== null ? @fopen($copyPath, 'wb') : null;
        abort_unless($copyPath === null || is_resource($copy), 503, 'The PDF could not be prepared for validation.');
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                abort_unless(is_string($chunk) && ($chunk !== '' || feof($stream)), 503, 'The uploaded object could not be read.');
                if ($chunk === '') {
                    continue;
                }
                hash_update($hash, $chunk);
                if (is_resource($copy)) {
                    $written = 0;
                    while ($written < strlen($chunk)) {
                        $count = fwrite($copy, substr($chunk, $written));
                        abort_unless(is_int($count) && $count > 0, 503, 'The PDF could not be prepared for validation.');
                        $written += $count;
                    }
                }
                if (strlen($prefix) < 8192) {
                    $prefix .= substr($chunk, 0, 8192 - strlen($prefix));
                }
                $suffix = substr($suffix.$chunk, -8192);
            }
            if (is_resource($copy)) {
                abort_unless(fflush($copy), 503, 'The PDF could not be prepared for validation.');
            }
        } finally {
            fclose($stream);
            if (is_resource($copy)) {
                fclose($copy);
            }
        }

        return [hash_final($hash), $prefix, $suffix];
    }

    /** @return array{0:?string,1:?string} */
    protected function storeThumbnail(string $disk, string $path, string $bytes, string $mime): array
    {
        if (! in_array($mime, ['image/jpeg', 'image/png'], true) || ! function_exists('imagecreatefromstring')) {
            return [null, null];
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return [null, null];
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, 480 / max($width, $height));
        $thumb = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb), $width, $height);
        ob_start();
        imagejpeg($thumb, null, 82);
        $thumbnail = (string) ob_get_clean();
        imagedestroy($thumb);
        imagedestroy($source);
        if ($thumbnail === '') {
            return [null, null];
        }
        $thumbnailPath = preg_replace('/\.[^.]+$/', '', $path).'-thumb.jpg';

        return Storage::disk($disk)->put($thumbnailPath, $thumbnail, ['visibility' => 'private']) ? [$disk, $thumbnailPath] : [null, null];
    }
}
