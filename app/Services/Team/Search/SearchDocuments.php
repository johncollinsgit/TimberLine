<?php

namespace App\Services\Team\Search;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\TeamSourceSnapshot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Operations\OperationsWorkspace;
use App\Services\Team\WorkGraph;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Index projections never grant access. Display projections always use current policy. */
class SearchDocuments
{
    public function __construct(private SearchIndex $index, private WorkGraph $graph) {}

    private function text(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (! is_array($value)) {
            return '';
        }

        return implode(' ', array_map($this->text(...), $value));
    }

    public function native(string $kind, array $record, array $projects = []): array
    {
        $body = $record['description'] ?? '';
        foreach ($record['comments'] ?? [] as $comment) {
            if (empty($comment['deleted_at'])) {
                $body .= ' '.($comment['body'] ?? '');
            }
        }
        $body .= ' '.$this->text($record['custom_fields'] ?? []);
        $body .= ' '.$this->text(array_intersect_key($record['current_status'] ?? [], array_flip(['summary', 'code', 'next_steps'])));
        $memberships = array_keys($record['memberships'] ?? []);
        $context = implode(', ', array_values(array_filter(array_map(fn ($id) => $projects[$id]['name'] ?? null, $memberships))));

        return ['id' => $kind.':'.$record['id'], 'type' => $kind, 'entity_id' => $record['id'], 'identifier' => $record['id'],
            'title' => $record['title'] ?? $record['name'], 'body' => trim($body), 'context' => $context,
            'projects' => $kind === 'project' ? [$record['id']] : $memberships,
            'people' => array_values(array_filter([$record['assignee_id'] ?? null, $record['owner_id'] ?? null, $record['creator_id'] ?? null])),
            'completed' => ! empty($record['completed_at']), 'archived' => (bool) ($record['archived'] ?? false),
            'status' => ! empty($record['completed_at']) ? 'Completed' : (! empty($record['archived']) ? 'Archived' : ($record['current_status']['code'] ?? 'Active')),
            'updated_at' => $record['updated_at'] ?? '', 'source' => 'Everbranch', 'target' => ['kind' => $kind, 'id' => $record['id']]];
    }

    public function graph(int $tenantId, array $before, array $after): void
    {
        if (! $this->index->available()) {
            return;
        }
        foreach (['project' => 'projects', 'task' => 'tasks'] as $kind => $key) {
            foreach ($after[$key] as $id => $record) {
                if (($before[$key][$id] ?? null) === $record) {
                    continue;
                }
                if (! empty($record['deleted'])) {
                    $this->index->remove($tenantId, $kind, $id);
                } else {
                    $this->index->put($tenantId, $kind, $id, $this->native($kind, $record));
                }
                if ($kind === 'task') {
                    foreach (DB::table('team_file_uploads')->where('tenant_id', $tenantId)->whereIn('id', $record['attachment_ids'] ?? [])->get() as $file) {
                        $this->index->put($tenantId, 'file', $file->id, $this->file($file));
                    }
                }
                if ($kind === 'project' && isset($record['current_status']) && ($before[$key][$id]['current_status']['id'] ?? null) !== $record['current_status']['id']) {
                    $this->index->put($tenantId, 'update', $id.':'.$record['current_status']['project_version'], $this->update($record, $record['current_status']));
                }
            }
        }
    }

    public function operation(TeamOperationRecord $record, bool $financial = false): array
    {
        $view = app(OperationsWorkspace::class)->view($record, false, $financial);
        // Search only fields the existing record presentation discloses; never raw evidence.
        $allowed = array_intersect_key($view['data'], array_flip(['description', 'notes', 'original_notes', 'fields', 'body', 'subject', 'filename', 'location', 'address', 'next_step', 'stage', 'name', 'summary']));
        $connection = $record->connection_id ? TeamProviderConnection::find($record->connection_id) : null;

        return ['id' => 'operation:'.$record->id, 'type' => $record->kind, 'entity_id' => $record->id,
            'title' => $record->title, 'identifier' => $record->id, 'body' => $this->text($allowed),
            'projects' => array_values(array_filter([$record->area_id, $record->data['native_project_id'] ?? null])),
            'people' => array_values(array_filter([(string) $record->assigned_user_id])),
            'context' => TeamArea::find($record->area_id)?->name ?? '', 'status' => $record->status,
            'completed' => in_array($record->status, ['completed', 'cancelled', 'lost'], true), 'archived' => $record->status === 'archived',
            'updated_at' => $record->updated_at?->toIso8601String(), 'source' => $connection ? ucfirst($connection->provider) : 'Everbranch',
            'synced_at' => $connection?->last_success_at?->toIso8601String(),
            'target' => ['kind' => 'operation', 'id' => $record->id, 'area' => $record->area_id]];
    }

