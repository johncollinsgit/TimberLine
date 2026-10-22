<?php

namespace App\Enterprise;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EnterpriseController
{
    public function __construct(private WorkspaceAccess $access, private RequestWorkflow $workflow) {}

    public function index(Request $request)
    {
        $tenant = $request->attributes->get('current_tenant');
        $actor = $this->access->actor($tenant, $request->user());

        return response()->view('enterprise.app', ['tenant' => $tenant, 'enterpriseRole' => $actor['role'],
            'clientBranding' => app(ClientPresentation::class)->settings($tenant, $request->user())['branding'] ?? []])->header('Cache-Control', 'private, no-store');
    }

    public function data(Request $request)
    {
        $tenant = $request->attributes->get('current_tenant');
        $actor = $this->access->actor($tenant, $request->user());
        $areas = TeamArea::query()->where('tenant_id', $tenant->id)->whereIn('profile', WorkspaceAccess::PROFILES)->orderBy('name')->get()
            ->filter(fn ($area) => WorkspaceAccess::permits($actor, $area->id));
        $selected = $request->validate(['area_id' => ['nullable', 'uuid']])['area_id'] ?? $areas->first()?->id;
        $area = $selected ? $this->access->area($tenant, $actor, $selected) : null;
        $state = app(TeamWorkRepository::class)->state($tenant->id);
        $graph = app(WorkGraph::class);
        $projects = [];
        foreach ($state['projects'] as $id => $project) {
            if ($area && ($project['enterprise_area_id'] ?? null) === $area->id && $graph->can($state, $actor, 'project', $id)) {
                $projects[] = $graph->read($state, $actor, 'project', $id);
            }
        }
        $rows = $area ? TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'enterprise_request'])
            ->orderByDesc('created_at')->orderBy('id')->limit(1000)->cursor() : collect();
        $items = [];
        $scanned = 0;
        foreach ($rows as $row) {
            $scanned++;
            try {
                $items[] = $this->workflow->view($tenant, $actor, $row);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (! in_array($e->getStatusCode(), [403, 404], true)) {
                    throw $e;
                }
            }
            if (count($items) > 100) {
                break;
            }
        }
        $members = $area ? $tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->whereNotNull('email_verified_at')->get()
            ->filter(fn ($user) => in_array($actor['active_roles'][(string) $user->id] ?? '', ['owner', 'admin', 'member'], true)
                && WorkspaceAccess::permits(array_replace($actor, ['user_id' => (string) $user->id, 'role' => $actor['active_roles'][(string) $user->id]]), $area->id, true)) : collect();

        return response()->json(['workspace' => ['id' => $tenant->id, 'name' => $tenant->name], 'viewer_id' => (string) $request->user()->id, 'selected_area_id' => $selected,
            'areas' => $areas->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'visibility' => $a->profile === 'enterprise_shared' ? 'shared' : 'department_only'])->values(),
            'requests' => array_slice($items, 0, 100), 'projects' => $projects, 'coverage' => 'up_to_100_visible_requests_scan_1000', 'has_more' => count($items) > 100,
            'scan_partial' => $scanned === 1000,
            'features' => ['collaboration' => (bool) config('enterprise.collaboration_enabled'), 'planning' => (bool) config('enterprise.planning_enabled'),
                'ask' => (bool) config('enterprise.ask_enabled'), 'voice_jobs' => (bool) config('enterprise.voice_jobs_enabled'), 'email_drafts' => (bool) config('enterprise.email_drafts_enabled'),
                'workflow' => (bool) config('enterprise.workflow_enabled'), 'briefing' => (bool) config('enterprise.briefing_enabled'),
                'operations' => (bool) config('enterprise.operations_enabled'), 'resources' => (bool) config('enterprise.resources_enabled'),
                'roll_call' => (bool) config('enterprise.roll_call_enabled'), 'requester' => (bool) config('enterprise.requester_enabled'),
                'reports' => (bool) config('enterprise.reports_enabled'), 'certificates' => (bool) config('enterprise.certificates_enabled')],
            'can_manage' => config('enterprise.writes_enabled') && in_array($actor['role'], ['owner', 'admin'], true),
            'can_submit' => $area && WorkspaceAccess::canSubmit($actor, $area->id),
            'can_review' => $area && WorkspaceAccess::permits($actor, $area->id, false, true),
            'can_coordinate' => $area && WorkspaceAccess::permits($actor, $area->id, true, true),
            'members' => $members->map(fn ($u) => ['id' => (string) $u->id, 'name' => $u->name])->values()])->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $request, string $id)
    {
        $tenant = $request->attributes->get('current_tenant');
        $actor = $this->access->actor($tenant, $request->user());
        $record = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'enterprise_request'])->findOrFail($id);
        $this->workflow->view($tenant, $actor, $record);
        $events = DB::table('team_operation_revisions')->where(['tenant_id' => $tenant->id, 'record_id' => $id])->orderByDesc('version')->limit(100)->get()
            ->map(fn ($row) => ['version' => $row->version, 'actor_id' => $row->user_id, 'at' => $row->created_at, 'event' => json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR)]);

        return response()->json(['items' => $events, 'coverage' => 'latest_100_request_revisions'])->header('Cache-Control', 'private, no-store');
    }

    public function command(Request $request)
    {
        $input = $request->validate([
            'action' => ['required', 'in:workspace.create,project.create,request.create,request.triage,request.assign,request.progress,request.complete'],
            'id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'area_id' => ['required_unless:action,workspace.create', 'uuid'],
            'title' => ['required_if:action,workspace.create,project.create,request.create', 'string', 'max:100'],
            'visibility' => ['required_if:action,workspace.create', 'in:shared,department_only'],
            'expected_version' => ['required_unless:action,workspace.create,project.create', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'], 'location' => ['nullable', 'string', 'max:200'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'due_on' => ['nullable', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:2000'],
            'project_id' => ['required_if:action,request.assign', 'uuid'], 'assignee_id' => ['required_if:action,request.assign', 'integer', 'min:1'],
        ]);
        if (isset($input['expected_version'])) {
            $input['expected_version'] = (int) $input['expected_version'];
        }
        try {
            $result = $this->workflow->execute($request->attributes->get('current_tenant'), $request->user(), $input);

            return response()->json($result)->header('Cache-Control', 'private, no-store');
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage(), 'saved' => false], match ($e->getMessage()) {
                'conflict', 'idempotency_mismatch' => 409, 'access_denied' => 403, default => 422,
            });
        }
    }
}
