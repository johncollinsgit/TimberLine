<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\WorkspaceAssetService;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EverbranchMobileAssetUploadController extends Controller
{
    public function initialize(Request $request, WorkspaceAssetService $assets, FieldServiceAccessService $access, TenantFinancialAccess $financial): JsonResponse
    {
        $validated = $request->validate(['file_name' => ['required', 'string', 'max:255'], 'mime_type' => ['required', 'string', 'max:160'], 'file_size' => ['required', 'integer', 'min:1', 'max:'.$assets->maxUploadBytes()], 'job_id' => ['nullable', 'integer'], 'visibility' => ['nullable', 'in:team,owner'], 'caption' => ['nullable', 'string', 'max:255']]);
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        $jobId = null;
        if (filled($validated['job_id'] ?? null)) {
            $job = FieldServiceJob::query()->forTenantId((int) $tenant->id)->findOrFail((int) $validated['job_id']);
            abort_unless($access->canUpdateProgress($user, $tenant, $job), 403);
            $jobId = (int) $job->id;
        }
        $visibility = (string) ($validated['visibility'] ?? 'team');
        abort_if($visibility === 'owner' && ! $financial->allows($user, $tenant), 403);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        abort_if($idempotencyKey !== '' && ! Str::isUuid($idempotencyKey), 422, 'The upload idempotency key must be a UUID.');
        $result = $assets->initializeSignedUpload($tenant, $user, $validated['file_name'], $validated['mime_type'], (int) $validated['file_size'], $jobId, $visibility, $validated['caption'] ?? null, $idempotencyKey ?: null);

        return response()->json([
            'upload_id' => (int) $result['upload']->id,
            'token' => $result['token'],
            'mode' => $result['mode'],
            'chunk_size' => $result['chunk_size'],
            'max_file_size' => $result['max_file_size'],
            'url' => $result['url'],
            'headers' => $result['headers'],
            'expires_at' => $result['upload']->expires_at?->toIso8601String(),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    public function chunk(Request $request, string $tenant, int $uploadId, int $index, WorkspaceAssetService $assets): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'offset' => ['required', 'integer', 'min:0'],
            'contents_base64' => ['required', 'string', 'max:700000'],
            'checksum_sha256' => ['required', 'string', 'size:64', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);
        $result = $assets->storeChunk(
            $this->tenant($request),
            $this->user($request),
            $uploadId,
            $index,
            (int) $validated['offset'],
            $validated['token'],
            $validated['contents_base64'],
            strtolower($validated['checksum_sha256']),
        );

        return response()->json([
            'ok' => true,
            'upload_id' => (int) $result['upload']->id,
            'chunk_index' => $index,
            'received_bytes' => $result['received_bytes'],
            'next_offset' => $result['next_offset'],
            'replayed' => $result['replayed'],
        ]);
    }

    public function complete(Request $request, WorkspaceAssetService $assets): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'checksum_sha256' => ['nullable', 'string', 'size:64', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);
        $result = $assets->completeSignedUpload(
            $this->tenant($request),
            $this->user($request),
            $validated['token'],
            isset($validated['checksum_sha256']) ? strtolower($validated['checksum_sha256']) : null,
        );

        return response()->json(['ok' => true, 'asset_id' => (int) $result['asset']->id, 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function cancel(Request $request, string $tenant, int $uploadId, WorkspaceAssetService $assets): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $result = $assets->cancelUpload($this->tenant($request), $this->user($request), $uploadId, $validated['token']);

        return response()->json(['ok' => true, 'upload_id' => $uploadId, 'replayed' => $result['replayed']]);
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active !== false, 401);

        return $user;
    }
}
