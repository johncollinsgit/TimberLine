<?php

namespace App\Services\Team\Studio;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Operations\OperationsWorkspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Additive authoring on the existing encrypted operations store and revision log. */
class TeamsStudio
{
    public function __construct(private OperationsAccess $access, private EventCostCalculator $costs) {}

    public function workspace(Tenant $tenant, User $user, TeamArea $area): array
    {
        $this->access->area($tenant, $user, $area->id);
        $admin = $this->access->admin($this->access->actor($tenant, $user));
        $blueprint = $this->published($tenant, $area, 'blueprint');
        $result = ['contract_version' => 2, 'area' => app(OperationsWorkspace::class)->areaView($area), 'blueprint' => $blueprint ? $this->view($blueprint) : null, 'capabilities' => ['manage' => $admin, 'budget' => $admin, 'write' => $this->access->mayWrite($tenant, $user, $area->id), 'native' => ($area->settings['source_authority'] ?? 'teams') === 'teams'], 'authority' => $this->authority($tenant, $area)];
        if ($admin) {
            $result['definitions'] = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->whereIn('kind', ['blueprint', 'cost_policy'])->where('status', '!=', 'withdrawn')->orderByDesc('updated_at')->limit(100)->get()->map(fn ($r) => $this->view($r))->all();
            $result['drafts'] = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event_draft', 'assigned_user_id' => $user->id])->where('status', 'draft')->orderByDesc('updated_at')->limit(30)->get()->map(fn ($r) => $this->view($r))->all();
        }

        return $result;
    }

    public function authority(Tenant $tenant, TeamArea $area): array
    {
        $source = $area->settings['source_authority'] ?? 'teams';
        $preset = config('team_operations.presets.'.$tenant->slug.'.'.$area->profile, []);
        $protected = isset($preset['migration_not_before']);

        return ['provider' => $protected ? 'asana' : $source, 'operational_sync' => true, 'bulk_migration_allowed' => false, 'migration_windows_available' => $protected && now('America/New_York')->year >= 2027, 'cutover_enabled' => false, 'notice' => $protected ? 'Asana remains authoritative. In 2027 an owner must choose and accept each work area migration; no automatic cutover.' : 'Native Teams is available without an Asana connection.'];
    }

    public function saveDefinition(Tenant $tenant, User $user, TeamArea $area, array $input): array
    {
        $this->access->financial($tenant, $user);
        $i = Validator::make($input, ['id' => 'required|uuid', 'request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:0', 'kind' => 'required|in:blueprint,cost_policy', 'publish' => 'required|boolean', 'definition' => 'required|array'])->validate();
        abort_if(strlen(json_encode($i['definition'])) > 250000, 422, 'Definition exceeds the size limit.');
        $definition = $i['kind'] === 'cost_policy' ? $this->costs->policy($i['definition'], $i['publish']) : $this->blueprint($i['definition']);

        return $this->once($tenant, $user, $area, $i, function () use ($tenant, $user, $area, $i, $definition) {
            $record = $this->editable($tenant, $area, $i['id'], $i['kind'], $i['expected_version']);
            abort_if($record->exists && $record->status === 'published', 409, 'Published definitions are immutable. Create a new draft version.');
            $record->fill(['title' => $definition['name'], 'status' => $i['publish'] ? 'published' : 'draft', 'visibility' => 'admin', 'data' => ['definition' => $definition, 'published_at' => $i['publish'] ? now()->toIso8601String() : null, 'author_id' => (string) $user->id], 'version' => $record->version + 1])->save();
            $this->revision($record, $user);
            if ($i['publish']) {
                $settings = $area->fresh()->settings ?? [];
                $settings['published_'.$i['kind']] = $record->id;
                $area->update(['settings' => $settings, 'version' => $area->version + 1]);
            }

            return $this->view($record);
        });
    }

