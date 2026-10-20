<?php

namespace App\Services\Team;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Read-only projection; immutable status history reuses existing native revisions/events. */
final class TeamProjectOverview
{
    public function __construct(private WorkGraph $graph, private TeamWorkRepository $repository) {}

    public function read(int $tenantId, string $projectId, callable $resolveActor, array $input): array
    {
        $actor = $resolveActor();
        $this->graph->read($this->repository->state($tenantId), $actor, 'project', $projectId);
        $query = DB::table('team_work_events')->where('tenant_id', $tenantId)->where('entity_type', 'project')->where('entity_id', $projectId)->where('payload->kind', 'project.status');
        if (isset($input['before'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($input['before']), true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                abort(422, 'Invalid status history cursor. Open the latest updates.');
            }
            abort_unless(is_array($cursor) && ($cursor['tenant_id'] ?? null) === $tenantId && ($cursor['project_id'] ?? null) === $projectId
                && ($cursor['user_id'] ?? null) === $actor['user_id'] && is_int($cursor['before'] ?? null) && $cursor['before'] > 0, 422);
            $query->where('id', '<', $cursor['before']);
        }
        $events = $query->orderByDesc('id')->limit(11)->get();
        $more = $events->count() > 10;
        $events = $events->take(10);
        $records = [];
        foreach ($events as $event) {
            $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
            $version = DB::table('team_work_versions')->where('tenant_id', $tenantId)->where('entity_type', 'project')->where('entity_id', $projectId)->where('version', $payload['status_version'] ?? 0)->first();
            abort_unless($version && hash_equals($version->sha256, hash('sha256', $version->snapshot)), 503, 'Project status history cannot be verified. Refresh later.');
            $snapshot = json_decode($version->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $record = $snapshot['current_status'] ?? null;
            abort_unless(($snapshot['tenant_id'] ?? null) === (string) $tenantId && ($snapshot['id'] ?? null) === $projectId
                && is_array($record) && $record['project_version'] === (int) $version->version && $record['project_id'] === $projectId, 503, 'Project status history cannot be verified.');
            $records[] = $record;
        }
        // Recheck current permission/visibility after reading history, including referenced tasks.
        $state = $this->repository->state($tenantId);
        $actor = $resolveActor();
        $project = $this->graph->read($state, $actor, 'project', $projectId);
        $total = 0;
        $completed = 0;
        $selected = [];
        foreach ($state['tasks'] as $task) {
            if (! isset($task['memberships'][$projectId]) || ! $this->graph->can($state, $actor, 'task', $task['id'])) {
                continue;
            }
            $total++;
            $completed += (int) ($task['completed_at'] !== null);
            if (in_array($task['id'], $input['selected'] ?? [], true)) {
                $selected[] = ['id' => $task['id'], 'title' => $task['title'], 'version' => $task['version']];
            }
        }

        return ['viewer_id' => $actor['user_id'], 'project' => $project, 'progress' => ['visible_tasks' => $total, 'completed_tasks' => $completed, 'coverage' => 'currently readable tasks explicitly added to this project'],
            'updates' => array_map(fn ($record) => $this->graph->projectStatus($state, $actor, $projectId, $record), $records),
            'next_before' => $more ? Crypt::encryptString(json_encode(['tenant_id' => $tenantId, 'project_id' => $projectId, 'user_id' => $actor['user_id'], 'before' => (int) $events->last()->id], JSON_THROW_ON_ERROR)) : null, 'selected_references' => $selected,
            'can_post_status' => ! $project['archived'] && $project['owner_id'] === $actor['user_id'] && in_array($actor['role'], ['owner', 'admin', 'member'], true)];
    }
}
