<?php

declare(strict_types=1);

namespace App\Services\Team;

use DateTimeImmutable;
use DomainException;

/**
 * Shared, side-effect-free work commands, used by the tenant-locked repository.
 * $actor MUST come from a trusted active-membership/module resolver, never request JSON.
 * The persistence adapter must lock, execute and commit state + receipt in one transaction.
 */
final class WorkGraph
{
    public static function empty(): array
    {
        return ['projects' => [], 'tasks' => [], 'events' => [], 'receipts' => []];
    }

    private static function fail(string $reason): never
    {
        throw new DomainException($reason);
    }

    private function authorize(array $actor): void
    {
        foreach (['tenant_id', 'user_id'] as $key) {
            if (! is_string($actor[$key] ?? null) || trim($actor[$key]) === '') {
                self::fail('access_denied');
            }
        }
        if (($actor['active'] ?? false) !== true || ($actor['verified'] ?? false) !== true || ($actor['entitled'] ?? false) !== true
            || ! in_array($actor['role'] ?? '', ['owner', 'admin', 'member', 'guest', 'viewer'], true)) {
            self::fail('access_denied');
        }
    }

    private function projectAccess(array $project, array $actor, bool $write = false): bool
    {
        if (isset($project['enterprise_area_id']) && ! \App\Enterprise\WorkspaceAccess::permits($actor, $project['enterprise_area_id'], $write)) {
            return false;
        }
        if ($project['tenant_id'] !== $actor['tenant_id']) {
            return false;
        }
        $grant = $project['members'][$actor['user_id']] ?? null;
        $read = $grant !== null || ($project['privacy'] === 'workspace' && ! in_array($actor['role'], ['guest'], true));
        if (! $write) {
            return $read;
        }

        return $read && ! $project['archived'] && $actor['role'] !== 'viewer' && $grant !== 'viewer'
            && ($grant === 'editor' || ($project['privacy'] === 'workspace' && in_array($actor['role'], ['owner', 'admin', 'member'], true)));
    }

    private function taskAccess(array $state, array $task, array $actor, bool $write = false, int $depth = 0): bool
    {
        if (isset($task['enterprise_area_id']) && ! \App\Enterprise\WorkspaceAccess::permits($actor, $task['enterprise_area_id'], $write)) {
            return false;
        }
        if ($depth > 5 || $task['tenant_id'] !== $actor['tenant_id'] || $task['deleted']) {
            return false;
        }
        if ($write && $actor['role'] === 'viewer') {
            return false;
        }
        if ($task['parent_id'] !== null) {
            $parent = $state['tasks'][$task['parent_id']] ?? null;
            if (! $parent || ! $this->taskAccess($state, $parent, $actor, $write, $depth + 1)) {
                return false;
            }
        }
        if ($task['memberships'] === []) {
            if ($task['parent_id'] !== null) {
                return true; // Parent access was checked above; unhomed subtasks inherit it.
            }

            return $task['creator_id'] === $actor['user_id'] || ($task['collaborators'][$actor['user_id']] ?? null) === ($write ? 'editor' : 'viewer')
                || (! $write && isset($task['collaborators'][$actor['user_id']]));
        }
        foreach ($task['memberships'] as $projectId => $_) {
            if (isset($state['projects'][$projectId]) && $this->projectAccess($state['projects'][$projectId], $actor, $write)) {
                return true;
            }
        }

        return false;
    }

    private function project(array $state, array $actor, string $id, bool $write = false): array
    {
        $project = $state['projects'][$id] ?? null;
        if (! $project || ! $this->projectAccess($project, $actor, $write)) {
            self::fail('access_denied');
        }

        return $project;
    }

    private function task(array $state, array $actor, string $id, bool $write = false): array
    {
        $task = $state['tasks'][$id] ?? null;
        if (! $task || ! $this->taskAccess($state, $task, $actor, $write)) {
            self::fail('access_denied');
        }

        return $task;
    }

    private function text(mixed $value, int $limit, bool $required = false): string
    {
        if (! is_string($value) || strlen($value) > $limit || ($required && trim($value) === '')) {
            self::fail('invalid_text');
        }

        return $value;
    }

    private function dates(array $task): void
    {
        foreach (['start', 'due'] as $prefix) {
            $on = $task[$prefix.'_on'];
            $at = $task[$prefix.'_at'];
            $parsedOn = is_string($on) ? DateTimeImmutable::createFromFormat('!Y-m-d', $on) : false;
            if ($on !== null && ($at !== null || ! is_string($on) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $on)
                || ! $parsedOn || $parsedOn->format('Y-m-d') !== $on)) {
                self::fail('invalid_date');
            }
            if ($at !== null && (! is_string($at) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/D', $at))) {
                self::fail('invalid_date');
            }
            if ($at !== null) {
                try {
                    $parsed = new DateTimeImmutable($at);
                } catch (\Exception) {
                    self::fail('invalid_date');
                }
                if ($parsed->format('Y-m-d\TH:i:s') !== substr($at, 0, 19)
                    || abs($parsed->getOffset()) > 14 * 3600) {
                    self::fail('invalid_date');
                }
            }
        }
        if ($task['start_on'] !== null && $task['due_on'] !== null && $task['start_on'] > $task['due_on']) {
            self::fail('invalid_date_order');
        }
        if ($task['start_at'] !== null && $task['due_at'] !== null && new DateTimeImmutable($task['start_at']) > new DateTimeImmutable($task['due_at'])) {
            self::fail('invalid_date_order');
        }
    }

    private function version(array $entity, array $command): void
    {
        if (! is_int($command['expected_version'] ?? null) || $command['expected_version'] !== $entity['version']) {
            self::fail('conflict');
        }
    }

