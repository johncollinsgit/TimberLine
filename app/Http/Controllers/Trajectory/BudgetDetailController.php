<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\RecordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A small plan editor. It can never record a purchase, fund transfer or saved cash. */
class BudgetDetailController extends Controller
{
    public function show(Request $request, Space $space, Record $record)
    {
        $record = $this->admit($request, $space, $record);

        return $this->response($record);
    }

    public function update(Request $request, Space $space, Record $record, RecordService $service)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'changes' => 'required|array|min:1|max:3',
            'changes.monthly_cents' => 'sometimes|integer|min:0|max:100000000000',
            'changes.target_cents' => 'sometimes|integer|min:0|max:100000000000',
            'changes.target_on' => 'sometimes|date_format:Y-m-d|after_or_equal:1900-01-01|before:2200-01-01']);
        $data['version'] = (int) $data['version'];

        return DB::transaction(function () use ($request, $space, $record, $service, $data) {
            Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            $record = $this->admit($request, $space, $record, true);
            abort_unless($record->version === $data['version'], 409, 'This plan changed. Reopen it before saving.');
            $fields = $record->kind === 'budget' ? ['monthly_cents'] : ['monthly_cents', 'target_cents', 'target_on'];
            abort_unless(array_diff(array_keys($request->input('changes')), $fields) === [], 422, 'Only plan amounts and the reserve target date can change here.');
            $before = ['version' => $record->version, 'name' => $record->name, 'data' => $record->data];
            $values = [...$record->data, ...$data['changes']];
            if ($record->kind === 'budget') {
                $values['reviewed'] = true;
            }
            $saved = $service->save($request->user(), $space, $record->kind, $record->name, $values, $record, $record->version);
            $event = Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'record_id' => $saved->id,
                'action' => 'budget_plan_edit', 'before' => $before,
                'after' => ['version' => $saved->version, 'name' => $saved->name, 'data' => $saved->data, 'cash_changed' => false]]);

            return $this->response($saved, ['event_id' => $event->id, 'version' => $saved->version]);
        });
    }

    public function undo(Request $request, Space $space, Record $record, RecordService $service)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'event_id' => 'required|integer|min:1']);
        $data['version'] = (int) $data['version'];

        return DB::transaction(function () use ($request, $space, $record, $service, $data) {
            Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            $record = $this->admit($request, $space, $record, true);
            $event = Event::where('space_id', $space->id)->where('record_id', $record->id)->where('actor_id', $request->user()->id)
                ->where('action', 'budget_plan_edit')->findOrFail($data['event_id']);
            abort_unless($record->version === $data['version'] && ($event->after['version'] ?? null) === $record->version
                && ($event->after['data'] ?? null) === $record->data && ($event->after['name'] ?? null) === $record->name, 409, 'This plan changed after your edit. Refresh before changing it.');
            $saved = $service->save($request->user(), $space, $record->kind, $event->before['name'], $event->before['data'], $record, $record->version);
            Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'record_id' => $saved->id,
                'action' => 'budget_plan_undo', 'after' => ['undone_event_id' => $event->id, 'version' => $saved->version, 'cash_changed' => false]]);

            return $this->response($saved);
        });
    }

    private function admit(Request $request, Space $space, Record $record, bool $lock = false): Record
    {
        app(FinanceAccess::class)->authorize($request->user(), $space->fresh());
        $query = Record::where('space_id', $space->id)->where('active', true)->whereIn('kind', ['budget', 'goal']);
        $record = ($lock ? $query->lockForUpdate() : $query)->findOrFail($record->id);
        abort_unless($record->kind === 'budget' ? ($record->data['purpose'] ?? null) === 'spending'
            : in_array($record->data['goal_type'] ?? null, ['savings', 'emergency'], true), 422, 'Open the dedicated debt or cash plan for this item.');

        return $record;
    }

    private function response(Record $record, ?array $undo = null)
    {
        return response()->json(['record' => $record->only(['id', 'space_id', 'kind', 'name', 'data', 'version', 'active']),
            'plan_only' => true, 'cash_changed' => false, 'undo' => $undo])->header('Cache-Control', 'private, no-store');
    }
}
