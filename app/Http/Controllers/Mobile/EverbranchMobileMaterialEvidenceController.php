<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceMaterial;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAsset;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\WorkspaceAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

class EverbranchMobileMaterialEvidenceController extends Controller
{
    public function storeComment(Request $request, string $tenant, FieldServiceJob $job, FieldServiceMaterial $material, FieldServiceAccessService $access): JsonResponse
    {
        [$tenantModel, $user] = $this->authorizeMaterial($request, $job, $material, $access);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $body = trim((string) $validated['body']);
        abort_if($body === '', 422, 'Write a comment before posting.');

        $comment = FieldServiceJobNote::query()->create([
            'tenant_id' => $tenantModel->id,
            'field_service_job_id' => $job->id,
            'created_by_user_id' => $user->id,
            'body' => $body,
            'noted_at' => now(),
            'metadata' => ['source' => 'material_comment', 'field_service_material_id' => $material->id],
        ]);

        return response()->json(['ok' => true, 'comment' => [
            'id' => (int) $comment->id,
            'body' => $body,
            'created_by' => $user->name,
            'created_at' => $comment->created_at?->toIso8601String(),
        ]], 201);
    }

    public function storePhotos(Request $request, string $tenant, FieldServiceJob $job, FieldServiceMaterial $material, FieldServiceAccessService $access, WorkspaceAssetService $assets): JsonResponse
    {
        [$tenantModel, $user] = $this->authorizeMaterial($request, $job, $material, $access);
        $request->validate([
            'photos' => ['required', 'array', 'size:1'],
            'photos.*' => ['required', 'image', 'max:25600'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);
        $caption = $request->string('caption')->toString();
        $result = $this->idempotentUpload($request, $tenantModel, $user, $job, $material, fn (): array => collect($request->file('photos', []))
            ->map(fn (UploadedFile $photo): array => $this->assetPayload($assets->storeUpload(
                $tenantModel, $user, $photo, [(int) $job->id], 'team', $caption, ['material-photo'],
                ['field_service_material_id' => (int) $material->id],
            ), $tenantModel))->values()->all());

        return response()->json(['ok' => true, 'photos' => $result['photos'], 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function storePhotoPayload(Request $request, string $tenant, FieldServiceJob $job, FieldServiceMaterial $material, FieldServiceAccessService $access, WorkspaceAssetService $assets): JsonResponse
    {
        [$tenantModel, $user] = $this->authorizeMaterial($request, $job, $material, $access);
        $single = ! $request->has('photos');
        $rules = [
            'file_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'in:image/jpeg,image/png,image/webp'],
            'contents_base64' => ['required', 'string', 'max:1500000'],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
        $validated = $single
            ? ['photos' => [$request->validate($rules)]]
            : $request->validate([
                'photos' => ['required', 'array', 'size:1'],
                ...collect($rules)->mapWithKeys(fn (array $rule, string $field): array => ['photos.*.'.$field => $rule])->all(),
            ]);

        $result = $this->idempotentUpload($request, $tenantModel, $user, $job, $material, function () use ($validated, $tenantModel, $user, $job, $material, $assets): array {
            return collect($validated['photos'])->map(function (array $payload) use ($tenantModel, $user, $job, $material, $assets): array {
                $contents = base64_decode((string) $payload['contents_base64'], true);
                abort_unless(is_string($contents) && $contents !== '' && strlen($contents) <= 1024 * 1024, 422, 'The phone photo is invalid or too large.');
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
                abort_unless($mime === $payload['mime_type'], 422, 'The photo file type does not match its contents.');
                $path = tempnam(storage_path('framework/cache'), 'eb-material-photo-');
                abort_unless(is_string($path), 500, 'Everbranch could not prepare this phone photo.');
                file_put_contents($path, $contents);

                try {
                    $photo = new UploadedFile($path, basename((string) $payload['file_name']), $mime, null, true);

                    return $this->assetPayload($assets->storeUpload(
                        $tenantModel, $user, $photo, [(int) $job->id], 'team', $payload['caption'] ?? null,
                        ['material-photo', 'ios-payload-fallback'], ['field_service_material_id' => (int) $material->id],
                    ), $tenantModel);
                } finally {
                    @unlink($path);
                }
            })->values()->all();
        });

        return response()->json([
            'ok' => true,
            'photo' => $single ? ($result['photos'][0] ?? null) : null,
            'photos' => $result['photos'],
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    /** @return array{Tenant,User} */
    private function authorizeMaterial(Request $request, FieldServiceJob $job, FieldServiceMaterial $material, FieldServiceAccessService $access): array
    {
        $tenant = $request->attributes->get('current_tenant');
        $user = $request->user();
        abort_unless($tenant instanceof Tenant && $user instanceof User && $user->is_active !== false, 401);
        abort_unless((int) $job->tenant_id === (int) $tenant->id
            && (int) $material->tenant_id === (int) $tenant->id
            && (int) $material->field_service_job_id === (int) $job->id
            && $access->canAccessJob($user, $tenant, $job), 404);
        abort_unless($access->canUpdateProgress($user, $tenant, $job), 403);

        return [$tenant, $user];
    }

    /** @param callable():array<int,array<string,mixed>> $upload
     * @return array{photos:array<int,array<string,mixed>>,replayed:bool}
     */
    private function idempotentUpload(Request $request, Tenant $tenant, User $user, FieldServiceJob $job, FieldServiceMaterial $material, callable $upload): array
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        abort_if(strlen($key) > 200, 422, 'The Idempotency-Key header is too long.');
        if ($key === '') {
            return ['photos' => $upload(), 'replayed' => false];
        }
        $cacheKey = 'everbranch:mobile:material-photo:'.hash('sha256', implode('|', [$tenant->id, $user->id, $job->id, $material->id, $key]));
        if (is_array($cached = Cache::get($cacheKey))) {
            return ['photos' => $cached, 'replayed' => true];
        }

        return Cache::lock($cacheKey.':lock', 300)->block(30, function () use ($cacheKey, $upload): array {
            if (is_array($cached = Cache::get($cacheKey))) {
                return ['photos' => $cached, 'replayed' => true];
            }
            $photos = $upload();
            Cache::put($cacheKey, $photos, now()->addDay());

            return ['photos' => $photos, 'replayed' => false];
        });
    }

    /** @return array<string,mixed> */
    private function assetPayload(WorkspaceAsset $asset, Tenant $tenant): array
    {
        return [
            'id' => (int) $asset->id,
            'name' => $asset->file_name,
            'mime_type' => $asset->mime_type,
            'caption' => $asset->caption,
            'tags' => $asset->tags ?: [],
            'captured_at' => $asset->captured_at?->toIso8601String(),
            'url' => route('mobile.v1.workspace.field-service.assets.show', ['tenant' => $tenant->slug, 'asset' => $asset->id], false),
        ];
    }
}
