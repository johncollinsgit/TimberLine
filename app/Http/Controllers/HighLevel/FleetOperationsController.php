<?php

namespace App\Http\Controllers\HighLevel;

use App\Http\Controllers\Controller;
use App\Models\HighLevel\Installation;
use App\Services\HighLevel\FleetJobSourceService;
use App\Services\HighLevel\FleetOperationsService;
use App\Services\HighLevel\FleetService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Http\Request;

class FleetOperationsController extends Controller
{
    public function sources(Request $request, FleetJobSourceService $jobs)
    {
        return response()->json($jobs->sources($this->install($request)));
    }

    public function sync(Request $request, FleetJobSourceService $jobs)
    {
        $p = $request->validate(['type' => 'required|in:calendar,pipeline', 'source_id' => 'required|string|regex:/^[A-Za-z0-9_-]{1,100}$/']);
        $install = $this->install($request);
        $result = $jobs->sync($install, $p['type'], $p['source_id']);
        $this->audit($request, 'jobs.sync');

        return response()->json($result);
    }

    public function profile(Request $request, int $device, FleetOperationsService $ops)
    {
        $p = $request->validate(['crew' => 'present|string|max:180', 'available' => 'required|boolean',
            'skills' => 'present|array|max:50', 'skills.*' => 'string|max:80|distinct', 'materials' => 'present|array|max:100', 'materials.*' => 'numeric|min:0|max:100000']);
        $record = $ops->save($this->install($request), 'profile', $p, $device);
        $this->audit($request, 'profile.saved', $record->id);

        return response()->json(['saved' => true]);
    }

    public function job(Request $request, int $job, FleetOperationsService $ops)
    {
        $install = $this->install($request);
        $record = $ops->record($install, $job, 'job');
        abort_unless($record->status === 'active', 422, 'Sync the current HighLevel source first.');
        $p = $request->validate(['device_id' => 'nullable|integer|min:1', 'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude', 'scheduled_start' => 'nullable|date',
            'scheduled_end' => 'nullable|date|after:scheduled_start|required_with:scheduled_start',
            'skills' => 'present|array|max:50', 'skills.*' => 'string|max:80|distinct', 'materials' => 'present|array|max:100', 'materials.*' => 'numeric|min:0|max:100000']);
        if (! empty($p['device_id'])) {
            $ops->device($install, $p['device_id']);
        }
        $ops->save($install, 'job', $p, record: $record);
        $this->audit($request, 'job.context', $record->id);

        return response()->json(['saved' => true]);
    }

    public function plan(Request $request, FleetOperationsService $ops)
    {
        $p = $request->validate(['device_id' => 'required|integer|min:1', 'title' => 'required|string|max:180', 'assignee' => 'nullable|string|max:180',
            'due_miles' => 'nullable|numeric|min:0|max:10000000|required_without:due_date', 'due_date' => 'nullable|date|required_without:due_miles',
            'interval_miles' => 'nullable|numeric|min:1|max:1000000', 'interval_days' => 'nullable|integer|min:1|max:3650']);
        $record = $ops->save($this->install($request), 'service_plan', $p, $p['device_id']);
        $this->audit($request, 'maintenance.plan', $record->id);

        return response()->json(['saved' => true]);
    }

    public function service(Request $request, FleetOperationsService $ops)
    {
        $p = $request->validate(['plan_id' => 'required|integer|min:1', 'odometer' => 'required|numeric|min:0|max:10000000',
            'serviced_at' => 'required|date|before_or_equal:today', 'notes' => 'nullable|string|max:2000']);
        $ops->completeService($this->install($request), $p);
        $this->audit($request, 'maintenance.completed', $p['plan_id']);

        return response()->json(['saved' => true]);
    }

    public function alert(Request $request, int $alert, FleetOperationsService $ops)
    {
        $p = $request->validate(['assignee' => 'present|string|max:180', 'status' => 'required|in:open,in_progress,resolved',
            'resolution' => 'nullable|string|max:2000|required_if:status,resolved']);
        $install = $this->install($request);
        $record = $ops->record($install, $alert, 'alert');
        $ops->device($install, $record->device_id);
        $record = $ops->save($install, 'alert', $p, record: $record);
        $record->update(['status' => $p['status']]);
        $this->audit($request, 'alert.reviewed', $record->id);

        return response()->json(['saved' => true]);
    }

    public function trip(Request $request, int $trip, FleetOperationsService $ops)
    {
        $p = $request->validate(['job_id' => 'nullable|integer|min:1', 'expected_miles' => 'nullable|numeric|min:0.1|max:100000',
            'baseline_reference' => 'nullable|string|max:255|required_with:expected_miles',
            'extra_miles' => 'required|numeric|min:0|max:500', 'extra_percent' => 'required|numeric|min:0|max:500',
            'review_status' => 'required|in:pending,approved_detour,investigate', 'review_note' => 'nullable|string|max:2000|required_if:review_status,approved_detour']);
        $install = $this->install($request);
        $record = $ops->record($install, $trip, 'trip');
        $ops->device($install, $record->device_id);
        if (! empty($p['job_id'])) {
            $ops->record($install, $p['job_id'], 'job');
        }
        $p['confirmed_by'] = $request->user()->id;
        $p['confirmed_at'] = now()->toIso8601String();
        $ops->save($install, 'trip', $p, record: $record);
        $this->audit($request, 'trip.reviewed', $record->id);

        return response()->json(['saved' => true]);
    }

    public function path(Request $request, int $trip, FleetOperationsService $ops)
    {
        $install = $this->install($request);

        return response()->json($ops->path($install, $ops->record($install, $trip, 'trip')));
    }

    public function dispatch(Request $request, int $job, FleetOperationsService $ops, FleetService $fleet)
    {
        $install = $this->install($request);

        return response()->json($ops->dispatch($install, $ops->record($install, $job, 'job'), $fleet->bootstrap($install)['vehicles']));
    }

    private function install(Request $request): Installation
    {
        $install = $request->attributes->get('highlevel_session')->installation;
        abort_unless($install->setupAllowed(), 403);

        return $install;
    }

    private function audit(Request $request, string $action, ?int $id = null): void
    {
        app(LandlordOperatorActionAuditService::class)->record($this->install($request)->tenant_id, $request->user()->id, 'highlevel.fleet.'.$action,
            targetType: 'fleet_operation_record', targetId: $id);
    }
}
