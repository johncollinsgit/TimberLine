<?php

namespace App\Proposals;

/** Closed atomic fictional commands only. Caller holds the synthetic tenant transaction. */
final class ReviewerWorkspaceCommands
{
    public function __construct(private ReviewerWorkspaceAccess $access, private ReviewerWorkspaceProjector $projector, private ReviewerWorkspaceSchema $schema) {}

    private function index(array $graph, string $kind, string $id): int
    {
        foreach ($graph[$kind] as $index => $row) {
            if ($row['id'] === $id) {
                return $index;
            }
        }
        abort(404, 'The current fictional record is unavailable.');
    }

    private function absent(array $graph, string $kind, mixed $id): void
    {
        abort_unless(ReviewerWorkspaceSchema::uuid($id), 422);
        abort_if(in_array($id, array_column($graph[$kind], 'id'), true), 409);
    }

    private function area(array $scope, array $selection, mixed $id, bool $routing = false): void
    {
        abort_unless(ReviewerWorkspaceSchema::uuid($id) && in_array($id, $scope['policy']['area_ids'], true)
            && ($routing || in_array($id, $selection['area_ids'], true)), 403);
    }

    private function building(array $graph, mixed $id, mixed $version, string $area): void
    {
        if ($id === null) {
            abort_unless($version === null, 422);

            return;
        }
        abort_unless(ReviewerWorkspaceSchema::uuid($id) && ReviewerWorkspaceSchema::version($version), 422);
        $row = $graph['buildings'][$this->index($graph, 'buildings', $id)];
        abort_unless($row['area_id'] === $area && $row['status'] === 'active', 422);
        abort_unless($row['version'] === $version, 409);
    }

    private function person(array $graph, mixed $id, string $area, bool $required = false): void
    {
        if ($id === null && ! $required) {
            return;
        }
        abort_unless(ReviewerWorkspaceSchema::person($id), 422);
        $row = $graph['people'][$this->index($graph, 'people', $id)];
        abort_unless($row['active'] && in_array($area, $row['area_ids'], true), 422);
    }

    private function touch(array &$row, string $at): void
    {
        $row['version']++;
        $row['updated_at'] = $at;
    }

    private function project(array $graph, string $id, string $area, ?int $expected = null): array
    {
        $row = $graph['projects'][$this->index($graph, 'projects', $id)];
        abort_unless($row['area_id'] === $area && $row['status'] !== 'archived', 422);
        abort_unless($expected === null || $row['version'] === $expected, 409);

        return $row;
    }

    /** A task link does not grant permission to change the submitted ticket's fields. */
    private function linkedTicketFields(array $selection, array $graph, array $task, array $payload): void
    {
        if ($selection['persona'] === 'demo_manager') {
            return;
        }
        foreach ($graph['tickets'] as $ticket) {
            if ($ticket['task_id'] !== $task['id']) {
                continue;
            }
            foreach (['assignee_id', 'priority', 'building_id'] as $field) {
                abort_if(array_key_exists($field, $payload) && $payload[$field] !== $task[$field], 403);
            }
        }
    }

