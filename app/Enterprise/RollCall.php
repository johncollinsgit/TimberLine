<?php

namespace App\Enterprise;

use App\Models\TeamArea;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Carbon\CarbonImmutable;

/** Deterministic priorities over current readable records; never a global campus data grant. */
class RollCall
{
    public function __construct(private WorkspaceAccess $access, private TeamWorkRepository $work, private WorkGraph $graph) {}

    public function context(Tenant $tenant, User $user, array $input): array
    {
        abort_unless(config('enterprise.roll_call_enabled'), 404);
        $actor = $this->access->actor($tenant, $user);
        $type = $input['type'] ?? $input['scope'] ?? 'workspace';
        abort_unless(is_string($type) && in_array($type, ['campus', 'workspace', 'project', 'team'], true), 422);
        $areas = TeamArea::where('tenant_id', $tenant->id)->whereIn('profile', WorkspaceAccess::PROFILES)->orderBy('name')->get()
            ->filter(fn ($a) => WorkspaceAccess::permits($actor, $a->id));
        if ($type !== 'campus') {
            $area = $this->access->area($tenant, $actor, (string) ($input['area_id'] ?? ''));
            $areas = $areas->where('id', $area->id);
        }
        $areas = $areas->take(100);
        $state = $this->work->state($tenant->id);
        $projectId = $type === 'project' ? (string) ($input['project_id'] ?? '') : null;
        if ($projectId !== null) {
            abort_unless($this->graph->can($state, $actor, 'project', $projectId)
                && $areas->contains('id', $state['projects'][$projectId]['enterprise_area_id'] ?? null), 404);
        }
        $person = null;
        if ($type === 'team') {
            $id = (string) ($input['team_user_id'] ?? '');
            abort_unless(in_array($id, $actor['verified_user_ids'], true)
                && in_array($actor['active_roles'][$id] ?? '', ['owner', 'admin', 'member'], true), 404);
            $recipient = array_replace($actor, ['user_id' => $id, 'role' => $actor['active_roles'][$id]]);
            abort_unless($areas->contains(fn ($a) => WorkspaceAccess::permits($recipient, $a->id, true)), 404);
            $person = ['id' => $id, 'name' => User::findOrFail($id)->name];
        }

        return compact('actor', 'areas', 'state', 'type', 'projectId', 'person');
    }