    public function saveDraft(Tenant $tenant, User $user, TeamArea $area, array $input): array
    {
        $this->access->financial($tenant, $user);
        $i = Validator::make($input, ['id' => 'required|uuid', 'request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:0', 'title' => 'required|string|max:500', 'step' => 'required|integer|min:0|max:4', 'logistics' => 'present|array', 'budget_inputs' => 'present|array', 'policy_id' => 'nullable|uuid', 'copy_from' => 'nullable|uuid', 'revision' => 'nullable|array:event_id,event_version,budget_id,reason', 'revision.event_id' => 'required_with:revision|uuid', 'revision.event_version' => 'required_with:revision|integer|min:1', 'revision.budget_id' => 'present_with:revision|nullable|uuid', 'revision.reason' => 'nullable|string|max:3000'])->validate();
        $logistics = $this->logistics($i['logistics']);
        if (isset($logistics['series_id'])) {
            $series = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'series'])->findOrFail($logistics['series_id']);
            $this->access->record($tenant, $user, $series);
        }
        abort_unless(array_diff(array_map('strval', $logistics['staff_ids'] ?? []), $this->access->actor($tenant, $user)['verified_user_ids']) === [], 422, 'Choose current verified staff in this workspace.');
        abort_if(strlen(json_encode($input)) > 300000, 422, 'Draft exceeds the size limit.');

        return $this->once($tenant, $user, $area, $i, function () use ($tenant, $user, $area, $i, $logistics) {
            $draft = $this->editable($tenant, $area, $i['id'], 'event_draft', $i['expected_version']);
            abort_unless(! $draft->exists || ($draft->status === 'draft' && (int) $draft->assigned_user_id === (int) $user->id), 409, 'This draft is already created or belongs to another author.');
            abort_if($draft->exists && ($draft->data['revision']['event_id'] ?? null) !== ($i['revision']['event_id'] ?? null), 409, 'Keep this draft attached to its original event or creation flow.');
            if (isset($i['revision'])) {
                $target = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event'])->findOrFail($i['revision']['event_id']);
                $this->access->record($tenant, $user, $target);
            }
            if (isset($i['copy_from'])) {
                $source = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event'])->findOrFail($i['copy_from']);
                $this->access->record($tenant, $user, $source);
            }
            if (isset($i['policy_id'])) {
                $this->policyRecord($tenant, $area, $i['policy_id']);
            }
            $draft->fill(['title' => $i['title'], 'status' => 'draft', 'visibility' => 'admin', 'assigned_user_id' => $user->id, 'data' => ['step' => $i['step'], 'logistics' => $logistics, 'budget_inputs' => $i['budget_inputs'], 'policy_id' => $i['policy_id'] ?? null, 'copied_from' => $i['copy_from'] ?? null, 'revision' => $i['revision'] ?? null], 'version' => $draft->version + 1])->save();
            $this->revision($draft, $user);

            return $this->view($draft);
        });
    }

    public function preview(Tenant $tenant, User $user, TeamArea $area, array $input): array
    {
        $this->access->financial($tenant, $user);
        $i = Validator::make($input, ['policy_id' => 'required|uuid', 'inputs' => 'required|array'])->validate();
        $policy = $this->policyRecord($tenant, $area, $i['policy_id']);

        return [...$this->costs->preview($i['inputs'], $policy->data['definition']), 'policy_id' => $policy->id, 'policy_version' => $policy->version, 'policy_status' => $policy->status];
    }

    public function createEvent(Tenant $tenant, User $user, TeamArea $area, array $input): array
    {
        $this->access->financial($tenant, $user);
        $i = Validator::make($input, ['request_id' => 'required|uuid', 'draft_id' => 'required|uuid', 'expected_version' => 'required|integer|min:1', 'accept_review_items' => 'accepted'])->validate();

        return $this->once($tenant, $user, $area, $i, function () use ($tenant, $user, $area, $i) {
            $draft = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event_draft', 'assigned_user_id' => $user->id])->lockForUpdate()->findOrFail($i['draft_id']);
            abort_unless($draft->version === $i['expected_version'] && $draft->status === 'draft', 409, 'Refresh this draft before creating.');
            $data = $draft->data;
            abort_if(isset($data['revision']), 422, 'Use the budget revision flow for this draft.');
            $logistics = $this->logistics($data['logistics'], true);
            $policy = $this->policyRecord($tenant, $area, $data['policy_id'] ?? '');
            abort_unless($policy->status === 'published', 422, 'Publish a reviewed cost policy first.');
            $inputs = $this->costs->inputs($data['budget_inputs']);
            abort_unless($inputs['starts_on'] === $logistics['starts_on'] && $inputs['ends_on'] === $logistics['ends_on'], 422, 'Budget dates must match the event.');
            $preview = $this->costs->preview($inputs, $policy->data['definition']);
            // Creation is durable locally; the existing reviewed Asana-create adapter
            // retains authority and owns uncertain provider outcomes.
            $eventId = (string) Str::uuid();
            $budgetId = (string) Str::uuid();
            $authority = $this->authority($tenant, $area);
            $event = TeamOperationRecord::create(['id' => $eventId, 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => $draft->title, 'version' => 1, 'status' => $authority['provider'] === 'asana' ? 'draft' : 'research', 'source_key' => hash('sha256', 'wizard:'.$draft->id), 'starts_on' => $logistics['starts_on'], 'ends_on' => $logistics['ends_on'], 'data' => array_diff_key($logistics, ['starts_on' => true, 'ends_on' => true]) + ['wizard_draft_id' => $draft->id, 'budget_id' => $budgetId, '_teams_enrichment' => ['budget_id' => $budgetId, 'wizard_draft_id' => $draft->id], 'source_authority' => $authority['provider'], 'source_sync_status' => $authority['provider'] === 'asana' ? 'awaiting_source_creation' : 'native']]);
            $budget = TeamOperationRecord::create(['id' => $budgetId, 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event_budget', 'title' => $draft->title.' budget', 'version' => 1, 'status' => 'frozen', 'source_key' => hash('sha256', 'budget:'.$draft->id), 'visibility' => 'admin', 'data' => ['event_id' => $eventId, 'policy_id' => $policy->id, 'policy_version' => $policy->version, 'policy_snapshot' => $policy->data['definition'], 'inputs' => $inputs, 'preview' => $preview, 'frozen_at' => now()->toIso8601String()]]);
            $draft->update(['status' => 'created', 'version' => $draft->version + 1, 'data' => $data + ['event_id' => $eventId, 'budget_id' => $budgetId]]);
            foreach ([$event, $budget, $draft] as $record) {
                $this->revision($record, $user);
            }

            return ['event_id' => $eventId, 'budget_id' => $budgetId, 'budget' => $preview, 'authority' => $authority, 'source_status' => $event->data['source_sync_status']];
        });
    }

    public function reviseBudget(Tenant $tenant, User $user, TeamArea $area, array $input): array
    {
        $this->access->financial($tenant, $user);
        $i = Validator::make($input, ['request_id' => 'required|uuid', 'draft_id' => 'required|uuid', 'expected_version' => 'required|integer|min:1', 'event_id' => 'required|uuid', 'expected_event_version' => 'required|integer|min:1', 'expected_budget_id' => 'present|nullable|uuid', 'reason' => 'required|string|min:12|max:3000', 'accept_review_items' => 'accepted'])->validate();

        return $this->once($tenant, $user, $area, $i, function () use ($tenant, $user, $area, $i) {
            $event = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event'])->lockForUpdate()->findOrFail($i['event_id']);
            $this->access->record($tenant, $user, $event);
            $previous = $event->data['_teams_enrichment']['budget_id'] ?? $event->data['budget_id'] ?? null;
            abort_unless($event->version === $i['expected_event_version'] && $previous === $i['expected_budget_id'], 409, 'The event or latest budget changed. Review the current revision.');
            $draft = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event_draft', 'assigned_user_id' => $user->id])->lockForUpdate()->findOrFail($i['draft_id']);
            abort_unless($draft->version === $i['expected_version'] && $draft->status === 'draft', 409, 'Refresh the budget draft before saving.');
            $target = $draft->data['revision'] ?? [];
            abort_unless(($target['event_id'] ?? null) === $event->id && ($target['event_version'] ?? null) === $i['expected_event_version'] && ($target['budget_id'] ?? null) === $i['expected_budget_id'] && ($target['reason'] ?? null) === $i['reason'], 409, 'The revision must match the reviewed saved draft.');
            $policy = $this->policyRecord($tenant, $area, $draft->data['policy_id'] ?? '');
            abort_unless($policy->status === 'published', 422, 'Choose an explicitly reviewed published policy version.');
            $inputs = $this->costs->inputs($draft->data['budget_inputs']);
            abort_unless($event->starts_on && $event->ends_on && $inputs['starts_on'] === $event->starts_on->toDateString() && $inputs['ends_on'] === $event->ends_on->toDateString(), 422, 'Budget dates must match the recorded event occurrence.');
            $preview = $this->costs->preview($inputs, $policy->data['definition']);
            $budget = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event_budget', 'title' => $event->title.' budget', 'version' => 1, 'status' => 'frozen', 'source_key' => hash('sha256', 'budget:'.$draft->id), 'visibility' => 'admin', 'data' => ['event_id' => $event->id, 'previous_budget_id' => $previous, 'based_event_version' => $event->version, 'reason' => $i['reason'], 'policy_id' => $policy->id, 'policy_version' => $policy->version, 'policy_snapshot' => $policy->data['definition'], 'inputs' => $inputs, 'preview' => $preview, 'frozen_at' => now()->toIso8601String()]]);
            $data = $event->data;
            $data['budget_id'] = $budget->id;
            $data['_teams_enrichment']['budget_id'] = $budget->id;
            $event->update(['data' => $data, 'version' => $event->version + 1]);
            $draft->update(['status' => 'budget_created', 'version' => $draft->version + 1, 'data' => $draft->data + ['event_id' => $event->id, 'budget_id' => $budget->id]]);
            foreach ([$budget, $event, $draft] as $record) {
                $this->revision($record, $user);
            }

            return ['event_id' => $event->id, 'event_version' => $event->version, 'budget_id' => $budget->id, 'budget' => $preview, 'source_status' => 'budget_only'];
        });
    }

    public function reviewOccurrence(Tenant $tenant, User $user, TeamArea $area, array $input): array
    {
        $this->access->financial($tenant, $user);
        $i = Validator::make($input, [
            'id' => 'required|uuid', 'request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:0',
            'series_id' => 'required|uuid', 'occurrence_id' => 'required|uuid', 'financial_id' => 'nullable|uuid',
            'staff_ids' => 'present|array|max:100', 'staff_ids.*' => 'uuid|distinct', 'allocation_reviewed' => 'required|boolean',
            'reason' => 'required|string|min:12|max:3000', 'venue' => 'required|string|max:1000', 'timezone' => 'required|timezone:all',
            'latitude' => 'required_with:longitude|nullable|numeric|between:-90,90', 'longitude' => 'required_with:latitude|nullable|numeric|between:-180,180',
            'verified_selling_days' => 'nullable|integer|min:1|max:366', 'verified_operating_minutes' => 'nullable|integer|min:1|max:527040',
            'daily_hours' => 'present|array|max:366', 'daily_hours.*.date' => 'required|date_format:Y-m-d|distinct', 'daily_hours.*.opens' => 'required|date_format:H:i', 'daily_hours.*.closes' => 'required|date_format:H:i',
        ])->validate();

        return $this->once($tenant, $user, $area, $i, function () use ($tenant, $user, $area, $i) {
            $series = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'series'])->findOrFail($i['series_id']);
            $this->access->record($tenant, $user, $series);
            $refs = [];
            foreach (array_unique([$i['occurrence_id'], ...$i['staff_ids'], ...array_filter([$i['financial_id'] ?? null])]) as $id) {
                $record = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->whereIn('kind', ['event', 'financial', 'staff'])->findOrFail($id);
                $this->access->record($tenant, $user, $record);
                $refs[$id] = $record;
            }
            abort_unless(in_array($refs[$i['occurrence_id']]->kind, ['event', 'financial'], true), 422);
            $primary = isset($i['financial_id']) ? $refs[$i['financial_id']] : null;
            abort_if($primary && $primary->kind !== 'financial', 422);
            $links = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event_link', 'status' => 'accepted'])->whereKeyNot($i['id'])->get();
            abort_if($links->contains(fn ($r) => $r->data['occurrence_id'] === $i['occurrence_id'] || ($primary && ($r->data['financial_id'] ?? null) === $primary->id)), 409, 'This occurrence or financial total already has a reviewed mapping. Edit that mapping instead.');
            $link = $this->editable($tenant, $area, $i['id'], 'event_link', $i['expected_version']);
            $link->fill(['title' => $refs[$i['occurrence_id']]->title, 'status' => 'accepted', 'visibility' => 'admin', 'data' => array_diff_key($i, array_flip(['id', 'request_id', 'expected_version'])) + ['starts_on' => $refs[$i['occurrence_id']]->starts_on?->toDateString(), 'ends_on' => $refs[$i['occurrence_id']]->ends_on?->toDateString(), 'financial_version' => $primary?->version, 'reviewed_by' => (string) $user->id, 'reviewed_at' => now()->toIso8601String()], 'version' => $link->version + 1])->save();
            $this->revision($link, $user);

            return $this->view($link);
        });
    }

    public function published(Tenant $tenant, TeamArea $area, string $kind): ?TeamOperationRecord
    {
        return TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => $kind, 'status' => 'published'])->find($area->settings['published_'.$kind] ?? '');
    }

    private function policyRecord(Tenant $tenant, TeamArea $area, string $id): TeamOperationRecord
    {
        return TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'cost_policy'])->whereIn('status', ['draft', 'published'])->findOrFail($id);
    }

    private function once(Tenant $tenant, User $user, TeamArea $area, array $input, callable $perform): array
    {
        $this->access->area($tenant, $user, $area->id, true);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $user, $area, $input, $hash, $perform) {
            TeamArea::where('tenant_id', $tenant->id)->whereKey($area->id)->lockForUpdate()->firstOrFail();
            $area->refresh();
            $this->access->financial($tenant, $user);
            $previous = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($previous) {
                abort_unless($previous->area_id === $area->id && $previous->input_hash === $hash && $previous->kind === 'studio.command', 409, 'This command ID belongs to different input.');

                return $previous->result;
            }
            $result = $perform();
            TeamOperationAttempt::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $user->id, 'kind' => 'studio.command', 'status' => 'completed', 'request_id' => $input['request_id'], 'input_hash' => $hash, 'payload' => $input, 'result' => $result]);

            return $result;
        }, 3);
    }

    private function editable(Tenant $tenant, TeamArea $area, string $id, string $kind, int $version): TeamOperationRecord
    {
        $record = TeamOperationRecord::whereKey($id)->first();
        if ($record) {
            abort_unless((int) $record->tenant_id === (int) $tenant->id && $record->area_id === $area->id && $record->kind === $kind, 404);
            abort_unless($record->version === $version, 409, 'This version changed. Refresh before saving.');

            return $record;
        }
        abort_unless($version === 0, 409);

        return new TeamOperationRecord(['id' => $id, 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => $kind, 'source_key' => hash('sha256', $kind.':'.$id), 'version' => 0]);
    }

    private function revision(TeamOperationRecord $record, User $user): void
    {
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $record->tenant_id, 'record_id' => $record->id, 'user_id' => $user->id, 'version' => $record->version, 'payload' => Crypt::encryptString(json_encode($this->view($record), JSON_THROW_ON_ERROR)), 'created_at' => now()]);
    }

    private function view(TeamOperationRecord $record): array
    {
        return ['id' => $record->id, 'kind' => $record->kind, 'title' => $record->title, 'status' => $record->status, 'version' => $record->version, 'data' => $record->data, 'updated_at' => $record->updated_at?->toIso8601String()];
    }

    private function logistics(array $input, bool $complete = false): array
    {
        return Validator::make($input, [
            'starts_on' => ($complete ? 'required' : 'nullable').'|date_format:Y-m-d', 'ends_on' => ($complete ? 'required' : 'nullable').'|date_format:Y-m-d|after_or_equal:starts_on',
            'timezone' => [$complete ? 'required' : 'nullable', 'timezone:all'],
            'venue' => 'nullable|string|max:500', 'address' => 'nullable|string|max:1000', 'city' => 'nullable|string|max:100', 'state' => 'nullable|string|max:100', 'booth' => 'nullable|string|max:100',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'setup_time' => 'nullable|string|max:2000', 'loadout_time' => 'nullable|string|max:2000', 'instructions' => 'nullable|string|max:10000',
            'daily_hours' => 'sometimes|array|max:366', 'daily_hours.*.date' => 'required|date_format:Y-m-d|distinct', 'daily_hours.*.opens' => 'required|date_format:H:i', 'daily_hours.*.closes' => 'required|date_format:H:i',
            'contacts' => 'sometimes|array|max:30', 'contacts.*.name' => 'required|string|max:150', 'contacts.*.email' => 'nullable|email|max:254', 'contacts.*.phone' => 'nullable|string|max:80',
            'series_id' => 'nullable|uuid', 'staff_ids' => 'sometimes|array|max:100', 'staff_ids.*' => 'integer|distinct',
        ])->validate();
    }

    private function blueprint(array $input): array
    {
        $definition = Validator::make($input, [
            'name' => 'required|string|max:100', 'terminology' => 'present|array|max:30', 'terminology.*' => 'string|max:80',
            'stages' => 'required|array|min:1|max:30', 'stages.*.id' => 'required|regex:/^[a-z][a-z0-9_]{0,31}$/D|distinct', 'stages.*.name' => 'required|string|max:80',
            'fields' => 'present|array|max:100', 'fields.*.id' => 'required|regex:/^[a-z][a-z0-9_]{0,59}$/D|distinct', 'fields.*.name' => 'required|string|max:100',
            'fields.*.type' => 'required|in:text,number,money,date,person,select,multiselect,boolean', 'fields.*.required' => 'required|boolean',
            'fields.*.options' => 'sometimes|array|max:100', 'fields.*.options.*' => 'string|max:100',
            'forms' => 'present|array|max:30', 'forms.*.id' => 'required|string|max:60|distinct', 'forms.*.name' => 'required|string|max:100', 'forms.*.field_ids' => 'present|array|max:100', 'forms.*.field_ids.*' => 'string|max:60',
            'templates' => 'present|array|max:30', 'templates.*.id' => 'required|string|max:60|distinct', 'templates.*.name' => 'required|string|max:100', 'templates.*.checklist' => 'present|array|max:100', 'templates.*.checklist.*' => 'string|max:500',
            'dashboards' => 'present|array|max:30', 'dashboards.*.id' => 'required|string|max:60|distinct', 'dashboards.*.name' => 'required|string|max:100', 'dashboards.*.widgets' => 'required|array|max:20', 'dashboards.*.widgets.*' => 'in:workload,calendar,board,financials,events,readiness',
        ])->validate();
        foreach ($definition['forms'] as $form) {
            abort_unless(count(array_diff($form['field_ids'], array_column($definition['fields'], 'id'))) === 0, 422, 'Forms can only reference defined fields.');
        }

        return $definition;
    }
}
