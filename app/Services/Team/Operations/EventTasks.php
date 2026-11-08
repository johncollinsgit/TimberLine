<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Asana owns these tasks. This service never copies them into the native graph. */
class EventTasks
{
    public const FIELDS = 'gid,name,notes,completed,due_on,start_on,due_at,start_at,modified_at,assignee.gid,assignee.name,workspace.gid,memberships.project.gid,memberships.project.name,permalink_url,num_subtasks,parent.gid';

    public function __construct(private OperationsAccess $access, private OperationsProviders $providers) {}

    public function connections(Tenant $tenant, User $user, TeamArea $area): array
    {
        $this->access->area($tenant, $user, $area->id);
        abort_unless($area->profile === 'events', 404);

        return TeamProviderConnection::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $user->id, 'provider' => 'asana', 'status' => 'connected'])
            ->orderBy('id')->get()->filter(fn ($c) => ctype_digit((string) ($c->settings['external_user_id'] ?? '')) && ctype_digit((string) ($c->settings['source']['asana_workspace'] ?? '')) && $this->providers->selectedProjects($c))
            ->values()->all();
    }

    private function connection(Tenant $tenant, User $user, TeamArea $area, string $id): TeamProviderConnection
    {
        $connection = collect($this->connections($tenant, $user, $area))->firstWhere('id', $id);
        abort_unless($connection, 403, 'Connect your own Asana account and select the event projects.');

        return $connection;
    }

    public function listing(Tenant $tenant, User $user, TeamArea $area, ?string $id, ?string $cursor): array
    {
        $connections = $this->connections($tenant, $user, $area);
        $accounts = array_map(fn ($c) => ['id' => $c->id, 'name' => $c->account_name], $connections);
        if (! $connections) {
            return ['items' => [], 'accounts' => [], 'has_more' => false, 'notice' => 'Connect your Asana account in Events connections and select your event projects.'];
        }
        $c = $id ? $this->connection($tenant, $user, $area, $id) : $connections[0];
        $offset = null;
        $scope = [$tenant->id, $user->id, $area->id, $c->id, $c->generation, $this->providers->selectedProjects($c)];
        if ($cursor) {
            try {
                $page = Crypt::decrypt($cursor);
            } catch (\Throwable) {
                abort(422, 'Invalid event page.');
            }
            abort_unless(($page['scope'] ?? null) === $scope && ($page['expires'] ?? 0) > time(), 422, 'The account or project selection changed. Refresh My Tasks.');
            $offset = $page['offset'];
        }
        $response = $this->providers->asana($c, 'tasks', ['assignee' => $c->settings['external_user_id'], 'workspace' => $c->settings['source']['asana_workspace'], 'completed_since' => now()->toIso8601String(), 'limit' => 100, 'offset' => $offset, 'opt_fields' => self::FIELDS]);
        $items = [];
        $canWrite = $this->access->mayWrite($tenant, $user, $area->id);
        foreach ($response['data'] ?? [] as $task) {
            if ((string) ($task['assignee']['gid'] ?? '') !== (string) $c->settings['external_user_id'] || ! $this->project($c, $task)) {
                continue;
            }
            $view = $this->present($c, $task);
            $view['can_write'] = $view['can_write'] && $canWrite;
            $view['can_comment'] = $view['can_comment'] && $canWrite;
            $items[(string) $task['gid']] = $view;
        }
        $next = $response['next_page']['offset'] ?? null;

        $pending = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'area_id' => $area->id, 'connection_id' => $c->id, 'kind' => 'asana.update'])->whereIn('status', ['executing', 'unknown'])->latest()->limit(50)->get()->map(fn ($attempt) => ['id' => $attempt->id, 'task_id' => $attempt->payload['task_id'], 'status' => $attempt->status])->all();

        return ['items' => array_values($items), 'accounts' => $accounts, 'connection_id' => $c->id, 'pending_updates' => $pending, 'has_more' => (bool) $next,
            'next_cursor' => $next ? Crypt::encrypt(['scope' => $scope, 'offset' => $next, 'expires' => time() + 600]) : null,
            'notice' => ($c->settings['read_only_snapshot'] ?? false) ? 'Saved Asana snapshot · '.($c->settings['captured_at'] ?? '').' · Connect Asana for live refresh and changes.' : 'Assigned to your connected Asana identity. Asana remains the source of truth.',
            'fetched_at' => now()->toIso8601String()];
    }

    private function project(TeamProviderConnection $c, array $task): ?string
    {
        if ((string) ($task['workspace']['gid'] ?? '') !== (string) $c->settings['source']['asana_workspace']) {
            return null;
        }
        $projects = collect($task['memberships'] ?? [])->pluck('project.gid')->map(fn ($id) => (string) $id)->all();

        return array_values(array_intersect($this->providers->selectedProjects($c), $projects))[0] ?? null;
    }

    private function read(TeamProviderConnection $c, string $gid, ?string $parent = null): array
    {
        abort_unless(ctype_digit($gid), 422);
        $task = $this->providers->asana($c, 'tasks/'.$gid, ['opt_fields' => self::FIELDS])['data'] ?? [];
        abort_unless((string) ($task['gid'] ?? '') === $gid, 403);
        if (! $this->project($c, $task) && $parent && ctype_digit($parent)) {
            $parentTask = $this->read($c, $parent);
            abort_unless((string) ($task['parent']['gid'] ?? '') === $parent && (string) ($task['workspace']['gid'] ?? '') === (string) $c->settings['source']['asana_workspace'], 403);
            $task['_verified_parent_project'] = $this->project($c, $parentTask);
            $task['_verified_parent'] = $parent;
        }
        abort_unless($this->project($c, $task) || isset($task['_verified_parent_project']), 403, 'This task is outside your selected event projects.');

        return $task;
    }

    private function present(TeamProviderConnection $c, array $task): array
    {
        return ['date_evidence' => app(EventSchedule::class)->dateEvidence($task, $this->version($task)), 'event_fields' => app(OperationsSync::class)->extract($task['notes'] ?? ''), 'event_schedule' => app(EventSchedule::class)->source($task), 'memberships' => array_values(array_filter($task['memberships'] ?? [], fn ($m) => in_array((string) ($m['project']['gid'] ?? ''), $this->providers->selectedProjects($c), true))), 'gid' => (string) $task['gid'], 'name' => $task['name'] ?? '', 'notes' => $task['notes'] ?? '', 'completed' => (bool) ($task['completed'] ?? false),
            'due_on' => $task['due_on'] ?? null, 'start_on' => $task['start_on'] ?? null, 'due_at' => $task['due_at'] ?? null, 'start_at' => $task['start_at'] ?? null, 'assignee' => $task['assignee'] ?? null,
            'modified_at' => $task['modified_at'] ?? null, 'source_url' => 'https://app.asana.com/0/'.($this->project($c, $task) ?? $task['_verified_parent_project'] ?? '0').'/'.$task['gid'],
            'connection_id' => $c->id, 'parent_id' => $task['_verified_parent'] ?? null, 'version' => $this->version($task), 'can_write' => $this->writable($c),
            'can_comment' => $this->writable($c) && in_array('stories:write', preg_split('/\s+/', $c->settings['scopes'] ?? ''), true), 'source_mode' => ($c->settings['read_only_snapshot'] ?? false) ? 'snapshot' : 'live'];
    }

    public function forOccurrence(Tenant $tenant, User $user, TeamOperationRecord $record): ?array
    {
        $area = $this->access->area($tenant, $user, $record->area_id);
        if ($area->profile !== 'events' || ! isset($record->data['source_workspace'], $record->data['source_id'])) {
            return null;
        }
        $projects = array_filter([$record->data['source_project'] ?? null, ...($record->data['source_projects'] ?? [])]);
        foreach ($this->connections($tenant, $user, $area) as $connection) {
            if ((string) $connection->settings['source']['asana_workspace'] === (string) $record->data['source_workspace'] && array_intersect($projects, $this->providers->selectedProjects($connection))) {
                return $this->detail($tenant, $user, $area, $connection->id, $record->data['source_id']);
            }
        }

        return null;
    }

    private function version(array $task): string
    {
        $projects = collect($task['memberships'] ?? [])->pluck('project.gid')->map(fn ($id) => (string) $id)->sort()->values()->all();

        return hash('sha256', json_encode([(string) $task['gid'], $task['modified_at'] ?? null, $task['name'] ?? '', $task['notes'] ?? '',
            $task['due_on'] ?? null, $task['start_on'] ?? null, $task['due_at'] ?? null, $task['start_at'] ?? null, (bool) ($task['completed'] ?? false), $task['assignee']['gid'] ?? null,
            $task['workspace']['gid'] ?? null, $task['parent']['gid'] ?? null, $projects], JSON_THROW_ON_ERROR));
    }

    public function calendarEvidence(Tenant $tenant, User $user, TeamOperationRecord $record, array $selections): array
    {
        $detail = $this->forOccurrence($tenant, $user, $record);
        abort_unless($detail && $detail['source_mode'] === 'live', 409, 'A current connected Asana read is required to verify dates; saved documents can be staff-attested instead.');
        $candidates = $detail['date_evidence']['candidates'];
        $cursors = array_unique(array_map(fn ($selection) => $selection['comment_cursor'] ?? '', $selections));
        // The ordinary task page covers name/notes. Read a selected comment page
        // only when its candidate was not present there; no unbounded history scan.
        $missing = array_diff(array_column($selections, 'evidence_id'), array_column($candidates, 'id'));
        if ($missing) {
            abort_if(count($cursors) > 5, 422, 'Review at most five comment pages in one calendar edit.');
            $area = $this->access->area($tenant, $user, $record->area_id);
            foreach ($cursors as $cursor) {
                $page = $this->detail($tenant, $user, $area, $detail['connection_id'], $detail['gid'], 'stories', $cursor ?: null);
                $candidates = [...$candidates, ...($page['date_evidence']['candidates'] ?? [])];
            }
        }

        return ['candidates' => $candidates, 'source_url' => $detail['source_url'], 'source_mode' => $detail['source_mode'], 'issues' => $detail['date_evidence']['issues']];
    }

    private function writable(TeamProviderConnection $c): bool
    {
        return (bool) config('team_operations.asana_write_enabled') && ! ($c->settings['read_only_snapshot'] ?? false)
            && ($c->settings['send_authorized'] ?? false) && in_array('tasks:write', preg_split('/\s+/', $c->settings['scopes'] ?? ''), true);
    }

    public function detail(Tenant $tenant, User $user, TeamArea $area, string $connection, string $gid, string $kind = 'task', ?string $cursor = null, ?string $parent = null): array
    {
        $c = $this->connection($tenant, $user, $area, $connection);
        $task = $this->read($c, $gid, $parent);
        if ($kind === 'task') {
            $view = $this->present($c, $task);
            $view['can_write'] = $view['can_write'] && $this->access->mayWrite($tenant, $user, $area->id);
            $view['can_comment'] = $view['can_comment'] && $view['can_write'];
            $record = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'source_key' => hash('sha256', 'asana:'.$c->settings['source']['asana_workspace'].':'.$gid)])->first();
            $view['occurrence'] = null;
            if ($record) {
                try {
                    $this->access->record($tenant, $user, $record);
                    $view['occurrence'] = app(OperationsWorkspace::class)->view($record, $this->access->mayWrite($tenant, $user, $area->id), $this->access->admin($this->access->actor($tenant, $user)));
                    $calendar = &$view['occurrence']['data']['event_schedule'];
                    if (isset($calendar['provenance'])) {
                        $changed = ($calendar['provenance']['source_version'] ?? null) !== ($task['modified_at'] ?? null);
                        $calendar['provenance']['fresh_source_task_version'] = $view['version'];
                        $calendar['provenance']['fresh_source_modified_at'] = $task['modified_at'] ?? null;
                        $calendar['provenance']['source_changed_since_review'] = $calendar['provenance']['source_changed_since_review'] || $changed;
                        $calendar['needs_review'] = $calendar['needs_review'] || $changed;
                    }
                    foreach ($calendar['session_evidence'] as &$evidence) {
                        if (($evidence['status'] ?? '') === 'source_verified_at_review' && ($evidence['source_version'] ?? '') !== $view['version']) {
                            $evidence['stale'] = true;
                            $calendar['needs_review'] = true;
                        }
                    }
                    unset($evidence, $calendar);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                    if (! in_array($error->getStatusCode(), [403, 404, 409], true)) {
                        throw $error;
                    }
                }
            }

            return $view;
        }
        abort_unless(in_array($kind, ['subtasks', 'stories', 'attachments'], true), 404);
        $scope = [$c->id, $c->generation, $gid, $kind];
        $offset = null;
        if ($cursor) {
            try {
                $page = Crypt::decrypt($cursor);
            } catch (\Throwable) {
                abort(422);
            }
            abort_unless(($page['scope'] ?? null) === $scope && ($page['expires'] ?? 0) > time(), 422);
            $offset = $page['offset'];
        }
        $fields = match ($kind) {
            'subtasks' => 'gid,name,notes,completed,due_on,assignee.name',
            'stories' => 'gid,text,created_at,created_by.name,resource_subtype',
            default => 'gid,name,permanent_url,resource_subtype',
        };
        $response = $this->providers->asana($c, $kind === 'attachments' ? 'attachments' : 'tasks/'.$gid.'/'.$kind, ['limit' => 100, 'offset' => $offset, 'opt_fields' => $fields, ...($kind === 'attachments' ? ['parent' => $gid] : [])]);
        $next = $response['next_page']['offset'] ?? null;

        return ['items' => $kind === 'attachments' ? array_map(fn ($file) => array_intersect_key($file, array_flip(['gid', 'name', 'permanent_url', 'resource_subtype'])), $response['data'] ?? []) : ($response['data'] ?? []), 'has_more' => (bool) $next,
            'next_cursor' => $next ? Crypt::encrypt(['scope' => $scope, 'offset' => $next, 'expires' => time() + 600]) : null, 'date_evidence' => $kind === 'stories' ? app(EventSchedule::class)->dateEvidence($task, $this->version($task), $response['data'] ?? []) : null];
    }

    public function prepare(Tenant $tenant, User $user, TeamArea $area, array $input): array
    {
        $this->access->area($tenant, $user, $area->id, true);
        $c = $this->connection($tenant, $user, $area, $input['connection_id']);
        abort_unless($this->writable($c), 409, 'Authorize Asana task editing before saving changes.');
        $changes = $input['changes'];
        abort_unless($changes && array_diff(array_keys($changes), ['name', 'due_on', 'start_on', 'completed', 'notes', 'assignee', 'comment']) === [], 422, 'Use supported source fields; note edits must include their original hash.');
        if (array_key_exists('notes', $changes)) {
            $changes['notes'] ??= '';
        }
        abort_if(isset($changes['comment']) && (count($changes) !== 1 || ! in_array('stories:write', preg_split('/\s+/', $c->settings['scopes'] ?? ''), true)), 422, 'Authorize comment posting and send comments separately.');
        ksort($changes);
        $hash = hash('sha256', json_encode([$area->id, $c->id, $c->generation, $input['task_id'], $input['parent_id'] ?? null, $input['version'], $changes], JSON_THROW_ON_ERROR));
        $existing = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
        if ($existing) {
            abort_unless($existing->kind === 'asana.update' && hash_equals($existing->input_hash, $hash), 409, 'This request already belongs to different changes.');

            return $this->receipt($existing);
        }
        $task = $this->read($c, $input['task_id'], $input['parent_id'] ?? null);
        abort_unless(hash_equals($this->present($c, $task)['version'], $input['version']), 409, 'The Asana task changed. Refresh and review the latest version.');
        if (array_key_exists('notes', $changes)) {
            abort_unless(is_string($input['notes_base_hash'] ?? null) && hash_equals(hash('sha256', $task['notes'] ?? ''), $input['notes_base_hash']), 409, 'Original notes changed. Review their complete current text.');
        }
        if (isset($changes['assignee'])) {
            $person = $this->providers->asana($c, 'users/'.$changes['assignee'], ['opt_fields' => 'gid,workspaces.gid'])['data'] ?? [];
            abort_unless((string) ($person['gid'] ?? '') === $changes['assignee'] && in_array((string) $c->settings['source']['asana_workspace'], array_map('strval', array_column($person['workspaces'] ?? [], 'gid')), true), 422, 'Choose a current user in this source workspace.');
        }
        abort_if((! empty($task['due_at']) || ! empty($task['start_at'])) && array_intersect(array_keys($changes), ['due_on', 'start_on']), 422, 'Edit timed schedules in Asana to preserve their times. Titles and completion can still be changed here.');
        abort_if(TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'area_id' => $area->id, 'connection_id' => $c->id, 'kind' => 'asana.update'])->whereIn('status', ['executing', 'unknown'])->get()->contains(fn ($a) => ($a->payload['task_id'] ?? '') === $input['task_id']), 409, 'A previous save needs review in Asana before another change.');
        if (isset($changes['start_on'])) {
            abort_unless(array_key_exists('due_on', $changes), 422, 'Review the start and due date together.');
        }
        $dates = array_replace($task, $changes);
        abort_if(($dates['start_on'] ?? null) && (! ($dates['due_on'] ?? null) || $dates['start_on'] > $dates['due_on']), 422, 'The due date must be on or after the start date.');
        $attempt = TeamOperationAttempt::firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']], ['id' => (string) Str::uuid(), 'area_id' => $area->id,
            'connection_id' => $c->id, 'request_id' => $input['request_id'], 'kind' => 'asana.update', 'input_hash' => $hash, 'status' => 'prepared',
            'payload' => ['task_id' => $input['task_id'], 'parent_id' => $input['parent_id'] ?? null, 'connection_generation' => $c->generation, 'version' => $input['version'], 'changes' => $changes,
                'before' => array_intersect_key($task, $changes)]]);
        abort_unless($attempt->kind === 'asana.update' && hash_equals($attempt->input_hash, $hash), 409, 'This request already belongs to different changes.');

        return $this->receipt($attempt);
    }

    private function attempt(Tenant $tenant, User $user, TeamArea $area, string $id): TeamOperationAttempt
    {
        $this->access->area($tenant, $user, $area->id, true);

        return TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'area_id' => $area->id, 'kind' => 'asana.update'])->findOrFail($id);
    }

    public function dispatch(Tenant $tenant, User $user, TeamArea $area, string $id): array
    {
        $attempt = $this->attempt($tenant, $user, $area, $id);
        $c = $this->connection($tenant, $user, $area, $attempt->connection_id);
        abort_unless($this->writable($c) && $c->generation === $attempt->payload['connection_generation'], 409, 'The connection or its write permission changed.');
        // Serialize this app's saves across accounts viewing the same source task.
        // Asana users can still edit concurrently; the provider offers no atomic CAS here.
        $lock = Cache::lock('team-event-save:'.hash('sha256', $tenant->id.':'.$c->settings['source']['asana_workspace'].':'.$attempt->payload['task_id']), 180);
        abort_unless($lock->get(), 409, 'Another save is in progress. Refresh before continuing.');
        try {
            return $this->dispatchLocked($tenant, $area, $c, $attempt->fresh());
        } finally {
            $lock->release();
        }
    }

    private function dispatchLocked(Tenant $tenant, TeamArea $area, TeamProviderConnection $c, TeamOperationAttempt $attempt): array
    {
        $id = $attempt->id;
        if ($attempt->status !== 'prepared') {
            return $this->receipt($attempt);
        }
        abort_if(TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'kind' => 'asana.update'])->whereKeyNot($id)->whereIn('status', ['executing', 'unknown'])->get()->contains(fn ($a) => ($a->payload['task_id'] ?? '') === $attempt->payload['task_id']), 409, 'An earlier save for this task needs review before another change.');
        $task = $this->read($c, $attempt->payload['task_id'], $attempt->payload['parent_id'] ?? null);
        abort_unless(hash_equals($this->present($c, $task)['version'], $attempt->payload['version']), 409, 'Asana changed after review. Refresh before saving.');
        $claimed = TeamOperationAttempt::whereKey($id)->where('status', 'prepared')->update(['status' => 'executing', 'updated_at' => now()]);
        if (! $claimed) {
            return $this->receipt($attempt->fresh());
        }
        try {
            // No automatic provider retry. Only the explicitly reviewed fields are sent.
            $comment = $attempt->payload['changes']['comment'] ?? null;
            if ($comment !== null) {
                abort_unless(in_array('stories:write', preg_split('/\s+/', $c->settings['scopes'] ?? ''), true), 403);
                $response = $this->providers->http($c, 'POST', 'https://app.asana.com/api/1.0/tasks/'.$task['gid'].'/stories', ['data' => ['text' => $comment]]);
                $accepted = $response['data'] ?? [];
                abort_unless(ctype_digit((string) ($accepted['gid'] ?? '')) && ($accepted['text'] ?? null) === $comment, 502);
            } else {
                $response = $this->providers->http($c, 'PUT', 'https://app.asana.com/api/1.0/tasks/'.$task['gid'], ['data' => $attempt->payload['changes']]);
                $accepted = $response['data'] ?? [];
                abort_unless((string) ($accepted['gid'] ?? '') === (string) $task['gid'] && $this->matches($accepted, $attempt->payload['changes']), 502);
            }
            $attempt->update(['status' => 'succeeded', 'result' => ['accepted_at' => now()->toIso8601String(), 'task_id' => $task['gid'], 'resource_id' => $accepted['gid']]]);
        } catch (\Throwable $error) {
            $attempt->update(['status' => 'unknown', 'result' => ['notice' => 'The save could not be confirmed. Check Asana before making another change.']]);
        }

        return $this->receipt($attempt->fresh());
    }

    private function matches(array $task, array $changes): bool
    {
        foreach ($changes as $key => $value) {
            if ($key === 'assignee') {
                if (($task['assignee']['gid'] ?? null) !== $value) {
                    return false;
                }

                continue;
            }
            if (! array_key_exists($key, $task) || $task[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    public function reconcile(Tenant $tenant, User $user, TeamArea $area, string $id, bool $acknowledge = false): array
    {
        $attempt = $this->attempt($tenant, $user, $area, $id);
        $c = $this->connection($tenant, $user, $area, $attempt->connection_id);
        abort_unless($c->generation === $attempt->payload['connection_generation'], 409, 'The account changed. Review this attempt with the original account.');
        $task = $this->read($c, $attempt->payload['task_id'], $attempt->payload['parent_id'] ?? null);
        if (in_array($attempt->status, ['unknown', 'executing'], true)) {
            // A still-running request must not be acknowledged while it could finish.
            abort_if($acknowledge && $attempt->status === 'executing' && $attempt->updated_at->isAfter(now()->subMinutes(2)), 409, 'The original save may still be running. Wait before closing this review.');
            $attempt->update(['result' => ['observed_at' => now()->toIso8601String(), 'current_values_match' => $this->matches($task, $attempt->payload['changes']),
                'observed_values' => array_intersect_key($task, $attempt->payload['changes']), 'acknowledged_by' => $acknowledge ? $user->id : null,
                'notice' => 'Read-only check. Matching values show the current Asana state, not proof of which request made the change. No write was retried.'],
                ...($acknowledge ? ['status' => 'reviewed'] : [])]);
        }

        return $this->receipt($attempt->fresh());
    }

    private function receipt(TeamOperationAttempt $attempt): array
    {
        return ['id' => $attempt->id, 'status' => $attempt->status, 'changes' => $attempt->payload['changes'], 'before' => $attempt->payload['before'], 'result' => $attempt->result];
    }
}