    private function membership(array $state, array $actor, string $projectId, array $value): array
    {
        $project = $this->project($state, $actor, $projectId, true);
        $section = $value['section_id'] ?? null;
        if ($section !== null && ! isset($project['sections'][$section])) {
            self::fail('invalid_section');
        }
        $position = $value['position'] ?? 0;
        if (! is_int($position) || $position < 0) {
            self::fail('invalid_position');
        }

        return ['section_id' => $section, 'position' => $position];
    }

    private function repeatScope(array $state, array $actor, array $task): void
    {
        if ($task['parent_id'] !== null || ($task['memberships'] === [] && ($actor['role'] === 'guest' || $task['creator_id'] !== $actor['user_id'] || $task['collaborators'] !== []))) {
            self::fail('recurrence_requires_unshared_root_or_projects');
        }
        foreach ($state['tasks'] as $child) {
            if (! $child['deleted'] && $child['parent_id'] === $task['id']) {
                self::fail('recurrence_subtasks_require_review');
            }
        }
        foreach ($task['memberships'] as $projectId => $_) {
            $this->project($state, $actor, (string) $projectId, true);
        }
    }

    private function repeat(array $state, array $actor, array &$task, string $now): array
    {
        if (isset($task['recurrence_successor_id']) || ($task['recurrence_finished'] ?? false)) {
            return $state; // Reopen/recomplete and differently keyed retries never fork the series.
        }
        $this->repeatScope($state, $actor, $task);
        $dates = (new TeamRecurrence)->next($task);
        if ($dates === null) {
            $task['recurrence_finished'] = true;

            return $state;
        }
        if ($task['assignee_id'] !== null && ! in_array($task['assignee_id'], $actor['verified_user_ids'] ?? [], true)) {
            self::fail('invalid_assignee');
        }
        $series = $task['recurrence_series_id'] ?? $task['id'];
        $index = ($task['recurrence_index'] ?? 1) + 1;
        $id = TeamRecurrence::stableId($series.':occurrence:'.$index);
        $key = 'repeat:'.$series.':'.$index;
        $state = $this->execute($state, $actor, ['kind' => 'task.create', 'id' => $id, 'idempotency_key' => $key,
            'title' => $task['title'], 'description' => $task['description'], 'priority' => $task['priority'], 'assignee_id' => $task['assignee_id'],
            'memberships' => $task['memberships'], ...$dates], $now)['state'];
        foreach ($task['custom_fields'] ?? [] as $projectId => $values) {
            $state = $this->execute($state, $actor, ['kind' => 'task.fields', 'id' => $id, 'idempotency_key' => $key.':'.$projectId,
                'expected_version' => $state['tasks'][$id]['version'], 'project_id' => (string) $projectId,
                'project_version' => $state['projects'][$projectId]['version'], 'values' => $values], $now)['state'];
        }
        $state['tasks'][$id]['recurrence'] = $task['recurrence'];
        $state['tasks'][$id]['recurrence_series_id'] = $series;
        $state['tasks'][$id]['recurrence_index'] = $index;
        $task['recurrence_successor_id'] = $id;

        return $state;
    }

