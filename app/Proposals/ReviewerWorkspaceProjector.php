<?php

namespace App\Proposals;

/** Server-side filtering and AI projection of the stored fictional graph. No browser facts or native user. */
final class ReviewerWorkspaceProjector
{
    public function __construct(private ReviewerWorkspaceAccess $access, private ReviewerAiSnapshot $snapshots) {}

    public function recordActions(array $scope, array $selection, array $graph, string $kind, array $row): array
    {
        if (! in_array($row['area_id'], $selection['area_ids'], true)) {
            return [];
        }
        $actions = $this->access->actions($scope, $selection);
        if (in_array($selection['persona'], ['demo_manager', 'manager'], true)) {
            $record = array_values(array_filter($actions, fn ($action) => str_starts_with($action, $kind.'.') && ! str_ends_with($action, '.create')));
            if ($kind === 'task' && collect($graph['projects'])->firstWhere('id', $row['project_id'])['status'] === 'archived') {
                return [];
            }

            return $record;
        }
        if ($kind === 'task' && $selection['persona'] === 'worker' && $row['assignee_id'] === $selection['person_id'] && $row['status'] !== 'archived') {
            return array_values(array_intersect($actions, ['task.status']));
        }
        if ($kind === 'ticket' && $row['owner_capability_hash'] !== null && hash_equals($scope['capability_hash'], $row['owner_capability_hash'])) {
            return array_values(array_intersect($actions, ['ticket.update']));
        }

        return [];
    }

    public function capabilities(array $scope, array $selection): array
    {
        return ['can_view_costs' => $scope['policy']['allow_financial'] && config('service_proposals.reviewer_workspace.financial_enabled') === true
            && in_array($selection['persona'], ['demo_manager', 'executive', 'manager'], true),
            'can_manage_plans' => in_array('plan.create', $this->access->actions($scope, $selection), true), 'can_manage_costs' => false];
    }

    public function graph(array $scope, array $selection, array $graph): array
    {
        $result = $graph;
        $areas = $selection['area_ids'];
        $persona = $selection['persona'];
        $result['areas'] = array_values(array_filter($graph['areas'], fn ($row) => in_array($row['id'], $areas, true)));
        $result['buildings'] = array_values(array_filter($graph['buildings'], fn ($row) => in_array($row['area_id'], $areas, true)));
        $tasks = array_values(array_filter($graph['tasks'], fn ($row) => in_array($row['area_id'], $areas, true)
            && $persona !== 'requester' && ($persona !== 'worker' || $row['assignee_id'] === $selection['person_id'])));
        $projects = array_values(array_filter($graph['projects'], fn ($row) => in_array($row['area_id'], $areas, true)
            && $persona !== 'requester' && ($persona !== 'worker' || in_array($row['id'], array_column($tasks, 'project_id'), true))));
        $projectIds = array_column($projects, 'id');
        $taskIds = array_column($tasks, 'id');
        $tickets = array_values(array_filter($graph['tickets'], fn ($row) => in_array($row['area_id'], $areas, true)
            && (! in_array($persona, ['worker', 'requester'], true) || $row['owner_capability_hash'] === $scope['capability_hash']
                || $persona === 'worker' && in_array($row['task_id'], $taskIds, true))));
        $peopleIds = [...array_column($tasks, 'assignee_id'), ...array_column($projects, 'owner_id')];
        $result['people'] = array_values(array_filter($graph['people'], fn ($row) => $row['active'] && array_intersect($row['area_ids'], $areas) !== []
            && $persona !== 'requester' && ($persona !== 'worker' || in_array($row['id'], $peopleIds, true))));
        $result['contractors'] = array_values(array_filter($graph['contractors'], fn ($row) => $persona !== 'requester' && in_array($row['area_id'], $areas, true)));
        $result['plans'] = array_values(array_filter($graph['plans'], fn ($row) => in_array($row['project_id'], $projectIds, true)));
        $financial = $this->capabilities($scope, $selection)['can_view_costs'];
        $result['costs'] = $financial ? array_values(array_filter($graph['costs'], fn ($row) => in_array($row['project_id'], $projectIds, true))) : [];
        foreach (['project' => $projects, 'task' => $tasks, 'ticket' => $tickets, 'plan' => $result['plans']] as $kind => $rows) {
            foreach ($rows as &$row) {
                $row['allowed_actions'] = $this->recordActions($scope, $selection, $graph, $kind, $row);
                if ($kind === 'ticket') {
                    $row['owned_by_caller'] = $row['owner_capability_hash'] === $scope['capability_hash'];
                    unset($row['owner_capability_hash']);
                    if (! in_array($row['project_id'], $projectIds, true)) {
                        $row['project_id'] = null;
                        $row['task_id'] = null;
                    } elseif (! in_array($row['task_id'], $taskIds, true)) {
                        $row['task_id'] = null;
                    }
                }
            }
            unset($row);
            $result[match ($kind) {
                'project' => 'projects', 'task' => 'tasks', 'ticket' => 'tickets', default => 'plans'
            }] = $rows;
        }

        return $result;
    }

