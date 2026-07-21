<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceFinancialDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAsset;
use App\Models\WorkspaceAssetUpload;
use App\Services\Integrations\QuickBooks\QuickBooksOnlineClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkspaceAssetService
{
    public function __construct(protected WorkspaceAssetAuditService $audit) {}

    /** @var array<int,string> */
    protected array $allowedMimes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/tiff', 'image/heic', 'image/heif', 'application/pdf', 'text/plain', 'text/csv',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** @param array<int,int> $jobIds */
    public function storeUpload(Tenant $tenant, User $user, UploadedFile $file, array $jobIds, string $visibility, ?string $caption = null, array $tags = []): WorkspaceAsset
    {
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        abort_unless(in_array($mime, $this->allowedMimes, true), 422, 'This file type is not supported.');
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
        );
    }

    /** @param array<string,mixed> $attachable */
    public function importQuickBooksAttachable(Tenant $tenant, QuickBooksOnlineClient $client, array $attachable): ?WorkspaceAsset
    {
        $externalId = trim((string) ($attachable['Id'] ?? ''));
        $fileName = trim((string) ($attachable['FileName'] ?? ''));
        $mime = trim((string) ($attachable['ContentType'] ?? ''));
        $size = (int) ($attachable['Size'] ?? 0);
        if ($externalId === '' || $fileName === '' || ! in_array($mime, $this->allowedMimes, true) || $size > 25 * 1024 * 1024) {
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
        if ($bytes === '' || strlen($bytes) > 25 * 1024 * 1024) {
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
            'User-Agent' => 'Everbranch/2.0 (+https://theeverbranch.com; support@theeverbranch.com)',
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

    /** @return array{upload:WorkspaceAssetUpload,token:string,url:string,headers:array<string,string>} */
    public function initializeSignedUpload(Tenant $tenant, User $user, string $fileName, string $mime, int $fileSize, ?int $jobId, string $visibility, ?string $caption): array
    {
        abort_unless(in_array($mime, $this->allowedMimes, true), 422, 'This file type is not supported.');
        abort_if($fileSize < 1 || $fileSize > 25 * 1024 * 1024, 422, 'Files must be 25 MB or smaller.');
        $disk = (string) config('filesystems.workspace_asset_disk', 'local');
        abort_unless($disk !== 'local', 409, 'Direct object uploads are not enabled. Use the standard upload endpoint.');
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $path = 'workspace-assets/'.$tenant->id.'/'.Str::uuid().($extension ? '.'.$extension : '');
        $token = Str::random(64);
        $upload = WorkspaceAssetUpload::query()->create([
            'tenant_id' => (int) $tenant->id, 'uploaded_by_user_id' => (int) $user->id, 'field_service_job_id' => $jobId,
            'token_hash' => hash('sha256', $token), 'storage_disk' => $disk, 'storage_path' => $path,
            'file_name' => Str::limit(basename($fileName), 255, ''), 'mime_type' => $mime, 'max_file_size' => $fileSize,
            'visibility' => $visibility === 'owner' ? 'owner' : 'team', 'caption' => $caption ? Str::limit(trim($caption), 255, '') : null,
            'status' => 'initialized', 'expires_at' => now()->addMinutes(15),
        ]);
        try {
            $signed = Storage::disk($disk)->temporaryUploadUrl($path, now()->addMinutes(15), ['ContentType' => $mime]);
        } catch (\Throwable $exception) {
            $upload->forceFill(['status' => 'failed'])->save();
            abort(503, 'Object storage could not initialize this upload.');
        }

        return ['upload' => $upload, 'token' => $token, 'url' => $signed['url'], 'headers' => (array) ($signed['headers'] ?? [])];
    }

    public function completeSignedUpload(Tenant $tenant, User $user, string $token): WorkspaceAsset
    {
        $upload = WorkspaceAssetUpload::query()->forTenantId((int) $tenant->id)->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless((int) $upload->uploaded_by_user_id === (int) $user->id && $upload->status === 'initialized' && $upload->expires_at->isFuture(), 409, 'This upload is expired or already completed.');
        $storage = Storage::disk($upload->storage_disk);
        abort_unless($storage->exists($upload->storage_path), 422, 'The uploaded object was not found.');
        $size = (int) $storage->size($upload->storage_path);
        abort_if($size < 1 || $size > (int) $upload->max_file_size || $size > 25 * 1024 * 1024, 422, 'The uploaded object does not match its declared size.');
        $asset = WorkspaceAsset::query()->create([
            'tenant_id' => (int) $tenant->id, 'uploaded_by_user_id' => (int) $user->id, 'source' => 'signed_upload',
            'visibility' => $upload->visibility, 'storage_disk' => $upload->storage_disk, 'storage_path' => $upload->storage_path,
            'file_name' => $upload->file_name, 'mime_type' => $upload->mime_type, 'file_size' => $size, 'caption' => $upload->caption,
            'search_text' => trim($upload->file_name.' '.$upload->caption), 'metadata' => ['upload_id' => (int) $upload->id],
        ]);
        if ($upload->field_service_job_id) {
            $asset->jobs()->sync([(int) $upload->field_service_job_id => ['tenant_id' => (int) $tenant->id, 'linked_by_user_id' => (int) $user->id]]);
        }
        $upload->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->audit->record($tenant, $asset, $user, 'uploaded', ['surface' => 'signed_object_upload', 'job_ids' => array_filter([$upload->field_service_job_id])]);

        return $asset;
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
