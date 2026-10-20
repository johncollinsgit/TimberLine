<?php

namespace App\Services\Team;

use DomainException;

/** Project-scoped, reviewed snapshots. Creation delegates every record to the shared graph. */
class TeamWorkBlueprints
{
    public function __construct(private WorkGraph $graph) {}

    public function capture(array $state, array $actor, array $project, array $command, string $now): array
    {
        $kind = $command['template_type'] ?? '';
        $name = $command['name'] ?? '';
        $templateId = $command['template_id'] ?? '';
        if (! in_array($kind, ['task', 'project'], true) || ! is_string($name) || trim($name) === '' || strlen($name) > 100
            || ! is_string($templateId) || $templateId === '') {
            throw new DomainException('invalid_template');
        }
        if (! isset($project['templates'][$templateId]) && count($project['templates'] ?? []) >= 20) {
            throw new DomainException('template_limit');
        }
        $sections = [];
        foreach ($project['sections'] as $id => $section) {
            $sections[$id] = ['key' => 's'.count($sections), 'name' => $section, 'source_section_id' => (string) $id];
        }
        $roots = array_values(array_filter($state['tasks'], fn ($task) => ! $task['deleted'] && $task['parent_id'] === null && isset($task['memberships'][$project['id']])
            && ($kind === 'project' || $task['id'] === ($command['source_task_id'] ?? null))));
        usort($roots, fn ($a, $b) => [$a['memberships'][$project['id']]['position'], $a['id']] <=> [$b['memberships'][$project['id']]['position'], $b['id']]);
        if ($kind === 'task' && count($roots) !== 1) {
            throw new DomainException('template_requires_project_root');
        }
        $nodes = [];
        $visit = function (array $task, ?string $parent, int $depth = 0) use (&$visit, &$nodes, $state, $actor, $project, $sections): void {
            if ($depth > 5 || count($nodes) >= 50 || ! $this->graph->can($state, $actor, 'task', $task['id'])
                || ($task['memberships'] !== [] && ! isset($task['memberships'][$project['id']]))) {
                throw new DomainException('template_restricted_or_too_large');
            }
            $key = 'n'.count($nodes);
            $section = $task['memberships'][$project['id']]['section_id'] ?? null;
            $nodes[] = ['key' => $key, 'parent' => $parent, 'source_task_id' => $task['id'], 'title' => $task['title'],
                'description' => $task['description'], 'priority' => $task['priority'], 'section' => $sections[$section]['key'] ?? null,
                'in_project' => isset($task['memberships'][$project['id']])];
            $children = array_values(array_filter($state['tasks'], fn ($child) => ! $child['deleted'] && $child['parent_id'] === $task['id']));
            $order = array_flip($task['child_order'] ?? []);
            usort($children, fn ($a, $b) => [$order[$a['id']] ?? PHP_INT_MAX, $a['id']] <=> [$order[$b['id']] ?? PHP_INT_MAX, $b['id']]);
            foreach ($children as $child) {
                $visit($child, $key, $depth + 1);
            }
        };
        foreach ($roots as $task) {
            $visit($task, null);
        }
        $template = ['id' => $templateId, 'kind' => $kind, 'name' => $name, 'version' => ($project['templates'][$templateId]['version'] ?? 0) + 1,
            'created_by' => $actor['user_id'], 'captured_at' => $now, 'description' => $kind === 'project' ? $project['description'] : '',
            'sections' => array_values($sections), 'nodes' => $nodes];
        if (strlen(json_encode($template, JSON_THROW_ON_ERROR)) > 500000 || count($sections) > 20) {
            throw new DomainException('template_restricted_or_too_large');
        }

        return $template;
    }

    public function visible(array $state, array $actor, array $template): bool
    {
        foreach ($template['nodes'] as $node) {
            if (! $this->graph->can($state, $actor, 'task', $node['source_task_id'])) {
                return false;
            }
        }

        return true;
    }

