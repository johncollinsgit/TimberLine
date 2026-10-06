<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\EverbranchMobilePushDevice;
use App\Models\FieldServiceCrewStatus;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceTimeSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceJobNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EverbranchMobileDispatchController extends Controller
{
    public function index(Request $request, FieldServiceAccessService $access): JsonResponse
    {
        [$tenant, $user] = $this->context($request);
        abort_unless($access->canManageJobs($user, $tenant), 403);

        $members = $tenant->users()->where('users.is_active', true)
            ->wherePivot('membership_active', true)->orderBy('users.name')->get(['users.id', 'users.name']);
        $memberIds = $members->pluck('id')->map(fn ($id): int => (int) $id);
        $statuses = FieldServiceCrewStatus::query()->forTenantId((int) $tenant->id)
            ->whereIn('user_id', $memberIds)->with('job:id,tenant_id,title,customer_name,scheduled_for,operational_status')
            ->get()->keyBy('user_id');
        $sessions = FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)
            ->whereIn('user_id', $memberIds)->whereIn('status', ['running', 'paused'])
            ->with('job:id,tenant_id,title,customer_name,scheduled_for,operational_status')
            ->latest('clocked_in_at')->get()->unique('user_id')->keyBy('user_id');
        $lastSeen = EverbranchMobilePushDevice::query()->whereIn('user_id', $memberIds)
            ->selectRaw('user_id, max(last_seen_at) as last_seen_at')->groupBy('user_id')->pluck('last_seen_at', 'user_id');
        $jobs = FieldServiceJob::query()->forTenantId((int) $tenant->id)->notGeneratedQuickBooksInvoice()
            ->whereNull('archived_at')->where(fn ($query) => $query->whereNull('operational_status')->orWhereNotIn('operational_status', ['complete', 'canceled', 'history']))
            ->with('assignedUser:id,name')->orderByRaw('scheduled_for is null')->orderBy('scheduled_for')->orderByDesc('updated_at')
            ->limit(150)->get();

        $crew = $members->map(function (User $member) use ($statuses, $sessions, $jobs, $lastSeen): array {
            $signal = $statuses->get($member->id);
            if ($signal?->expires_at?->isPast()) {
                $signal = null;
            }
            $session = $sessions->get($member->id);
            $currentJob = $session?->job ?: $signal?->job ?: $jobs->firstWhere('assigned_user_id', (int) $member->id);
            $nextJob = $jobs->filter(fn (FieldServiceJob $job): bool => (int) $job->assigned_user_id === (int) $member->id
                    && $job->scheduled_for?->isFuture() && (int) $job->id !== (int) ($currentJob?->id ?? 0))
                ->sortBy('scheduled_for')->first();
            [$state, $label] = $this->state($session, $signal, $currentJob);

            return [
                'user' => ['id' => (int) $member->id, 'name' => (string) $member->name, 'role' => (string) $member->pivot->role],
                'state' => $state, 'label' => $label, 'available' => $state === 'available',
                'current_job' => $this->jobPayload($currentJob), 'next_job' => $this->jobPayload($nextJob),
                'clock' => $session ? ['status' => $session->status, 'clocked_in_at' => $session->clocked_in_at?->toIso8601String()] : null,
                'field_status' => $signal ? ['status' => $signal->status, 'note' => $signal->note, 'updated_at' => $signal->updated_at?->toIso8601String()] : null,
                'last_seen_at' => $lastSeen->get($member->id),
            ];
        })->values();

        return response()->json([
            'contract_version' => 1,
            'summary' => [
                'working' => $crew->where('state', 'working')->count(), 'en_route' => $crew->where('state', 'en_route')->count(),
                'on_site' => $crew->where('state', 'on_site')->count(), 'available' => $crew->where('state', 'available')->count(),
                'unassigned_jobs' => $jobs->whereNull('assigned_user_id')->count(),
            ],
            'crew' => $crew,
            'jobs' => $jobs->map(fn (FieldServiceJob $job): array => [
                ...$this->jobPayload($job),
                'lead' => $job->assignedUser ? ['id' => (int) $job->assignedUser->id, 'name' => (string) $job->assignedUser->name] : null,
            ])->values(),
            'refreshed_at' => now()->toIso8601String(),
        ]);
    }

    public function assign(Request $request, string $tenant, FieldServiceJob $job, FieldServiceAccessService $access, FieldServiceJobNotificationService $notifications): JsonResponse
    {
        [$tenantModel, $user] = $this->context($request);
        abort_unless($access->canManageJobs($user, $tenantModel) && (int) $job->tenant_id === (int) $tenantModel->id, 404);
        $validated = $request->validate(['assigned_user_id' => ['required', 'integer']]);
        $assignee = $tenantModel->users()->whereKey((int) $validated['assigned_user_id'])
            ->where('users.is_active', true)->wherePivot('membership_active', true)->first();
        if (! $assignee) {
            return response()->json(['message' => 'Choose an active employee in this workspace.'], 422);
        }

        $previousAssigneeId = $job->assigned_user_id ? (int) $job->assigned_user_id : null;
        DB::transaction(function () use ($job, $assignee, $tenantModel, $previousAssigneeId): void {
            $job->forceFill(['assigned_user_id' => (int) $assignee->id])->save();
            if ($previousAssigneeId && $previousAssigneeId !== (int) $assignee->id) {
                $job->participants()->updateExistingPivot($previousAssigneeId, ['role' => 'member']);
            }
            $job->participants()->syncWithoutDetaching([
                (int) $assignee->id => ['tenant_id' => (int) $tenantModel->id, 'role' => 'lead', 'following' => true],
            ]);
        });
        $job = $job->fresh();
        $notifications->notifyJobEvent($job, $user, 'assigned', $assignee->name.' is now the lead for '.$job->title.'.',
            'dispatch-assigned:'.$job->id.':'.$job->updated_at?->timestamp, [(int) $assignee->id]);

        return response()->json(['message' => $assignee->name.' is now assigned.', 'job' => [
            ...$this->jobPayload($job), 'lead' => ['id' => (int) $assignee->id, 'name' => (string) $assignee->name],
        ]]);
    }

    public function updateStatus(Request $request, FieldServiceAccessService $access): JsonResponse
    {
        [$tenant, $user] = $this->context($request);
        $validated = $request->validate([
            'status' => ['required', 'in:available,en_route,on_site,unavailable'],
            'job_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:240'],
        ]);
        $job = null;
        if (! empty($validated['job_id'])) {
            $job = FieldServiceJob::query()->forTenantId((int) $tenant->id)->findOrFail((int) $validated['job_id']);
            abort_unless($access->canUpdateProgress($user, $tenant, $job), 403);
        }
        if (in_array($validated['status'], ['en_route', 'on_site'], true) && ! $job) {
            return response()->json(['message' => 'Choose a job for this status.'], 422);
        }

        $status = FieldServiceCrewStatus::query()->updateOrCreate(
            ['tenant_id' => (int) $tenant->id, 'user_id' => (int) $user->id],
            ['field_service_job_id' => $job?->id, 'status' => (string) $validated['status'],
                'note' => $validated['note'] ?? null, 'expires_at' => now()->addHours(12)]
        );

        return response()->json(['message' => 'Crew status updated.', 'status' => [
            'status' => $status->status, 'job_id' => $status->field_service_job_id, 'updated_at' => $status->updated_at?->toIso8601String(),
        ]]);
    }

    /** @return array{Tenant,User} */
    private function context(Request $request): array
    {
        $tenant = $request->attributes->get('current_tenant');
        $user = $request->user();
        abort_unless($tenant instanceof Tenant, 403);
        abort_unless($user instanceof User && $user->is_active !== false, 401);

        return [$tenant, $user];
    }

    /** @return array{0:string,1:string} */
    private function state(?FieldServiceTimeSession $session, ?FieldServiceCrewStatus $status, ?FieldServiceJob $job): array
    {
        if ($session?->status === 'paused') {
            return ['break', 'On break'];
        }
        if ($session) {
            return ['working', 'Clocked in'];
        }
        if ($status) {
            return [$status->status, match ($status->status) {
                'en_route' => 'En route', 'on_site' => 'On site', 'unavailable' => 'Unavailable', default => 'Available',
            }];
        }
        if ($job?->scheduled_for?->isToday()) {
            return ['scheduled', 'Scheduled today'];
        }

        return ['available', 'Available'];
    }

    /** @return array<string,mixed>|null */
    private function jobPayload(?FieldServiceJob $job): ?array
    {
        if (! $job) {
            return null;
        }

        return ['id' => (int) $job->id, 'title' => (string) $job->title, 'customer' => $job->customer_name,
            'scheduled_for' => $job->scheduled_for?->toIso8601String(), 'status' => $job->operational_status ?: $job->status];
    }
}