    public function indexOperation(TeamOperationRecord $record): void
    {
        if (! $this->index->available()) {
            return;
        }
        if ($record->status === 'withdrawn') {
            $this->index->remove($record->tenant_id, 'operation', $record->id);
        } else {
            $this->index->put($record->tenant_id, 'operation', $record->id, $this->operation($record, true));
        }
    }

    public function person(User $user): array
    {
        return ['id' => 'person:'.$user->id, 'type' => 'person', 'entity_id' => (string) $user->id, 'title' => $user->name,
            'identifier' => (string) $user->id, 'body' => '', 'context' => 'Workspace member', 'source' => 'Everbranch',
            'people' => [(string) $user->id], 'target' => ['kind' => 'person', 'id' => (string) $user->id]];
    }

    public function snapshot(TeamSourceSnapshot $snapshot): ?array
    {
        $payload = $snapshot->payload;
        // Asana/Sheets snapshots are raw evidence; their current canonical records are indexed above.
        // Only retained Gmail messages with a current read attestation enter this additional lane.
        $connection = TeamProviderConnection::find($snapshot->connection_id);
        if (! $connection || $connection->provider !== 'gmail' || ! isset($payload['id'], $payload['payload']) || ! $snapshot->search_generation) {
            return null;
        }
        $headers = [];
        foreach ($payload['payload']['headers'] ?? [] as $header) {
            $headers[strtolower($header['name'] ?? '')] = $header['value'] ?? '';
        }
        $parts = function (array $part) use (&$parts): string {
            $text = '';
            if (($part['mimeType'] ?? '') === 'text/plain' && empty($part['filename']) && isset($part['body']['data'])) {
                $text = base64_decode(strtr($part['body']['data'], '-_', '+/'), true) ?: '';
            }
            foreach ($part['parts'] ?? [] as $child) {
                $text .= ' '.$parts($child);
            }

            return $text;
        };
        $ref = $snapshot->connection_id.':'.$snapshot->resource_key;

        return ['id' => 'source:'.$ref, 'type' => 'message', 'identifier' => (string) $payload['id'],
            'title' => $headers['subject'] ?? 'Saved email', 'body' => trim(($headers['from'] ?? '').' '.($payload['snippet'] ?? '').' '.$parts($payload['payload'])),
            'projects' => [$connection->area_id], 'people' => [(string) $connection->user_id],
            'context' => TeamArea::find($connection->area_id)?->name ?? '', 'source' => 'Gmail', 'status' => 'Saved copy',
            'synced_at' => ($snapshot->search_observed_at ?? $snapshot->observed_at)?->toIso8601String(), 'updated_at' => ($snapshot->search_observed_at ?? $snapshot->observed_at)?->toIso8601String(),
            'target' => ['kind' => 'saved-message', 'id' => $ref]];
    }

    public function indexSnapshot(TeamSourceSnapshot $snapshot): void
    {
        if (! $this->index->available() || ! $snapshot->search_generation) {
            return;
        }
        $snapshot = TeamSourceSnapshot::where(['tenant_id' => $snapshot->tenant_id, 'connection_id' => $snapshot->connection_id, 'resource_key' => $snapshot->resource_key, 'search_generation' => $snapshot->search_generation])
            ->orderByDesc('search_observed_at')->orderByDesc('id')->first();
        if ($snapshot && ($document = $this->snapshot($snapshot))) {
            $this->index->put($snapshot->tenant_id, 'source', $snapshot->connection_id.':'.$snapshot->resource_key, $document);
        }
    }

    public function update(array $project, array $status): array
    {
        return ['id' => 'update:'.$project['id'].':'.$status['project_version'], 'type' => 'update', 'title' => Str::limit($status['summary'], 140),
            'identifier' => $status['id'], 'body' => $this->text(array_intersect_key($status, array_flip(['summary', 'highlights', 'blockers', 'next_steps']))),
            'projects' => [$project['id']], 'people' => [$status['author_id']], 'context' => $project['name'], 'status' => $status['code'],
            'source' => 'Everbranch', 'updated_at' => $status['created_at'], 'archived' => (bool) $project['archived'],
            'target' => ['kind' => 'project', 'id' => $project['id'], 'update' => $status['id']]];
    }

