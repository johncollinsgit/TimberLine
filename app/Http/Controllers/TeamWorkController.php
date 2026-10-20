<?php

namespace App\Http\Controllers;

use App\Models\TeamProject;
use App\Models\TeamTask;
use App\Models\Tenant;
use App\Services\Team\TeamTaskFeed;
use App\Services\Team\TeamWorkAccess;
use App\Services\Team\TeamWorkBlueprints;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class TeamWorkController extends Controller
{
    public function __construct(private TeamWorkRepository $repository, private TeamWorkAccess $access, private WorkGraph $graph) {}

    private function scope(Request $request): array
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return [$tenant, $this->access->context($request->user(), $tenant)];
    }

    public function index(Request $request)
    {
        return $this->workspace($request, false);
    }

    public function app(Request $request)
    {
        return $this->workspace($request, true);
    }

    private function workspace(Request $request, bool $standalone)
    {
        [$tenant, $actor] = $this->scope($request);

        $input = $request->validate(['task' => ['nullable', 'uuid'], 'comment' => ['nullable', 'uuid'], 'project' => ['nullable', 'uuid']]);
        $initialTask = $input['task'] ?? null;
        $initialProject = $input['project'] ?? null;
        abort_if($initialTask !== null && $initialProject !== null, 422);
        if ($initialProject !== null) {
            Gate::authorize('view', TeamProject::query()->where('tenant_id', $tenant->id)->findOrFail($initialProject));
        }
        if (isset($input['comment']) && $initialTask === null) {
            abort(422);
        }
        if ($initialTask !== null) {
            $task = TeamTask::query()->where('tenant_id', $tenant->id)->findOrFail($initialTask);
            Gate::authorize('view', $task);
        }

        return response()->view($standalone ? 'team.app' : 'team.index', ['standalone' => $standalone, 'teamAppName' => app(\App\Services\Team\TeamAppPresentation::class)->name($tenant), 'tenant' => $tenant, 'actor' => $actor, 'initialTask' => $initialTask, 'initialProject' => $initialProject, 'initialComment' => $input['comment'] ?? null])->header('Cache-Control', 'private, no-store');
    }

    /** Lists only the caller's currently eligible Team workspaces, never their work contents. */
    public function workspaces(Request $request)
    {
        [$current] = $this->scope($request);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000']]);
        $page = (int) ($input['page'] ?? 1);
        $query = trim($input['q'] ?? '');
        $items = [];
        foreach ($request->user()->tenants()->wherePivot('membership_active', true)->orderBy('tenants.name')->orderBy('tenants.id')->get(['tenants.id', 'tenants.name', 'tenants.slug']) as $candidate) {
            if ($query !== '' && mb_stripos($candidate->name, $query) === false && mb_stripos($candidate->slug, $query) === false) {
                continue;
            }
            try {
                $this->access->context($request->user(), $candidate);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                    throw $exception;
                }

                continue;
            }
            // The existing soccer shell intentionally does not expose the general Team workspace.
            if (app(\App\Services\Soccer\SoccerAccess::class)->enabled($candidate)) {
                continue;
            }
            $items[] = ['id' => (string) $candidate->id, 'name' => $candidate->name, 'slug' => $candidate->slug,
                'url' => route('team.index', ['tenant' => $candidate->slug]), 'current' => $candidate->id === $current->id];
        }

        return response()->json(['viewer_id' => (string) $request->user()->id, 'csrf_token' => csrf_token(), 'workspace' => ['id' => (string) $current->id, 'name' => $current->name, 'slug' => $current->slug],
            'items' => array_slice($items, ($page - 1) * 25, 25), 'page' => $page, 'has_more' => count($items) > $page * 25])
            ->header('Cache-Control', 'private, no-store');
    }

    public function data(Request $request)
    {
        [$tenant, $actor] = $this->scope($request);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:500'], 'mine' => ['nullable', 'boolean'], 'project_id' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1'],
            'completion' => ['nullable', 'in:all,incomplete,completed'], 'priority' => ['nullable', 'in:low,normal,high,urgent'], 'month' => ['nullable', 'date_format:Y-m'],
            'date_filter' => ['nullable', 'in:all,today,tomorrow,overdue,week,undated'], 'sort' => ['nullable', 'in:due,start,priority,created,title'], 'timezone' => ['nullable', 'timezone'], 'project_page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $state = $this->repository->state($tenant->id);
        $projects = [];
        foreach ($state['projects'] as $id => $project) {
            if ($this->graph->can($state, $actor, 'project', $id)) {
                $visible = $this->graph->read($state, $actor, 'project', $id);
                if (isset($visible['current_status'])) {
                    $visible['current_status'] = array_intersect_key($visible['current_status'], array_flip(['id', 'code', 'created_at', 'project_version']));
                }
                $projects[] = $visible + ['can_edit' => $project['owner_id'] === $actor['user_id'] && in_array($actor['role'], ['owner', 'admin', 'member'], true), 'can_add_task' => $this->graph->can($state, $actor, 'project', $id, true)];
            }
        }
        if (isset($input['project_id'])) {
            abort_unless($this->graph->can($state, $actor, 'project', $input['project_id']), 404);
        }
        // Project discovery is independent of task/My Tasks filters. Filter permissions
        // before matching, counting or paging; descriptions never become result snippets.
        $projectQuery = trim($input['q'] ?? '');
        $projectPage = (int) ($input['project_page'] ?? 1);
        $projectMatches = $projectQuery === '' ? [] : array_values(array_filter($projects,
            fn ($project) => mb_stripos($project['name'], $projectQuery) !== false || mb_stripos($project['description'] ?? '', $projectQuery) !== false));
        usort($projectMatches, fn ($a, $b) => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);
        $projectSearch = ['items' => array_map(fn ($project) => array_intersect_key($project, array_flip(['id', 'name', 'archived'])), array_slice($projectMatches, ($projectPage - 1) * 25, 25)),
            'page' => $projectPage, 'total' => count($projectMatches), 'has_more' => count($projectMatches) > $projectPage * 25];
        $feed = app(TeamTaskFeed::class);
        $clock = now()->toDateTimeImmutable();
        $timezone = $input['timezone'] ?? 'UTC';
        $home = $feed->home($this->graph->search($state, $actor, '', true, true), $actor['user_id'], $clock, $timezone);
        $tasks = $this->graph->search($state, $actor, $input['q'] ?? '', (bool) ($input['mine'] ?? false), true);
        $tasks = array_values(array_filter($tasks, function ($task) use ($input, $feed, $clock, $timezone) {
            if (! $feed->matches($task, $input['date_filter'] ?? 'all', $clock, $timezone)) {
                return false;
            }
            if (($input['completion'] ?? 'all') === 'incomplete' && $task['completed_at'] !== null) {
                return false;
            }
            if (($input['completion'] ?? 'all') === 'completed' && $task['completed_at'] === null) {
                return false;
            }
            if (isset($input['priority']) && $task['priority'] !== $input['priority']) {
                return false;
            }
            // Date-only deadlines remain literal. Timed deadlines use UTC in this first calendar slice.
            $day = $task['due_on'] ?? ($task['due_at'] ? (new \DateTimeImmutable($task['due_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d') : null);

            return ! isset($input['month']) || ($day !== null && substr($day, 0, 7) === $input['month']);
        }));
        if (isset($input['project_id'])) {
            $tasks = array_values(array_filter($tasks, fn ($task) => isset($task['memberships'][$input['project_id']])));
            $projectId = $input['project_id'];
            $sectionIds = array_keys($state['projects'][$projectId]['sections']);
            usort($tasks, function ($a, $b) use ($projectId, $sectionIds) {
                $left = $a['memberships'][$projectId];
                $right = $b['memberships'][$projectId];

                return [array_search($left['section_id'], $sectionIds, true) ?: 0, $left['position'], $a['id']]
                    <=> [array_search($right['section_id'], $sectionIds, true) ?: 0, $right['position'], $b['id']];
            });
        }
        if (isset($input['sort'])) {
            $tasks = $feed->sort($tasks, $input['sort'], $timezone);
        }
        $total = count($tasks);
        $page = (int) ($input['page'] ?? 1);
        $hasMore = count($tasks) > $page * 50;
        $tasks = array_map(fn ($task) => $task + ['can_edit' => $this->graph->can($state, $actor, 'task', $task['id'], true)], array_slice($tasks, ($page - 1) * 50, 50));
        $members = in_array($actor['role'], ['guest', 'viewer'], true) ? collect([$request->user()])
            : $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->get();

        return response()->json(['workspace' => ['id' => (string) $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug], 'projects' => $projects, 'project_search' => $projectSearch, 'tasks' => $tasks, 'page' => $page, 'has_more' => $hasMore, 'total' => $total, 'home' => $home,
            'members' => $members->map(fn ($member) => ['id' => (string) $member->id, 'name' => $member->name])->values(),
            'can_create_project' => in_array($actor['role'], ['owner', 'admin', 'member'], true), 'can_create_task' => in_array($actor['role'], ['owner', 'admin', 'member'], true) || collect($projects)->contains('can_add_task', true)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function project(Request $request, string $id)
    {
        [$tenant, $actor] = $this->scope($request);
        $project = TeamProject::query()->where('tenant_id', $tenant->id)->findOrFail($id);
        Gate::authorize('view', $project);
        $state = $this->repository->state($tenant->id);

        return response()->json($this->graph->read($state, $actor, 'project', $id))->header('Cache-Control', 'private, no-store');
    }

    public function overview(Request $request, string $id)
    {
        [$tenant] = $this->scope($request);
        Gate::authorize('view', TeamProject::query()->where('tenant_id', $tenant->id)->findOrFail($id));
        $input = $request->validate(['before' => ['nullable', 'string', 'max:4000'], 'selected' => ['sometimes', 'array', 'max:20'], 'selected.*' => ['uuid', 'distinct']]);
        try {
            $data = app(\App\Services\Team\TeamProjectOverview::class)->read($tenant->id, $id,
                fn () => $this->access->context($request->user()->fresh() ?? abort(403), $tenant), $input);
        } catch (DomainException) {
            abort(403);
        }

        return response()->json($data + ['project_url' => route('team.index', ['tenant' => $tenant->slug, 'project' => $id])])->header('Cache-Control', 'private, no-store');
    }

    public function task(Request $request, string $id)
    {
        [$tenant, $actor] = $this->scope($request);
        $task = TeamTask::query()->where('tenant_id', $tenant->id)->findOrFail($id);
        Gate::authorize('view', $task);
        $state = $this->repository->state($tenant->id);

        $result = $this->graph->read($state, $actor, 'task', $id);
        $children = [];
        foreach ($state['tasks'] as $child) {
            if ($child['parent_id'] === $id && $this->graph->can($state, $actor, 'task', $child['id'])) {
                $children[] = ['id' => $child['id'], 'title' => $child['title'], 'completed_at' => $child['completed_at'], 'due_on' => $child['due_on'], 'due_at' => $child['due_at'],
                    'version' => $child['version'], 'can_edit' => $this->graph->can($state, $actor, 'task', $child['id'], true)];
            }
        }
        $order = array_flip($result['child_order'] ?? []);
        usort($children, fn ($a, $b) => [$order[$a['id']] ?? PHP_INT_MAX, $a['id']] <=> [$order[$b['id']] ?? PHP_INT_MAX, $b['id']]);

        return response()->json($result + ['url' => route('team.index', ['tenant' => $tenant->slug, 'task' => $id]), 'can_edit' => $this->graph->can($state, $actor, 'task', $id, true), 'subtasks' => array_slice($children, 0, 100), 'subtasks_truncated' => count($children) > 100])
            ->header('Cache-Control', 'private, no-store');
    }

    public function template(Request $request, string $id, string $template)
    {
        [$tenant, $actor] = $this->scope($request);
        $project = TeamProject::query()->where('tenant_id', $tenant->id)->findOrFail($id);
        Gate::authorize('view', $project);
        try {
            $preview = (new TeamWorkBlueprints($this->graph))->preview($this->repository->state($tenant->id), $actor, $id, $template);
        } catch (DomainException) {
            abort(404);
        }

        return response()->json($preview)->header('Cache-Control', 'private, no-store');
    }

    public function command(Request $request)
    {
        [$tenant, $actor] = $this->scope($request);
        $input = $request->validate(['kind' => ['required', 'string', 'in:project.create,project.update,project.status,project.archive,project.member,project.transfer_owner,project.section,project.field,project.template,project.from_template,task.create,task.from_template,task.follow_up,task.update,task.complete,task.recurrence,task.dependency,task.membership,task.collaborator,task.comment,task.comment.update,task.comment.delete,task.delete,task.fields,task.subtask_order'],
            'id' => ['nullable', 'uuid'], 'idempotency_key' => ['required', 'uuid'], 'expected_version' => ['nullable', 'integer', 'min:1'],
            'status_code' => ['sometimes', 'in:on_track,at_risk,off_track,on_hold,complete'], 'summary' => ['sometimes', 'nullable', 'string', 'max:4000'], 'highlights' => ['sometimes', 'nullable', 'string', 'max:2000'], 'blockers' => ['sometimes', 'nullable', 'string', 'max:2000'], 'next_steps' => ['sometimes', 'nullable', 'string', 'max:2000'], 'references' => ['sometimes', 'array', 'max:20'], 'references.*' => ['integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:500'], 'title' => ['sometimes', 'string', 'max:500'], 'description' => ['nullable', 'string', 'max:100000'],
            'privacy' => ['sometimes', 'in:private,workspace'], 'assignee_id' => ['nullable', 'integer', 'min:1'], 'parent_id' => ['nullable', 'uuid'],
            'due_on' => ['nullable', 'date_format:Y-m-d'], 'start_on' => ['nullable', 'date_format:Y-m-d'], 'due_at' => ['nullable', 'string', 'max:40'], 'start_at' => ['nullable', 'string', 'max:40'],
            'memberships' => ['sometimes', 'array', 'max:50'], 'memberships.*' => ['array:section_id,position'], 'memberships.*.section_id' => ['nullable', 'string', 'max:120'], 'memberships.*.position' => ['nullable', 'integer', 'min:0'],
            'changes' => ['sometimes', 'array:title,description,assignee_id,priority,start_on,due_on,start_at,due_at,name,privacy'],
            'changes.title' => ['sometimes', 'string', 'max:500'], 'changes.description' => ['nullable', 'string', 'max:100000'],
            'changes.name' => ['sometimes', 'string', 'max:500'], 'changes.privacy' => ['sometimes', 'in:private,workspace'],
            'changes.assignee_id' => ['nullable', 'integer', 'min:1'], 'changes.priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'changes.start_on' => ['nullable', 'date_format:Y-m-d'], 'changes.due_on' => ['nullable', 'date_format:Y-m-d'],
            'changes.start_at' => ['nullable', 'string', 'max:40'], 'changes.due_at' => ['nullable', 'string', 'max:40'],
            'project_id' => ['sometimes', 'uuid'], 'section_id' => ['nullable', 'string', 'max:120'], 'position' => ['sometimes', 'integer', 'min:0'],
            'user_id' => ['sometimes', 'integer', 'min:1'], 'access' => ['nullable', 'in:viewer,editor'],
            'archived' => ['sometimes', 'boolean'], 'completed' => ['sometimes', 'boolean'], 'remove' => ['sometimes', 'boolean'], 'acknowledge_sharing' => ['sometimes', 'boolean'],
            'comment_id' => ['sometimes', 'uuid'], 'body' => ['sometimes', 'string', 'max:100000'],
            'child_ids' => ['sometimes', 'array', 'max:100'], 'child_ids.*' => ['uuid', 'distinct'],
            'field_id' => ['sometimes', 'uuid'], 'field_type' => ['sometimes', 'in:text,number,date,enum'], 'choices' => ['sometimes', 'array', 'max:20'], 'choices.*' => ['string', 'max:100', 'distinct'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'template_id' => ['sometimes', 'uuid'], 'template_type' => ['sometimes', 'in:task,project'], 'source_task_id' => ['required_if:kind,task.follow_up', 'uuid'], 'source_version' => ['required_if:kind,task.follow_up', 'integer', 'min:1'], 'source_comment_id' => ['sometimes', 'nullable', 'uuid'], 'destination_versions' => ['sometimes', 'array', 'max:50'], 'destination_versions.*' => ['integer', 'min:1'], 'template_version' => ['sometimes', 'integer', 'min:1'],
            'blocker_id' => ['sometimes', 'uuid'], 'remove_unavailable' => ['sometimes', 'boolean'],
            'rule' => ['sometimes', 'nullable', 'array:frequency,interval,timezone,overlap,until_on,max_occurrences'],
            'rule.frequency' => ['required_with:rule', 'in:daily,weekly,monthly'], 'rule.interval' => ['required_with:rule', 'integer', 'min:1', 'max:12'],
            'rule.timezone' => ['required_with:rule', 'timezone'], 'rule.overlap' => ['required_with:rule', 'in:reject,earlier,later'],
            'rule.until_on' => ['nullable', 'date_format:Y-m-d'], 'rule.max_occurrences' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'values' => ['sometimes', 'array', 'max:20'], 'project_version' => ['sometimes', 'integer', 'min:1'],
            'mentioned_user_ids' => ['sometimes', 'array', 'max:50'], 'mentioned_user_ids.*' => ['integer', 'min:1']]);
        if ($input['kind'] === 'project.status') {
            foreach (['summary', 'highlights', 'blockers', 'next_steps'] as $field) {
                $input[$field] ??= '';
            }
        }
        $input['id'] ??= (string) Str::uuid();
        if (array_key_exists('description', $input)) {
            $input['description'] ??= '';
        }
        if (array_key_exists('description', $input['changes'] ?? [])) {
            $input['changes']['description'] ??= '';
        }
        foreach (['user_id', 'assignee_id'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = (string) $input[$field];
            }
        }
        if (isset($input['changes']['assignee_id'])) {
            $input['changes']['assignee_id'] = (string) $input['changes']['assignee_id'];
        }
        if (isset($input['mentioned_user_ids'])) {
            $input['mentioned_user_ids'] = array_values(array_unique(array_map('strval', $input['mentioned_user_ids'])));
        }
        if (! in_array($input['kind'], ['project.create', 'task.create', 'project.from_template', 'task.from_template', 'task.follow_up'], true)) {
            $class = str_starts_with($input['kind'], 'project.') ? TeamProject::class : TeamTask::class;
            $entity = $class::query()->where('tenant_id', $tenant->id)->findOrFail($input['id']);
            // Transfer retries may come from the former owner; graph checks the current receipt and ACL.
            Gate::authorize(in_array($input['kind'], ['project.transfer_owner', 'project.status'], true) ? 'view' : ($input['kind'] === 'task.delete' && $entity->deleted ? 'replayDeletion' : 'update'), $entity);
        }
        try {
            $result = $this->repository->execute($tenant->id, fn () => $this->access->context($request->user(), $tenant), $input);

            return response()->json($result)->header('Cache-Control', 'private, no-store');
        } catch (DomainException $e) {
            $status = match ($e->getMessage()) {
                'conflict', 'idempotency_mismatch' => 409, 'access_denied' => 403, default => 422
            };

            return response()->json(['error' => $e->getMessage(), 'saved' => false], $status);
        }
    }

    public function inbox(Request $request)
    {
        [$tenant, $actor] = $this->scope($request);
        $state = $this->repository->state($tenant->id);
        $rows = DB::table('team_inbox_items')->where('tenant_id', $tenant->id)->where('recipient_user_id', $request->user()->id)->whereNull('archived_at')->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();
        $items = [];
        foreach ($rows as $row) {
            if ($this->graph->can($state, $actor, 'task', $row->task_id)) {
                $items[] = ['id' => $row->id, 'task_id' => $row->task_id, 'event_type' => $row->event_type, 'read_at' => $row->read_at,
                    'created_at' => $row->created_at, 'title' => $state['tasks'][$row->task_id]['title']];
            }
        }

        $items = array_merge($items, app(\App\Services\Team\Operations\OperationsReminders::class)->inbox($tenant, $request->user()));

        return response()->json(['items' => $items, 'coverage' => 'recent_100_recipient_events', 'operations_coverage' => config('team_operations.enabled') ? 'recent_100_permitted_reminders' : 'disabled'])->header('Cache-Control', 'private, no-store');
    }

    public function updateInbox(Request $request, string $id)
    {
        [$tenant, $actor] = $this->scope($request);
        $input = $request->validate(['read' => ['sometimes', 'boolean'], 'archived' => ['sometimes', 'boolean']]);
        $item = DB::table('team_inbox_items')->where('tenant_id', $tenant->id)->where('recipient_user_id', $request->user()->id)->where('id', $id)->first();
        if (! $item && config('team_operations.enabled')) {
            app(\App\Services\Team\Operations\OperationsReminders::class)->update($tenant, $request->user(), $id, $input);

            return response()->json(['saved' => true]);
        }
        abort_unless($item && $this->graph->can($this->repository->state($tenant->id), $actor, 'task', $item->task_id), 404);
        $changes = [];
        foreach (['read', 'archived'] as $field) {
            if (array_key_exists($field, $input)) {
                $column = $field.'_at';
                $changes[$column] = $input[$field] ? ($item->$column ?? now()) : null;
            }
        }
        if ($changes) {
            DB::table('team_inbox_items')->where('tenant_id', $tenant->id)->where('recipient_user_id', $request->user()->id)->where('id', $id)->update($changes);
        }

        return response()->json(['saved' => true]);
    }
}