    public function apply(array $scope, array $selection, array $graph, array $command, string $at): array
    {
        $action = $command['action'];
        $id = $command['record_id'];
        $payload = $command['payload'];
        foreach ($payload as $key => $value) {
            if (in_array($key, ['owner_id', 'assignee_id'], true)) {
                abort_unless($value === null || ReviewerWorkspaceSchema::person($value), 422);
            } elseif (str_ends_with((string) $key, '_id')) {
                abort_unless($key === 'building_id' && $value === null || ReviewerWorkspaceSchema::uuid($value), 422);
            } elseif (in_array($key, ['project_version', 'contractor_version', 'building_version'], true)) {
                abort_unless($key === 'building_version' && $value === null || ReviewerWorkspaceSchema::version($value), 422);
            }
        }
        abort_unless(in_array($action, $this->access->actions($scope, $selection), true), 403);
        [$prefix, $verb] = explode('.', $action, 2);
        $kind = match ($prefix) {
            'project', 'job' => 'projects', 'task' => 'tasks', 'ticket' => 'tickets', 'plan' => 'plans', default => abort(422)
        };
        $create = $verb === 'create';
        if ($create) {
            $this->absent($graph, $kind, $id);
            abort_unless($command['expected_record_version'] === 0, 409);
        } else {
            $index = $this->index($graph, $kind, $id);
            $row = $graph[$kind][$index];
            abort_unless($row['version'] === $command['expected_record_version'], 409);
            abort_unless(in_array($action, $this->projector->recordActions($scope, $selection, $graph, $prefix, $row), true), 403);
        }
        if ($action === 'job.create') {
            ReviewerWorkspaceSchema::exact($payload, ['area_id', 'title', 'description', 'task_title', 'owner_id', 'task_id', 'ticket_id', 'assignee_id', 'building_id', 'building_version', 'priority', 'due_on', 'type']);
            $area = $payload['area_id'];
            $this->area($scope, $selection, $area);
            $this->absent($graph, 'tasks', $payload['task_id']);
            $this->absent($graph, 'tickets', $payload['ticket_id']);
            abort_unless(count(array_unique([$id, $payload['task_id'], $payload['ticket_id']])) === 3, 422);
            $this->person($graph, $payload['owner_id'], $area);
            $this->person($graph, $payload['assignee_id'], $area, true);
            $this->building($graph, $payload['building_id'], $payload['building_version'], $area);
            $graph['projects'][] = ['id' => $id, 'area_id' => $area, 'title' => $payload['title'], 'description' => $payload['description'], 'version' => 1,
                'status' => 'active', 'owner_id' => $payload['owner_id'], 'due_on' => $payload['due_on'], 'updated_at' => $at];
            $graph['tasks'][] = ['id' => $payload['task_id'], 'project_id' => $id, 'area_id' => $area, 'title' => $payload['task_title'], 'description' => $payload['description'],
                'version' => 1, 'status' => 'active', 'priority' => $payload['priority'], 'due_on' => $payload['due_on'], 'assignee_id' => $payload['assignee_id'], 'building_id' => $payload['building_id'], 'updated_at' => $at];
            $graph['tickets'][] = ['id' => $payload['ticket_id'], 'area_id' => $area, 'title' => $payload['task_title'], 'description' => $payload['description'], 'version' => 1,
                'status' => 'assigned', 'priority' => $payload['priority'], 'due_on' => $payload['due_on'], 'building_id' => $payload['building_id'],
                'project_id' => $id, 'task_id' => $payload['task_id'], 'owner_capability_hash' => $scope['capability_hash'], 'position' => 0, 'type' => $payload['type'], 'updated_at' => $at];
        } elseif ($action === 'project.create') {
            ReviewerWorkspaceSchema::exact($payload, ['area_id', 'title', 'description', 'owner_id', 'due_on']);
            $this->area($scope, $selection, $payload['area_id']);
            $this->person($graph, $payload['owner_id'], $payload['area_id']);
            $graph['projects'][] = ['id' => $id, 'area_id' => $payload['area_id'], 'title' => $payload['title'], 'description' => $payload['description'],
                'version' => 1, 'status' => 'active', 'owner_id' => $payload['owner_id'], 'due_on' => $payload['due_on'], 'updated_at' => $at];
        } elseif ($action === 'project.update') {
            ReviewerWorkspaceSchema::exact($payload, ['title', 'description', 'owner_id', 'due_on']);
            abort_if($row['status'] === 'archived', 409);
            $this->person($graph, $payload['owner_id'], $row['area_id']);
            $graph[$kind][$index] = array_replace($row, $payload);
            $this->touch($graph[$kind][$index], $at);
        } elseif ($action === 'project.archive') {
            ReviewerWorkspaceSchema::exact($payload, ['archived']);
            abort_unless(is_bool($payload['archived']), 422);
            $graph[$kind][$index]['status'] = $payload['archived'] ? 'archived' : 'active';
            $this->touch($graph[$kind][$index], $at);
        } elseif ($action === 'task.create') {
            ReviewerWorkspaceSchema::exact($payload, ['area_id', 'project_id', 'title', 'description', 'assignee_id', 'building_id', 'building_version', 'priority', 'due_on']);
            $this->area($scope, $selection, $payload['area_id']);
            $this->project($graph, $payload['project_id'], $payload['area_id']);
            $this->person($graph, $payload['assignee_id'], $payload['area_id']);
            $this->building($graph, $payload['building_id'], $payload['building_version'], $payload['area_id']);
            unset($payload['building_version']);
            $graph['tasks'][] = ['id' => $id, 'version' => 1, 'status' => 'active', 'updated_at' => $at] + $payload;
        } elseif (in_array($action, ['task.update', 'task.assign', 'task.status', 'task.archive'], true)) {
            abort_if($row['status'] === 'archived', 409);
            if ($action === 'task.update') {
                ReviewerWorkspaceSchema::exact($payload, ['title', 'description', 'assignee_id', 'building_id', 'building_version', 'priority', 'due_on']);
                $this->linkedTicketFields($selection, $graph, $row, $payload);
                $this->person($graph, $payload['assignee_id'], $row['area_id']);
                $this->building($graph, $payload['building_id'], $payload['building_version'], $row['area_id']);
                unset($payload['building_version']);
            } elseif ($action === 'task.assign') {
                ReviewerWorkspaceSchema::exact($payload, ['assignee_id']);
                $this->linkedTicketFields($selection, $graph, $row, $payload);
                $this->person($graph, $payload['assignee_id'], $row['area_id']);
            } elseif ($action === 'task.status') {
                ReviewerWorkspaceSchema::exact($payload, ['status']);
                abort_unless(in_array($payload['status'], array_diff(ReviewerWorkspaceSchema::TASK_STATUSES, ['archived']), true), 422);
            } else {
                ReviewerWorkspaceSchema::exact($payload, ['acknowledge_removal']);
                abort_unless($payload['acknowledge_removal'] === true, 422);
                $payload = ['status' => 'archived'];
            }
            $graph[$kind][$index] = array_replace($row, $payload);
            $this->touch($graph[$kind][$index], $at);
            if (isset($payload['status'])) {
                foreach ($graph['tickets'] as &$ticket) {
                    if ($ticket['task_id'] === $id) {
                        $ticket['status'] = match ($payload['status']) {
                            'archived', 'closed' => 'closed', 'completed' => 'completed', default => 'in_progress'
                        };
                        $this->touch($ticket, $at);
                    }
                } unset($ticket);
            }
        } elseif ($action === 'ticket.create') {
            ReviewerWorkspaceSchema::exact($payload, ['area_id', 'title', 'description', 'priority', 'due_on', 'building_id', 'building_version', 'type']);
            $this->area($scope, $selection, $payload['area_id']);
            $this->building($graph, $payload['building_id'], $payload['building_version'], $payload['area_id']);
            unset($payload['building_version']);
            $graph['tickets'][] = ['id' => $id, 'version' => 1, 'status' => 'submitted', 'project_id' => null, 'task_id' => null,
                'owner_capability_hash' => $scope['capability_hash'], 'position' => 0, 'updated_at' => $at] + $payload;
        } elseif ($action === 'ticket.update') {
            ReviewerWorkspaceSchema::exact($payload, ['title', 'description', 'priority', 'due_on', 'building_id', 'building_version', 'type']);
            abort_unless(in_array($row['status'], ['submitted', 'triaged'], true) && $row['task_id'] === null, 409);
            $this->building($graph, $payload['building_id'], $payload['building_version'], $row['area_id']);
            unset($payload['building_version']);
            $graph[$kind][$index] = array_replace($row, $payload);
            $this->touch($graph[$kind][$index], $at);
        } elseif ($action === 'ticket.classify') {
            ReviewerWorkspaceSchema::exact($payload, ['type', 'priority']);
            abort_unless(in_array($row['status'], ['submitted', 'triaged'], true) && $row['task_id'] === null, 409);
            $graph[$kind][$index] = array_replace($row, $payload);
            $this->touch($graph[$kind][$index], $at);
        } elseif (in_array($action, ['ticket.assign', 'ticket.plan'], true)) {
            ReviewerWorkspaceSchema::exact($payload, ['target_area_id', 'project_id', 'project_version', 'task_id', 'assignee_id', 'building_id', 'building_version', 'acknowledge_routing']);
            abort_unless(in_array($row['status'], ['submitted', 'triaged'], true) && $row['task_id'] === null && $payload['acknowledge_routing'] === true
                && ReviewerWorkspaceSchema::uuid($payload['project_id']) && ReviewerWorkspaceSchema::version($payload['project_version']), 422);
            $target = $payload['target_area_id'];
            $this->area($scope, $selection, $target, true);
            abort_unless($action !== 'ticket.assign' || $target === $row['area_id'], 422);
            $this->project($graph, $payload['project_id'], $target, $payload['project_version']);
            $this->absent($graph, 'tasks', $payload['task_id']);
            $this->person($graph, $payload['assignee_id'], $target, true);
            $this->building($graph, $payload['building_id'], $payload['building_version'], $target);
            $graph['tasks'][] = ['id' => $payload['task_id'], 'project_id' => $payload['project_id'], 'area_id' => $target, 'title' => $row['title'], 'description' => $row['description'],
                'version' => 1, 'status' => 'active', 'priority' => $row['priority'], 'due_on' => $row['due_on'], 'assignee_id' => $payload['assignee_id'], 'building_id' => $payload['building_id'], 'updated_at' => $at];
            $graph['tickets'][$index] = array_replace($row, ['area_id' => $target, 'project_id' => $payload['project_id'], 'task_id' => $payload['task_id'],
                'building_id' => $payload['building_id'], 'status' => 'assigned']);
            $this->touch($graph['tickets'][$index], $at);
        } elseif ($action === 'ticket.status') {
            ReviewerWorkspaceSchema::exact($payload, ['status']);
            abort_unless(in_array($payload['status'], ReviewerWorkspaceSchema::TICKET_STATUSES, true), 422);
            abort_if($row['task_id'] !== null && in_array($payload['status'], ['submitted', 'triaged'], true), 409);
            $graph[$kind][$index]['status'] = $payload['status'];
            $this->touch($graph[$kind][$index], $at);
            if ($row['task_id'] !== null) {
                $taskIndex = $this->index($graph, 'tasks', $row['task_id']);
                $graph['tasks'][$taskIndex]['status'] = match ($payload['status']) {
                    'completed' => 'completed', 'closed' => 'closed', default => 'active'
                };
                $this->touch($graph['tasks'][$taskIndex], $at);
            }
        } elseif ($action === 'ticket.reorder') {
            ReviewerWorkspaceSchema::exact($payload, ['position']);
            abort_unless(is_int($payload['position']) && $payload['position'] >= 0 && $payload['position'] < 200, 422);
            $indices = array_keys(array_filter($graph['tickets'], fn ($ticket) => $ticket['id'] !== $id && $ticket['area_id'] === $row['area_id'] && $ticket['status'] === $row['status']));
            usort($indices, fn ($a, $b) => [$graph['tickets'][$a]['position'], $graph['tickets'][$a]['id']] <=> [$graph['tickets'][$b]['position'], $graph['tickets'][$b]['id']]);
            array_splice($indices, min($payload['position'], count($indices)), 0, [$index]);
            foreach ($indices as $position => $ticketIndex) {
                $graph['tickets'][$ticketIndex]['position'] = $position;
                $this->touch($graph['tickets'][$ticketIndex], $at);
            }
        } elseif ($action === 'plan.create' || $action === 'plan.update') {
            ReviewerWorkspaceSchema::exact($payload, ['area_id', 'project_id', 'project_version', 'contractor_id', 'contractor_version', 'starts_on', 'ends_on']);
            $this->area($scope, $selection, $payload['area_id']);
            abort_unless(ReviewerWorkspaceSchema::uuid($payload['project_id']) && ReviewerWorkspaceSchema::version($payload['project_version'])
                && ReviewerWorkspaceSchema::uuid($payload['contractor_id']) && ReviewerWorkspaceSchema::version($payload['contractor_version']), 422);
            $this->project($graph, $payload['project_id'], $payload['area_id'], $payload['project_version']);
            $contractor = $graph['contractors'][$this->index($graph, 'contractors', $payload['contractor_id'])];
            abort_unless($contractor['area_id'] === $payload['area_id'] && $contractor['status'] === 'active', 422);
            abort_unless($contractor['version'] === $payload['contractor_version'], 409);
            abort_unless($payload['starts_on'] !== null && $payload['ends_on'] !== null && ReviewerWorkspaceSchema::date($payload['starts_on']) && ReviewerWorkspaceSchema::date($payload['ends_on']) && $payload['starts_on'] <= $payload['ends_on'], 422);
            foreach ($graph['plans'] as $plan) {
                abort_if($plan['id'] !== $id && $plan['status'] === 'planned' && $plan['contractor_id'] === $payload['contractor_id'] && $plan['starts_on'] <= $payload['ends_on'] && $plan['ends_on'] >= $payload['starts_on'], 409, 'The fictional contractor plan overlaps.');
            }
            unset($payload['project_version'], $payload['contractor_version']);
            $next = ['id' => $id, 'version' => $create ? 1 : $row['version'] + 1, 'status' => 'planned', 'updated_at' => $at] + $payload;
            if ($create) {
                $graph['plans'][] = $next;
            } else {
                $graph['plans'][$index] = $next;
            }
        } elseif ($action === 'plan.cancel') {
            ReviewerWorkspaceSchema::exact($payload, []);
            $graph['plans'][$index]['status'] = 'cancelled';
            $this->touch($graph['plans'][$index], $at);
        } else {
            abort(422);
        }

        return $this->schema->graph($graph);
    }
}
