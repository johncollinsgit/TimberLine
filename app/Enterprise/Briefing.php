<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Current, bounded, object-authorized evidence. No stored or model-generated narrative is authoritative. */
class Briefing
{
    public function __construct(private WorkspaceAccess $access, private TeamWorkRepository $work, private WorkGraph $graph, private RequestWorkflow $requests) {}

    public function build(Tenant $tenant, User $user, string $areaId): array
    {
        abort_unless(config('enterprise.briefing_enabled'), 404);
        $actor = $this->access->actor($tenant, $user);
        $area = $this->access->area($tenant, $actor, $areaId);
        $state = $this->work->state($tenant->id);
        $projects = [];
        foreach ($state['projects'] as $id => $raw) {
            if (($raw['enterprise_area_id'] ?? null) !== $areaId || ! $this->graph->can($state, $actor, 'project', $id)) {
                continue;
            }
            $project = $this->graph->read($state, $actor, 'project', $id);
            $projects[$id] = ['id' => $id, 'title' => mb_substr($project['name'], 0, 160), 'status' => $project['archived'] ? 'archived' : 'active',
                'version' => $project['version'], 'tasks' => 0, 'completed_tasks' => 0, 'overdue_tasks' => 0,
                'url' => $this->url($tenant, $areaId, 'projects', $id)];
        }
        // Authorization precedes every result cap; hidden records do not displace readable records.
        uasort($projects, fn ($a, $b) => [$a['status'] === 'archived', $a['title'], $a['id']] <=> [$b['status'] === 'archived', $b['title'], $b['id']]);
        $projects = array_slice($projects, 0, 100, true);
        $counts = ['projects' => count($projects), 'tasks' => 0, 'completed_tasks' => 0, 'overdue_tasks' => 0, 'open_requests' => 0];
        $today = now()->toDateString();
        foreach ($state['tasks'] as $id => $task) {
            if (isset($task['enterprise_discussion_project']) || ! $this->graph->can($state, $actor, 'task', $id)) {
                continue;
            }
            $visibleProjects = array_intersect_key($projects, $task['memberships']);
            if ($visibleProjects === []) {
                continue;
            }
            $completed = $task['completed_at'] !== null;
            $overdue = ! $completed && (($task['due_on'] !== null && $task['due_on'] < $today)
                || ($task['due_at'] !== null && CarbonImmutable::parse($task['due_at'])->isBefore(now())));
            $counts['tasks']++;
            $counts['completed_tasks'] += (int) $completed;
            $counts['overdue_tasks'] += (int) $overdue;
            foreach ($visibleProjects as $projectId => $_) {
                $projects[$projectId]['tasks']++;
                $projects[$projectId]['completed_tasks'] += (int) $completed;
                $projects[$projectId]['overdue_tasks'] += (int) $overdue;
            }
            if ($counts['tasks'] === 1000) {
                break;
            }
        }
        $requests = [];
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => 'enterprise_request'])
            ->orderByDesc('updated_at')->orderBy('id')->limit(1000)->cursor();
        foreach ($rows as $record) {
            try {
                $request = $this->requests->view($tenant, $actor, $record);
            } catch (HttpExceptionInterface $e) {
                if (! in_array($e->getStatusCode(), [403, 404], true)) {
                    throw $e;
                }

                continue;
            }
            $counts['open_requests'] += (int) ($request['status'] !== 'completed');
            $requests[] = ['id' => $request['id'], 'title' => mb_substr($request['title'], 0, 160), 'status' => $request['status'],
                'due_on' => $request['due_on'], 'version' => $request['version'], 'url' => $this->url($tenant, $areaId, 'requests', $request['id'])];
            if (count($requests) === 100) {
                break;
            }
        }
        uasort($projects, fn ($a, $b) => [-$a['overdue_tasks'], $a['status'] === 'archived', $a['title'], $a['id']] <=> [-$b['overdue_tasks'], $b['status'] === 'archived', $b['title'], $b['id']]);
        $sources = [];
        foreach (array_slice(array_values($projects), 0, 8) as $project) {
            $sources[] = ['ref' => 'project:'.$project['id'], 'title' => $project['title'], 'url' => $project['url'],
                'facts' => array_intersect_key($project, array_flip(['status', 'version', 'tasks', 'completed_tasks', 'overdue_tasks']))];
        }
        foreach (array_slice($requests, 0, 4) as $request) {
            $sources[] = ['ref' => 'request:'.$request['id'], 'title' => $request['title'], 'url' => $request['url'],
                'facts' => array_intersect_key($request, array_flip(['status', 'version', 'due_on']))];
        }
        $sample = (bool) config('enterprise.sample_data', false);

        return ['as_of' => now()->toIso8601String(), 'scope' => ['workspace' => $tenant->name, 'department' => $area->name, 'area_id' => $areaId],
            'viewer' => ['id' => (string) $user->id, 'role' => $actor['role']], 'sample_data' => $sample,
            'label' => $sample ? 'SAMPLE DATA - illustrative campus work, not live college operations' : 'Current authorized workspace records',
            'summary' => ['mode' => 'record_backed', 'headline' => $counts['overdue_tasks'] > 0 ? 'Recorded work needs attention' : 'Your current work at a glance',
                'text' => $counts['projects'].' readable projects; '.$counts['completed_tasks'].' of '.$counts['tasks'].' checked tasks completed; '
                    .$counts['overdue_tasks'].' overdue tasks; '.$counts['open_requests'].' open requests in the checked sample.',
                'model_status' => 'Record-derived summary. AI prioritization requires an explicit refresh and approved Enterprise provider access.'],
            'counts' => $counts, 'projects' => array_slice(array_values($projects), 0, 8), 'requests' => array_slice($requests, 0, 4), 'sources' => $sources,
            'coverage' => 'Selected workspace only: up to 100 readable projects, 1,000 readable linked tasks and 100 readable requests from a 1,000-request scan. Display: up to 8 projects and 4 requests. Unauthorized private records, other departments, notes, files, mailbox content, live feeds and accounting are excluded. Counts describe the checked sample, not everything happening on campus.',
            'snapshot_notice' => 'This briefing is an as-of snapshot. Refresh or download again to regenerate it from current authorized records.'];
    }

    public function refresh(Tenant $tenant, User $user, string $areaId, string $requestId): array
    {
        $before = $this->build($tenant, $user, $areaId);
        $payload = ['scope' => $before['scope'], 'sources' => array_map(function ($source) {
            unset($source['url']);

            return $source;
        }, $before['sources'])];
        try {
            $answer = app(Intelligence::class)->generate($tenant, $user, $areaId, 'briefing', $payload, $requestId);
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() !== 503) {
                throw $e;
            }
            $fresh = $this->build($tenant, $user, $areaId);
            $uncertain = str_contains(strtolower($e->getMessage()), 'uncertain');
            $fresh['summary']['model_status'] = $uncertain
                ? 'The AI request outcome is uncertain. Its maximum cost remains reserved. Keep this request key for reconciliation; do not retry with a new key. Current record facts remain available.'
                : 'AI prioritization is unavailable because its connection or usage approval is not configured. This fresh record-derived summary remains usable.';
            $fresh['ai_request'] = ['status' => $uncertain ? 'uncertain' : 'unavailable', 'request_id' => $requestId];

            return $fresh;
        }
        $fresh = $this->build($tenant, $user, $areaId);
        abort_unless(hash_equals($this->digest($before), $this->digest($fresh)), 409, 'Access or source records changed. Refresh the briefing again.');
        $refs = $answer['value']['refs'] ?? null;
        abort_unless(is_array($refs) && array_is_list($refs) && count($refs) <= count($fresh['sources']), 503);
        foreach ($refs as $ref) {
            abort_unless(is_string($ref) && in_array($ref, array_column($fresh['sources'], 'ref'), true), 503, 'The model returned an unsupported source.');
        }
        $chosen = array_flip(array_values(array_unique($refs)));
        usort($fresh['sources'], fn ($a, $b) => ($chosen[$a['ref']] ?? 100) <=> ($chosen[$b['ref']] ?? 100));
        usort($fresh['projects'], fn ($a, $b) => ($chosen['project:'.$a['id']] ?? 100) <=> ($chosen['project:'.$b['id']] ?? 100));
        usort($fresh['requests'], fn ($a, $b) => ($chosen['request:'.$a['id']] ?? 100) <=> ($chosen['request:'.$b['id']] ?? 100));
        $fresh['summary']['mode'] = 'model_selected_records';
        $fresh['summary']['model_status'] = 'AI prioritized checked record references. All displayed facts, counts and links were computed and rechecked by the server.';
        $fresh['summary']['priority_refs'] = array_values(array_unique($refs));
        $fresh['summary']['priority_items'] = array_values(array_map(function ($source): array {
            $facts = $source['facts'];
            $reason = isset($facts['tasks'])
                ? ($facts['overdue_tasks'] > 0 ? $facts['overdue_tasks'].' overdue checked tasks.'
                    : $facts['completed_tasks'].' of '.$facts['tasks'].' checked tasks completed; '.$facts['status'].' project.')
                : 'Recorded request status: '.str_replace('_', ' ', $facts['status']).($facts['due_on'] ? '; due '.$facts['due_on'] : '').'.';

            return ['ref' => $source['ref'], 'title' => $source['title'], 'url' => $source['url'], 'reason' => $reason];
        }, array_filter($fresh['sources'], fn ($source) => isset($chosen[$source['ref']]))));
        $fresh['summary']['usage_id'] = $answer['usage_id'];
        $fresh['ai_request'] = ['status' => 'completed', 'request_id' => $requestId, 'replayed' => (bool) ($answer['replayed'] ?? false)];

        return $fresh;
    }

    private function digest(array $briefing): string
    {
        unset($briefing['as_of']);

        return hash('sha256', json_encode($briefing, JSON_THROW_ON_ERROR));
    }

    private function url(Tenant $tenant, string $areaId, string $section, string $id): string
    {
        return route('enterprise.index', ['tenant' => $tenant->slug, 'area_id' => $areaId, 'section' => $section, 'selected' => $id]);
    }
}
