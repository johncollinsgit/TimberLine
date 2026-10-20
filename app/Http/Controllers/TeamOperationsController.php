<?php

namespace App\Http\Controllers;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Operations\OperationsProviders;
use App\Services\Team\Operations\OperationsStatistics;
use App\Services\Team\Operations\OperationsSync;
use App\Services\Team\Operations\OperationsWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TeamOperationsController extends Controller
{
    public function __construct(private OperationsAccess $access, private OperationsWorkspace $workspace, private OperationsProviders $providers) {}

    private function tenant(Request $r): Tenant
    {
        $t = $r->attributes->get('current_tenant');
        abort_unless($t instanceof Tenant, 403);
        $this->access->actor($t, $r->user());

        return $t;
    }

    private function area(Request $r, bool $write = false): TeamArea
    {
        $r->validate(['area' => 'required|uuid']);

        return $this->access->area($this->tenant($r), $r->user(), $r->input('area'), $write);
    }

    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function areas(Request $r)
    {
        $t = $this->tenant($r);

        return $this->json(['items' => $this->workspace->areas($t, $r->user()), 'profiles' => config('team_operations.profiles'), 'can_manage' => $this->access->admin($this->access->actor($t, $r->user()))]);
    }

    public function personal(Request $r)
    {
        $i = $r->validate(['q' => 'nullable|string|max:200', 'mine' => 'sometimes|boolean', 'page' => 'nullable|integer|min:1|max:1000']);

        return $this->json($this->workspace->personal($this->tenant($r), $r->user(), $i['q'] ?? '', $r->boolean('mine'), (int) ($i['page'] ?? 1)));
    }

    public function templates(Request $r)
    {
        return $this->json(app(\App\Services\Team\Operations\OperationsTemplates::class)->catalog($this->tenant($r), $r->user(), $this->area($r)));
    }

    public function templatePreview(Request $r)
    {
        $area = $this->area($r, true);
        $tenant = $this->tenant($r);
        $input = $r->validate(['record_id' => 'required|uuid', 'recipient_user_id' => 'required|integer', 'template_id' => 'nullable|uuid', 'starter_id' => 'nullable|string|max:100', 'subject' => 'nullable|string|max:200', 'text' => 'nullable|string|max:20000', 'cta_label' => 'nullable|string|max:100', 'cta_url' => 'nullable|url:https|max:2000']);
        $record = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->findOrFail($input['record_id']);
        abort_unless(in_array((string) $input['recipient_user_id'], $this->access->actor($tenant, $r->user())['verified_user_ids'], true), 422);

        return $this->json(app(\App\Services\Team\Operations\OperationsTemplates::class)->render($tenant, $r->user(), $area, $record, \App\Models\User::findOrFail($input['recipient_user_id']), $input));
    }

    public function automationPreview(Request $r)
    {
        $area = $this->area($r, true);
        $tenant = $this->tenant($r);
        $this->access->financial($tenant, $r->user());
        $input = $r->validate(['data' => 'required|array', 'data.trigger' => 'required|string|max:50', 'data.channel' => 'sometimes|in:inbox', 'data.timezone' => 'sometimes|timezone', 'data.local_time' => 'sometimes|date_format:H:i', 'data.quiet_start' => 'sometimes|date_format:H:i', 'data.quiet_end' => 'sometimes|date_format:H:i', 'data.days_after' => 'nullable|integer|min:1|max:730', 'data.stage' => 'nullable|string|max:32', 'data.enabled' => 'sometimes|boolean', 'data.audience_ids' => 'required|array|max:100', 'data.audience_ids.*' => 'integer|distinct']);
        $engine = app(\App\Services\Team\Operations\OperationsReminders::class);
        $engine->validate($area, $input['data']);

        return $this->json($engine->preview($tenant, $r->user(), $area, $input['data']));
    }

    public function review(Request $r, string $id)
    {
        $t = $this->tenant($r);
        $record = TeamOperationRecord::where('tenant_id', $t->id)->findOrFail($id);
        $i = $r->validate(['request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:1', 'reviewed' => 'accepted', 'action' => 'required|in:fields,forecast,square', 'field_indices' => 'required_if:action,fields|array|max:50', 'field_indices.*' => 'integer|min:0|max:1000|distinct', 'low_cents' => 'required_if:action,forecast|integer|min:-100000000000|max:100000000000', 'base_cents' => 'required_if:action,forecast|integer|min:-100000000000|max:100000000000', 'high_cents' => 'required_if:action,forecast|integer|min:-100000000000|max:100000000000', 'reason' => 'required_if:action,forecast|string|min:8|max:2000', 'order_ids' => 'required_if:action,square|array|max:1000', 'order_ids.*' => 'string|max:192|distinct']);
        $saved = app(\App\Services\Team\Operations\OperationsEnrichment::class)->review($t, $r->user(), $record, $i);
        $this->access->record($t, $r->user(), $saved);

        return $this->json($this->workspace->view($saved, $this->access->mayWrite($t, $r->user(), $saved->area_id), $this->access->admin($this->access->actor($t, $r->user()))));
    }

    public function createArea(Request $r)
    {
        $input = $r->validate(['name' => 'required|string|max:100', 'profile' => 'required|in:events,wholesale']);

        return $this->json($this->workspace->areaView($this->workspace->createArea($this->tenant($r), $r->user(), $input)));
    }

    public function members(Request $r, string $id)
    {
        $t = $this->tenant($r);
        $this->access->area($t, $r->user(), $id);

        $areaMembers = DB::table('team_area_members')->where(['tenant_id' => $t->id, 'area_id' => $id])->pluck('user_id')->map(fn ($i) => (int) $i)->all();

        return $this->json(['items' => $t->users()->wherePivot('membership_active', true)->where('users.is_active', true)->whereNotNull('users.email_verified_at')->orderBy('users.name')->get()->map(fn ($u) => ['id' => (string) $u->id, 'name' => $u->name, 'area_access' => in_array($u->pivot->role, ['owner', 'admin'], true) || in_array((int) $u->id, $areaMembers, true)])->all()]);
    }

    public function updateArea(Request $r, string $id)
    {
        $t = $this->tenant($r);
        $this->access->financial($t, $r->user());
        $area = $this->access->area($t, $r->user(), $id, true);
        $input = $r->validate(['expected_version' => 'required|integer|min:1', 'stages' => 'sometimes|array|min:1|max:15', 'stages.*' => 'string|max:50', 'user_id' => 'sometimes|integer', 'role' => 'required_with:user_id|in:viewer,editor,coordinator,remove']);
        if (isset($input['stages'])) {
            $input['stages'] = array_map(fn ($s) => Str::slug($s, '_'), $input['stages']);
            abort_unless(count(array_unique($input['stages'])) === count($input['stages']) && collect($input['stages'])->every(fn ($s) => preg_match('/^[a-z_]{1,32}$/D', $s)), 422, 'Use distinct stage names with letters and spaces, up to 32 characters.');
        }
        abort_unless($area->version === (int) $input['expected_version'], 409);
        if (isset($input['user_id'])) {
            abort_unless(in_array((string) $input['user_id'], $this->access->actor($t, $r->user())['verified_user_ids'], true), 422);
            $key = ['tenant_id' => $t->id, 'area_id' => $id, 'user_id' => $input['user_id']];
            if ($input['role'] === 'remove') {
                DB::table('team_area_members')->where($key)->delete();
            } else {
                DB::table('team_area_members')->updateOrInsert($key, ['id' => (string) Str::uuid(), 'role' => $input['role'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $area->update(['settings' => array_replace($area->settings ?? [], array_intersect_key($input, ['stages' => true])), 'version' => $area->version + 1]);

        return $this->json($this->workspace->areaView($area));
    }

    public function records(Request $r)
    {
        $a = $this->area($r);
        $input = $r->validate(['kind' => 'nullable|in:'.implode(',', OperationsWorkspace::KINDS), 'q' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1|max:10000', 'cursor' => 'nullable|string|max:8192', 'target_id' => 'nullable|uuid']);

        return $this->json($this->workspace->records($this->tenant($r), $r->user(), $a, $input));
    }

    public function record(Request $r, string $id)
    {
        $t = $this->tenant($r);
        $record = TeamOperationRecord::query()->where('tenant_id', $t->id)->findOrFail($id);
        $this->access->record($t, $r->user(), $record);
        $view = $this->workspace->view($record, $this->access->mayWrite($t, $r->user(), $record->area_id), $this->access->admin($this->access->actor($t, $r->user())));
        if ($record->kind === 'staff' && $record->connection_id) {
            $rows = [];
            $person = $record->data['person_id'] ?? null;
            foreach (TeamOperationRecord::query()->where(['tenant_id' => $t->id, 'area_id' => $record->area_id, 'connection_id' => $record->connection_id, 'kind' => 'financial'])->where('status', '!=', 'withdrawn')->orderByDesc('starts_on')->cursor() as $line) {
                if (! $person || ($line->data['person_id'] ?? null) !== $person) {
                    continue;
                }
                $this->access->record($t, $r->user(), $line);
                $rows[] = ['title' => $line->title, 'starts_on' => $line->starts_on?->toDateString(), 'hours' => $line->data['hours'] ?? null, 'payroll_cents' => $line->data['payroll_cents'] ?? null, 'pay_type' => $line->data['pay_type'] ?? null, 'pay_week' => $line->data['pay_week'] ?? null, 'source_tab' => $line->data['source_tab'] ?? null, 'source_row' => $line->data['source_row'] ?? null];
            }
            $view['assignments'] = $rows;
            $view['identity_notice'] = $person ? 'Source identity remains separate from verified user/contact matching.' : 'Resolve staff identity before combining compensation history. Names alone do not link people.';
        }

        return $this->json($view);
    }

    public function save(Request $r)
    {
        $a = $this->area($r, true);
        $input = $r->validate(['id' => 'required|uuid', 'request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:0', 'kind' => 'required|in:'.implode(',', OperationsWorkspace::KINDS), 'title' => 'required|string|max:500', 'status' => 'required|string|regex:/^[a-z_]{1,32}$/D', 'starts_on' => 'nullable|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on', 'assigned_user_id' => 'nullable|integer', 'data' => 'sometimes|array']);
        abort_if(strlen(json_encode($input['data'] ?? [])) > 120000, 422);
        $r->validate(['data.enabled' => 'sometimes|boolean', 'data.reviewed' => 'sometimes|boolean', 'data.external' => 'sometimes|boolean', 'data.audience_ids' => 'sometimes|array|max:100', 'data.audience_ids.*' => 'integer|distinct', 'data.alias_names' => 'sometimes|array|max:30', 'data.alias_names.*' => 'string|max:200', 'data.organizer_emails' => 'sometimes|array|max:30', 'data.organizer_emails.*' => 'email|max:254', 'data.probability' => 'nullable|integer|min:0|max:100', 'data.amount_cents' => 'nullable|integer|min:0|max:100000000000', 'data.timezone' => 'nullable|timezone', 'data.local_time' => 'nullable|date_format:H:i']);

        return $this->json($this->workspace->view($this->workspace->save($this->tenant($r), $r->user(), $a, $input)));
    }

    public function prefill(Request $r)
    {
        $a = $this->area($r);
        $r->validate(['q' => 'required|string|min:2|max:200']);
        $result = $this->workspace->records($this->tenant($r), $r->user(), $a, ['q' => $r->input('q'), 'kind' => 'event']);
        foreach ($result['items'] as &$item) {
            $safe = ['venue', 'address', 'city', 'state', 'timezone', 'environment', 'organizer', 'email', 'phone', 'booth', 'instructions', 'alias_names', 'organizer_emails'];
            $item['prefill'] = array_intersect_key(array_replace($item['data'], $item['data']['reviewed_fields'] ?? []), array_flip($safe));
            unset($item['data']);
            $item['notice'] = 'Previous occurrence: confirm dates, contacts, costs and instructions. Acceptance and payment are not copied.';
        }

        return $this->json($result);
    }

    public function statistics(Request $r)
    {
        $a = $this->area($r);
        $r->validate(['year' => 'nullable|integer|min:2000|max:2100']);
        $this->access->financial($this->tenant($r), $r->user());

        return $this->json($a->profile === 'events' ? app(OperationsStatistics::class)->events($this->tenant($r), $r->user(), $a, (int) $r->input('year', now()->year)) : app(\App\Services\Team\Operations\OperationsWholesale::class)->statistics($this->tenant($r), $r->user(), $a));
    }

    public function connections(Request $r)
    {
        $a = $this->area($r);
        $items = TeamProviderConnection::query()->where(['tenant_id' => $this->tenant($r)->id, 'area_id' => $a->id, 'user_id' => $r->user()->id])->get()->map(fn ($c) => $this->providers->status($c))->all();

        return $this->json(['items' => $items, 'preset' => $a->settings['preset'] ?? [], 'configured' => ['asana' => (bool) config('team_work.asana.client_id'), 'google' => (bool) config('team_operations.google.client_id')]]);
    }

    public function connect(Request $r, string $provider)
    {
        $a = $this->area($r);
        if ($provider === 'sheets') {
            $this->access->financial($this->tenant($r), $r->user());
        } $r->validate(['send' => 'sometimes|boolean']);
        if ($r->boolean('send')) {
            $this->access->area($this->tenant($r), $r->user(), $a->id, true);
        }

        return $this->json(['url' => $this->providers->begin($r, $this->tenant($r), $a, $provider, $r->boolean('send'))]);
    }

    public function callback(Request $r, string $provider)
    {
        $t = $this->providers->callback($r, $provider);

        return redirect()->route('team.app', ['tenant' => $t->slug, 'area' => $r->session()->pull('team_operations.connected_area'), 'area_view' => 'connections']);
    }

    public function disconnect(Request $r, string $provider, string $id)
    {
        $c = $this->access->connection($this->tenant($r), $r->user(), $id, true);
        abort_unless($c->provider === $provider, 404);
        $c->update(['status' => 'disconnected', 'generation' => $c->generation + 1, 'credentials' => null, 'sync_state' => ['status' => 'disconnected', 'complete' => false]]);

        return $this->json(['status' => 'disconnected']);
    }

    public function select(Request $r, string $provider, string $id)
    {
        $c = $this->access->connection($this->tenant($r), $r->user(), $id, true);
        abort_unless($c->provider === $provider, 404);
        $input = $r->validate(['expected_generation' => 'required|integer|min:1', 'source' => 'required|array:asana_workspace,asana_project,asana_projects,asana_account_model,spreadsheet_id,tabs,after', 'source.asana_workspace' => 'nullable|regex:/^\d{1,120}$/D', 'source.asana_project' => 'nullable|regex:/^\d{1,120}$/D', 'source.asana_projects' => 'nullable|array|max:1000', 'source.asana_projects.*' => 'string|regex:/^\d{1,120}$/D|distinct', 'source.asana_account_model' => 'nullable|in:tasks,projects', 'source.spreadsheet_id' => 'nullable|regex:/^[a-zA-Z0-9_-]{10,160}$/D', 'source.tabs' => 'nullable|array|max:10', 'source.tabs.*' => 'string|max:100', 'source.after' => 'nullable|date_format:Y-m-d', 'mapping_approved' => 'sometimes|boolean']);
        abort_if(strlen(json_encode($input['source'])) > 20000, 422, 'Source selection is too large.');
        abort_unless($c->generation === (int) $input['expected_generation'], 409);
        if ($provider === 'sheets') {
            $this->access->financial($this->tenant($r), $r->user());
        }
        $candidate = clone $c;
        $candidate->settings = array_replace($c->settings, ['source' => $input['source']]);
        $this->providers->read($candidate, 'records'); // Verify live ownership/access before accepting the selection.
        DB::transaction(function () use ($c, $input, $provider, $r) {
            $current = TeamProviderConnection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->generation === $input['expected_generation'] && $current->status === 'connected', 409);
            $current->update(['settings' => array_replace($current->settings, ['source' => $input['source'], 'selection_required' => false, 'mapping_approved' => $provider === 'sheets' && $r->boolean('mapping_approved')]), 'generation' => $current->generation + 1, 'sync_state' => ['status' => 'selected', 'complete' => false], 'last_success_at' => null]);
        });
        $c->refresh();

        return $this->json($this->providers->status($c));
    }

    public function catalog(Request $r, string $provider, string $id)
    {
        $c = $this->access->connection($this->tenant($r), $r->user(), $id);
        abort_unless($c->provider === $provider, 404);
        $r->validate(['cursor' => 'nullable|string|max:4096', 'resource' => 'nullable|in:workspaces,catalog', 'workspace' => 'nullable|regex:/^\d{1,120}$/D', 'include_archived' => 'sometimes|boolean']);
        if ($r->filled('workspace')) {
            $c->settings = array_replace($c->settings, ['source' => array_replace($c->settings['source'] ?? [], ['asana_workspace' => $r->input('workspace'), 'include_archived' => $r->boolean('include_archived')])]);
        }

        return $this->json($this->providers->read($c, $r->input('resource', 'catalog'), $r->input('cursor')));
    }

    public function watch(Request $r, string $provider, string $id)
    {
        $c = $this->access->connection($this->tenant($r), $r->user(), $id, true);
        abort_unless($c->provider === $provider, 404);
        if ($provider === 'sheets') {
            $this->access->financial($this->tenant($r), $r->user());
        }

        return $this->json(app(\App\Services\Team\Operations\OperationsWatches::class)->renew($c));
    }

    public function refresh(Request $r, string $provider, string $id)
    {
        $c = $this->access->connection($this->tenant($r), $r->user(), $id, true);
        abort_unless($c->provider === $provider, 404);
        abort_if($c->settings['selection_required'] ?? false, 409, 'Verify and select a source before refreshing.');
        if ($provider === 'sheets') {
            $this->access->financial($this->tenant($r), $r->user());
        }

        return $this->json(app(OperationsSync::class)->refresh($c));
    }

    public function sourceRead(Request $r, string $provider, string $id)
    {
        $c = $this->access->connection($this->tenant($r), $r->user(), $id);
        abort_unless($c->provider === $provider, 404);
        if ($provider === 'sheets') {
            $this->access->financial($this->tenant($r), $r->user());
        } $r->validate(['cursor' => 'nullable|string|max:4096']);

        return $this->json($this->providers->read($c, 'records', $r->input('cursor')));
    }

    public function reviewDocument(Request $r, string $id)
    {
        $t = $this->tenant($r);
        $doc = TeamOperationRecord::query()->where(['tenant_id' => $t->id, 'kind' => 'document'])->findOrFail($id);
        $this->access->record($t, $r->user(), $doc);
        $this->access->area($t, $r->user(), $doc->area_id, true);
        $input = $r->validate(['target_id' => 'required|uuid', 'expected_version' => 'required|integer|min:1', 'reviewed' => 'accepted']);
        abort_unless($doc->version === (int) $input['expected_version'], 409);
        $target = TeamOperationRecord::query()->where(['tenant_id' => $t->id, 'area_id' => $doc->area_id])->whereIn('kind', ['event', 'account'])->findOrFail($input['target_id']);
        $this->access->record($t, $r->user(), $target);
        $doc->update(['data' => array_replace($doc->data, ['target_id' => $target->id, 'matching' => ['reviewed_by' => $r->user()->id, 'reviewed_at' => now()->toIso8601String()]]), 'status' => ($doc->data['scan'] ?? '') === 'clean' ? 'linked' : 'needs_review', 'version' => $doc->version + 1]);
        $this->workspace->revision($doc, $r->user()->id);

        return $this->json($this->workspace->view($doc));
    }

    public function download(Request $r, string $id)
    {
        $t = $this->tenant($r);
        $doc = TeamOperationRecord::query()->where(['tenant_id' => $t->id, 'kind' => 'document', 'status' => 'linked'])->findOrFail($id);
        $this->access->record($t, $r->user(), $doc);
        abort_unless(($doc->data['scan'] ?? '') === 'clean', 409, 'Awaiting a successful security scan.');
        $target = TeamOperationRecord::query()->where(['tenant_id' => $t->id, 'area_id' => $doc->area_id])->findOrFail($doc->data['target_id']);
        $this->access->record($t, $r->user(), $target);

        return Storage::disk('local')->download($doc->data['storage_key'], basename($doc->title), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function weather(Request $r, string $id)
    {
        $t = $this->tenant($r);
        $event = TeamOperationRecord::query()->where(['tenant_id' => $t->id, 'kind' => 'event'])->findOrFail($id);
        $this->access->record($t, $r->user(), $event);

        return $this->json(app(\App\Services\Team\Operations\OperationsWeather::class)->event($event));
    }

    public function sales(Request $r, string $id)
    {
        $t = $this->tenant($r);
        $r->validate(['page' => 'nullable|integer|min:1|max:10000']);
        $event = TeamOperationRecord::where(['tenant_id' => $t->id, 'kind' => 'event'])->findOrFail($id);

        return $this->json(app(\App\Services\Team\Operations\OperationsSquare::class)->event($t, $r->user(), $event, (int) $r->input('page', 1)));
    }

    public function wholesale(Request $r, string $view)
    {
        $a = $this->area($r);
        abort_unless($a->profile === 'wholesale', 404);

        return $this->json(app(\App\Services\Team\Operations\OperationsWholesale::class)->read($this->tenant($r), $r->user(), $a, $view, $r));
    }

    public function wholesaleAction(Request $r, string $action)
    {
        $a = $this->area($r, true);
        abort_unless($a->profile === 'wholesale', 404);

        return $this->json(app(\App\Services\Team\Operations\OperationsWholesale::class)->act($this->tenant($r), $r->user(), $a, $action, $r));
    }

    public function communicate(Request $r)
    {
        $a = $this->area($r, true);

        return $this->json(app(\App\Services\Team\Operations\OperationsActions::class)->prepare($this->tenant($r), $r->user(), $a, $r));
    }

    public function briefing(Request $r)
    {
        $a = $this->area($r);
        $i = $r->validate(['request_id' => 'required|uuid', 'record_ids' => 'required|array|min:1|max:12', 'record_ids.*' => 'required|uuid', 'ai' => 'sometimes|boolean']);

        return $this->json(app(\App\Services\Team\Operations\OperationsBriefing::class)->generate($this->tenant($r), $r->user(), $a, $i['record_ids'], $i['request_id'], $r->boolean('ai')));
    }

    public function dispatch(Request $r, string $id)
    {
        $a = $this->area($r, true);

        return $this->json(app(\App\Services\Team\Operations\OperationsActions::class)->dispatch($this->tenant($r), $r->user(), $a, $id, $r));
    }

    public function reconcile(Request $r, string $id)
    {
        $a = $this->area($r, true);

        return $this->json(app(\App\Services\Team\Operations\OperationsReconciliation::class)->attempt($this->tenant($r), $r->user(), $a, $id));
    }

    public function communications(Request $r)
    {
        $a = $this->area($r);
        $items = TeamOperationAttempt::query()->where(['tenant_id' => $this->tenant($r)->id, 'area_id' => $a->id, 'user_id' => $r->user()->id])->whereIn('kind', ['gmail.send', 'sms.send', 'asana.create'])->latest()->paginate(30);

        $visible = [];
        $partial = false;
        foreach ($items->items() as $attempt) {
            $record = TeamOperationRecord::where(['tenant_id' => $this->tenant($r)->id, 'area_id' => $a->id])->find($attempt->record_id);
            if (! $record) {
                $partial = true;

                continue;
            }
            try {
                $this->access->record($this->tenant($r), $r->user(), $record);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $partial = true;

                continue;
            }
            $visible[] = ['id' => $attempt->id, 'kind' => $attempt->kind, 'status' => $attempt->status, 'created_at' => $attempt->created_at, 'title' => $record->title];
        }

        return $this->json(['items' => $visible, 'page' => $items->currentPage(), 'has_more' => $items->hasMorePages(), 'partial' => $partial, 'notice' => $partial ? 'Some communication history is hidden because current work access could not be verified.' : null]);
    }

    public function sheetPreview(Request $r, string $id)
    {
        $c = $this->access->connection($this->tenant($r), $r->user(), $id, true);
        $this->access->financial($this->tenant($r), $r->user());
        abort_unless($c->provider === 'sheets', 404);

        return $this->json(app(\App\Services\Team\Operations\SheetIdentityService::class)->preview($c));
    }

    public function sheetIds(Request $r, string $id)
    {
        $c = $this->access->connection($this->tenant($r), $r->user(), $id, true);
        $this->access->financial($this->tenant($r), $r->user());
        abort_unless($c->provider === 'sheets', 404);
        $r->validate(['reviewed' => 'accepted', 'backup_id' => 'required|uuid']);

        return $this->json(app(\App\Services\Team\Operations\SheetIdentityService::class)->initialize($c, $r->input('backup_id')));
    }
}
