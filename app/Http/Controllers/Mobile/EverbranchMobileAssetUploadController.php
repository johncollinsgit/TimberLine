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

class EverbranchMobileAssetUploadController extends Controller
{
    public function initialize(Request $request, WorkspaceAssetService $assets, FieldServiceAccessService $access, TenantFinancialAccess $financial): JsonResponse
    {
        $validated = $request->validate(['file_name' => ['required', 'string', 'max:255'], 'mime_type' => ['required', 'string', 'max:160'], 'file_size' => ['required', 'integer', 'min:1', 'max:26214400'], 'job_id' => ['nullable', 'integer'], 'visibility' => ['nullable', 'in:team,owner'], 'caption' => ['nullable', 'string', 'max:255']]);
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        $jobId = null;
        if (filled($validated['job_id'] ?? null)) {
            $job = FieldServiceJob::query()->forTenantId((int) $tenant->id)->findOrFail((int) $validated['job_id']);
            abort_unless($access->canAccessJob($user, $tenant, $job), 404);
            $jobId = (int) $job->id;
        }
        $visibility = (string) ($validated['visibility'] ?? 'team');
        abort_if($visibility === 'owner' && ! $financial->allows($user, $tenant), 403);
        $result = $assets->initializeSignedUpload($tenant, $user, $validated['file_name'], $validated['mime_type'], (int) $validated['file_size'], $jobId, $visibility, $validated['caption'] ?? null);

        return response()->json(['upload_id' => (int) $result['upload']->id, 'token' => $result['token'], 'url' => $result['url'], 'headers' => $result['headers'], 'expires_at' => $result['upload']->expires_at?->toIso8601String()], 201);
    }

    public function complete(Request $request, WorkspaceAssetService $assets): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $asset = $assets->completeSignedUpload($this->tenant($request), $this->user($request), $validated['token']);

        return response()->json(['ok' => true, 'asset_id' => (int) $asset->id], 201);
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
