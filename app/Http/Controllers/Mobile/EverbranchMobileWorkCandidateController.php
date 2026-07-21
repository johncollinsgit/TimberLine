<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceWorkCandidate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceWorkCandidateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EverbranchMobileWorkCandidateController extends Controller
{
    public function index(Request $request, FieldServiceAccessService $access, FieldServiceWorkCandidateService $candidates): JsonResponse
    {
        $tenant = $this->tenant($request);
        abort_unless($access->canManageJobs($this->user($request), $tenant), 403);

        return response()->json(['contract_version' => 5, 'candidates' => $candidates->pending($tenant)->map(fn (FieldServiceWorkCandidate $candidate): array => $this->payload($candidate))->values()]);
    }

    public function review(Request $request, string $tenant, FieldServiceWorkCandidate $candidate, FieldServiceAccessService $access, FieldServiceWorkCandidateService $candidates): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        abort_unless($access->canManageJobs($user, $tenantModel), 403);
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
        return ['id' => (int) $candidate->id, 'source' => $candidate->source, 'source_type' => $candidate->source_type, 'title' => $candidate->title, 'customer' => $candidate->customer_name, 'amount' => $candidate->amount === null ? null : (float) $candidate->amount, 'balance' => $candidate->balance === null ? null : (float) $candidate->balance, 'description' => $candidate->description, 'updated_at' => $candidate->updated_at?->toIso8601String(), 'actions' => ['create_job', 'link', 'dismiss']];
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
