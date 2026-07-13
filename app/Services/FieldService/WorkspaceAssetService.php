<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceFinancialDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAsset;
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
        array $tags = []
    ): WorkspaceAsset {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $safeName = Str::uuid().($extension !== '' ? '.'.$extension : '');
        $path = 'workspace-assets/'.$tenant->id.'/'.$safeName;
        Storage::disk('local')->put($path, $bytes);
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
            'storage_disk' => 'local',
            'storage_path' => $path,
            'file_name' => Str::limit(basename($fileName), 255, ''),
            'mime_type' => $mime,
            'file_size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'caption' => $caption ? Str::limit(trim($caption), 255, '') : null,
            'tags' => $tags,
            'search_text' => trim(implode(' ', array_filter([$fileName, $caption, implode(' ', $tags), $extracted]))),
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
}
