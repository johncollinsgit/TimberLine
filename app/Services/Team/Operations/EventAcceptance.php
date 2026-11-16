<?php

namespace App\Services\Team\Operations;

use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;

/** Booking evidence, separate from task completion, application and payment state. */
final class EventAcceptance
{
    private const CONFLICTS = ['not accepted', 'declined', 'rejected', 'cancelled', 'canceled', 'pending', 'acceptance pending', 'pending acceptance', 'awaiting acceptance', 'waitlist', 'waitlisted', 'unconfirmed', 'not attending'];

    /** A missing field is legacy/unavailable, never proof that the tag was removed. */
    public function source(TeamProviderConnection $connection, string $project, array $task): ?array
    {
        if (! array_key_exists('tags', $task)) {
            return null;
        }
        $tags = [];
        $valid = is_array($task['tags']) && array_is_list($task['tags']);
        foreach ($valid ? $task['tags'] : [] as $tag) {
            if (! is_array($tag) || ! is_string($tag['gid'] ?? null) || ! ctype_digit($tag['gid']) || ! is_string($tag['name'] ?? null) || trim($tag['name']) === '') {
                $valid = false;

                continue;
            }
            $tags[] = ['gid' => $tag['gid'], 'name' => $tag['name']];
        }
        $source = $connection->settings['source'] ?? [];
        $valid = $valid && ctype_digit((string) ($task['gid'] ?? '')) && (string) ($task['workspace']['gid'] ?? '') === (string) ($source['asana_workspace'] ?? '')
            && in_array($project, app(OperationsProviders::class)->selectedProjects($connection), true)
            && collect($task['memberships'] ?? [])->contains(fn ($membership) => (string) ($membership['project']['gid'] ?? '') === $project);

        return ['version' => 1, 'valid' => $valid, 'tenant_id' => (int) $connection->tenant_id, 'area_id' => (string) $connection->area_id,
            'connection_id' => $connection->id, 'connection_generation' => $connection->generation, 'actor_id' => (int) $connection->user_id,
            'workspace_id' => (string) ($source['asana_workspace'] ?? ''), 'project_id' => $project, 'task_id' => (string) ($task['gid'] ?? ''),
            'source_version' => $task['modified_at'] ?? null, 'tags' => $tags];
    }

    public function project(TeamOperationRecord $record, ?array $hint = null): array
    {
        $data = $record->data ?? [];
        if (! $record->connection_id) {
            return $this->legacy($data['acceptance_status'] ?? null, 'native');
        }
        if ($hint !== null && ($hint['evidence'] ?? null) !== null) {
            $evidence = $hint['evidence'];
            $valid = $this->hintMatches($record, $hint) && ($evidence['valid'] ?? false) === true && ($evidence['version'] ?? null) === 1
                && ($evidence['actor_id'] ?? null) === ($hint['actor_id'] ?? null)
                && ($evidence['tenant_id'] ?? null) === (int) $record->tenant_id && ($evidence['area_id'] ?? null) === (string) $record->area_id
                && ($evidence['workspace_id'] ?? null) === ($data['source_workspace'] ?? null) && ($evidence['task_id'] ?? null) === ($data['source_id'] ?? null)
                && in_array($evidence['project_id'] ?? null, [$data['source_project'] ?? null, ...($data['source_projects'] ?? [])], true);
            if (! $valid) {
                return $this->legacy(null, 'source_unverified');
            }
            $names = array_map(fn ($tag) => mb_strtolower(trim($tag['name'])), $evidence['tags']);
            $accepted = in_array('accepted', $names, true) && ! array_intersect(self::CONFLICTS, $names);

            return ['status' => $accepted ? 'accepted' : null, 'label' => $accepted ? 'Accepted' : 'Needs confirmation', 'basis' => 'asana_tags', 'evidence' => $evidence];
        }
        // A known structured source cannot lend an old accepted snapshot to an
        // unavailable current read or fall back to an earlier affirmative note.
        if (array_key_exists('source_acceptance', $data) || $hint !== null && ! $this->hintMatches($record, $hint)) {
            return $this->legacy(null, 'source_unverified');
        }

        return $this->legacy($data['reviewed_fields']['acceptance_status'] ?? null, 'reviewed_fields');
    }

    public function hintMatches(TeamOperationRecord $record, array $hint): bool
    {
        $data = $record->data ?? [];

        return ($hint['record_id'] ?? null) === $record->id && ($hint['record_version'] ?? null) === $record->version
            && ($hint['record_connection_id'] ?? null) === $record->connection_id && ($hint['record_source_version'] ?? null) === ($data['source_version'] ?? null)
            && ($hint['source_id'] ?? null) === ($data['source_id'] ?? null) && ($hint['record_scope'] ?? null) === $this->recordScope($record);
    }

    public function recordScope(TeamOperationRecord $record): string
    {
        $data = $record->data ?? [];

        return hash('sha256', json_encode([(int) $record->tenant_id, $record->area_id, $record->connection_id, $record->version, $record->kind, $record->status, $record->assigned_user_id, $record->visibility,
            $data['source_id'] ?? null, $data['source_workspace'] ?? null, $data['source_project'] ?? null, $data['source_projects'] ?? [],
            $data['_generation'] ?? null, $data['source_version'] ?? null], JSON_THROW_ON_ERROR));
    }

    public function confirmed(array $projection): bool
    {
        $value = $projection['status'] ?? null;
        if (! is_string($value)) {
            return false;
        }
        $status = mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));

        return in_array($status, ['yes', 'accepted', 'confirmed'], true)
            || ($projection['basis'] ?? null) === 'reviewed_fields' && (preg_match('/^yes\s*,\s*accepted(?:\s+on\s*\d+(?:[.\/-]\d+)+)?$/D', $status)
                || preg_match('/^yes\s*\(\s*via\s+email\s*(?:on\s*)?\d+(?:[.\/-]\d+)+\s*\)$/D', $status));
    }

    private function legacy(mixed $value, string $basis): array
    {
        $status = is_string($value) ? $value : null;

        return ['status' => $status, 'label' => $status ?: 'Needs confirmation', 'basis' => $basis];
    }
}