    public function build(Tenant $tenant, User $user, array $input): array
    {
        $context = $this->context($tenant, $user, $input);
        extract($context);
        $items = [];
        $people = $tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->whereNotNull('email_verified_at')->get()->keyBy(fn ($u) => (string) $u->id);
        $owner = fn ($id) => $id !== null && isset($people[(string) $id]) ? ['id' => (string) $id, 'name' => $people[(string) $id]->name] : null;
        $projects = [];
        foreach ($state['projects'] as $id => $project) {
            if ($areas->contains('id', $project['enterprise_area_id'] ?? null) && ($projectId === null || $id === $projectId)
                && $this->graph->can($state, $actor, 'project', $id)) {
                $projects[$id] = $project;
            }
        }
        $seen = [];
        $today = now('America/New_York')->toDateString();
        foreach ($areas as $area) {
            $recordOwner = function ($id) use ($owner, $actor, $area): ?array {
                $recipient = array_replace($actor, ['user_id' => (string) $id, 'role' => $actor['active_roles'][(string) $id] ?? 'guest']);

                return WorkspaceAccess::permits($recipient, $area->id) ? $owner($id) : null;
            };
            $operations = config('enterprise.operations_enabled') ? app(Operations::class)->listing($tenant, $user, $area->id, $projectId) : null;
            $rows = $operations['items'] ?? [];
            if ($operations === null) {
                foreach ($state['tasks'] as $id => $task) {
                    $memberships = array_intersect_key($projects, $task['memberships']);
                    if ($memberships === [] || isset($task['enterprise_discussion_project']) || ! $this->graph->can($state, $actor, 'task', $id)
                        || ($task['enterprise_area_id'] ?? null) !== $area->id) {
                        continue;
                    }
                    $rows[] = ['id' => $id, 'task_id' => $id, 'project_id' => array_key_first($memberships), 'area_id' => $area->id,
                        'title' => $task['title'], 'status' => $task['completed_at'] ? 'completed' : 'active', 'version' => 0, 'task_version' => $task['version'],
                        'owner_user_id' => $task['assignee_id'], 'ball_user_id' => $task['assignee_id'], 'ball_team' => null,
                        'next_action' => 'Review the task and record the next action.', 'due_on' => $task['due_on'], 'blockers' => []];
                }
            }
            foreach ($rows as $row) {
                $id = $row['task_id'];
                $task = $state['tasks'][$id] ?? null;
                if (! $task || isset($seen[$id]) || ! isset($projects[$row['project_id']]) || ! $this->graph->can($state, $actor, 'task', $id)
                    || ($person && ! in_array($person['id'], [(string) ($row['owner_user_id'] ?? ''), (string) ($row['ball_user_id'] ?? '')], true))) {
                    continue;
                }
                $seen[$id] = true;
                if (in_array($row['status'], ['completed', 'closed'], true)) {
                    continue;
                }
                $overdue = ($row['due_on'] && $row['due_on'] < $today)
                    || ($task['due_at'] && CarbonImmutable::parse($task['due_at'])->isBefore(now()));
                $priorities = [];
                if ($task['priority'] === 'urgent') {
                    $priorities[10] = 'Emergency priority is recorded.';
                }
                if ($row['status'] === 'interrupted') {
                    $priorities[20] = 'Interrupted work still needs an explicit resumption decision.';
                }
                if ($overdue) {
                    $priorities[30] = 'The recorded due date has passed.';
                }
                if ($row['blockers'] !== []) {
                    $priorities[50] = 'An operational blocker needs resolution.';
                }
                if ($row['status'] === 'awaiting_verification') {
                    $priorities[55] = 'Work is awaiting manager verification.';
                }
                if ($priorities === []) {
                    $priorities[90] = 'Current assigned work to review.';
                }
                ksort($priorities);
                $items[] = ['ref' => 'task:'.$id, 'kind' => array_key_first($priorities) === 10 ? 'emergency' : ($row['status'] === 'interrupted' ? 'interrupted' : ($overdue ? 'overdue' : 'task')),
                    'title' => mb_substr($row['title'], 0, 160), 'reason' => implode(' ', $priorities), 'owner' => $recordOwner($row['owner_user_id']),
                    'ball' => $recordOwner($row['ball_user_id']) ?? ($row['ball_team'] ? ['id' => null, 'name' => $area->name.' team'] : null),
                    'next_action' => mb_substr($row['next_action'] ?: 'Record the next action.', 0, 300), 'due_on' => $row['due_on'],
                    'source_url' => $this->url($tenant, ['area_id' => $area->id, 'section' => 'projects', 'selected' => $row['project_id'], 'task' => $id]),
                    'source_updated_at' => $row['updated_at'] ?? $task['updated_at'], 'version' => $row['task_version'], 'operations_version' => $row['version'], 'rank' => array_key_first($priorities)];
            }
            foreach ($operations['pending_handoffs'] ?? [] as $handoff) {
                if (($projectId !== null && $handoff['target_project_id'] !== $projectId)
                    || ($person && (string) $handoff['assignee_id'] !== $person['id'])) {
                    continue;
                }
                $items[] = ['ref' => 'handoff:'.$handoff['id'], 'kind' => 'handoff', 'title' => mb_substr($handoff['title'], 0, 160),
                    'reason' => 'A linked departmental handoff is waiting for acceptance.', 'owner' => $recordOwner($handoff['assignee_id']), 'ball' => $recordOwner($handoff['assignee_id']),
                    'next_action' => mb_substr($handoff['next_action'] ?: 'Accept or decline the pending handoff.', 0, 300), 'due_on' => null,
                    'source_url' => $this->url($tenant, ['area_id' => $area->id, 'section' => 'roll_call', 'handoff' => $handoff['id']]),
                    'source_updated_at' => $handoff['updated_at'] ?? null, 'version' => $handoff['version'], 'rank' => 40];
            }
            if (config('enterprise.planning_enabled')) {
                foreach (app(Planning::class)->listing($tenant, $user, $area->id)['items'] as $row) {
                    if ($row['kind'] !== 'schedule' || $row['status'] !== 'planned' || ! isset($projects[$row['data']['project_id']])
                        || ($person && (string) $projects[$row['data']['project_id']]['owner_id'] !== $person['id'])) {
                        continue;
                    }
                    $manager = $recordOwner($projects[$row['data']['project_id']]['owner_id']);
                    $items[] = ['ref' => 'schedule:'.$row['id'], 'kind' => 'contractor_dependency', 'title' => $row['title'],
                        'reason' => 'A planned contractor date block depends on confirmation. Recorded dates are not live contractor availability.',
                        'owner' => $manager, 'ball' => $manager, 'next_action' => 'Confirm contractor readiness and resolve dependencies with the project owner.',
                        'due_on' => $row['starts_on'], 'source_url' => $this->url($tenant, ['area_id' => $area->id, 'section' => 'contractors', 'selected' => $row['id']]),
                        'source_updated_at' => $row['updated_at'], 'version' => $row['version'], 'rank' => 60];
                }
            }
            if (config('enterprise.resources_enabled')) {
                $items = [...$items, ...$this->resourceItems($tenant, $user, $area->id, $projectId, $person, $recordOwner)];
            }
        }
        $unique = [];
        foreach ($items as $item) {
            $unique[$item['ref']] = $item;
        }
        $items = array_values($unique);
        usort($items, fn ($a, $b) => [$a['rank'], $a['due_on'] ?? '9999', $a['title'], $a['ref']] <=> [$b['rank'], $b['due_on'] ?? '9999', $b['title'], $b['ref']]);
        $scope = ['type' => $type, 'area_id' => $type === 'campus' ? null : $areas->first()?->id, 'project_id' => $projectId,
            'team_user_id' => $person['id'] ?? null, 'label' => match ($type) {
                'campus' => 'Campus - currently readable workspaces', 'project' => $projects[$projectId]['name'],
                'team' => 'Assigned person: '.$person['name'], default => $areas->first()?->name ?? 'Workspace',
            }];
        $sample = (bool) config('enterprise.sample_data');

        return ['scope' => $scope, 'as_of' => now()->toIso8601String(), 'method' => 'current_record_rules',
            'method_label' => 'Current record-derived ordering; no AI or external feed was called.', 'sample_data' => $sample,
            'label' => $sample ? 'SAMPLE DATA - illustrative campus operations' : 'Current authorized records',
            'items' => array_slice($items, 0, 200), 'total_visible' => count($items), 'overflow_count' => max(0, count($items) - 200),
            'can_lead' => $areas->contains(fn ($a) => WorkspaceAccess::permits($actor, $a->id, true, true)),
            'complete_url' => $this->url($tenant, ['section' => 'roll_call', 'scope' => $type,
                ...array_filter($scope, fn ($value, $key) => ! in_array($key, ['type', 'label'], true) && $value !== null, ARRAY_FILTER_USE_BOTH)]),
            'coverage' => 'Up to 100 readable workspaces and 200 displayed priorities from bounded authorized operational, contractor and resource projections. Team scope currently means an assigned active person, not a permission group. No private ratings, internal notes, student details, security case bodies, mailbox content or live class/tour/calendar feeds are searched. Missing owners, dates or freshness remain unknown; this is not complete campus coverage.'];
    }

