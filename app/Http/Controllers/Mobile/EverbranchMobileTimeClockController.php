<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceReminderSetting;
use App\Models\FieldServiceTimeChangeRequest;
use App\Models\FieldServiceTimeEntry;
use App\Models\FieldServiceTimeSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceTimeClockService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EverbranchMobileTimeClockController extends Controller
{
    public function current(Request $request, FieldServiceTimeClockService $clock): JsonResponse
    {
        return response()->json(['contract_version' => 5, 'timer' => $this->payload($clock->current($this->tenant($request), $this->user($request)))]);
    }

    public function history(Request $request, TenantModuleAccessResolver $modules): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:day,week,month'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        abort_unless((bool) data_get($modules->resolveForTenant((int) $tenant->id, ['time_tracking']), 'modules.time_tracking.enabled', false), 403);

        $period = (string) ($validated['period'] ?? 'day');
        $offset = (int) ($validated['offset'] ?? 0);
        $timezone = (string) (FieldServiceReminderSetting::query()->forTenantId((int) $tenant->id)->value('timezone') ?: config('app.timezone', 'UTC'));
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }
        $today = CarbonImmutable::now($timezone);
        $start = match ($period) {
            'week' => $today->startOfWeek(CarbonInterface::MONDAY)->subWeeks($offset)->startOfDay(),
            'month' => $today->startOfMonth()->subMonths($offset)->startOfDay(),
            default => $today->startOfDay()->subDays($offset),
        };
        $end = match ($period) {
            'week' => $start->endOfWeek(CarbonInterface::SUNDAY),
            'month' => $start->endOfMonth(),
            default => $start->endOfDay(),
        };

        $sessions = FieldServiceTimeSession::query()->forTenantId((int) $tenant->id)
            ->where('user_id', (int) $user->id)
            ->whereBetween('clocked_in_at', [$start->utc(), $end->utc()])
            ->whereNotIn('status', ['running', 'paused'])
            ->with('job:id,tenant_id,title')->orderByDesc('clocked_in_at')->get();
        $manual = FieldServiceTimeEntry::query()->forTenantId((int) $tenant->id)
            ->where('user_id', (int) $user->id)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->with('job:id,tenant_id,title')->orderByDesc('work_date')->get();
        $corrections = FieldServiceTimeChangeRequest::query()->forTenantId((int) $tenant->id)
            ->where('requested_by_user_id', (int) $user->id)
            ->whereIn('field_service_time_session_id', $sessions->pluck('id'))
            ->latest('id')->get()->unique('field_service_time_session_id')->keyBy('field_service_time_session_id');

        $entries = $sessions->map(fn (FieldServiceTimeSession $session): array => [
            'source' => 'timer', 'id' => (int) $session->id,
            'work_date' => $session->clocked_in_at?->copy()->timezone($timezone)->toDateString(),
            'job' => $session->job?->title,
            'started_at' => $session->clocked_in_at?->toIso8601String(),
            'ended_at' => $session->clocked_out_at?->toIso8601String(),
            'break_minutes' => (int) round((int) $session->break_seconds / 60),
            'duration_seconds' => (int) $session->duration_seconds,
            'status' => (string) $session->status,
            'notes' => $session->clock_out_notes,
            'correction_status' => $corrections->get($session->id)?->status,
        ])->concat($manual->map(fn (FieldServiceTimeEntry $entry): array => [
            'source' => 'manual', 'id' => (int) $entry->id,
            'work_date' => $entry->work_date?->toDateString(),
            'job' => $entry->job?->title,
            'started_at' => CarbonImmutable::parse($entry->work_date?->toDateString().' '.$entry->started_at, $timezone)->toIso8601String(),
            'ended_at' => CarbonImmutable::parse($entry->work_date?->toDateString().' '.$entry->ended_at, $timezone)->toIso8601String(),
            'break_minutes' => (int) $entry->break_minutes,
            'duration_seconds' => (int) $entry->duration_minutes * 60,
            'status' => (string) $entry->status,
            'notes' => $entry->notes,
            'correction_status' => null,
        ]))->sortByDesc('started_at')->values();
        $included = $entries->whereIn('status', ['submitted', 'approved']);

        return response()->json([
            'contract_version' => 1, 'period' => $period, 'offset' => $offset,
            'range' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'timezone' => $timezone],
            'total_seconds' => $included->sum('duration_seconds'),
            'by_day' => $included->groupBy('work_date')->map(fn ($rows, string $date): array => [
                'date' => $date, 'total_seconds' => $rows->sum('duration_seconds'),
            ])->sortKeys()->values(),
            'entries' => $entries,
        ]);
    }

    public function start(Request $request, FieldServiceTimeClockService $clock, FieldServiceAccessService $access): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['required', 'integer'], 'client_uuid' => ['required', 'uuid'],
            'device_context' => ['nullable', 'array'], 'device_context.platform' => ['nullable', 'in:ios,android,web'],
        ]);
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        $job = FieldServiceJob::query()->forTenantId((int) $tenant->id)->findOrFail((int) $validated['job_id']);
        abort_unless($access->canClockJob($user, $tenant, $job), 404, 'Choose a job assigned to you.');
        $session = $clock->start($tenant, $user, $job, $validated['client_uuid'], (array) ($validated['device_context'] ?? []));

        return response()->json(['ok' => true, 'timer' => $this->payload($session)], 201);
    }

    public function pause(Request $request, FieldServiceTimeClockService $clock): JsonResponse
    {
        $validated = $request->validate(['client_uuid' => ['required', 'uuid']]);

        return response()->json(['ok' => true, 'timer' => $this->payload($clock->startBreak($this->tenant($request), $this->user($request), $validated['client_uuid']))]);
    }

    public function resume(Request $request, FieldServiceTimeClockService $clock): JsonResponse
    {
        $validated = $request->validate(['client_uuid' => ['required', 'uuid']]);

        return response()->json(['ok' => true, 'timer' => $this->payload($clock->resume($this->tenant($request), $this->user($request), $validated['client_uuid']))]);
    }

    public function stop(Request $request, FieldServiceTimeClockService $clock): JsonResponse
    {
        $validated = $request->validate(['client_uuid' => ['required', 'uuid'], 'notes' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['ok' => true, 'timer' => $this->payload($clock->stop($this->tenant($request), $this->user($request), $validated['client_uuid'], $validated['notes'] ?? null))]);
    }

    /** @return array<string,mixed>|null */
    protected function payload(?FieldServiceTimeSession $session): ?array
    {
        if (! $session) {
            return null;
        }
        $session->loadMissing(['job:id,tenant_id,title,customer_name', 'breaks']);

        return [
            'id' => (int) $session->id,
            'status' => (string) $session->status,
            'job' => $session->job ? ['id' => (int) $session->job->id, 'title' => $session->job->title, 'customer' => $session->job->customer_name] : null,
            'clocked_in_at' => $session->clocked_in_at?->toIso8601String(),
            'clocked_out_at' => $session->clocked_out_at?->toIso8601String(),
            'break_seconds' => (int) $session->break_seconds,
            'duration_seconds' => $session->duration_seconds === null ? null : (int) $session->duration_seconds,
            'clock_out_notes' => $session->clock_out_notes,
            'active_break_started_at' => $session->breaks->firstWhere('ended_at', null)?->started_at?->toIso8601String(),
            'server_now' => now()->toIso8601String(),
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
