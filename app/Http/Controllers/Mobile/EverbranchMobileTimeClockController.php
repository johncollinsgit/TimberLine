<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceTimeSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceTimeClockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EverbranchMobileTimeClockController extends Controller
{
    public function current(Request $request, FieldServiceTimeClockService $clock): JsonResponse
    {
        return response()->json(['contract_version' => 5, 'timer' => $this->payload($clock->current($this->tenant($request), $this->user($request)))]);
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
        abort_unless($access->canAccessJob($user, $tenant, $job), 404);
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
