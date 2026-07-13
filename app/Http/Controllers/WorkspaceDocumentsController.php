<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\WorkspaceAsset;
use App\Services\FieldService\WorkspaceAssetAuditService;
use App\Services\FieldService\WorkspaceAssetService;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceDocumentsController extends Controller
{
    public function index(Request $request, Tenant $tenant, TenantFinancialAccess $financialAccess): View
    {
        $owner = $financialAccess->allows($request->user(), $tenant);
        $query = trim((string) $request->query('q'));
        $assets = WorkspaceAsset::query()->forTenantId((int) $tenant->id)
            ->with('jobs:id,title')
            ->when(! $owner, fn ($builder) => $builder->where('visibility', 'team'))
            ->when($query !== '', fn ($builder) => $builder->where(function ($search) use ($query): void {
                $like = '%'.$query.'%';
                $search->where('file_name', 'like', $like)->orWhere('caption', 'like', $like)
                    ->orWhere('search_text', 'like', $like)
                    ->orWhereHas('jobs', fn ($jobs) => $jobs->where('title', 'like', $like));
            }))
            ->latest()->paginate(30)->withQueryString();

        return view('documents.index', [
            'tenant' => $tenant,
            'assets' => $assets,
            'jobs' => $tenant->fieldServiceJobs()->latest()->limit(100)->get(['id', 'title']),
            'canViewOwner' => $owner,
            'query' => $query,
        ]);
    }

    public function store(Request $request, Tenant $tenant, WorkspaceAssetService $assets, TenantFinancialAccess $financialAccess): RedirectResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file', 'max:25600'],
            'job_ids' => ['nullable', 'array'],
            'job_ids.*' => ['integer'],
            'caption' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['nullable', 'in:team,owner'],
        ]);
        $visibility = (string) ($validated['visibility'] ?? 'team');
        if ($visibility === 'owner') {
            abort_unless($financialAccess->allows($request->user(), $tenant), 403);
        }
        foreach ($request->file('files', []) as $file) {
            $assets->storeUpload(
                $tenant,
                $request->user(),
                $file,
                (array) ($validated['job_ids'] ?? []),
                $visibility,
                $validated['caption'] ?? null,
                preg_split('/[,\n]+/', (string) ($validated['tags'] ?? '')) ?: [],
            );
        }

        return back()->with('status', 'Documents uploaded.');
    }

    public function download(Request $request, Tenant $tenant, WorkspaceAsset $asset, TenantFinancialAccess $financialAccess, WorkspaceAssetAuditService $audit): StreamedResponse
    {
        $this->authorizeAsset($request, $tenant, $asset, $financialAccess);
        abort_unless(Storage::disk($asset->storage_disk)->exists($asset->storage_path), 404);
        $audit->record($tenant, $asset, $request->user(), 'downloaded');

        return Storage::disk($asset->storage_disk)->download($asset->storage_path, $asset->file_name, [
            'Content-Type' => $asset->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function updateLinks(Request $request, Tenant $tenant, WorkspaceAsset $asset, TenantFinancialAccess $financialAccess, WorkspaceAssetAuditService $audit): RedirectResponse
    {
        $this->authorizeAsset($request, $tenant, $asset, $financialAccess);
        $validated = $request->validate(['job_ids' => ['nullable', 'array'], 'job_ids.*' => ['integer']]);
        $ids = $tenant->fieldServiceJobs()->whereIn('id', (array) ($validated['job_ids'] ?? []))->pluck('id')->map(fn ($id): int => (int) $id);
        $asset->jobs()->sync($ids->mapWithKeys(fn (int $id): array => [$id => [
            'tenant_id' => (int) $tenant->id,
            'linked_by_user_id' => (int) $request->user()->id,
        ]])->all());
        $audit->record($tenant, $asset, $request->user(), 'job_links_updated', ['job_ids' => $ids->all()]);

        return back()->with('status', 'Document job links updated.');
    }

    public function destroy(Request $request, Tenant $tenant, WorkspaceAsset $asset, TenantFinancialAccess $financialAccess, WorkspaceAssetAuditService $audit): RedirectResponse
    {
        abort_unless((int) $asset->tenant_id === (int) $tenant->id, 404);
        $owner = $financialAccess->allows($request->user(), $tenant);
        abort_unless($owner || (int) $asset->uploaded_by_user_id === (int) $request->user()->id, 403);
        $audit->record($tenant, $asset, $request->user(), 'deleted', ['checksum' => $asset->checksum, 'file_name' => $asset->file_name]);
        Storage::disk($asset->storage_disk)->delete($asset->storage_path);
        $asset->delete();

        return back()->with('status', 'Document deleted.');
    }

    protected function authorizeAsset(Request $request, Tenant $tenant, WorkspaceAsset $asset, TenantFinancialAccess $access): void
    {
        abort_unless((int) $asset->tenant_id === (int) $tenant->id, 404);
        abort_if($asset->visibility === 'owner' && ! $access->allows($request->user(), $tenant), 403);
    }
}
