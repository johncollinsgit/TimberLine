<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\BudgetDefaultsService;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\MonthlyBudgetService;
use App\Services\Trajectory\RecordService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A small plan editor. It can never record a purchase, fund transfer or saved cash. */
class BudgetDetailController extends Controller
{
    public function show(Request $request, Space $space, Record $record)
    {
        $record = $this->admit($request, $space, $record);
        $input = $request->validate(['month' => 'sometimes|date_format:Y-m']);
        $aggregate = null;
        $context = null;
        if ($record->kind === 'budget') {
            $records = Record::where('space_id', $space->id)->where('kind', 'budget')->where('active', true)->get();
            $context = app(BudgetDefaultsService::class)->contextHash($space, $records, $record->data['category'], $record->data['program_record_id'] ?? null);
            if (isset($input['month'])) {
                $sources = $this->selectedSources($space, $record, $records, CarbonImmutable::parse($input['month'].'-01', $space->timezone));
                abort_unless($sources->contains('id', $record->id), 409, 'This month’s allowance changed. Reopen the category.');
                $aggregate = ['record_ids' => $sources->pluck('id')->all(), 'versions' => $sources->mapWithKeys(fn ($source) => [$source->id => $source->version])->all(),
                    'cash_spending_pending' => $sources->contains(fn ($source) => $source->data['cash_spending_pending'] ?? false),
                    'category_review_pending' => $sources->contains(fn ($source) => $source->data['category_review_pending'] ?? false),
                    'monthly_cents' => (int) $sources->sum(fn ($source) => $source->data['monthly_cents'])];
            }
        }

        return $this->response($record, null, $context, $aggregate);
    }

