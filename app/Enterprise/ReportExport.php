<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Current authorized facts and recorded actions, never a reconstructed quarter-end report. */
class ReportExport
{
    public const LIMITS = ['project' => 100, 'task' => 500, 'request' => 200];

    public const EVENT_LIMIT = 1000;

    private const NATIVE_SCAN = 10000;

    private const NATIVE_ACTIONS = ['project.create', 'project.update', 'project.archive', 'project.status', 'task.create', 'task.update', 'task.complete', 'task.follow_up'];

    private const REQUEST_ACTIONS = ['request.create', 'request.triage', 'request.assign', 'request.progress', 'request.complete'];

    public function __construct(private WorkspaceAccess $access, private TeamWorkRepository $work, private WorkGraph $graph, private RequestWorkflow $requests) {}

    public function build(Tenant $tenant, User $user, array $raw): array
    {
        abort_unless(config('enterprise.reports_enabled'), 404);
        $input = Validator::make($raw, ['area_id' => ['required', 'uuid'], 'project_id' => ['nullable', 'uuid'],
            'mode' => ['required', 'in:calendar_quarter,date_range'], 'timezone' => ['required', 'timezone:all'],
            'year' => ['required_if:mode,calendar_quarter', 'integer', 'between:2000,2100'], 'quarter' => ['required_if:mode,calendar_quarter', 'integer', 'between:1,4'],
            'start_date' => ['required_if:mode,date_range', 'date_format:Y-m-d'], 'end_date' => ['required_if:mode,date_range', 'date_format:Y-m-d']])->validate();
        $actor = $this->actor($tenant, $user, $input['area_id']);
        $asOf = CarbonImmutable::now('UTC');
        $window = $this->window($input, $asOf);
        $state = $this->work->state($tenant->id);
        $projectId = $input['project_id'] ?? null;
        if ($projectId !== null) {
            abort_unless($this->graph->can($state, $actor, 'project', $projectId)
                && ($state['projects'][$projectId]['enterprise_area_id'] ?? null) === $input['area_id'], 404);
        }
        $records = $available = $requestRows = [];
        $counts = array_fill_keys(array_keys(self::LIMITS), 0);
        $add = function (array $record) use (&$records, &$available, &$counts): void {
            $kind = $record['entity_type'];
            $counts[$kind]++;
            if ($counts[$kind] <= self::LIMITS[$kind]) {
                $records[] = $record;
                $available[$kind.':'.$record['id']] = $record;
            }
        };
        $projects = [];
        ksort($state['projects']);
        foreach ($state['projects'] as $id => $row) {
            if (($row['enterprise_area_id'] ?? null) !== $input['area_id'] || ($projectId !== null && $id !== $projectId)
                || ! $this->graph->can($state, $actor, 'project', $id)) {
                continue;
            }
            $projects[$id] = $row;
            $add($this->record($tenant, $input['area_id'], 'project', $id, $row['name'], $row['archived'] ? 'archived' : 'active', $id,
                $row['owner_id'], null, null, $row['version']));
        }
        ksort($state['tasks']);
        foreach ($state['tasks'] as $id => $row) {
            $memberships = array_intersect_key($projects, $row['memberships']);
            if (($row['enterprise_area_id'] ?? null) !== $input['area_id'] || $memberships === [] || isset($row['enterprise_discussion_project'])
                || ! $this->graph->can($state, $actor, 'task', $id)) {
                continue;
            }
            $add($this->record($tenant, $input['area_id'], 'task', $id, $row['title'], $row['completed_at'] ? 'completed' : 'active',
                array_key_first($memberships), $row['assignee_id'], $row['due_on'], $row['updated_at'], $row['version']));
        }
        $requestScanned = 0;
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $input['area_id'], 'kind' => 'enterprise_request'])
            ->orderByDesc('updated_at')->orderBy('id')->limit(2000)->cursor() as $row) {
            $requestScanned++;
            try {
                $view = $this->requests->view($tenant, $actor, $row);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                if (in_array($e->getStatusCode(), [403, 404], true)) {
                    continue;
                }
                throw $e;
            }
            $linkedProject = $view['data']['project_id'] ?? null;
            if (($projectId !== null && $linkedProject !== $projectId) || ($linkedProject !== null && ! isset($projects[$linkedProject]))) {
                continue;
            }
            $add($this->record($tenant, $input['area_id'], 'request', $row->id, $row->title, $view['status'], $linkedProject,
                $row->assigned_user_id, $view['due_on'], $row->updated_at?->toIso8601String(), $row->version));
            if (isset($available['request:'.$row->id])) {
                $requestRows[$row->id] = $row;
            }
        }
        [$events, $eventCoverage] = $this->events($tenant, $state['events'], $available, $window);
        // Re-resolve the current actor/object rails immediately before returning exportable bytes.
        $actor = $this->actor($tenant, $user, $input['area_id']);
        $current = $this->work->state($tenant->id);
        foreach ($records as $record) {
            if ($record['entity_type'] === 'request') {
                $row = $requestRows[$record['id']]->fresh();
                abort_unless($row && (int) $row->tenant_id === (int) $tenant->id
                    && $row->area_id === $input['area_id'] && $row->kind === 'enterprise_request', 404);
                $fresh = $this->requests->view($tenant, $actor, $row);
                $linked = $fresh['data']['project_id'] ?? null;
                abort_unless($projectId === null || $linked === $projectId, 404);
                if ($linked !== null) {
                    abort_unless(is_string($linked) && ($current['projects'][$linked]['enterprise_area_id'] ?? null) === $input['area_id']
                        && $this->graph->can($current, $actor, 'project', $linked), 404);
                }
            } else {
                $collection = $record['entity_type'] === 'task' ? 'tasks' : 'projects';
                abort_unless(($current[$collection][$record['id']]['enterprise_area_id'] ?? null) === $input['area_id']
                    && $this->graph->can($current, $actor, $record['entity_type'], $record['id']), 404);
                if ($record['entity_type'] === 'task' && $projectId !== null) {
                    abort_unless(isset($current['tasks'][$record['id']]['memberships'][$projectId]), 404);
                }
            }
        }
        $sample = (bool) config('enterprise.sample_data');

        return ['schema_version' => 1, 'report_type' => 'period_activity', 'generated_at_utc' => $asOf->toIso8601String(),
            'sample_data' => $sample, 'label' => $sample ? 'SAMPLE DATA - structured operational export' : 'Current authorized operational export',
            'selection' => ['area_id' => $input['area_id'], 'project_id' => $projectId], 'window' => $window,
            'basis' => ['current_state' => 'Current canonical facts read during generation, regardless of selected event dates. Task status reflects native completion, not operational checkpoints. Not a quarter-end snapshot or an atomic historical reconstruction.',
                'recorded_events' => 'Allowlisted actions recorded in the selected window for included currently readable records. No historical state reconstruction; task.complete may mean completion or reopening.'],
            'current_records' => $records, 'recorded_events' => $events,
            'coverage' => ['current_records' => ['visible_candidates_in_checked_sources' => $counts, 'limits' => self::LIMITS,
                'returned' => count($records), 'request_scan_limit' => 2000, 'request_scan_limit_reached' => $requestScanned === 2000,
                'omitted_visible_candidates' => array_sum($counts) - count($records)], 'recorded_events' => $eventCoverage, 'partial' => true,
                'notice' => 'One selected workspace; optional project. Current tenant graph plus a bounded request scan. Event history covers included current records only; inaccessible, deleted and omitted records are not reconstructed. Counts describe this checked sample, not complete campus or quarterly activity. Titles are authorized user-entered content, not automatically anonymized or classified.'],
            'exclusions' => ['Financial/cost and payment records', 'Certificates, insurance and document/file contents or names', 'Private contractor evaluations',
                'Comments, descriptions, internal notes and raw audit snapshots', 'Mailbox, student and security case records', 'External systems and live feeds'],
            'snapshot_notice' => 'Downloaded files are dated snapshots. Download again to regenerate with current permissions. Calendar periods use your selected timezone; this is not a reconstructed quarter-end state.'];
    }

    private function actor(Tenant $tenant, User $user, string $areaId): array
    {
        $actor = $this->access->actor($tenant, $user);
        $this->access->area($tenant, $actor, $areaId);
        abort_unless(WorkspaceAccess::permits($actor, $areaId, false, true), 403, 'A current workspace coordinator or organization manager must export reports.');

        return $actor;
    }

    private function window(array $input, CarbonImmutable $asOf): array
    {
        $tz = $input['timezone'];
        if ($input['mode'] === 'calendar_quarter') {
            $start = CarbonImmutable::create((int) $input['year'], ((int) $input['quarter'] - 1) * 3 + 1, 1, 0, 0, 0, $tz);
            $end = $start->addMonths(3);
        } else {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $input['start_date'], $tz);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $input['end_date'], $tz)->addDay();
            abort_unless($end->isAfter($start) && $start->diffInDays($end) <= 366, 422, 'Choose an ordered date window of at most 366 local calendar days.');
        }

        return ['mode' => $input['mode'], 'timezone' => $tz, 'start_local' => $start->toDateString(), 'end_local_inclusive' => $end->subDay()->toDateString(),
            'start_utc' => $start->utc()->toIso8601String(), 'end_utc_exclusive' => $end->utc()->toIso8601String(),
            'observed_through_utc' => $end->utc()->min($asOf)->toIso8601String(), 'period_in_progress' => ! $start->isAfter($asOf) && $end->isAfter($asOf),
            'period_state' => $start->isAfter($asOf) ? 'future' : ($end->isAfter($asOf) ? 'in_progress' : 'closed')];
    }

    private function record(Tenant $tenant, string $area, string $type, string $id, string $title, string $status, ?string $project, $owner, ?string $due, ?string $updated, int $version): array
    {
        return ['entity_type' => $type, 'id' => $id, 'project_id' => $project, 'title' => mb_substr($title, 0, 160), 'status' => $status,
            'owner_id' => $owner === null ? null : (string) $owner, 'due_on' => $due, 'updated_at' => $updated, 'version' => $version,
            'source_url' => route('enterprise.index', array_filter(['tenant' => $tenant->slug, 'area_id' => $area, 'section' => $type === 'request' ? 'requests' : 'projects',
                'selected' => $type === 'task' ? $project : $id, 'task' => $type === 'task' ? $id : null]))];
    }

    private function events(Tenant $tenant, array $native, array $available, array $window): array
    {
        $start = CarbonImmutable::parse($window['start_utc']);
        $end = CarbonImmutable::parse($window['observed_through_utc']);
        $exclusive = CarbonImmutable::parse($window['end_utc_exclusive']);
        $events = [];
        $scanned = 0;
        foreach (array_reverse($native) as $event) {
            $record = $available[($event['entity_type'] ?? '').':'.($event['entity_id'] ?? '')] ?? null;
            if (! $record || $record['entity_type'] === 'request') {
                continue;
            }
            if (++$scanned > self::NATIVE_SCAN) {
                break;
            }
            if (! in_array($event['kind'] ?? '', self::NATIVE_ACTIONS, true) || ! is_string($event['at'] ?? null)) {
                continue;
            }
            $at = CarbonImmutable::parse($event['at'])->utc();
            if ($at->isBefore($start) || ! $at->isBefore($exclusive) || $at->isAfter($end)) {
                continue;
            }
            $events[] = $this->event($record, $event['kind'], $at->toIso8601String(), null);
        }
        $ids = array_values(array_map(fn ($row) => $row['id'], array_filter($available, fn ($row) => $row['entity_type'] === 'request')));
        $revisions = 0;
        if ($ids !== [] && ! $end->isBefore($start)) {
            foreach (DB::table('team_operation_revisions')->where('tenant_id', $tenant->id)->whereIn('record_id', $ids)
                ->where('created_at', '>=', $start->format('Y-m-d H:i:s'))->where('created_at', '<', $exclusive->format('Y-m-d H:i:s'))
                ->where('created_at', '<=', $end->format('Y-m-d H:i:s'))
                ->orderByDesc('created_at')->orderByDesc('id')->limit(2000)->cursor() as $row) {
                $revisions++;
                $payload = json_decode(Crypt::decryptString($row->payload), true, 64, JSON_THROW_ON_ERROR);
                if (in_array($payload['action'] ?? '', self::REQUEST_ACTIONS, true)) {
                    $events[] = $this->event($available['request:'.$row->record_id], $payload['action'], CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(), (int) $row->version);
                }
            }
        }
        usort($events, fn ($a, $b) => strcmp($b['recorded_at'], $a['recorded_at']) ?: [$a['entity_type'], $a['id'], $a['action']] <=> [$b['entity_type'], $b['id'], $b['action']]);

        return [array_slice($events, 0, self::EVENT_LIMIT), ['returned' => min(count($events), self::EVENT_LIMIT), 'matching_in_checked_sources' => count($events),
            'limit' => self::EVENT_LIMIT, 'omitted_matching_events' => max(0, count($events) - self::EVENT_LIMIT),
            'native_scan_limit' => self::NATIVE_SCAN, 'native_scan_limit_reached' => $scanned > self::NATIVE_SCAN,
            'request_revision_scan_limit' => 2000, 'request_revision_scan_limit_reached' => $revisions === 2000,
            'notice' => 'Latest 10,000 native actions for included records checked; up to 2,000 request revisions in the selected window. No raw event payload, old text, actor directory or field changes are exported.']];
    }

    private function event(array $record, string $action, string $at, ?int $version): array
    {
        return ['entity_type' => $record['entity_type'], 'id' => $record['id'], 'current_title' => $record['title'], 'action' => $action,
            'recorded_at' => $at, 'version' => $version, 'source_url' => $record['source_url']];
    }

    public function csv(array $report): string
    {
        $columns = ['row_type', 'entity_type', 'id', 'title', 'status', 'owner_id', 'due_on', 'updated_at', 'version', 'recorded_at', 'action', 'source_url',
            'generated_at_utc', 'window_start_utc', 'window_end_utc_exclusive', 'observed_through_utc', 'timezone', 'sample_data', 'coverage'];
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $columns, ',', '"', '');
        $write = function (string $type, array $row) use ($stream, $columns, $report): void {
            $values = ['row_type' => $type, ...$row, 'generated_at_utc' => $report['generated_at_utc'], 'window_start_utc' => $report['window']['start_utc'],
                'window_end_utc_exclusive' => $report['window']['end_utc_exclusive'], 'observed_through_utc' => $report['window']['observed_through_utc'],
                'timezone' => $report['window']['timezone'], 'sample_data' => $report['sample_data'] ? 'SAMPLE DATA' : 'Current authorized records',
                'coverage' => json_encode($report['coverage'], JSON_THROW_ON_ERROR).' '.$report['basis']['current_state'].' '.$report['basis']['recorded_events']];
            fputcsv($stream, array_map(fn ($key) => $this->cell((string) ($values[$key] ?? '')), $columns), ',', '"', '');
        };
        $write('metadata', []);
        foreach ($report['current_records'] as $row) {
            $write('current_record', $row);
        }
        foreach ($report['recorded_events'] as $row) {
            $row['title'] = $row['current_title'];
            $write('recorded_event', $row);
        }
        rewind($stream);
        $result = stream_get_contents($stream);
        fclose($stream);

        return $result;
    }

    private function cell(string $value): string
    {
        return preg_match('/^[\p{Z}\p{C}\s]*[=+\-@]/u', $value) || preg_match('/^[\t\r\n]/', $value) ? "'".$value : $value;
    }
}