    public function snapshot(array $scope, array $selection, array $graph, int $revision): array
    {
        $visible = $this->graph($scope, $selection, $graph);
        $sources = [];
        $people = array_column($visible['people'], null, 'id');
        $buildings = array_column($visible['buildings'], null, 'id');
        $contractors = array_column($visible['contractors'], null, 'id');
        $represented = [];
        foreach ($visible['projects'] as $project) {
            $tasks = [];
            $locations = [];
            $plans = [];
            $costs = [];
            foreach ($visible['tasks'] as $task) {
                if ($task['project_id'] !== $project['id'] || $task['status'] === 'archived') {
                    continue;
                }
                $building = $task['building_id'] === null ? null : ($buildings[$task['building_id']] ?? null);
                if ($building !== null && $building['status'] !== 'active') {
                    $building = null;
                }
                if ($building !== null) {
                    $locations[$building['id']] = ['id' => $building['id'], 'title' => $building['title'], 'version' => $building['version']];
                }
                $tickets = array_values(array_filter($visible['tickets'], fn ($row) => $row['project_id'] === $project['id'] && $row['task_id'] === $task['id']));
                $ticket = count($tickets) === 1 ? $tickets[0] : null;
                if ($ticket !== null) {
                    $represented[$ticket['id']] = true;
                }
                $person = $task['assignee_id'] === null ? null : ($people[$task['assignee_id']] ?? null);
                $tasks[] = ['id' => $task['id'], 'title' => $task['title'], 'version' => $task['version'], 'status' => $task['status'],
                    'assigned_person' => $person['name'] ?? null, 'priority' => $task['priority'], 'due_on' => $task['due_on'],
                    'assigned_person_id' => $person['id'] ?? null, 'building_id' => $building['id'] ?? null,
                    'request' => $ticket !== null ? ['id' => $ticket['id'], 'version' => $ticket['version'], 'status' => $ticket['status']] : null];
            }
            foreach ($visible['tickets'] as $ticket) {
                $building = $ticket['building_id'] === null ? null : ($buildings[$ticket['building_id']] ?? null);
                if ($ticket['project_id'] === $project['id'] && $building !== null && $building['status'] === 'active') {
                    $locations[$building['id']] = ['id' => $building['id'], 'title' => $building['title'], 'version' => $building['version']];
                }
            }
            foreach ($visible['plans'] as $plan) {
                $contractor = $contractors[$plan['contractor_id']] ?? null;
                if ($plan['project_id'] === $project['id'] && $plan['status'] === 'planned' && $contractor !== null && $contractor['status'] === 'active') {
                    $plans[] = ['id' => $plan['id'], 'version' => $plan['version'], 'contractor_id' => $contractor['id'], 'contractor_version' => $contractor['version'],
                        'contractor_title' => $contractor['title'], 'starts_on' => $plan['starts_on'], 'ends_on' => $plan['ends_on']];
                }
            }
            foreach ($visible['costs'] as $cost) {
                if ($cost['project_id'] === $project['id']) {
                    $costs[] = array_intersect_key($cost, array_flip(['id', 'version', 'amount_minor', 'currency', 'basis']));
                }
            }
            $sources[] = ['ref' => 'project:'.$project['id'], 'kind' => 'project', 'area_id' => $project['area_id'], 'title' => $project['title'], 'version' => $project['version'],
                'status' => $project['status'], 'tasks' => $tasks, 'buildings' => array_values($locations), 'contractor_plans' => $plans, 'costs' => $costs,
                'comparisons' => ReviewerAiEvidence::comparisons($costs)];
        }
        foreach ($visible['tickets'] as $ticket) {
            if (isset($represented[$ticket['id']])) {
                continue;
            }
            $building = $ticket['building_id'] === null ? null : ($buildings[$ticket['building_id']] ?? null);
            if ($building !== null && $building['status'] !== 'active') {
                $building = null;
            }
            $sources[] = ['ref' => 'request:'.$ticket['id'], 'kind' => 'request', 'area_id' => $ticket['area_id'], 'title' => $ticket['title'], 'version' => $ticket['version'],
                'status' => $ticket['status'], 'priority' => $ticket['priority'], 'due_on' => $ticket['due_on'], 'building' => $building['title'] ?? '',
                'building_id' => $building['id'] ?? null, 'building_version' => $building['version'] ?? null, 'project_id' => $ticket['project_id'], 'task_id' => $ticket['task_id']];
        }

        try {
            return $this->snapshots->normalize(['schema_version' => 2, 'persona' => $selection['persona'], 'local_revision' => $revision,
                'area_ids' => $selection['area_ids'], 'sources' => $sources, 'coverage' => ReviewerAiSnapshot::COVERAGE_SERVER]);
        } catch (\Illuminate\Validation\ValidationException) {
            abort(422, 'This current scope exceeds the closed AI evidence limits. Choose a smaller explicit selection.');
        }
    }
}
