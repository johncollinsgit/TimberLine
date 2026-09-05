<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceTimeHoursService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EverbranchMobileTimeHoursController extends Controller
{
    public function job(Request $request, string $tenant, int $job, FieldServiceAccessService $access, FieldServiceTimeHoursService $hours): JsonResponse
    {
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        $jobModel = FieldServiceJob::query()->forTenantId((int) $tenant->id)->findOrFail($job);
        abort_unless($access->canAccessJob($user, $tenant, $jobModel), 404, 'That job is not available to your account.');
        $validated = $request->validate([
            'range' => ['nullable', 'in:week,pay_period,month'],
        ]);

        return response()->json($hours->jobAnalytics(
            $tenant,
            $jobModel,
            $user,
            $access->canManageJobs($user, $tenant),
            $validated,
        ));
    }

    public function index(Request $request, FieldServiceAccessService $access, FieldServiceTimeHoursService $hours, TenantModuleAccessResolver $modules): JsonResponse
    {
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        $this->assertTimeTracking($tenant, $modules);
        $validated = $request->validate([
            'range' => ['nullable', 'in:week,pay_period,month,custom'],
            'start_date' => ['nullable', 'required_if:range,custom', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'required_if:range,custom', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
        ]);
        if (! $access->canManageJobs($user, $tenant)) {
            // A field employee can only ever receive their own time data. Ignore
            // a supplied employee_id rather than trusting a client-side filter.
            $validated['employee_id'] = (int) $user->id;
            $validated['self_only'] = true;
        }

        return response()->json($hours->analytics($tenant, $validated));
    }

    public function update(Request $request, string $tenant, string $source, int $entry, FieldServiceAccessService $access, FieldServiceTimeHoursService $hours, TenantModuleAccessResolver $modules): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        $this->assertAccess($tenantModel, $user, $access, $modules);
        $validated = $request->validate([
            'started_at' => ['sometimes', 'date'],
            'ended_at' => ['sometimes', 'date'],
            'break_seconds' => ['sometimes', 'integer', 'min:0', 'max:604800'],
            'status' => ['sometimes', 'in:submitted,approved,rejected'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'user_id' => ['sometimes', 'integer'],
            'job_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        return response()->json([
            'ok' => true,
            'entry' => $hours->update($tenantModel, $user, $source, $entry, $validated),
        ]);
    }

    private function assertAccess(Tenant $tenant, User $user, FieldServiceAccessService $access, TenantModuleAccessResolver $modules): void
    {
        $this->assertTimeTracking($tenant, $modules);
        abort_unless($access->canManageJobs($user, $tenant), 403);
    }

    private function assertTimeTracking(Tenant $tenant, TenantModuleAccessResolver $modules): void
    {
        abort_unless((bool) data_get($modules->resolveForTenant((int) $tenant->id, ['time_tracking']), 'modules.time_tracking.enabled', false), 403);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active !== false, 401);

        return $user;
    }
}
