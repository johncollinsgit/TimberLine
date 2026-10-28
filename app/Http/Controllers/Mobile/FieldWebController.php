<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FieldWebController extends Controller
{
    public function materials(Request $request, FieldServiceAccessService $access)
    {
        [$tenant, $user] = $this->context($request);
        abort_unless(in_array($access->role($user, $tenant), ['admin', 'owner', 'tenant_owner'], true), 403);
        $data = $request->validate(['before_id' => ['nullable', 'integer', 'min:1']]);
        $jobs = $access->scopeVisibleJobs(FieldServiceJob::where('tenant_id', $tenant->id)->notGeneratedQuickBooksInvoice(), $user, $tenant);
        $query = \App\Models\FieldServiceMaterial::where('tenant_id', $tenant->id)->where('status', 'needed')
            ->whereIn('field_service_job_id', $jobs->select('field_service_jobs.id'))
            ->where(fn ($q) => $q->whereNotNull('requested_by_user_id')->orWhere(fn ($legacy) => $legacy->whereNull('external_source')->whereNull('field_material_catalog_item_id')));
        $total = (clone $query)->count();
        $rows = $query->when($data['before_id'] ?? null, fn ($q, $id) => $q->where('id', '<', $id))
            ->with(['requestedBy:id,name', 'job:id,tenant_id,title', 'job.assets' => fn ($q) => $q->where('visibility', 'team')->where('mime_type', 'like', 'image/%')->latest('id')->limit(1)])
            ->latest('id')->limit(51)->get();
        $more = $rows->count() > 50;
        $rows = $rows->take(50);
        $photos = \App\Models\WorkspaceAsset::where('tenant_id', $tenant->id)->where('visibility', 'team')->where('mime_type', 'like', 'image/%')
            ->whereIn('metadata->field_service_material_id', $rows->pluck('id'))->whereHas('jobs', fn ($q) => $q->whereIn('field_service_jobs.id', $rows->pluck('field_service_job_id')))
            ->with('jobs:id')->latest('id')->get();

        return response()->json(['total' => $total, 'next_cursor' => $more ? (int) $rows->last()->id : null,
            'materials' => $rows->map(function ($material) use ($photos): array {
                $photo = $photos->first(fn ($asset) => (int) data_get($asset->metadata, 'field_service_material_id') === (int) $material->id
                    && $asset->jobs->contains('id', $material->field_service_job_id)) ?? $material->job?->assets->first();

                return ['id' => (int) $material->id, 'name' => $material->name, 'quantity' => (float) $material->quantity, 'unit' => $material->unit,
                    'status' => $material->status, 'notes' => $material->notes, 'created_at' => $material->created_at?->toIso8601String(),
                    'requester' => $material->requestedBy ? ['id' => $material->requestedBy->id, 'name' => $material->requestedBy->name] : null,
                    'job' => ['id' => $material->job->id, 'title' => $material->job->title],
                    'photo' => $photo ? ['id' => $photo->id, 'name' => $photo->file_name, 'mime_type' => $photo->mime_type, 'url' => ''] : null];
            })->values()])->header('Cache-Control', 'no-store, private');
    }

    public function order(Request $request)
    {
        [$tenant, $user] = $this->context($request);

        return response()->json(['job_order' => (array) data_get($user->ui_preferences, 'field_job_order.'.$tenant->id, [])]);
    }

    public function saveOrder(Request $request, FieldServiceAccessService $access)
    {
        [$tenant, $user] = $this->context($request);
        $data = $request->validate(['job_ids' => ['present', 'array', 'max:2000'], 'job_ids.*' => ['integer', 'min:1', 'distinct']]);
        $ids = $data['job_ids'];
        $visible = $access->scopeVisibleJobs(FieldServiceJob::query()->where('tenant_id', $tenant->id), $user, $tenant)
            ->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        abort_unless(count($visible) === count($ids), 403);
        DB::transaction(function () use ($user, $tenant, $ids): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $preferences = (array) $locked->ui_preferences;
            $preferences['field_job_order'][(string) $tenant->id] = $ids;
            $locked->forceFill(['ui_preferences' => $preferences])->save();
        });

        return response()->json(['job_order' => $ids]);
    }

    public function linkPhoto(Request $request, FieldServiceAccessService $access)
    {
        [$tenant, $user] = $this->context($request);
        $data = $request->validate(['asset_id' => ['required', 'integer'], 'job_id' => ['required', 'integer'],
            'task_id' => ['required_without:material_id', 'prohibits:material_id', 'integer'],
            'material_id' => ['required_without:task_id', 'prohibits:task_id', 'integer']]);
        $job = FieldServiceJob::where('tenant_id', $tenant->id)->findOrFail($data['job_id']);
        abort_unless($access->canUpdateProgress($user, $tenant, $job), 403);
        if (isset($data['task_id'])) {
            $target = \App\Models\FieldServiceTask::where('tenant_id', $tenant->id)->where('field_service_job_id', $job->id)->findOrFail($data['task_id']);
            abort_unless($access->canUpdateTask($user, $tenant, $job, $target), 403);
            $key = 'field_service_task_id';
        } else {
            $target = \App\Models\FieldServiceMaterial::where('tenant_id', $tenant->id)->where('field_service_job_id', $job->id)->findOrFail($data['material_id']);
            $key = 'field_service_material_id';
        }
        DB::transaction(function () use ($data, $tenant, $user, $job, $target, $key): void {
            $asset = \App\Models\WorkspaceAsset::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($data['asset_id']);
            abort_unless((int) $asset->uploaded_by_user_id === (int) $user->id && $asset->visibility === 'team' && str_starts_with($asset->mime_type, 'image/')
                && $asset->jobs()->whereKey($job->id)->exists(), 403);
            $metadata = (array) $asset->metadata;
            abort_if(($key !== 'field_service_task_id' && isset($metadata['field_service_task_id']))
                || ($key !== 'field_service_material_id' && isset($metadata['field_service_material_id'])), 409);
            abort_if(isset($metadata[$key]) && (int) $metadata[$key] !== $target->id, 409);
            $metadata[$key] = $target->id;
            $asset->forceFill(['metadata' => $metadata])->save();
            app(\App\Services\FieldService\WorkspaceAssetAuditService::class)->record($tenant, $asset, $user, 'linked_to_work', ['job_id' => $job->id, $key => $target->id]);
        });

        return response()->json(['ok' => true]);
    }

    public function assistant(Request $request, \App\Services\FieldService\FieldCrewAssistant $assistant)
    {
        [$tenant, $user] = $this->context($request);
        $data = $request->validate(['question' => ['required', 'string', 'max:3000'], 'request_id' => ['required', 'uuid'],
            'history' => ['sometimes', 'array', 'max:8'], 'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:4000'], 'context_job_id' => ['nullable', 'integer', 'min:1']]);

        $hours = app(\App\Services\FieldService\FieldCrewHours::class)->answer($tenant, $user, $data['question'], $data['history'] ?? []);
        if ($hours !== null) {
            return response()->json($hours)->header('Cache-Control', 'no-store, private');
        }

        $navigation = app(\App\Services\FieldService\FieldCrewNavigation::class)->resolve($tenant, $user, $data['question'], $data['context_job_id'] ?? null);
        if ($navigation !== null) {
            return response()->json($navigation)->header('Cache-Control', 'no-store, private');
        }

        return response()->json($assistant->ask($tenant, $user, $data['question'], $data['request_id'], $data['history'] ?? [], $data['context_job_id'] ?? null))
            ->header('Cache-Control', 'no-store, private');
    }

    private function context(Request $request): array
    {
        $tenant = $request->attributes->get('current_tenant');
        $user = $request->user();
        abort_unless($tenant instanceof Tenant && $user instanceof User, 403);
        $user->refresh();
        abort_unless($user->is_active, 403);
        abort_unless(app(FieldServiceAccessService::class)->role($user, $tenant) !== ''
            && app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'field_service'), 403);

        return [$tenant, $user];
    }
}