    /** Narrow saved-source access: current owner, generation and selection, no provider transport. */
    private function savedOperation(Tenant $tenant, User $user, TeamOperationRecord $record, array $actor, array $state): bool
    {
        if (! $record->connection_id) {
            app(OperationsAccess::class)->record($tenant, $user, $record);

            return true;
        }
        $access = app(OperationsAccess::class);
        $access->area($tenant, $user, $record->area_id);
        if ($record->status === 'withdrawn') {
            return false;
        }
        if (in_array($record->kind, ['financial', 'staff'], true) || $record->visibility === 'admin') {
            $access->financial($tenant, $user);
        }
        if ($record->visibility === 'assigned' && ! $access->admin($actor) && (int) $record->assigned_user_id !== $user->id) {
            return false;
        }
        $connection = $access->connection($tenant, $user, $record->connection_id);
        if ($connection->status !== 'connected' || ($record->data['_generation'] ?? null) !== $connection->generation) {
            return false;
        }
        if ($connection->provider === 'sheets') {
            $access->financial($tenant, $user);
        }
        if ($connection->provider === 'asana') {
            $source = $connection->settings['source'] ?? [];
            $selected = array_map('strval', [$source['asana_project'] ?? '', ...($source['asana_projects'] ?? [])]);
            $projects = array_map('strval', [$record->data['source_project'] ?? '', ...($record->data['source_projects'] ?? [])]);
            if (! array_intersect(array_filter($selected), array_filter($projects)) || ($source['asana_workspace'] ?? '') !== ($record->data['source_workspace'] ?? '')) {
                return false;
            }
        }
        if ($project = ($record->data['native_project_id'] ?? null)) {
            return $this->graph->can($state, $actor, 'project', $project);
        }

        return true;
    }

    public function resolve(Tenant $tenant, User $user, array $actor, array $state, string $kind, string $id): ?array
    {
        try {
            if (in_array($kind, ['task', 'project'], true)) {
                $projection = $this->graph->searchProjection($state, $actor, $kind, $id);
                if (! $projection) {
                    return null;
                }

                return $this->native($kind, $projection, $state['projects']);
            }
            if ($kind === 'operation') {
                $record = TeamOperationRecord::where('tenant_id', $tenant->id)->find($id);
                if (! $record || ! $this->savedOperation($tenant, $user, $record, $actor, $state)) {
                    return null;
                }

                return $this->operation($record, in_array($actor['role'], ['owner', 'admin'], true));
            }
            if ($kind === 'source') {
                [$connectionId, $key] = array_pad(explode(':', $id, 2), 2, '');
                $connection = app(OperationsAccess::class)->connection($tenant, $user, $connectionId);
                if ($connection->status !== 'connected' || $connection->provider !== 'gmail') {
                    return null;
                }
                $snapshot = TeamSourceSnapshot::where(['tenant_id' => $tenant->id, 'connection_id' => $connection->id, 'resource_key' => $key, 'search_generation' => $connection->generation])
                    ->orderByDesc('search_observed_at')->orderByDesc('id')->first();

                return $snapshot ? $this->snapshot($snapshot) : null;
            }
            if ($kind === 'update') {
                [$projectId, $version] = array_pad(explode(':', $id, 2), 2, '');
                if (! $this->graph->can($state, $actor, 'project', $projectId)) {
                    return null;
                }
                $row = DB::table('team_work_versions')->where(['tenant_id' => $tenant->id, 'entity_type' => 'project', 'entity_id' => $projectId, 'version' => $version])->first();
                if (! $row || ! hash_equals($row->sha256, hash('sha256', $row->snapshot))) {
                    return null;
                }
                $status = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR)['current_status'] ?? null;
                if (! $status || (string) $status['project_version'] !== $version) {
                    return null;
                }
                $status = $this->graph->projectStatus($state, $actor, $projectId, $status);

                return $this->update($state['projects'][$projectId], $status);
            }
            if ($kind === 'person') {
                if (in_array($actor['role'], ['guest', 'viewer'], true) && (string) $user->id !== $id) {
                    return null;
                }
                $member = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->find($id);

                return $member ? $this->person($member) : null;
            }
            if ($kind === 'file') {
                $file = DB::table('team_file_uploads')->where(['tenant_id' => $tenant->id, 'id' => $id, 'status' => 'ready'])->first();
                if (! $file || ! $this->graph->can($state, $actor, 'task', $file->task_id)
                    || ! in_array($id, $state['tasks'][$file->task_id]['attachment_ids'] ?? [], true)) {
                    return null;
                }

                $task = $this->graph->searchProjection($state, $actor, 'task', $file->task_id);
                $context = $this->native('task', $task, $state['projects']);

                return $this->file($file) + ['projects' => $context['projects'], 'people' => $context['people']];
            }
        } catch (HttpException $e) {
            if (! in_array($e->getStatusCode(), [403, 404, 409], true)) {
                throw $e;
            }
        }

        return null;
    }

    public function file(object $file): array
    {
        return ['id' => 'file:'.$file->id, 'type' => 'file', 'entity_id' => $file->id, 'identifier' => $file->id,
            'title' => $file->filename, 'body' => $file->mime_type, 'context' => 'Task attachment', 'status' => 'Ready', 'source' => 'Everbranch',
            'target' => ['kind' => 'task', 'id' => $file->task_id, 'file' => $file->id]];
    }
}
