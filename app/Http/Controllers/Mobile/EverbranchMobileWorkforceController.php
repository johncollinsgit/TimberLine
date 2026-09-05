<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceTimeSession;
use App\Models\FieldServiceWorkShift;
use App\Models\FleetTrackingPolicyAcknowledgement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceWorkforceService;
use App\Services\FleetTracking\FleetLocationIngestionService;
use App\Services\FleetTracking\FleetTrackingAccessService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EverbranchMobileWorkforceController extends Controller
{
    public function shifts(Request $request, TenantModuleAccessResolver $modules): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->assertModule($modules, $tenant, 'time_tracking');
        $shifts = FieldServiceWorkShift::query()->forTenantId((int) $tenant->id)->where('user_id', (int) $this->user($request)->id)
            ->where('ends_at', '>=', now()->subDay())->where('starts_at', '<=', now()->addDays(14))->orderBy('starts_at')->get();

        return response()->json(['contract_version' => 1, 'shifts' => $shifts->map(fn (FieldServiceWorkShift $shift) => ['id' => (int) $shift->id, 'status' => $shift->status, 'job_id' => $shift->field_service_job_id, 'starts_at' => $shift->starts_at?->toIso8601String(), 'ends_at' => $shift->ends_at?->toIso8601String(), 'unpaid_break_minutes' => (int) $shift->unpaid_break_minutes, 'notes' => $shift->notes])->values()]);
    }

    public function requestCorrection(Request $request, FieldServiceWorkforceService $workforce, TenantModuleAccessResolver $modules): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->assertModule($modules, $tenant, 'time_tracking');
        $data = $request->validate(['session_id' => ['required', 'integer'], 'clocked_in_at' => ['required', 'date'], 'clocked_out_at' => ['required', 'date', 'after:clocked_in_at'], 'break_minutes' => ['required', 'integer', 'min:0', 'max:720'], 'clock_out_notes' => ['nullable', 'string', 'max:3000'], 'reason' => ['required', 'string', 'max:3000']]);
        $change = $workforce->requestSessionCorrection($tenant, $this->user($request), FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)->findOrFail((int) $data['session_id']), $data, $data['reason']);

        return response()->json(['ok' => true, 'request_id' => (int) $change->id, 'status' => $change->status], 201);
    }

    public function locationPolicy(Request $request, FleetTrackingAccessService $access): JsonResponse
    {
        $tenant = $this->tenant($request);
        $settings = $access->settings($tenant);
        $visible = $access->enabledFor($tenant) && $settings->phone_tracking_enabled && $access->isLegallyReady($settings);
        $accepted = $visible && FleetTrackingPolicyAcknowledgement::query()->forTenantId((int) $tenant->id)->where('user_id', (int) $this->user($request)->id)->where('policy_version', $settings->policy_version)->exists();

        return response()->json(['contract_version' => 1, 'phone_tracking_available' => $visible, 'policy_version' => $visible ? $settings->policy_version : null, 'accepted' => $accepted, 'retention_days' => $visible ? (int) $settings->retention_days : null, 'rule' => 'Only share while actively clocked in; sharing stops on pause or clock-out.']);
    }

    public function acceptLocationPolicy(Request $request, FleetTrackingAccessService $access): JsonResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['policy_version' => ['required', 'string', 'max:80'], 'device_context' => ['nullable', 'array']]);
        $settings = $access->settings($tenant);
        abort_unless($access->enabledFor($tenant) && $settings->phone_tracking_enabled && $access->isLegallyReady($settings) && hash_equals((string) $settings->policy_version, (string) $data['policy_version']), 403);
        FleetTrackingPolicyAcknowledgement::query()->updateOrCreate(['tenant_id' => (int) $tenant->id, 'user_id' => (int) $this->user($request)->id, 'policy_version' => $settings->policy_version], ['policy_sha256' => $settings->policy_sha256, 'accepted_at' => now(), 'acceptance_source' => 'mobile', 'device_context' => $data['device_context'] ?? null]);

        return response()->json(['ok' => true]);
    }

    public function storeLocation(Request $request, FleetLocationIngestionService $locations): JsonResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['session_id' => ['required', 'integer'], 'client_uuid' => ['required', 'uuid'], 'latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'accuracy_meters' => ['nullable', 'integer', 'min:0', 'max:10000'], 'recorded_at' => ['required', 'date'], 'platform' => ['required', 'in:ios,android']]);
        $session = FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)->findOrFail((int) $data['session_id']);
        $point = $locations->recordPhone($tenant, $this->user($request), $session, $data);

        return response()->json(['ok' => true, 'point_id' => (int) $point->id], 201);
    }

    public function crewMap(Request $request, FleetTrackingAccessService $access): JsonResponse
    {
        $tenant = $this->tenant($request);
        $viewer = $this->user($request);
        abort_unless($access->canView($viewer, $tenant), 403);

        $settings = $access->settings($tenant);
        $enabled = $access->enabledFor($tenant);
        $legallyReady = $access->isLegallyReady($settings);
        $members = $tenant->users()->where('users.is_active', true)
            ->wherePivot('membership_active', true)->orderBy('users.name')->get(['users.id', 'users.name']);
        $memberIds = $members->pluck('id')->map(fn ($id): int => (int) $id);

        $latestPhonePoints = collect();
        if ($memberIds->isNotEmpty()) {
            $ranked = DB::table('fleet_location_points as points')
                ->join('field_service_time_sessions as sessions', function ($join): void {
                    $join->on('sessions.id', '=', 'points.field_service_time_session_id')
                        ->on('sessions.tenant_id', '=', 'points.tenant_id');
                })
                ->where('points.tenant_id', (int) $tenant->id)
                ->where('points.source', 'mobile')
                ->where('sessions.status', 'running')
                ->whereIn('points.user_id', $memberIds)
                ->selectRaw('points.id, points.user_id, points.field_service_time_session_id, points.latitude, points.longitude, points.accuracy_meters, points.recorded_at, row_number() over (partition by points.user_id order by points.recorded_at desc, points.id desc) as location_rank');

            $latestPhonePoints = DB::query()->fromSub($ranked, 'ranked_locations')
                ->where('location_rank', 1)->get()->keyBy('user_id');
        }

        $sessions = FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)
            ->whereIn('user_id', $memberIds)->where('status', 'running')
            ->with('job:id,tenant_id,title,customer_name')->latest('clocked_in_at')->get()->unique('user_id')->keyBy('user_id');

        $crew = $members->map(function (User $member) use ($latestPhonePoints, $sessions): array {
            $point = $latestPhonePoints->get($member->id);
            $session = $sessions->get($member->id);
            $recordedAt = $point ? Carbon::parse((string) $point->recorded_at) : null;
            $ageSeconds = $recordedAt ? max(0, (int) $recordedAt->diffInSeconds(now())) : null;

            return [
                'user' => ['id' => (int) $member->id, 'name' => (string) $member->name, 'role' => (string) $member->pivot->role],
                'on_duty' => $session !== null,
                'job' => $session?->job ? ['id' => (int) $session->job->id, 'title' => (string) $session->job->title, 'customer' => $session->job->customer_name] : null,
                'location' => $point ? [
                    'latitude' => (float) $point->latitude,
                    'longitude' => (float) $point->longitude,
                    'accuracy_meters' => $point->accuracy_meters !== null ? (int) $point->accuracy_meters : null,
                    'recorded_at' => $recordedAt?->toIso8601String(),
                    'age_seconds' => $ageSeconds,
                    'freshness' => $ageSeconds <= 120 ? 'live' : ($ageSeconds <= 900 ? 'recent' : 'stale'),
                ] : null,
            ];
        })->values();

        return response()->json([
            'contract_version' => 1,
            'tracking' => [
                'available' => $enabled && $settings->phone_tracking_enabled && $legallyReady,
                'module_enabled' => $enabled,
                'phone_tracking_enabled' => (bool) $settings->phone_tracking_enabled,
                'policy_ready' => $legallyReady,
                'retention_days' => (int) $settings->retention_days,
                'setup_message' => ! $enabled
                    ? 'The Location Tracker Branch is not enabled for this workspace.'
                    : (! $legallyReady ? 'An administrator must finish the reviewed location policy before employee sharing can begin.'
                        : (! $settings->phone_tracking_enabled ? 'On-duty phone sharing is turned off in Location Tracker settings.' : null)),
            ],
            'summary' => [
                'team_members' => $crew->count(),
                'on_duty' => $crew->where('on_duty', true)->count(),
                'sharing_now' => $crew->whereNotNull('location')->where('location.freshness', 'live')->count(),
            ],
            'crew' => $crew,
            'refreshed_at' => now()->toIso8601String(),
            'poll_after_seconds' => 30,
        ]);
    }

    private function assertModule(TenantModuleAccessResolver $modules, Tenant $tenant, string $module): void
    {
        abort_unless((bool) data_get($modules->resolveForTenant((int) $tenant->id, [$module]), 'modules.'.$module.'.enabled', false), 403);
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
