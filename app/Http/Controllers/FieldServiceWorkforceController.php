<?php

namespace App\Http\Controllers;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceTimeChangeRequest;
use App\Models\FieldServiceTimeSession;
use App\Models\FieldServiceWorkShift;
use App\Models\Tenant;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceWorkforceService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FieldServiceWorkforceController extends Controller
{
    public function __construct(
        private readonly TenantModuleAccessResolver $modules,
        private readonly FieldServiceAccessService $fieldAccess,
        private readonly FieldServiceWorkforceService $workforce,
        private readonly LandlordOperatorActionAuditService $audit,
    ) {}

    public function storeShift(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        $data = $request->validate([
            'user_id' => ['required', 'integer'], 'field_service_job_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'unpaid_break_minutes' => ['nullable', 'integer', 'min:0', 'max:720'], 'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        abort_unless($tenant->users()->whereKey((int) $data['user_id'])->exists(), 422);
        if (filled($data['field_service_job_id'] ?? null)) {
            abort_unless(FieldServiceJob::query()->forTenantId((int) $tenant->id)->whereKey((int) $data['field_service_job_id'])->exists(), 422);
        }
        $shift = FieldServiceWorkShift::query()->create([
            'tenant_id' => (int) $tenant->id, 'user_id' => (int) $data['user_id'],
            'field_service_job_id' => filled($data['field_service_job_id'] ?? null) ? (int) $data['field_service_job_id'] : null,
            'created_by_user_id' => (int) $request->user()->id, 'status' => 'scheduled',
            'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
            'unpaid_break_minutes' => (int) ($data['unpaid_break_minutes'] ?? 0), 'notes' => $data['notes'] ?? null,
        ]);
        $this->audit->record((int) $tenant->id, (int) $request->user()->id, 'field_service.shift.created', targetType: 'field_service_work_shift', targetId: $shift->id, afterState: $this->shiftState($shift));

        return back()->with('status', 'Shift scheduled.');
    }

    public function cancelShift(Request $request, FieldServiceWorkShift $shift): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        abort_unless((int) $shift->tenant_id === (int) $tenant->id, 404);
        $before = $this->shiftState($shift);
        $shift->forceFill(['status' => 'canceled', 'canceled_at' => now()])->save();
        $this->audit->record((int) $tenant->id, (int) $request->user()->id, 'field_service.shift.canceled', targetType: 'field_service_work_shift', targetId: $shift->id, beforeState: $before, afterState: $this->shiftState($shift));

        return back()->with('status', 'Shift canceled.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        $data = $request->validate(['enforce_scheduled_clocking' => ['nullable', 'boolean'], 'clock_early_minutes' => ['required', 'integer', 'min:0', 'max:120'], 'clock_late_minutes' => ['required', 'integer', 'min:0', 'max:120']]);
        $settings = $this->workforce->settings($tenant);
        $before = $settings->only(['enforce_scheduled_clocking', 'clock_early_minutes', 'clock_late_minutes']);
        $settings->forceFill(['enforce_scheduled_clocking' => (bool) ($data['enforce_scheduled_clocking'] ?? false), 'clock_early_minutes' => (int) $data['clock_early_minutes'], 'clock_late_minutes' => (int) $data['clock_late_minutes'], 'updated_by_user_id' => (int) $request->user()->id])->save();
        $this->audit->record((int) $tenant->id, (int) $request->user()->id, 'field_service.workforce_settings.updated', targetType: 'tenant_workforce_setting', targetId: $settings->id, beforeState: $before, afterState: $settings->only(['enforce_scheduled_clocking', 'clock_early_minutes', 'clock_late_minutes']));

        return back()->with('status', 'Clock-window policy saved.');
    }

    public function requestSessionCorrection(Request $request, FieldServiceTimeSession $session): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeTimeTracking($tenant);
        $data = $request->validate(['clocked_in_at' => ['required', 'date'], 'clocked_out_at' => ['required', 'date', 'after:clocked_in_at'], 'break_minutes' => ['required', 'integer', 'min:0', 'max:720'], 'clock_out_notes' => ['nullable', 'string', 'max:3000'], 'reason' => ['required', 'string', 'max:3000']]);
        $this->workforce->requestSessionCorrection($tenant, $request->user(), $session, $data, $data['reason']);

        return back()->with('status', 'Timecard edit request sent for review.');
    }

    public function resolveSessionCorrection(Request $request, FieldServiceTimeChangeRequest $change): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'reviewer_note' => ['nullable', 'string', 'max:3000']]);
        $this->workforce->resolveSessionCorrection($tenant, $request->user(), $change, $data['decision'], $data['reviewer_note'] ?? null);

        return back()->with('status', 'Timecard edit request '.$data['decision'].'.');
    }

    private function authorizeManage(Request $request, Tenant $tenant): void
    {
        $this->authorizeTimeTracking($tenant);
        abort_unless($this->fieldAccess->canManageJobs($request->user(), $tenant), 403);
    }

    private function authorizeTimeTracking(Tenant $tenant): void
    {
        abort_unless((bool) data_get($this->modules->resolveForTenant((int) $tenant->id, ['time_tracking']), 'modules.time_tracking.enabled', false), 403);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    /** @return array<string,mixed> */
    private function shiftState(FieldServiceWorkShift $shift): array
    {
        return ['user_id' => (int) $shift->user_id, 'job_id' => $shift->field_service_job_id === null ? null : (int) $shift->field_service_job_id, 'status' => $shift->status, 'starts_at' => $shift->starts_at?->toIso8601String(), 'ends_at' => $shift->ends_at?->toIso8601String(), 'unpaid_break_minutes' => (int) $shift->unpaid_break_minutes];
    }
}
