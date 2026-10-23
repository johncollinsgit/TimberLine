<?php

namespace App\Enterprise;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;

/** One authorized projection for search results/counts; record text is inert data. */
class CampusSearch
{
    public function __construct(private WorkspaceAccess $access, private TeamWorkRepository $work, private WorkGraph $graph) {}

    public function search(Tenant $tenant, User $user, string $query, ?string $areaId = null): array
    {
        abort_unless(config('enterprise.operations_enabled'), 404);
        $actor = $this->access->actor($tenant, $user);
        if ($areaId !== null) {
            $this->access->area($tenant, $actor, $areaId);
        }
        $areas = TeamArea::query()->where('tenant_id', $tenant->id)->whereIn('profile', WorkspaceAccess::PROFILES)->orderBy('id')->get()
            ->filter(fn ($area) => ($areaId === null || $area->id === $areaId) && WorkspaceAccess::permits($actor, $area->id))->keyBy('id');
        $query = trim($query);
        $results = [];
        $add = function (string $kind, string $id, string $title, string $text, ?string $area, array $extra = []) use (&$results, $query, $tenant): void {
            if (count($results) >= 100 || ($query !== '' && mb_stripos($title.' '.$text, $query) === false)) {
                return;
            }
            $section = match ($kind) {
                'request' => 'requests', 'building' => 'buildings', 'asset' => 'assets', 'reservation', 'conflict' => 'calendar',
                'person', 'contractor' => 'people', default => 'projects',
            };
            $url = route('enterprise.index', array_filter(['tenant' => $tenant->slug, 'area_id' => $area, 'section' => $section, 'selected' => $extra['project_id'] ?? $id,
                'task' => $extra['task_id'] ?? null], fn ($value) => $value !== null));
            $results[] = ['kind' => $kind, 'id' => $id, 'title' => $title, 'excerpt' => mb_substr($text, 0, 180), 'area_id' => $area, 'source_url' => $url, ...$extra];
        };
        $state = $this->work->state($tenant->id);
        foreach ($state['projects'] as $id => $project) {
            if (isset($areas[$project['enterprise_area_id'] ?? '']) && $this->graph->can($state, $actor, 'project', $id)) {
                $project = $this->graph->read($state, $actor, 'project', $id);
                $add('project', $id, $project['name'], $project['description'] ?? '', $project['enterprise_area_id'], ['status' => $project['archived'] ? 'archived' : 'active']);
            }
        }
        $searchableTasks = [];
        foreach ($state['tasks'] as $id => $task) {
            if (! isset($areas[$task['enterprise_area_id'] ?? '']) || ! $this->graph->can($state, $actor, 'task', $id)) {
                continue;
            }
            $task = $this->graph->read($state, $actor, 'task', $id);
            $project = array_key_first($task['memberships']);
            $extra = ['task_id' => $id, 'project_id' => $project, 'owner_id' => $task['assignee_id'], 'status' => $task['completed_at'] ? 'completed' : 'active'];
            if (count($searchableTasks) < 1000) {
                $searchableTasks[$id] = ['area_id' => $task['enterprise_area_id'], 'extra' => $extra];
            }
            if (! isset($task['enterprise_discussion_project'])) {
                $add('task', $id, $task['title'], $task['description'] ?? '', $task['enterprise_area_id'], $extra);
            }
            foreach ($task['comments'] ?? [] as $comment) {
                if (($comment['deleted_at'] ?? null) === null) {
                    $add('comment', $comment['id'], 'Note on '.$task['title'], $comment['body'], $task['enterprise_area_id'], $extra);
                }
            }
        }
        if (config('enterprise.collaboration_enabled') && count($results) < 100 && $searchableTasks !== []) {
            foreach (app(FileCaptions::class)->searchable($tenant, $user, array_keys($searchableTasks)) as $file) {
                $context = $searchableTasks[$file['task_id']];
                $add('attachment', $file['id'], $file['filename'], trim($file['caption'].' '.$file['status']), $context['area_id'], $context['extra']);
            }
        }
        foreach (TeamOperationRecord::query()->where('tenant_id', $tenant->id)->whereIn('area_id', $areas->keys())
            ->where('kind', 'enterprise_request')->orderByDesc('updated_at')->limit(1000)->cursor() as $row) {
            try {
                $request = app(RequestWorkflow::class)->view($tenant, $actor, $row);
                $add('request', $row->id, $row->title, $request['data']['description'] ?? '', $row->area_id, ['status' => $request['status'], 'location' => $request['data']['location'] ?? '']);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }
            }
        }
        foreach ($areas as $area) {
            if (config('enterprise.resources_enabled')) {
                foreach (app(Resources::class)->listing($tenant, $user, $area->id)['items'] as $row) {
                    $kind = match ($row['kind']) {
                        'location' => 'building', 'asset' => 'asset', 'reservation', 'booking' => 'reservation', default => 'conflict',
                    };
                    $add($kind, $row['id'], $row['title'], $row['data']['description'] ?? '', $area->id, ['status' => $row['status']]);
                }
            }
            if (config('enterprise.planning_enabled')) {
                foreach (app(Planning::class)->listing($tenant, $user, $area->id)['items'] as $row) {
                    if ($row['kind'] === 'contractor') {
                        $add('contractor', $row['id'], $row['title'], $row['data']['notes'] ?? '', $area->id, ['status' => $row['status']]);
                    }
                }
            }
        }
        foreach ($tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->whereNotNull('email_verified_at')->orderBy('users.id')->get() as $person) {
            $personId = (string) $person->id;
            $other = array_replace($actor, ['user_id' => $personId, 'role' => $actor['active_roles'][$personId] ?? 'guest']);
            if (! in_array($other['role'], ['owner', 'admin', 'member', 'viewer'], true)) {
                continue;
            }
            $visibleArea = $areas->first(fn ($area) => WorkspaceAccess::permits($other, $area->id));
            if ($visibleArea && in_array($personId, $actor['verified_user_ids'], true)) {
                $add('person', $personId, $person->name, 'Current employee in an authorized workspace', $visibleArea->id);
            }
        }
        if (config('enterprise.resources_enabled')) {
            foreach (app(LocationCatalog::class)->all($query)['entries'] as $entry) {
                $add('building', 'catalog:'.$entry['map_number'], '#'.$entry['map_number'].' '.$entry['name'],
                    implode(' ', array_filter([$entry['official_function_details'] ?? '', $entry['aliases'] ?? '', $entry['related_spaces'] ?? ''])),
                    null, ['source' => 'official_public_catalog', 'coordinates_verified' => false, 'map_number' => $entry['map_number']]);
            }
        }

        return ['items' => $results, 'visible_count' => count($results), 'partial' => true, 'as_of' => now()->toIso8601String(),
            'coverage' => 'Up to 100 currently authorized matches; bounded request/resource scans. Attachment names/captions only, no file contents or mailbox messages. Private contractor evaluations and other departments are excluded. Public location catalog is source metadata, not verified inventory.'];
    }
}