    private function resourceItems(Tenant $tenant, User $user, string $areaId, ?string $projectId, ?array $person, callable $owner): array
    {
        // Resource owner supplies an ACL-filtered projection; raw encrypted resource records are never read here.
        $items = [];
        foreach (app(Resources::class)->attention($tenant, $user, $areaId)['items'] as $row) {
            if (($row['kind'] ?? '') === 'reservation' && in_array($row['status'] ?? '', ['scheduled', 'conflicted'], true) && $projectId === null && ! $person
                && CarbonImmutable::parse($row['data']['ends_at'])->isAfter(now())) {
                $category = $row['data']['category'] ?? 'other';
                $items[] = ['ref' => 'reservation:'.$row['id'], 'kind' => in_array($category, ['maintenance', 'closure'], true) ? 'maintenance_window' : 'scheduled_activity',
                    'title' => mb_substr($row['title'], 0, 160), 'reason' => 'A recorded '.$category.' reservation affects resource availability. This is a manually recorded activity, not a live schedule feed.',
                    'owner' => null, 'ball' => null, 'next_action' => 'Confirm the responsible department and review resource availability before this activity.',
                    'due_on' => $row['data']['starts_at'], 'source_url' => $row['url'], 'source_updated_at' => $row['updated_at'] ?? null,
                    'version' => $row['version'], 'rank' => 80];
            }
            if (($row['kind'] ?? '') === 'asset' && ($row['status'] ?? '') === 'checked_out' && $projectId === null
                && ($row['data']['expected_return_at'] ?? null) && CarbonImmutable::parse($row['data']['expected_return_at'])->isBefore(now())
                && (! $person || (string) ($row['data']['holder_id'] ?? '') === $person['id'])) {
                $holder = $owner($row['data']['holder_id'] ?? null);
                $items[] = ['ref' => 'asset:'.$row['id'], 'kind' => 'asset_return', 'title' => mb_substr($row['title'], 0, 160),
                    'reason' => 'The recorded asset return time has passed; the current holder or last location may need confirmation.',
                    'owner' => $holder, 'ball' => $holder, 'next_action' => 'Confirm custody and record return or a reasoned extension.',
                    'due_on' => $row['data']['expected_return_at'], 'source_url' => $row['url'], 'source_updated_at' => $row['updated_at'] ?? null,
                    'version' => $row['version'], 'rank' => 66];
            }
            if (($row['kind'] ?? '') !== 'conflict' || ($row['status'] ?? '') !== 'open' || $person
                || ($projectId !== null && ($row['data']['project_id'] ?? null) !== $projectId)) {
                continue;
            }
            $items[] = ['ref' => 'resource_conflict:'.$row['id'], 'kind' => 'schedule_conflict', 'title' => mb_substr($row['title'], 0, 160),
                'reason' => 'An authorized resource conflict needs manager resolution: '.mb_substr($row['data']['reason'] ?? 'Review the recorded reservation overlap.', 0, 200),
                'owner' => $owner($row['data']['owner_user_id'] ?? null), 'ball' => null,
                'next_action' => 'Review both authorized source reservations; agree a resolution without silently rescheduling another department.',
                'due_on' => $row['data']['starts_at'] ?? null, 'source_url' => $row['url'], 'source_updated_at' => $row['updated_at'] ?? null,
                'version' => $row['version'], 'rank' => 65];
        }

        return $items;
    }

    public function url(Tenant $tenant, array $query): string
    {
        return rtrim((string) config('app.url'), '/').route('enterprise.index', ['tenant' => $tenant->slug, ...$query], false);
    }
}