    public function update(Request $request, Space $space, Record $record, RecordService $service)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'month' => 'sometimes|date_format:Y-m',
            'plan_context' => 'sometimes|string|size:64', 'changes' => 'required|array|min:1|max:3',
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
            $create = false;
            if ($record->kind === 'budget') {
                $values['reviewed'] = true;
                if (isset($data['month'])) {
                    abort_unless(isset($data['plan_context']) && hash_equals($this->contextHash($space, $record), $data['plan_context']), 409, 'This category’s plan changed. Reopen it before saving.');
                    $month = CarbonImmutable::parse($data['month'].'-01', $space->timezone);
                    $records = Record::where('space_id', $space->id)->where('kind', 'budget')->where('active', true)->get();
                    $sources = $this->selectedSources($space, $record, $records, $month);
                    abort_unless($sources->contains('id', $record->id), 409, 'A newer plan applies to this month. Reopen the category.');
                    $create = $sources->count() > 1 || $values['effective_on'] < $month->toDateString() || empty($values['ends_on']) || $values['ends_on'] > $month->endOfMonth()->toDateString();
                    if ($create) {
                        abort_if($sources->count() > 1 && $sources->contains(fn ($source) => $source->data['rollover'] ?? false), 422, 'Adjust rolling funds separately from a combined monthly allowance.');
                        $before['sources'] = $sources->map(fn ($source) => $source->only(['id', 'name', 'data', 'version']))->all();
                        unset($values['source_id'], $values['supersedes_record_id'], $values['supersedes_record_ids'], $values['reported_cash_spent_cents'], $values['reported_cash_as_of']);
                        $values['month_only'] = $sources->contains(fn ($source) => empty($source->data['ends_on']) || $source->data['ends_on'] > $month->endOfMonth()->toDateString());
                        if ($sources->count() === 1) {
                            $values['supersedes_record_id'] = $record->id;
                        } else {
                            $values['supersedes_record_ids'] = $sources->pluck('id')->all();
                        }
                        $cashReports = $sources->filter(fn ($source) => substr($source->data['reported_cash_as_of'] ?? '', 0, 7) === $data['month']);
                        $values['cash_spending_pending'] = $sources->contains(fn ($source) => ($source->data['cash_spending_pending'] ?? false)
                            || (isset($source->data['reported_cash_as_of']) && substr($source->data['reported_cash_as_of'], 0, 7) !== $data['month'])) || $cashReports->count() > 1;
                        $values['category_review_pending'] = $sources->contains(fn ($source) => $source->data['category_review_pending'] ?? false);
                        if ($cashReports->count() === 1) {
                            $values['reported_cash_spent_cents'] = $cashReports->first()->data['reported_cash_spent_cents'];
                            $values['reported_cash_as_of'] = $cashReports->first()->data['reported_cash_as_of'];
                        }
                        $values['effective_on'] = max($month->toDateString(), $sources->max(fn ($source) => $source->data['effective_on']));
                        $values['ends_on'] = $sources->min(fn ($source) => empty($source->data['ends_on']) || $source->data['ends_on'] < $month->toDateString()
                            ? $month->endOfMonth()->toDateString() : min($month->endOfMonth()->toDateString(), $source->data['ends_on']));
                    }
                }
            }
            $saved = $service->save($request->user(), $space, $record->kind, $record->name, $values, $create ? null : $record, $create ? null : $record->version);
            $event = Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'record_id' => $saved->id,
                'action' => $create ? 'budget_month_override' : 'budget_plan_edit', 'before' => $before,
                'after' => ['version' => $saved->version, 'name' => $saved->name, 'data' => $saved->data, 'cash_changed' => false,
                    'plan_context' => $saved->kind === 'budget' ? $this->contextHash($space, $saved) : null]]);

            return $this->response($saved, ['event_id' => $event->id, 'version' => $saved->version], $saved->kind === 'budget' ? $this->contextHash($space, $saved) : null);
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
                ->whereIn('action', ['budget_plan_edit', 'budget_month_override'])->findOrFail($data['event_id']);
            abort_unless($record->version === $data['version'] && ($event->after['version'] ?? null) === $record->version
                && ($event->after['data'] ?? null) === $record->data && ($event->after['name'] ?? null) === $record->name, 409, 'This plan changed after your edit. Refresh before changing it.');
            abort_if($record->kind === 'budget' && isset($event->after['plan_context']) && ! hash_equals($event->after['plan_context'], $this->contextHash($space, $record)), 409, 'Another allowance changed after this edit. Reopen the category before using Undo.');
            if ($event->action === 'budget_month_override') {
                $record->update(['active' => false, 'version' => $record->version + 1]);
                Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'record_id' => $record->id,
                    'action' => 'budget_plan_undo', 'after' => ['undone_event_id' => $event->id, 'version' => $record->version, 'cash_changed' => false]]);

                return response()->json(['removed_override' => true, 'cash_changed' => false])->header('Cache-Control', 'private, no-store');
            }
            $saved = $service->save($request->user(), $space, $record->kind, $event->before['name'], $event->before['data'], $record, $record->version);
            Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'record_id' => $saved->id,
                'action' => 'budget_plan_undo', 'after' => ['undone_event_id' => $event->id, 'version' => $saved->version, 'cash_changed' => false]]);

            return $this->response($saved, null, $saved->kind === 'budget' ? $this->contextHash($space, $saved) : null);
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

    private function contextHash(Space $space, Record $record): string
    {
        return app(BudgetDefaultsService::class)->contextHash($space, Record::where('space_id', $space->id)->where('kind', 'budget')->where('active', true)->get(), $record->data['category'], $record->data['program_record_id'] ?? null);
    }

    private function selectedSources(Space $space, Record $record, \Illuminate\Support\Collection $records, CarbonImmutable $month): \Illuminate\Support\Collection
    {
        $selected = app(MonthlyBudgetService::class)->build($space, $records, $month);

        return $records->whereIn('id', $selected['record_ids'])->filter(fn ($source) => $source->data['category'] === $record->data['category']
            && (int) ($source->data['program_record_id'] ?? 0) === (int) ($record->data['program_record_id'] ?? 0));
    }

    private function response(Record $record, ?array $undo = null, ?string $context = null, ?array $aggregate = null)
    {
        return response()->json(['record' => $record->only(['id', 'space_id', 'kind', 'name', 'data', 'version', 'active']),
            'plan_context' => $context, 'aggregate' => $aggregate, 'plan_only' => true, 'cash_changed' => false, 'undo' => $undo])->header('Cache-Control', 'private, no-store');
    }
}
