<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\WorkGraph;
use Illuminate\Support\Facades\DB;

/** Current-member visibility comparison. Never changes the signed-in actor or grants. */
final class RolePreview
{
    public function __construct(private WorkspaceAccess $access, private WorkGraph $graph) {}

    private function observer(Tenant $tenant, User $user): array
    {
        abort_unless(config('enterprise.operations_enabled'), 404);
        $actor = $this->access->actor($tenant, $user);
        abort_unless(in_array($actor['role'], ['owner', 'admin'], true), 403);

        return $actor;
    }

    private function identity(User $user, array $actor, string $area): array
    {
        return ['id' => (string) $user->id, 'name' => $user->name, 'tenant_role' => $actor['role'],
            'area_role' => $actor['enterprise_scopes'][$area]['members'][(string) $user->id] ?? null];
    }

    public function show(Tenant $tenant, User $user, string $areaId, ?int $memberId = null): array
    {
        $observer = $this->observer($tenant, $user);
        $area = $this->access->area($tenant, $observer, $areaId);
        $roster = [];
        $candidates = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)
            ->whereNotNull('users.email_verified_at')->orderBy('users.name')->orderBy('users.id')->limit(101)->get();
        foreach ($candidates->take(100) as $member) {
            if (! in_array($observer['active_roles'][(string) $member->id] ?? '', ['owner', 'admin', 'member', 'viewer'], true)) {
                continue;
            }
            $actor = $this->access->actor($tenant, $member);
            if (WorkspaceAccess::permits($actor, $areaId)) {
                $roster[] = $this->identity($member, $actor, $areaId);
            }
        }
        $result = ['read_only' => true, 'impersonation' => false, 'observer' => $this->identity($user->fresh(), $observer, $areaId),
            'scope' => ['tenant_id' => (string) $tenant->id, 'area_id' => $areaId, 'selection' => 'current_area'],
            'roster' => $roster, 'roster_partial' => $candidates->count() > 100, 'preview' => null,
            'notice' => 'Read-only visibility of an existing current member, intersected with your own access. Your identity and permissions are unchanged.',
            'excluded' => ['financial_records', 'message_content', 'descriptions', 'files', 'provider_data'], 'as_of' => now()->toIso8601String()];
        if ($memberId === null) {
            return $result;
        }
        $member = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)
            ->whereNotNull('users.email_verified_at')->findOrFail($memberId);
        $subject = $this->access->actor($tenant, $member);
        $this->access->area($tenant, $subject, $areaId);
        [$state, $graphPartial] = $this->authorizationGraph($tenant);
        $projects = [];
        foreach ($state['projects'] as $project) {
            if (($project['enterprise_area_id'] ?? null) === $areaId
                && $this->graph->can($state, $observer, 'project', $project['id']) && $this->graph->can($state, $subject, 'project', $project['id'])) {
                $projects[] = ['id' => $project['id'], 'area_id' => $areaId, 'name' => $project['name'], 'archived' => $project['archived']];
            }
        }
        $requests = [];
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => 'enterprise_request'])
            ->orderBy('id')->limit(501)->get(['id', 'tenant_id', 'area_id', 'title', 'status', 'starts_on', 'data']);
        foreach ($rows->take(500) as $row) {
            // The canonical request link is encrypted with request-owned fields.
            // Extract only its task ID, never expose request content or graph payloads.
            $taskId = $row->data['task_id'] ?? null;
            if ($taskId !== null && (! is_string($taskId) || ! $this->graph->can($state, $observer, 'task', $taskId)
                || ! $this->graph->can($state, $subject, 'task', $taskId))) {
                continue;
            }
            $task = $taskId !== null ? $state['tasks'][$taskId] : null;
            $status = $task && $task['completed_at'] !== null ? 'completed' : ($task && $row->status === 'completed' ? 'assigned' : $row->status);
            $requests[] = ['id' => $row->id, 'area_id' => $areaId, 'title' => $row->title, 'status' => $status, 'due_on' => $row->starts_on?->toDateString()];
        }
        $projectPartial = count($projects) > 100;
        $requestPartial = $rows->count() > 500 || count($requests) > 100;
        $projects = array_slice($projects, 0, 100);
        $requests = array_slice($requests, 0, 100);
        $result['preview'] = ['member' => $this->identity($member, $subject, $areaId),
            'areas' => [['id' => $area->id, 'name' => $area->name, 'visibility' => $area->profile === 'enterprise_shared' ? 'shared' : 'department_only']],
            'projects' => $projects, 'requests' => $requests,
            'counts' => ['areas' => 1, 'projects' => count($projects), 'requests' => count($requests)],
            'coverage' => ['counts' => 'returned_authorized_intersection_only', 'projects_partial' => $projectPartial,
                'requests_partial' => $requestPartial, 'authorization_graph_partial' => $graphPartial,
                'limits' => ['projects' => 100, 'requests' => 100, 'request_scan' => 500, 'project_acl_scan' => 2000, 'task_acl_scan' => 5000]]];

        return $result;
    }

    /** Only ACL metadata: no repository events, receipts, descriptions, comments or file records. */
    private function authorizationGraph(Tenant $tenant): array
    {
        $state = WorkGraph::empty();
        $projects = DB::table('team_projects')->where('tenant_id', $tenant->id)->orderBy('id')->limit(2001)
            ->get(['id', 'tenant_id', 'name', 'privacy', 'archived', 'payload->enterprise_area_id as enterprise_area_id']);
        foreach ($projects->take(2000) as $row) {
            $state['projects'][$row->id] = ['id' => $row->id, 'tenant_id' => (string) $row->tenant_id,
                'name' => $row->name, 'privacy' => $row->privacy, 'archived' => (bool) $row->archived, 'members' => []];
            if (is_string($row->enterprise_area_id)) {
                $state['projects'][$row->id]['enterprise_area_id'] = $row->enterprise_area_id;
            }
        }
        foreach (DB::table('team_project_members')->where('tenant_id', $tenant->id)->whereIn('project_id', array_keys($state['projects']))->get(['project_id', 'user_id', 'access']) as $row) {
            $state['projects'][$row->project_id]['members'][(string) $row->user_id] = $row->access;
        }
        $tasks = DB::table('team_tasks')->where('tenant_id', $tenant->id)->orderBy('id')->limit(5001)
            ->get(['id', 'tenant_id', 'parent_id', 'deleted', 'completed_at', 'payload->creator_id as creator_id',
                'payload->collaborators as collaborators', 'payload->enterprise_area_id as enterprise_area_id']);
        foreach ($tasks->take(5000) as $row) {
            $state['tasks'][$row->id] = ['id' => $row->id, 'tenant_id' => (string) $row->tenant_id, 'parent_id' => $row->parent_id,
                'deleted' => (bool) $row->deleted, 'completed_at' => $row->completed_at, 'creator_id' => (string) $row->creator_id,
                'collaborators' => is_string($row->collaborators) ? json_decode($row->collaborators, true, 16, JSON_THROW_ON_ERROR) : [], 'memberships' => []];
            if (is_string($row->enterprise_area_id)) {
                $state['tasks'][$row->id]['enterprise_area_id'] = $row->enterprise_area_id;
            }
        }
        foreach (DB::table('team_task_projects')->where('tenant_id', $tenant->id)->whereIn('task_id', array_keys($state['tasks']))->get(['task_id', 'project_id']) as $row) {
            $state['tasks'][$row->task_id]['memberships'][$row->project_id] = [];
        }

        return [$state, $projects->count() > 2000 || $tasks->count() > 5000];
    }
}
