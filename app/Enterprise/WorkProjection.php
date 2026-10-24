<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Team\WorkGraph;

/** A bounded projection of current readable graph work; never an all-campus roster or task count. */
class WorkProjection
{
    public function build(Tenant $tenant, array $actor, string $area, array $state, array $projects, array $requests, array $buildings): array
    {
        $graph = app(WorkGraph::class);
        $coordinate = WorkspaceAccess::permits($actor, $area, true, true);
        $people = $tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->whereNotNull('email_verified_at')->get()->keyBy(fn ($u) => (string) $u->id);
        $person = function ($id) use ($people, $actor, $area): ?array {
            $id = (string) $id;
            $recipient = array_replace($actor, ['user_id' => $id, 'role' => $actor['active_roles'][$id] ?? 'guest']);

            return isset($people[$id]) && WorkspaceAccess::permits($recipient, $area) ? ['id' => $id, 'name' => $people[$id]->name] : null;
        };
        $projectIds = array_flip(array_column($projects, 'id'));
        // Match the management service's bounded link scan; the100-row list is not link authority.
        $linkRows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => 'enterprise_request'])->orderBy('id')->limit(1001)->get();
        $linksComplete = $linkRows->count() <= 1000;
        $links = [];
        if ($linksComplete) {
            foreach ($linkRows as $record) {
                $taskId = $record->data['task_id'] ?? null;
                if ($taskId) {
                    $links[$taskId][] = $record;
                }
            }
        }

        $tasks = [];
        foreach ($state['tasks'] as $id => $task) {
            if (($task['enterprise_area_id'] ?? null) !== $area || isset($task['enterprise_discussion_project'])
                || ! array_intersect_key($task['memberships'], $projectIds) || ! $graph->can($state, $actor, 'task', $id)) {
                continue;
            }
            $managedMemberships = array_filter($task['memberships'], fn ($_, $projectId) => ($state['projects'][$projectId]['enterprise_area_id'] ?? null) === $area, ARRAY_FILTER_USE_BOTH);
            $singleProject = count($task['memberships']) === 1 && count($managedMemberships) === 1;
            $matches = $links[$id] ?? [];
            $linkReady = $linksComplete && count($matches) <= 1;
            $request = null;
            if ($linkReady && $matches) {
                try {
                    $request = app(RequestWorkflow::class)->view($tenant, $actor, $matches[0]);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                    if (! in_array($error->getStatusCode(), [403, 404], true)) {
                        throw $error;
                    }
                    $linkReady = false;
                }
            }

            $tasks[] = ['id' => $id, 'title' => $task['title'], 'description' => $task['description'], 'version' => $task['version'],
                'priority' => $task['priority'], 'status' => $task['completed_at'] ? 'completed' : 'active', 'completed_at' => $task['completed_at'],
                'due_on' => $task['due_on'], 'updated_at' => $task['updated_at'], 'assignee_id' => $task['assignee_id'], 'assignee' => $person($task['assignee_id']),
                'project_ids' => array_keys(array_intersect_key($task['memberships'], $projectIds)),
                'can_edit' => $singleProject && $linkReady && $coordinate && $graph->can($state, $actor, 'task', $id, true) && $task['completed_at'] === null,
                'edit_link_status' => $singleProject && $linkReady ? 'current' : 'review_required',
                'linked_request' => $request ? ['id' => $request['id'], 'version' => $request['version'], 'status' => $request['status']] : null];
        }
        usort($tasks, fn ($a, $b) => [$a['title'], $a['id']] <=> [$b['title'], $b['id']]);
        $partial = count($tasks) > 200;
        $tasks = array_slice($tasks, 0, 200);
        foreach ($projects as &$project) {
            $project['tasks'] = array_values(array_filter($tasks, fn ($task) => in_array($project['id'], $task['project_ids'], true)));
            $project['owner'] = $person($project['owner_id']);
            $project['can_edit'] = $coordinate && (string) $project['owner_id'] === $actor['user_id'];
            $project['can_add_task'] = ! $project['archived'] && $coordinate && $graph->can($state, $actor, 'project', $project['id'], true);
            $project['tasks_has_more'] = $partial;
            $project['tasks_coverage'] = 'Up to 200 readable tasks in this department; counts describe returned tasks only.';
            $dates = array_column($project['tasks'], 'updated_at');
            foreach ($state['events'] as $event) {
                if ($event['entity_type'] === 'project' && $event['entity_id'] === $project['id']) {
                    $dates[] = $event['at'];
                }
            }
            $project['last_visible_work_at'] = $dates ? max($dates) : null;
            $project['locations'] = [];
            foreach ($requests as $request) {
                if (($request['data']['project_id'] ?? null) !== $project['id']) {
                    continue;
                }
                $building = collect($buildings['items'] ?? [])->firstWhere('id', $request['data']['location_id'] ?? null);
                if (! $building || (int) ($request['data']['location_version'] ?? 0) !== $building['version']) {
                    continue;
                }
                $project['locations'][$building['id']] = ['id' => $building['id'], 'version' => $building['version'], 'name' => $building['title'],
                    'map_number' => $building['map_number'] ?? null, 'source_url' => $building['source_url'] ?? null];
            }
            $project['locations'] = array_values($project['locations']);
        }
        unset($project);

        return ['projects' => $projects, 'tasks' => $tasks, 'tasks_has_more' => $partial, 'tasks_coverage' => 'Up to 200 current readable department tasks.'];
    }
}
