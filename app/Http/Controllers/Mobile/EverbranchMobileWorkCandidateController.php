<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceWorkCandidate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceWorkCandidateService;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EverbranchMobileWorkCandidateController extends Controller
{
    public function index(Request $request, FieldServiceAccessService $access, FieldServiceWorkCandidateService $candidates, TenantFinancialAccess $financial): JsonResponse
    {
        $tenant = $this->tenant($request);
        abort_unless($financial->allows($this->user($request), $tenant), 403);
        $status = $request->validate(['status' => ['nullable', 'in:active,archived']])['status'] ?? 'active';

        return response()->json(['contract_version' => 7, 'status' => $status, 'job_drafts' => $candidates->forStatus($tenant, $status)->map(fn (FieldServiceWorkCandidate $candidate): array => $this->payload($candidate))->values()]);
    }

    public function show(Request $request, string $tenant, FieldServiceWorkCandidate $draft, FieldServiceWorkCandidateService $candidates, TenantFinancialAccess $financial): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        abort_unless($financial->allows($this->user($request), $tenantModel) && (int) $draft->tenant_id === (int) $tenantModel->id, 404);

        return response()->json(['contract_version' => 7, 'job_draft' => $this->payload($draft)]);
    }

    public function update(Request $request, string $tenant, FieldServiceWorkCandidate $draft, FieldServiceWorkCandidateService $candidates, TenantFinancialAccess $financial): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        abort_unless($financial->allows($this->user($request), $tenantModel), 403);
        $validated = $request->validate($this->rules());
        if (array_key_exists('assigned_user_id', $validated) && filled($validated['assigned_user_id'])) {
            abort_unless($tenantModel->users()->whereKey((int) $validated['assigned_user_id'])->wherePivot('membership_active', true)->exists(), 422, 'Choose an active workspace lead.');
        }
        if (array_key_exists('participant_user_ids', $validated)) {
            $ids = array_values(array_unique(array_map('intval', (array) $validated['participant_user_ids'])));
            abort_unless($tenantModel->users()->whereIn('users.id', $ids)->wherePivot('membership_active', true)->count() === count($ids), 422, 'Every crew member must be active in this workspace.');
            $validated['participant_user_ids'] = $ids;
        }

        return response()->json(['ok' => true, 'job_draft' => $this->payload($candidates->update($tenantModel, $draft, $validated))]);
    }

    public function publish(Request $request, string $tenant, FieldServiceWorkCandidate $draft, FieldServiceWorkCandidateService $candidates, TenantFinancialAccess $financial): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($financial->allows($user, $tenantModel), 403);
        $job = $candidates->publish($tenantModel, $user, $draft);

        return response()->json(['ok' => true, 'status' => 'published', 'destination' => ['kind' => 'field_service_job', 'id' => (int) $job->id]], 201);
    }

    public function link(Request $request, string $tenant, FieldServiceWorkCandidate $draft, FieldServiceWorkCandidateService $candidates, TenantFinancialAccess $financial): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($financial->allows($user, $tenantModel), 403);
        $validated = $request->validate(['job_id' => ['required', 'integer']]);
        $job = FieldServiceJob::query()->forTenantId((int) $tenantModel->id)->findOrFail((int) $validated['job_id']);
        $candidates->link($tenantModel, $user, $draft, $job);

        return response()->json(['ok' => true, 'status' => 'linked', 'destination' => ['kind' => 'field_service_job', 'id' => (int) $job->id]]);
    }

    public function destroy(Request $request, string $tenant, FieldServiceWorkCandidate $draft, FieldServiceWorkCandidateService $candidates, TenantFinancialAccess $financial): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($financial->allows($user, $tenantModel), 403);
        $candidates->archive($tenantModel, $user, $draft);

        return response()->json(['ok' => true, 'status' => 'archived']);
    }

    public function restore(Request $request, string $tenant, FieldServiceWorkCandidate $draft, FieldServiceWorkCandidateService $candidates, TenantFinancialAccess $financial): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        abort_unless($financial->allows($this->user($request), $tenantModel), 403);

        return response()->json(['ok' => true, 'status' => 'active', 'job_draft' => $this->payload($candidates->restore($tenantModel, $draft))]);
    }

    public function review(Request $request, string $tenant, FieldServiceWorkCandidate $candidate, FieldServiceAccessService $access, FieldServiceWorkCandidateService $candidates, TenantFinancialAccess $financial): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($financial->allows($user, $tenantModel), 403);
        $validated = $request->validate(['action' => ['required', 'in:create_job,link,dismiss'], 'job_id' => ['nullable', 'integer', 'required_if:action,link']]);
        if ($validated['action'] === 'dismiss') {
            $candidates->dismiss($tenantModel, $user, $candidate);

            return response()->json(['ok' => true, 'status' => 'dismissed']);
        }
        if ($validated['action'] === 'link') {
            $job = FieldServiceJob::query()->forTenantId((int) $tenantModel->id)->findOrFail((int) $validated['job_id']);
            $candidates->link($tenantModel, $user, $candidate, $job);
        } else {
            $job = $candidates->createJob($tenantModel, $user, $candidate);
        }

        return response()->json(['ok' => true, 'status' => $candidate->fresh()->status, 'destination' => ['kind' => 'field_service_job', 'id' => (int) $job->id]]);
    }

    /** @return array<string,mixed> */
    protected function payload(FieldServiceWorkCandidate $candidate): array
    {
        return [
            'id' => (int) $candidate->id, 'status' => $candidate->status === 'dismissed' ? 'archived' : 'active',
            'title' => $candidate->title, 'customer' => ['name' => $candidate->customer_name, 'email' => $candidate->customer_email, 'phone' => $candidate->customer_phone],
            'description' => $candidate->description, 'priority' => $candidate->priority ?: 'normal',
            'scheduled_for' => $candidate->scheduled_for?->toIso8601String(), 'scheduled_end_at' => $candidate->scheduled_end_at?->toIso8601String(),
            'address' => ['line_1' => $candidate->service_address_line_1, 'line_2' => $candidate->service_address_line_2, 'city' => $candidate->service_city, 'state' => $candidate->service_state, 'postal_code' => $candidate->service_postal_code, 'country' => $candidate->service_country],
            'lead_user_id' => $candidate->assigned_user_id, 'crew_user_ids' => array_values((array) $candidate->participant_user_ids),
            'project_manager' => ['name' => $candidate->project_manager_name, 'company' => $candidate->project_manager_company, 'phone' => $candidate->project_manager_phone, 'email' => $candidate->project_manager_email],
            'updated_at' => $candidate->updated_at?->toIso8601String(), 'archived_at' => $candidate->archived_at?->toIso8601String(),
            'actions' => $candidate->status === 'dismissed' ? ['restore'] : ['publish', 'link', 'archive'],
        ];
    }

    /** @return array<string,array<int,string>> */
    protected function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'], 'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:255'], 'customer_phone' => ['sometimes', 'nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'], 'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'], 'scheduled_end_at' => ['sometimes', 'nullable', 'date'],
            'service_address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'], 'service_address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service_city' => ['sometimes', 'nullable', 'string', 'max:120'], 'service_state' => ['sometimes', 'nullable', 'string', 'max:80'],
            'service_postal_code' => ['sometimes', 'nullable', 'string', 'max:40'], 'service_country' => ['sometimes', 'nullable', 'string', 'max:80'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer'], 'participant_user_ids' => ['sometimes', 'array', 'max:50'], 'participant_user_ids.*' => ['integer'],
            'project_manager_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'project_manager_company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project_manager_phone' => ['sometimes', 'nullable', 'string', 'max:80'], 'project_manager_email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
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
