<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Deterministic, ephemeral answers. No model, tool execution, memory or business writes. */
class ReadOnlyAssistant
{
    public function __construct(private WorkspaceAccess $access, private Briefing $briefing, private Resources $resources,
        private RequestWorkflow $requests, private Operations $operations, private Planning $planning,
        private TeamWorkRepository $work, private WorkGraph $graph) {}

    public function answer(Tenant $tenant, User $user, string $areaId, string $intent, ?string $buildingId = null): array
    {
        abort_unless(config('enterprise.read_only_assistant_enabled'), 404);
        $actor = $this->access->actor($tenant, $user);
        $area = $this->access->area($tenant, $actor, $areaId);
        $base = ['mode' => 'deterministic_records', 'provider_dispatch' => false, 'as_of' => now()->toIso8601String(),
            'sample_data' => (bool) config('enterprise.sample_data'), 'intent' => $intent,
            'scope' => ['tenant_id' => (string) $tenant->id, 'user_id' => (string) $user->id, 'area_id' => $areaId, 'department' => $area->name],
            'notice' => 'Current saved records only. No live campus feed, approved budgets or staffing capacity are connected. Nothing is sent or changed.'];
        $read = fn (array $currentActor) => match ($intent) {
            'overview' => $this->overview($tenant, $user, $areaId),
            'building' => $this->building($tenant, $user, $currentActor, $areaId, $buildingId),
            'costs' => $this->costs($tenant, $user, $currentActor, $areaId),
            default => abort(422),
        };
        $result = $read($actor);
        // Do not retain an answer across a membership, role or department-scope change during retrieval.
        $fresh = $this->access->actor($tenant, $user);
        $this->access->area($tenant, $fresh, $areaId);
        abort_unless(hash_equals(AskEverbranch::digest($actor), AskEverbranch::digest($fresh)), 409,
            'Access changed. Run this read again with your current permissions.');

        // Object grants and source versions can change without changing the tenant role.
        // Repeat the bounded canonical read; never return an old count or calculation after that change.
        $currentResult = $read($fresh);
        $latest = $this->access->actor($tenant, $user);
        $this->access->area($tenant, $latest, $areaId);
        abort_unless(hash_equals(AskEverbranch::digest($actor), AskEverbranch::digest($latest))
            && hash_equals(AskEverbranch::digest($result), AskEverbranch::digest($currentResult)), 409,
            'Access or source records changed. Check current records again.');

        return $base + $currentResult;
    }

    private function overview(Tenant $tenant, User $user, string $areaId): array
    {
        $briefing = $this->briefing->build($tenant, $user, $areaId);
        foreach ($briefing['sources'] as &$source) {
            [$kind, $id] = explode(':', $source['ref'], 2);
            $source['url'] = $this->url($tenant, $areaId, $kind === 'request' ? 'requests' : 'projects', $id);
        }
        unset($source);

        return ['reply' => $briefing['summary']['text'], 'sources' => $briefing['sources'], 'partial' => true,
            'coverage' => $briefing['coverage'], 'comparisons' => [], 'jobs' => [], 'building' => null];
    }

    private function url(Tenant $tenant, string $areaId, string $section, string $id, array $extra = []): string
    {
        $kind = match ($section) {
            'requests' => 'request', 'contractors' => 'cost', 'buildings' => 'building', default => isset($extra['task']) ? 'task' : 'project',
        };

        return route('enterprise.assistant_source', ['tenant' => $tenant->slug, 'area_id' => $areaId, 'kind' => $kind,
            'id' => $extra['task'] ?? $id, 'section' => $section, 'selected' => $id] + $extra);
    }

