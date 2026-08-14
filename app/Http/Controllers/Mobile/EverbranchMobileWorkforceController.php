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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