    private function dependency(array $state, array $actor, array $task, array $command): array
    {
        if (($command['remove_unavailable'] ?? false) === true) {
            $task['blocker_ids'] = array_filter($task['blocker_ids'] ?? [], fn ($_, $id) => $this->can($state, $actor, 'task', (string) $id), ARRAY_FILTER_USE_BOTH);

            return $task;
        }
        $blocker = $this->text($command['blocker_id'] ?? '', 120, true);
        if (($command['remove'] ?? false) === true) {
            unset($task['blocker_ids'][$blocker]);

            return $task;
        }
        $this->task($state, $actor, $blocker); // The endpoint grants no access to either task.
        $pending = [$blocker];
        $seen = [];
        while ($pending !== []) {
            $id = array_pop($pending);
            if ($id === $task['id']) {
                self::fail('dependency_cycle');
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (($state['tasks'][$id]['tenant_id'] ?? null) !== $actor['tenant_id']) {
                self::fail('access_denied');
            }
            if (count($seen) > 10000) {
                self::fail('dependency_graph_limit');
            }
            foreach (array_keys($state['tasks'][$id]['blocker_ids'] ?? []) as $next) {
                $pending[] = $next;
            }
        }
        if (! isset($task['blocker_ids'][$blocker]) && count($task['blocker_ids'] ?? []) >= 100) {
            self::fail('dependency_limit');
        }
        $task['blocker_ids'][$blocker] = true;

        return $task;
    }

    public function projectStatus(array $state, array $actor, string $projectId, array $record): array
    {
        $this->authorize($actor);
        $this->project($state, $actor, $projectId);
        if (($record['project_id'] ?? null) !== $projectId) {
            self::fail('access_denied');
        }
        $record['references'] = [];
        foreach (array_keys($record['reference_versions'] ?? []) as $id) {
            if ($this->can($state, $actor, 'task', (string) $id)) {
                $task = $state['tasks'][$id];
                $record['references'][] = ['id' => $task['id'], 'title' => $task['title'], 'completed' => $task['completed_at'] !== null];
            }
        }
        unset($record['reference_versions']);

        return $record;
    }

    private function statusUpdate(array $state, array $actor, array $project, array $command, string $now): array
    {
        if ($project['archived'] || ! in_array($actor['role'], ['owner', 'admin', 'member'], true)) {
            self::fail('access_denied');
        }
        if (($command['acknowledge_sharing'] ?? false) !== true) {
            self::fail('sharing_review_required');
        }
        $code = $command['status_code'] ?? '';
        if (! in_array($code, ['on_track', 'at_risk', 'off_track', 'on_hold', 'complete'], true)) {
            self::fail('invalid_status');
        }
        $references = $command['references'] ?? [];
        if (! is_array($references) || count($references) > 20) {
            self::fail('invalid_status_references');
        }
        foreach ($references as $id => $version) {
            $task = $this->task($state, $actor, (string) $id);
            if (! isset($task['memberships'][$project['id']])) {
                self::fail('invalid_status_reference_project');
            }
            $this->version($task, ['expected_version' => $version]);
        }

        return ['id' => TeamRecurrence::stableId($actor['tenant_id'].':'.$actor['user_id'].':'.$project['id'].':status:'.$command['idempotency_key']),
            'project_id' => $project['id'], 'project_version' => $project['version'] + 1, 'code' => $code,
            'summary' => $this->text($command['summary'] ?? '', 4000, true),
            'highlights' => $this->text($command['highlights'] ?? '', 2000), 'blockers' => $this->text($command['blockers'] ?? '', 2000), 'next_steps' => $this->text($command['next_steps'] ?? '', 2000),
            'author_id' => $actor['user_id'], 'created_at' => $now, 'reference_versions' => $references];
    }

    private function followUp(array $state, array $actor, array $command, string $now): array
    {
        $source = $this->task($state, $actor, $this->text($command['source_task_id'] ?? '', 120, true));
        $this->version($source, ['expected_version' => $command['source_version'] ?? null]);
        if (($command['acknowledge_sharing'] ?? false) !== true) {
            self::fail('sharing_review_required');
        }
        $commentId = $command['source_comment_id'] ?? null;
        if ($commentId !== null && (! isset($source['comments'][$commentId]) || $source['comments'][$commentId]['deleted_at'] !== null)) {
            self::fail('source_comment_unavailable');
        }
        if (($command['assignee_id'] ?? null) !== null && ! in_array($command['assignee_id'], $actor['verified_user_ids'] ?? [], true)) {
            self::fail('invalid_assignee');
        }
        $memberships = $command['memberships'] ?? [];
        $versions = $command['destination_versions'] ?? [];
        if (! is_array($memberships) || ! is_array($versions)) {
            self::fail('invalid_destination_review');
        }
        foreach ($memberships as $projectId => $_) {
            $this->project($state, $actor, (string) $projectId, true);
        }
        if (array_diff_key($memberships, $versions) || array_diff_key($versions, $memberships)) {
            self::fail('invalid_destination_review');
        }
        foreach ($memberships as $projectId => $_) {
            $project = $this->project($state, $actor, (string) $projectId, true);
            $this->version($project, ['expected_version' => $versions[$projectId]]);
        }
        // Only newly reviewed content and explicit dates/assignment/destination are created.
        // Never copy source notes, comments, files, grants, recurrence or history.
        $create = array_intersect_key($command, array_flip(['id', 'title', 'description', 'priority', 'assignee_id', 'memberships', 'start_on', 'due_on', 'start_at', 'due_at']));
        $create['kind'] = 'task.create';
        $create['idempotency_key'] = 'follow-up:'.$command['idempotency_key'];
        $state = $this->execute($state, $actor, $create, $now)['state'];
        $state['tasks'][$command['id']]['follow_up_source'] = ['task_id' => $source['id'], 'version' => $source['version'], 'comment_id' => $commentId, 'recorded_at' => $now];

        return $state;
    }

    /** Returns the next state and an ACL-filtered result; input is unchanged on failure. */
    public function execute(array $state, array $actor, array $command, string $now): array
    {
        $this->authorize($actor);
        if ($actor['role'] === 'viewer') {
            self::fail('access_denied');
        }
        $kind = $command['kind'] ?? '';
        $id = $this->text($command['id'] ?? '', 120, true);
        $key = $this->text($command['idempotency_key'] ?? '', 120, true);
        $receiptId = json_encode([$actor['tenant_id'], $actor['user_id'], $key], JSON_THROW_ON_ERROR);
        $digest = hash('sha256', json_encode($command, JSON_THROW_ON_ERROR));
        // Recheck current object access before returning a previous receipt.
        if (isset($state['receipts'][$receiptId])) {
            $receipt = $state['receipts'][$receiptId];
            if ($receipt['digest'] !== $digest) {
                self::fail('idempotency_mismatch');
            }
            if ($receipt['entity_type'] === 'project') {
                $this->project($state, $actor, $id);
            } else {
                $visibleState = $state;
                if ($kind === 'task.delete' && isset($visibleState['tasks'][$id])) {
                    $visibleState['tasks'][$id]['deleted'] = false;
                }
                $this->task($visibleState, $actor, $id);
                if ($kind === 'task.delete') {
                    return ['state' => $state, 'result' => ['id' => $id, 'deleted' => true], 'replayed' => true];
                }
            }

            $result = $this->read($state, $actor, $receipt['entity_type'], $id);
            if ($kind === 'project.status') {
                $result['posted_status_id'] = TeamRecurrence::stableId($actor['tenant_id'].':'.$actor['user_id'].':'.$id.':status:'.$key);
            }

            return ['state' => $state, 'result' => $result, 'replayed' => true];
        }

        $type = str_starts_with($kind, 'project.') ? 'project' : 'task';
        if ($kind === 'task.follow_up') {
            $state = $this->followUp($state, $actor, $command, $now);
        } elseif (in_array($kind, ['project.from_template', 'task.from_template'], true)) {
            if (isset($state['projects'][$command['project_id'] ?? '']['enterprise_area_id'])) {
                $this->project($state, $actor, $command['project_id'], true);
                self::fail('enterprise_copy_not_available');
            }
            $state = (new TeamWorkBlueprints($this))->instantiate($state, $actor, $command, $now);
        } elseif ($kind === 'project.create') {
            if (! in_array($actor['role'], ['owner', 'admin', 'member'], true) || isset($state['projects'][$id])) {
                self::fail('access_denied');
            }
            $privacy = $command['privacy'] ?? 'private';
            if (! in_array($privacy, ['private', 'workspace'], true)) {
                self::fail('invalid_privacy');
            }
            $state['projects'][$id] = ['id' => $id, 'tenant_id' => $actor['tenant_id'], 'name' => $this->text($command['name'] ?? '', 500, true),
                'description' => $this->text($command['description'] ?? '', 100000), 'privacy' => $privacy, 'archived' => false,
                'owner_id' => $actor['user_id'], 'members' => [$actor['user_id'] => 'editor'], 'sections' => [], 'version' => 1];
            if (isset($command['enterprise_area_id'])) {
                if (! \App\Enterprise\WorkspaceAccess::permits($actor, $command['enterprise_area_id'], true, true)) {
                    self::fail('access_denied');
                }
                $state['projects'][$id]['enterprise_area_id'] = $command['enterprise_area_id'];
            }
            foreach (['start_on', 'due_on', 'start_at', 'due_at'] as $field) {
                $state['projects'][$id][$field] = $command[$field] ?? null;
            }
            $this->dates($state['projects'][$id]);
        } elseif (str_starts_with($kind, 'project.')) {
            $project = $this->project($state, $actor, $id);
            if (isset($project['enterprise_area_id']) && ! \App\Enterprise\WorkspaceAccess::permits($actor, $project['enterprise_area_id'], true)) {
                self::fail('access_denied');
            }
            $this->version($project, $command);
            if ($project['owner_id'] !== $actor['user_id']) {
                self::fail('access_denied');
            }
            if ($kind === 'project.status') {
                $project['current_status'] = $this->statusUpdate($state, $actor, $project, $command, $now);
            } elseif ($kind === 'project.template') {
                $templateId = $this->text($command['template_id'] ?? '', 120, true);
                if (($command['remove'] ?? false) === true) {
                    unset($project['templates'][$templateId]);
                } else {
                    if ($project['archived']) {
                        self::fail('project_archived');
                    }
                    $project['templates'][$templateId] = (new TeamWorkBlueprints($this))->capture($state, $actor, $project, $command, $now);
                }
            } elseif ($kind === 'project.transfer_owner') {
                $userId = $this->text($command['user_id'] ?? '', 120, true);
                if ($project['archived']) {
                    self::fail('project_archived');
                }
                if (($command['acknowledge_sharing'] ?? false) !== true) {
                    self::fail('sharing_review_required');
                }
                if ($userId === $project['owner_id'] || ($project['members'][$userId] ?? null) !== 'editor'
                    || ! in_array($userId, $actor['active_user_ids'] ?? [], true)
                    || ! in_array($userId, $actor['verified_user_ids'] ?? [], true)
                    || ! in_array($actor['active_roles'][$userId] ?? '', ['owner', 'admin', 'member'], true)) {
                    self::fail('invalid_owner');
                }
                // Transfer project control only; retain the former owner's explicit editor grant.
                $project['owner_id'] = $userId;
            } elseif ($kind === 'project.archive') {
                $project['archived'] = (bool) ($command['archived'] ?? true);
            } elseif ($kind === 'project.update') {
                $changes = $command['changes'] ?? [];
                if (array_diff(array_keys($changes), ['name', 'description', 'privacy', 'start_on', 'due_on', 'start_at', 'due_at'])) {
                    self::fail('invalid_field');
                }
                foreach ($changes as $field => $value) {
                    if ($field === 'privacy') {
                        if (! in_array($value, ['private', 'workspace'], true)) {
                            self::fail('invalid_privacy');
                        }
                        if ($value === 'workspace' && $project['privacy'] === 'private' && ($command['acknowledge_sharing'] ?? false) !== true) {
                            self::fail('sharing_review_required');
                        }
                        $project[$field] = $value;
                    } elseif (in_array($field, ['start_on', 'due_on', 'start_at', 'due_at'], true)) {
                        $project[$field] = $value;
                    } else {
                        $project[$field] = $this->text($value, $field === 'name' ? 500 : 100000, $field === 'name');
                    }
                }
                $this->dates($project + ['start_on' => null, 'due_on' => null, 'start_at' => null, 'due_at' => null]);
            } elseif ($kind === 'project.member') {
                $userId = $this->text($command['user_id'] ?? '', 120, true);
                if ($userId === $project['owner_id']) {
                    self::fail('owner_required');
                }
                $role = $command['access'] ?? null;
                if ($role !== null && ! in_array($role, ['viewer', 'editor'], true)) {
                    self::fail('invalid_role');
                }
                // IDs are resolved by the trusted server adapter, not client choices.
                if ($role !== null && ! in_array($userId, $actor['active_user_ids'] ?? [], true)) {
                    self::fail('invalid_member');
                }
                if ($role === null) {
                    unset($project['members'][$userId]);
                } else {
                    $project['members'][$userId] = $role;
                }
            } elseif ($kind === 'project.section') {
                if ($project['archived']) {
                    self::fail('project_archived');
                }
                $sectionId = $this->text($command['section_id'] ?? '', 120, true);
                $project['sections'][$sectionId] = $this->text($command['name'] ?? '', 500, true);
            } elseif ($kind === 'project.field') {
                if ($project['archived']) {
                    self::fail('project_archived');
                }
                $fieldId = $this->text($command['field_id'] ?? '', 120, true);
                $fieldType = $command['field_type'] ?? '';
                if (! in_array($fieldType, ['text', 'number', 'date', 'enum'], true)) {
                    self::fail('invalid_field_type');
                }
                $old = $project['custom_fields'][$fieldId] ?? null;
                $choices = $fieldType === 'enum' ? ($command['choices'] ?? []) : [];
                if (! is_array($choices) || count($choices) > 20 || ($fieldType === 'enum' && $choices === []) || count(array_unique($choices)) !== count($choices)) {
                    self::fail('invalid_field_choices');
                }
                foreach ($choices as $choice) {
                    $this->text($choice, 100, true);
                }
                if ($old && ($old['type'] !== $fieldType || $old['choices'] !== $choices)) {
                    self::fail('field_definition_immutable');
                }
                if (! $old && count($project['custom_fields'] ?? []) >= 20) {
                    self::fail('field_limit');
                }
                $project['custom_fields'][$fieldId] = ['id' => $fieldId, 'name' => $this->text($command['name'] ?? '', 100, true), 'type' => $fieldType, 'choices' => array_values($choices)];
            } else {
                self::fail('unsupported_command');
            }
            $project['version']++;
            $state['projects'][$id] = $project;
        } elseif ($kind === 'task.create') {
            if (isset($state['tasks'][$id])) {
                self::fail('access_denied');
            }
            $memberships = [];
            foreach ($command['memberships'] ?? [] as $projectId => $value) {
                $memberships[$projectId] = $this->membership($state, $actor, (string) $projectId, $value);
            }
            if ($actor['role'] === 'guest' && $memberships === [] && ($command['parent_id'] ?? null) === null) {
                self::fail('access_denied');
            }
            $parent = $command['parent_id'] ?? null;
            if ($parent !== null) {
                $parentTask = $this->task($state, $actor, $parent, true);
                $depth = 1;
                while ($parentTask['parent_id'] !== null) {
                    $depth++;
                    $parentTask = $this->task($state, $actor, $parentTask['parent_id'], true);
                }
                if ($depth > 5) {
                    self::fail('subtask_depth');
                }
            }
            $assignee = $command['assignee_id'] ?? null;
            if ($assignee !== null && ! in_array($assignee, $actor['active_user_ids'] ?? [], true)) {
                self::fail('invalid_assignee');
            }
            $task = ['id' => $id, 'tenant_id' => $actor['tenant_id'], 'creator_id' => $actor['user_id'], 'title' => $this->text($command['title'] ?? '', 500, true),
                'description' => $this->text($command['description'] ?? '', 100000), 'assignee_id' => $assignee, 'parent_id' => $parent,
                'collaborators' => [], 'memberships' => $memberships, 'completed_at' => null, 'deleted' => false, 'priority' => $command['priority'] ?? 'normal',
                'version' => 1, 'comments' => [], 'field_job_ids' => [], 'created_at' => $now, 'updated_at' => $now];
            $scope = $parent !== null ? ($state['tasks'][$parent]['enterprise_area_id'] ?? null) : null;
            foreach ($memberships as $projectId => $_) {
                $projectScope = $state['projects'][$projectId]['enterprise_area_id'] ?? null;
                if ($scope !== null && $scope !== $projectScope) {
                    self::fail('enterprise_scope_mismatch');
                }
                $scope = $projectScope ?? $scope;
            }
            if ($scope !== null) {
                foreach ($memberships as $projectId => $_) {
                    if (($state['projects'][$projectId]['enterprise_area_id'] ?? null) !== $scope) {
                        self::fail('enterprise_scope_mismatch');
                    }
                }
                $task['enterprise_area_id'] = $scope;
            }
            if (isset($command['enterprise_discussion_project'])) {
                $discussionProject = $command['enterprise_discussion_project'];
                if ($scope === null || count($memberships) !== 1 || ! isset($memberships[$discussionProject]) || $parent !== null || $assignee !== null) {
                    self::fail('invalid_discussion_scope');
                }
                $task['enterprise_discussion_project'] = $discussionProject;
            }
            if (! in_array($task['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
                self::fail('invalid_priority');
            }
            foreach (['start_on', 'due_on', 'start_at', 'due_at'] as $field) {
                $task[$field] = $command[$field] ?? null;
            }
            $this->dates($task);
            $state['tasks'][$id] = $task;
            // Assigning work never grants access or sends a notification.
            if ($assignee !== null && ! $this->taskAccess($state, $task, array_replace($actor, ['user_id' => $assignee, 'role' => ($actor['active_roles'][$assignee] ?? 'guest')]))) {
                self::fail('assignee_lacks_access');
            }
        } else {
            $task = $this->task($state, $actor, $id, true);
            $this->version($task, $command);
            if ($kind === 'task.update') {
                $changes = $command['changes'] ?? [];
                if (array_diff(array_keys($changes), ['title', 'description', 'assignee_id', 'priority', 'start_on', 'due_on', 'start_at', 'due_at'])) {
                    self::fail('invalid_field');
                }
                foreach ($changes as $field => $value) {
                    if (in_array($field, ['title', 'description'], true)) {
                        $value = $this->text($value, $field === 'title' ? 500 : 100000, $field === 'title');
                    }
                    if ($field === 'priority' && ! in_array($value, ['low', 'normal', 'high', 'urgent'], true)) {
                        self::fail('invalid_priority');
                    }
                    if ($field === 'assignee_id' && $value !== null && ! in_array($value, $actor['active_user_ids'] ?? [], true)) {
                        self::fail('invalid_assignee');
                    }
                    $task[$field] = $value;
                }
                $this->dates($task);
                if (($task['recurrence'] ?? null) !== null && array_intersect(array_keys($changes), ['start_on', 'due_on', 'start_at', 'due_at'])) {
                    $input = array_intersect_key($task['recurrence'], array_flip(['frequency', 'interval', 'timezone', 'overlap', 'until_on', 'max_occurrences']));
                    $task['recurrence'] = (new TeamRecurrence)->configure($task, $input);
                }
                if ($task['assignee_id'] !== null && ! $this->taskAccess($state, $task, array_replace($actor, ['user_id' => $task['assignee_id'], 'role' => $actor['active_roles'][$task['assignee_id']] ?? 'guest']))) {
                    self::fail('assignee_lacks_access');
                }
            } elseif ($kind === 'task.complete') {
                if (isset($actor['enterprise_ops_managed'][$task['id']])) {
                    $proof = $actor['enterprise_ops_completion_proofs'][$task['id']] ?? null;
                    if (! ($command['completed'] ?? true) || ! is_array($proof) || $proof['task_version'] !== $task['version']
                        || $proof['approver_id'] !== $actor['user_id']) {
                        self::fail('enterprise_manager_approval_required');
                    }
                }
                if (($command['completed'] ?? true) && $task['completed_at'] === null && ($task['recurrence'] ?? null) !== null) {
                    $state = $this->repeat($state, $actor, $task, $now);
                }
                $task['completed_at'] = ($command['completed'] ?? true) ? $now : null;
            } elseif ($kind === 'task.recurrence') {
                if (isset($task['enterprise_area_id'])) {
                    self::fail('enterprise_recurrence_not_available');
                }
                if (($command['rule'] ?? null) === null) {
                    $task['recurrence'] = null;
                } else {
                    if ($task['completed_at'] !== null || isset($task['recurrence_successor_id']) || ($task['recurrence_finished'] ?? false)) {
                        self::fail('recurrence_series_already_advanced');
                    }
                    if (($command['acknowledge_sharing'] ?? false) !== true) {
                        self::fail('sharing_review_required');
                    }
                    $this->repeatScope($state, $actor, $task);
                    $task['recurrence'] = (new TeamRecurrence)->configure($task, $command['rule']);
                    $task['recurrence_series_id'] ??= $id;
                    $task['recurrence_index'] ??= 1;
                }
            } elseif ($kind === 'task.dependency') {
                $task = $this->dependency($state, $actor, $task, $command);
            } elseif ($kind === 'task.collaborator') {
                // Personal root sharing is creator-controlled. Project and child ACLs remain authoritative.
                if ($task['creator_id'] !== $actor['user_id'] || $task['parent_id'] !== null || $task['memberships'] !== []) {
                    self::fail('access_denied');
                }
                $userId = $this->text($command['user_id'] ?? '', 120, true);
                $grant = $command['access'] ?? null;
                if ($userId === $task['creator_id']) {
                    self::fail('owner_required');
                }
                if ($grant !== null && (! in_array($grant, ['viewer', 'editor'], true)
                    || ! in_array($userId, $actor['active_user_ids'] ?? [], true)
                    || ! in_array($userId, $actor['verified_user_ids'] ?? [], true)
                    || (($actor['active_roles'][$userId] ?? '') === 'viewer' && $grant === 'editor'))) {
                    self::fail('invalid_member');
                }
                if ($grant !== null && ($command['acknowledge_sharing'] ?? false) !== true) {
                    self::fail('sharing_review_required');
                }
                if ($grant === null) {
                    unset($task['collaborators'][$userId]);
                } else {
                    if (! isset($task['collaborators'][$userId]) && count($task['collaborators']) >= 50) {
                        self::fail('collaborator_limit');
                    }
                    $task['collaborators'][$userId] = $grant;
                }
            } elseif ($kind === 'task.membership') {
                if ($task['memberships'] === [] && $task['parent_id'] === null
                    && ($task['creator_id'] !== $actor['user_id'] || $task['collaborators'] !== [])) {
                    self::fail('personal_sharing_review_required');
                }
                $projectId = $this->text($command['project_id'] ?? '', 120, true);
                $membership = $this->membership($state, $actor, $projectId, $command);
                if (! isset($task['memberships'][$projectId])) {
                    if (($command['acknowledge_sharing'] ?? false) !== true) {
                        self::fail('sharing_review_required');
                    }
                    foreach ($task['memberships'] as $sourceId => $_) {
                        $this->project($state, $actor, (string) $sourceId, true);
                    }
                }
                if (($command['remove'] ?? false) === true) {
                    unset($task['memberships'][$projectId]);
                } else {
                    if (($task['enterprise_area_id'] ?? null) !== ($state['projects'][$projectId]['enterprise_area_id'] ?? null)) {
                        self::fail('enterprise_scope_mismatch');
                    }
                    $task['memberships'][$projectId] = $membership;
                }
            } elseif ($kind === 'task.comment') {
                $commentId = $this->text($command['comment_id'] ?? '', 120, true);
                if (isset($task['comments'][$commentId])) {
                    self::fail('duplicate_comment');
                }
                $body = $this->text($command['body'] ?? '', 100000, true);
                foreach ($command['mentioned_user_ids'] ?? [] as $mentionedId) {
                    if (! in_array($mentionedId, $actor['active_user_ids'] ?? [], true)
                        || ! $this->taskAccess($state, $task, array_replace($actor, ['user_id' => $mentionedId, 'role' => $actor['active_roles'][$mentionedId] ?? 'guest']))) {
                        self::fail('invalid_mention');
                    }
                }
                $task['comments'][$commentId] = ['id' => $commentId, 'author_id' => $actor['user_id'], 'body' => $body, 'created_at' => $now,
                    'edited_at' => null, 'deleted_at' => null, 'versions' => [['body' => $body, 'at' => $now]]];
            } elseif (in_array($kind, ['task.comment.update', 'task.comment.delete'], true)) {
                $commentId = $this->text($command['comment_id'] ?? '', 120, true);
                $comment = $task['comments'][$commentId] ?? null;
                if (! $comment || $comment['author_id'] !== $actor['user_id'] || ($comment['deleted_at'] ?? null) !== null) {
                    self::fail('access_denied');
                }
                if ($kind === 'task.comment.delete') {
                    $comment['deleted_at'] = $now;
                } else {
                    $comment['body'] = $this->text($command['body'] ?? '', 100000, true);
                    $comment['edited_at'] = $now;
                    $comment['versions'][] = ['body' => $comment['body'], 'at' => $now];
                }
                $task['comments'][$commentId] = $comment;
            } elseif ($kind === 'task.subtask_order') {
                $ids = $command['child_ids'] ?? [];
                if (! is_array($ids) || count($ids) > 100 || count(array_unique($ids)) !== count($ids)) {
                    self::fail('invalid_subtask_order');
                }
                $visible = [];
                foreach ($state['tasks'] as $child) {
                    if ($child['parent_id'] === $id && $this->taskAccess($state, $child, $actor)) {
                        $visible[] = $child['id'];
                    }
                }
                $sorted = $ids;
                $expected = $visible;
                sort($sorted);
                sort($expected);
                if ($sorted !== $expected) {
                    self::fail('invalid_subtask_order');
                }
                $remaining = array_values($ids);
                $order = [];
                foreach ($task['child_order'] ?? [] as $childId) {
                    if (in_array($childId, $visible, true)) {
                        $order[] = array_shift($remaining);
                    } elseif (isset($state['tasks'][$childId]) && $state['tasks'][$childId]['parent_id'] === $id && ! $state['tasks'][$childId]['deleted']) {
                        $order[] = $childId;
                    }
                }
                $task['child_order'] = array_merge($order, $remaining);
            } elseif ($kind === 'task.fields') {
                $projectId = $this->text($command['project_id'] ?? '', 120, true);
                $project = $this->project($state, $actor, $projectId, true);
                if (! isset($task['memberships'][$projectId])) {
                    self::fail('invalid_field_project');
                }
                $this->version($project, ['expected_version' => $command['project_version'] ?? null]);
                $values = $command['values'] ?? [];
                if (! is_array($values) || count($values) > 20) {
                    self::fail('invalid_field_value');
                }
                foreach ($values as $fieldId => $value) {
                    $definition = $project['custom_fields'][$fieldId] ?? null;
                    if (! $definition) {
                        self::fail('invalid_field_value');
                    }
                    if ($value !== null) {
                        if ($definition['type'] === 'text') {
                            $this->text($value, 2000);
                        } elseif ($definition['type'] === 'number' && (! is_int($value) && ! is_float($value) || ! is_finite((float) $value))) {
                            self::fail('invalid_field_value');
                        } elseif ($definition['type'] === 'enum' && ! in_array($value, $definition['choices'], true)) {
                            self::fail('invalid_field_value');
                        } elseif ($definition['type'] === 'date') {
                            $this->dates(['start_on' => null, 'start_at' => null, 'due_on' => $value, 'due_at' => null]);
                        }
                    }
                    $task['custom_fields'][$projectId][$fieldId] = $value;
                }
            } elseif ($kind === 'task.attachment') {
                // Invoked by the trusted file service only; absent from HTTP command vocabulary.
                $attachmentId = $this->text($command['attachment_id'] ?? '', 120, true);
                $task['attachment_ids'] ??= [];
                if (! in_array($attachmentId, $task['attachment_ids'], true)) {
                    $task['attachment_ids'][] = $attachmentId;
                }
            } elseif ($kind === 'task.field_link') {
                $jobId = $this->text($command['job_id'] ?? '', 120, true);
                if (! in_array($jobId, $actor['accessible_field_job_ids'] ?? [], true)) {
                    self::fail('access_denied');
                }
                if (($command['remove'] ?? false) === true) {
                    unset($task['field_job_ids'][$jobId]);
                } else {
                    $task['field_job_ids'][$jobId] = true;
                }
            } elseif ($kind === 'task.delete') {
                $task['deleted'] = true;
            } else {
                self::fail('unsupported_command');
            }
            $task['version']++;
            $task['updated_at'] = $now;
            $state['tasks'][$id] = $task;
        }
        // Store only changed field names in activity; removed access must not reveal old text.
        $state['events'][] = ['tenant_id' => $actor['tenant_id'], 'entity_type' => $type, 'entity_id' => $id,
            'actor_id' => $actor['user_id'], 'kind' => $kind, 'fields' => array_keys($command['changes'] ?? []), 'at' => $now];
        if ($kind === 'project.status') {
            $state['events'][array_key_last($state['events'])]['status_version'] = $state['projects'][$id]['version'];
        }
        $state['receipts'][$receiptId] = ['digest' => $digest, 'entity_type' => $type, 'entity_id' => $id];
        $result = $kind === 'task.delete' ? ['id' => $id, 'deleted' => true]
            : ($type === 'task' && ! $this->taskAccess($state, $state['tasks'][$id], $actor)
                ? ['id' => $id, 'access_removed' => true] : $this->read($state, $actor, $type, $id));

        if ($kind === 'project.status') {
            $result['posted_status_id'] = $state['projects'][$id]['current_status']['id'];
        }

        return ['state' => $state, 'result' => $result, 'replayed' => false];
    }

    public function read(array $state, array $actor, string $type, string $id): array
    {
        $this->authorize($actor);
        if ($type === 'project') {
            $project = $this->project($state, $actor, $id);
            // Directory and detail do not disclose roster or grants.
            unset($project['members']);
            if (isset($project['current_status'])) {
                $project['current_status'] = $this->projectStatus($state, $actor, $id, $project['current_status']);
            }
            if (isset($project['templates'])) {
                $templates = [];
                foreach ($project['templates'] as $template) {
                    if ((new TeamWorkBlueprints($this))->visible($state, $actor, $template)) {
                        $templates[] = array_intersect_key($template, array_flip(['id', 'name', 'kind', 'version', 'captured_at'])) + ['task_count' => count($template['nodes'])];
                    }
                }
                $project['templates'] = $templates;
            }

            return $project;
        }
        if ($type !== 'task') {
            self::fail('unsupported_entity');
        }
        $task = $this->task($state, $actor, $id);
        if (isset($task['follow_up_source'])) {
            $source = $task['follow_up_source'];
            if ($this->can($state, $actor, 'task', $source['task_id'])) {
                $original = $state['tasks'][$source['task_id']];
                $task['follow_up'] = ['task_id' => $original['id'], 'title' => $original['title'], 'source_changed' => $original['version'] !== $source['version']];
                if ($source['comment_id'] !== null && isset($original['comments'][$source['comment_id']]) && $original['comments'][$source['comment_id']]['deleted_at'] === null) {
                    $task['follow_up']['comment_id'] = $source['comment_id'];
                }
            }
            unset($task['follow_up_source']);
        }
        $task['dependencies'] = [];
        $task['dependency_details_unavailable'] = false;
        $task['is_blocked'] = false;
        foreach (array_keys($task['blocker_ids'] ?? []) as $blockerId) {
            $blocker = $state['tasks'][$blockerId] ?? null;
            $visible = $this->can($state, $actor, 'task', (string) $blockerId);
            $task['is_blocked'] = $task['is_blocked'] || ! $visible || ! $blocker || $blocker['deleted'] || $blocker['completed_at'] === null;
            if ($visible) {
                $task['dependencies'][] = ['id' => $blockerId, 'title' => $blocker['title'], 'completed' => $blocker['completed_at'] !== null];
            } else {
                $task['dependency_details_unavailable'] = true;
            }
        }
        $task['next_occurrence'] = isset($task['recurrence_successor_id']) && $this->can($state, $actor, 'task', $task['recurrence_successor_id'])
            ? ['id' => $task['recurrence_successor_id'], 'title' => $state['tasks'][$task['recurrence_successor_id']]['title']] : null;
        unset($task['blocker_ids'], $task['recurrence_successor_id'], $task['recurrence_series_id'], $task['template_provenance']);
        $task['memberships'] = array_filter($task['memberships'], fn ($_, $projectId) => isset($state['projects'][$projectId]) && $this->projectAccess($state['projects'][$projectId], $actor), ARRAY_FILTER_USE_BOTH);
        $task['field_job_ids'] = array_values(array_intersect(array_keys($task['field_job_ids']), $actor['accessible_field_job_ids'] ?? []));
        if (isset($task['child_order'])) {
            $task['child_order'] = array_values(array_filter($task['child_order'], fn ($childId) => isset($state['tasks'][$childId]) && $this->taskAccess($state, $state['tasks'][$childId], $actor)));
        }
        if (isset($task['custom_fields'])) {
            $task['custom_fields'] = array_filter($task['custom_fields'], fn ($_, $projectId) => isset($task['memberships'][$projectId]), ARRAY_FILTER_USE_BOTH);
        }
        foreach ($task['comments'] as &$comment) {
            unset($comment['versions']);
            if (($comment['deleted_at'] ?? null) !== null) {
                $comment['body'] = '';
            }
        }
        unset($comment);
        $task['can_manage_sharing'] = $task['creator_id'] === $actor['user_id'] && $task['parent_id'] === null
            && $task['memberships'] === [] && ! in_array($actor['role'], ['viewer'], true);
        if (! $task['can_manage_sharing']) {
            unset($task['collaborators']);
        }
        $task['events'] = array_values(array_filter($state['events'], fn ($event) => $event['tenant_id'] === $actor['tenant_id'] && $event['entity_type'] === 'task' && $event['entity_id'] === $id));

        return $task;
    }

    public function can(array $state, array $actor, string $type, string $id, bool $write = false): bool
    {
        try {
            $this->authorize($actor);
            if ($type === 'project') {
                return isset($state['projects'][$id]) && $this->projectAccess($state['projects'][$id], $actor, $write);
            }

            return isset($state['tasks'][$id]) && $this->taskAccess($state, $state['tasks'][$id], $actor, $write);
        } catch (DomainException) {
            return false;
        }
    }

    public function search(array $state, array $actor, string $query = '', bool $mine = false, bool $includeCompleted = false): array
    {
        $this->authorize($actor);
        $results = [];
        foreach ($state['tasks'] as $task) {
            if (isset($task['enterprise_discussion_project'])) {
                continue; // Backing records for discussion/files are not operational work.
            }
            if (! $this->taskAccess($state, $task, $actor)) {
                continue;
            }
            $visibleTask = $this->read($state, $actor, 'task', $task['id']);
            $searchable = $task['title'].' '.$task['description'];
            foreach ($visibleTask['comments'] as $comment) {
                if (($comment['deleted_at'] ?? null) === null) {
                    $searchable .= ' '.$comment['body'];
                }
            }
            foreach ($visibleTask['custom_fields'] ?? [] as $fields) {
                foreach ($fields as $value) {
                    if (is_scalar($value)) {
                        $searchable .= ' '.(string) $value;
                    }
                }
            }
            if ($this->taskAccess($state, $task, $actor) && (! $mine || $task['assignee_id'] === $actor['user_id'])
                && ($includeCompleted || $task['completed_at'] === null)
                && ($query === '' || stripos($searchable, $query) !== false)) {
                $results[] = $visibleTask;
            }
        }
        usort($results, fn ($a, $b) => [$a['due_on'] ?? $a['due_at'] ?? '9999', $a['id']] <=> [$b['due_on'] ?? $b['due_at'] ?? '9999', $b['id']]);

        return $results;
    }
}