    private function building(Tenant $tenant, User $user, array $actor, string $areaId, ?string $id): array
    {
        abort_unless($id !== null, 422);
        $building = $this->resources->show($tenant, $user, $id);
        abort_unless($building['area_id'] === $areaId && $building['kind'] === 'location'
            && $building['status'] === 'active' && ($building['data']['level'] ?? null) === 'facility', 404);
        $sources = [['ref' => 'building:'.$id, 'title' => $building['title'], 'url' => $this->url($tenant, $areaId, 'buildings', $id),
            'facts' => ['status' => 'active', 'version' => $building['version']]]];
        $counts = ['linked_requests' => 0, 'open_requests' => 0, 'urgent_open_requests' => 0, 'unknown_priority_open_requests' => 0];
        $jobs = [];
        $projects = [];
        $state = $this->work->state($tenant->id);
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => 'enterprise_request'])
            ->orderByDesc('updated_at')->orderBy('id')->limit(1000)->cursor();
        foreach ($rows as $row) {
            if (($row->data['location_id'] ?? null) !== $id) {
                continue;
            }
            try {
                $request = $this->requests->view($tenant, $actor, $row);
            } catch (HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }

                continue;
            }
            $open = ! in_array($request['status'], ['completed', 'closed', 'cancelled', 'rejected'], true);
            $priority = $request['task']['priority'] ?? null;
            $counts['linked_requests']++;
            $counts['open_requests'] += (int) $open;
            $counts['urgent_open_requests'] += (int) ($open && $priority === 'urgent');
            $counts['unknown_priority_open_requests'] += (int) ($open && ! in_array($priority, ['urgent', 'high', 'normal', 'low'], true));
            $sources[] = ['ref' => 'request:'.$row->id, 'title' => $request['title'],
                'url' => $this->url($tenant, $areaId, 'requests', $row->id),
                'facts' => ['status' => $request['status'], 'priority' => $priority, 'due_on' => $request['due_on'], 'version' => $request['version']]];
            if (config('enterprise.operations_enabled') && isset($request['data']['task_id'])) {
                try {
                    $task = $this->operations->show($tenant, $user, $request['data']['task_id']);
                    if ($task['area_id'] === $areaId) {
                        $projectIds = array_values(array_filter(array_keys($state['tasks'][$task['id']]['memberships'] ?? []),
                            fn ($projectId) => isset($state['projects'][$projectId])
                                && ($state['projects'][$projectId]['enterprise_area_id'] ?? null) === $areaId
                                && ! $state['projects'][$projectId]['archived']
                                && $this->graph->can($state, $actor, 'project', $projectId)));
                        $explicit = $request['data']['project_id'] ?? null;
                        // A stale explicit association never falls back to a different project.
                        $projectId = array_key_exists('project_id', $request['data'])
                            ? (is_string($explicit) && in_array($explicit, $projectIds, true) ? $explicit : null)
                            : (count($projectIds) === 1 ? $projectIds[0] : null);
                        $jobs[$task['id']] ??= array_intersect_key($task, array_flip(['id', 'title', 'status', 'priority', 'next_action', 'due_on', 'updated_at', 'task_version', 'version']));
                        $jobs[$task['id']]['url'] = $this->url($tenant, $areaId, 'projects', $task['project_id'], ['task' => $task['id']]);
                        $jobs[$task['id']]['request_refs'][] = 'request:'.$row->id;
                        $jobs[$task['id']]['project_refs'] ??= [];
                        if ($projectId !== null) {
                            $projects[$projectId] = $state['projects'][$projectId];
                            $jobs[$task['id']]['project_refs'][] = 'project:'.$projectId;
                        }
                        $jobs[$task['id']]['owner'] = $this->assignment($tenant, $actor, $areaId, $task['owner_user_id'], $task['id'], $state);
                        $jobs[$task['id']]['handler'] = $this->assignment($tenant, $actor, $areaId, $task['ball_user_id'], $task['id'], $state);
                        $jobs[$task['id']]['team_queue'] = $task['ball_team'];
                    }
                } catch (HttpExceptionInterface $error) {
                    if (! in_array($error->getStatusCode(), [403, 404], true)) {
                        throw $error;
                    }
                }
            }
            if ($counts['linked_requests'] === 100) {
                break;
            }
        }
        foreach ($jobs as &$job) {
            $job['request_refs'] = array_values(array_unique($job['request_refs']));
            $job['project_refs'] = array_values(array_unique($job['project_refs']));
            $sources[] = ['ref' => 'task:'.$job['id'], 'title' => $job['title'], 'url' => $job['url'],
                'facts' => array_diff_key($job, array_flip(['id', 'title', 'url']))];
        }
        unset($job);
        $contractors = $this->scheduledContractors($tenant, $actor, $areaId, $projects);
        $sources = [...$sources, ...$contractors['sources']];
        $costs = config('enterprise.planning_enabled') && Planning::financial($actor)
            ? $this->costs($tenant, $user, $actor, $areaId, array_keys($projects)) : ['sources' => [], 'comparisons' => []];
        $sources = [...$sources, ...$costs['sources']];
        $rank = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
        usort($jobs, fn ($a, $b) => [$rank[$a['priority']] ?? 4, $a['due_on'] ?? '9999', $a['id']]
            <=> [$rank[$b['priority']] ?? 4, $b['due_on'] ?? '9999', $b['id']]);
        // Re-check the selected building after reading linked work, including archive/area changes.
        $fresh = $this->resources->show($tenant, $user, $id);
        abort_unless($fresh['area_id'] === $areaId && $fresh['status'] === 'active' && $fresh['version'] === $building['version'], 409,
            'This building changed. Read its current records again.');

        return ['reply' => $counts['open_requests'].' open requests; '.$counts['urgent_open_requests'].' recorded urgent open requests in the checked linked sample.'
            .($counts['unknown_priority_open_requests'] ? ' Priority is not recorded for '.$counts['unknown_priority_open_requests'].' open requests.' : ''),
            'building' => ['id' => $id, 'title' => $building['title'], 'counts' => $counts], 'jobs' => array_values($jobs),
            'sources' => $sources, 'comparisons' => $costs['comparisons'], 'contractors' => $contractors['items'], 'partial' => true,
            'coverage' => 'Up to 100 currently authorized requests with this saved building ID from a 1000-request scan. Text-only location labels and the public campus catalog are excluded. Counts are checked-sample counts, not building totals. Jobs have no inferred staffing capacity or cost allocation. Only explicit request/project membership or a single authorized project joins planned contractors and project-level costs; ambiguous associations are omitted. Planned contractor dates do not establish physical presence. Costs are project-level, never a building allocation or approved budget. Contractor schedules are bounded to 100 valid records from a 1000-record scan.'];
    }

    /** Names only for current verified workspace participants already named by authorized Operations. */
    private function assignment(Tenant $tenant, array $actor, string $area, ?string $id, string $taskId, array $state): ?array
    {
        if ($id === null || ! in_array($id, $actor['verified_user_ids'], true)
            || ! WorkspaceAccess::permits(array_replace($actor, ['user_id' => $id, 'role' => $actor['active_roles'][$id] ?? 'guest']), $area, true)
            || ! $this->graph->can($state, array_replace($actor, ['user_id' => $id, 'role' => $actor['active_roles'][$id] ?? 'guest']), 'task', $taskId)) {
            return null;
        }
        $name = $tenant->users()->wherePivot('membership_active', true)->where('users.id', $id)
            ->where('users.is_active', true)->whereNotNull('users.email_verified_at')->value('users.name');

        return $name !== null ? ['id' => $id, 'name' => $name] : null;
    }

    private function scheduledContractors(Tenant $tenant, array $actor, string $area, array $projects): array
    {
        $sources = $items = [];
        if (! config('enterprise.planning_enabled') || $projects === []) {
            return compact('sources', 'items');
        }
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => Planning::KINDS['schedule']])
            ->orderByDesc('updated_at')->orderBy('id')->limit(1000)->cursor();
        foreach ($rows as $row) {
            $data = $row->data ?? [];
            if (! is_string($data['project_id'] ?? null) || ! isset($projects[$data['project_id']])
                || ! is_string($data['contractor_id'] ?? null) || $row->status !== 'planned' || $row->starts_on === null || $row->ends_on === null
                || $row->starts_on->gt($row->ends_on)) {
                continue;
            }
            try {
                $schedule = $this->planning->view($tenant, $actor, $row);
                $contractor = $this->planning->view($tenant, $actor, $this->planning->record($tenant, $actor, 'contractor', $data['contractor_id']));
                if ($schedule['area_id'] !== $area || $contractor['area_id'] !== $area || $contractor['status'] !== 'active') {
                    continue;
                }
            } catch (HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }

                continue;
            }
            $facts = ['project_ref' => 'project:'.$data['project_id'], 'project' => $projects[$data['project_id']]['name'],
                'contractor_ref' => 'contractor:'.$contractor['id'], 'contractor' => $contractor['title'],
                'starts_on' => $schedule['starts_on'], 'ends_on' => $schedule['ends_on'], 'status' => $schedule['status'],
                'version' => $schedule['version'], 'contractor_version' => $contractor['version'],
                'basis' => 'Recorded project-level plan; not a task assignment or verified on-site presence.'];
            $url = fn ($kind, $id) => route('enterprise.assistant_source', ['tenant' => $tenant->slug, 'area_id' => $area, 'kind' => $kind, 'id' => $id]);
            $items[] = ['ref' => 'schedule:'.$schedule['id']] + $facts;
            $sources['schedule:'.$schedule['id']] = ['ref' => 'schedule:'.$schedule['id'], 'title' => $schedule['title'],
                'url' => $url('schedule', $schedule['id']), 'facts' => $facts];
            $sources['contractor:'.$contractor['id']] = ['ref' => 'contractor:'.$contractor['id'], 'title' => $contractor['title'],
                'url' => $url('contractor', $contractor['id']), 'facts' => ['status' => $contractor['status'], 'version' => $contractor['version']]];
            if (count($items) === 100) {
                break;
            }
        }

        return ['sources' => array_values($sources), 'items' => $items];
    }

    private function costs(Tenant $tenant, User $user, array $actor, string $areaId, ?array $projectIds = null): array
    {
        abort_unless(config('enterprise.planning_enabled') && Planning::financial($actor), 404);
        $state = $this->work->state($tenant->id);
        $groups = [];
        $sources = [];
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId,
            'kind' => Planning::KINDS['cost'], 'status' => 'recorded'])->orderByDesc('updated_at')->orderBy('id')->limit(1000)->cursor();
        foreach ($rows as $row) {
            $data = $row->data ?? [];
            // Validate legacy payload shape before a canonical planning read dereferences its project.
            if (! is_string($data['project_id'] ?? null) || ! is_int($data['amount_minor'] ?? null)
                || $data['amount_minor'] < 0 || $data['amount_minor'] > 1000000000
                || ! is_string($data['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $data['currency'])
                || ! in_array($data['basis'] ?? null, ['estimate', 'recorded_cost'], true)) {
                continue;
            }
            if ($projectIds !== null && ! in_array($data['project_id'], $projectIds, true)) {
                continue;
            }
            try {
                $cost = $this->planning->view($tenant, $actor, $row);
            } catch (HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }

                continue;
            }
            $data = $cost['data'];
            $projectId = $data['project_id'] ?? '';
            // Malformed legacy values cannot silently change arithmetic or become facts.
            if (! is_int($data['amount_minor'] ?? null) || $data['amount_minor'] < 0 || $data['amount_minor'] > 1000000000
                || ! is_string($data['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $data['currency'])
                || ! in_array($data['basis'] ?? null, ['estimate', 'recorded_cost'], true)
                || ! $this->graph->can($state, $actor, 'project', $projectId)) {
                continue;
            }
            $project = $this->graph->read($state, $actor, 'project', $projectId);
            $key = $projectId.':'.$data['currency'];
            $groups[$key] ??= ['project_id' => $projectId, 'scope' => 'project_level', 'project_title' => $project['name'], 'currency' => $data['currency'],
                'project_url' => $this->url($tenant, $areaId, 'projects', $projectId),
                'estimate_minor' => 0, 'recorded_cost_minor' => 0, 'estimate_refs' => [], 'recorded_cost_refs' => []];
            $basis = $data['basis'];
            $ref = 'cost:'.$row->id;
            $groups[$key][$basis.'_minor'] += $data['amount_minor'];
            $groups[$key][$basis.'_refs'][] = $ref;
            $sources[] = ['ref' => $ref, 'title' => $cost['title'], 'url' => $this->url($tenant, $areaId, 'contractors', $row->id),
                'facts' => ['project_id' => $projectId, 'project' => $project['name'], 'scope' => 'project_level', 'amount_minor' => $data['amount_minor'], 'currency' => $data['currency'],
                    'basis' => $basis, 'version' => $cost['version'], 'updated_at' => $cost['updated_at']]];
            if (count($sources) === 200) {
                break;
            }
        }
        foreach ($groups as &$group) {
            // Missing estimates are distinct from an explicitly recorded zero estimate.
            $group['difference_minor'] = $group['estimate_refs'] !== [] && $group['recorded_cost_refs'] !== []
                ? $group['recorded_cost_minor'] - $group['estimate_minor'] : null;
            $group['comparison_label'] = $group['difference_minor'] === null ? 'Comparison unavailable: one basis is missing.'
                : 'Checked recorded-cost subtotal minus checked estimate subtotal; not an approved-budget variance.';
        }
        unset($group);
        ksort($groups);

        return ['reply' => 'Compare checked recorded costs with checked estimates by project and currency. These records do not establish which jobs are over an approved budget.',
            'sources' => $sources, 'comparisons' => array_values($groups), 'jobs' => [], 'building' => null, 'partial' => true,
            'coverage' => 'Up to 200 authorized valid recorded cost/estimate records from a 1000-record scan. Each record is counted once. Subtotals cover only the linked inputs shown; missing, excluded or newer records can change the comparison. No currency conversion, accounting reconciliation, approved budget or staffing cost is inferred. Amounts use the saved currency minor units.'];
    }
}