    public function instantiate(array $state, array $actor, array $command, string $now): array
    {
        $sourceId = $command['project_id'] ?? '';
        if (! in_array($actor['role'], ['owner', 'admin', 'member'], true) || ! $this->graph->can($state, $actor, 'project', $sourceId, true)) {
            throw new DomainException('access_denied');
        }
        $project = $state['projects'][$sourceId];
        if ($project['archived']) {
            throw new DomainException('project_archived');
        }
        $template = $project['templates'][$command['template_id'] ?? ''] ?? null;
        if (! $template || ! $this->visible($state, $actor, $template)) {
            throw new DomainException('access_denied');
        }
        if (($command['project_version'] ?? null) !== $project['version'] || ($command['template_version'] ?? null) !== $template['version']) {
            throw new DomainException('conflict');
        }
        if (($command['acknowledge_sharing'] ?? false) !== true) {
            throw new DomainException('sharing_review_required');
        }
        $projectCopy = $command['kind'] === 'project.from_template';
        if ($template['kind'] !== ($projectCopy ? 'project' : 'task') || (! $projectCopy && ! $this->graph->can($state, $actor, 'project', $sourceId, true))) {
            throw new DomainException('access_denied');
        }
        $step = 0;
        $execute = function (array $input) use (&$state, &$step, $actor, $command, $now): void {
            $input['idempotency_key'] = 'tpl:'.$command['idempotency_key'].':'.$step++;
            $state = $this->graph->execute($state, $actor, $input, $now)['state'];
        };
        $destination = $projectCopy ? $command['id'] : $sourceId;
        $sectionMap = [];
        if ($projectCopy) {
            $execute(['kind' => 'project.create', 'id' => $destination, 'name' => $command['name'] ?? '', 'description' => $template['description'], 'privacy' => 'private']);
            foreach ($template['sections'] as $section) {
                $sectionId = TeamRecurrence::stableId($destination.':section:'.$section['key']);
                $sectionMap[$section['key']] = $sectionId;
                $execute(['kind' => 'project.section', 'id' => $destination, 'expected_version' => $state['projects'][$destination]['version'], 'section_id' => $sectionId, 'name' => $section['name']]);
            }
        } else {
            // Names can repeat. Resolve the original identity rather than guessing by section label.
            foreach ($template['sections'] as $section) {
                if (isset($project['sections'][$section['source_section_id']])) {
                    $sectionMap[$section['key']] = $section['source_section_id'];
                }
            }
        }
        $ids = [];
        $children = [];
        foreach ($template['nodes'] as $index => $node) {
            $id = ! $projectCopy && $index === 0 ? $command['id'] : TeamRecurrence::stableId($command['id'].':template-node:'.$node['key']);
            $ids[$node['key']] = $id;
            $parent = $node['parent'] !== null ? $ids[$node['parent']] : null;
            if ($node['section'] !== null && ! isset($sectionMap[$node['section']])) {
                throw new DomainException('template_section_review_required');
            }
            if ($parent !== null) {
                $children[$parent][] = $id;
            }
            $execute(['kind' => 'task.create', 'id' => $id, 'title' => $node['title'], 'description' => $node['description'], 'priority' => $node['priority'],
                'parent_id' => $parent, 'memberships' => $parent === null || $node['in_project'] ? [$destination => ['section_id' => $sectionMap[$node['section']] ?? null, 'position' => $index]] : []]);
            $state['tasks'][$id]['template_provenance'] = ['source_project_id' => $sourceId, 'template_id' => $template['id'], 'template_version' => $template['version']];
        }
        foreach ($children as $parentId => $childIds) {
            $execute(['kind' => 'task.subtask_order', 'id' => $parentId, 'expected_version' => $state['tasks'][$parentId]['version'], 'child_ids' => $childIds]);
        }

        return $state;
    }

    public function preview(array $state, array $actor, string $projectId, string $templateId): array
    {
        $template = $state['projects'][$projectId]['templates'][$templateId] ?? null;
        if (! $this->graph->can($state, $actor, 'project', $projectId) || ! $template || ! $this->visible($state, $actor, $template)) {
            throw new DomainException('access_denied');
        }
        $tasks = array_map(fn ($node) => array_intersect_key($node, array_flip(['key', 'parent', 'title', 'description', 'priority', 'section', 'in_project'])), $template['nodes']);
        $sections = array_map(fn ($section) => array_intersect_key($section, array_flip(['key', 'name'])), $template['sections']);

        return array_intersect_key($template, array_flip(['id', 'name', 'kind', 'version', 'captured_at', 'description'])) + ['tasks' => $tasks, 'sections' => $sections, 'project_version' => $state['projects'][$projectId]['version'],
            'copy_rules' => 'Titles, descriptions, priority, sections and inherited subtasks only. No dates, assignees, grants, files, conversations, fields, recurrence, dependencies or original history. Project copies are private.'];
    }
}
