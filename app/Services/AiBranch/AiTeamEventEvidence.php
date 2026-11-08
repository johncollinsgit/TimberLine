<?php

namespace App\Services\AiBranch;

use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Operations\EventTasks;
use App\Services\Team\Operations\OperationsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staging-only adapter. No controller or model provider calls this service yet.
 * Proofs are server-internal and must never be accepted from a client.
 */
class AiTeamEventEvidence
{
    public const NOTE_BYTE_LIMIT = 6000;

    public function capture(Tenant $tenant, User $user, string $eventId): array
    {
        // The Asana authorization and task reads below perform network I/O.
        abort_unless(DB::transactionLevel() === 0, 409, 'Event evidence must be read outside a database transaction.');
        app(AiBranchAccess::class)->authorize($tenant, $user);
        abort_unless($user->hasVerifiedEmail(), 403);
        abort_unless(Str::isUuid($eventId), 422, 'A Teams event UUID is required.');
        $event = TeamOperationRecord::query()->where('tenant_id', $tenant->id)->where('kind', 'event')->findOrFail($eventId);
        app(OperationsAccess::class)->record($tenant, $user, $event);
        $detail = app(EventTasks::class)->forOccurrence($tenant, $user, $event);
        abort_unless($detail && ($detail['source_mode'] ?? null) === 'live' && is_string($detail['notes'] ?? null)
            && ($detail['gid'] ?? null) === (string) ($event->data['source_id'] ?? '')
            && ($detail['modified_at'] ?? null) && ($detail['modified_at'] ?? null) === ($event->data['source_version'] ?? null)
            && ($detail['notes'] ?? null) === ($event->data['original_notes'] ?? null), 409, 'Event source is unavailable or changed. Refresh the event first.');
        $connection = TeamProviderConnection::query()->where(['tenant_id' => $tenant->id, 'area_id' => $event->area_id, 'user_id' => $user->id,
            'provider' => 'asana', 'status' => 'connected'])->findOrFail($detail['connection_id']);
        $binding = $this->binding($tenant, $user, $event, $connection, $detail);
        $freshEvent = TeamOperationRecord::query()->where('tenant_id', $tenant->id)->findOrFail($event->id);
        $freshConnection = TeamProviderConnection::query()->where('tenant_id', $tenant->id)->findOrFail($connection->id);
        abort_unless(hash_equals($binding, $this->binding($tenant, $user, $freshEvent, $freshConnection, $detail)), 409, 'Event access or source changed. Refresh and try again.');
        app(AiBranchAccess::class)->authorize($tenant, $user);
        abort_unless($user->hasVerifiedEmail(), 403);
        app(OperationsAccess::class)->area($tenant, $user, $freshEvent->area_id);
        $notes = mb_strcut($detail['notes'], 0, self::NOTE_BYTE_LIMIT, 'UTF-8');

        return [
            'binding' => $binding,
            'event_id' => $event->id,
            'source' => ['ref' => 'team_event:'.$event->id, 'type' => 'team_event', 'id' => $event->id,
                'title' => mb_substr($event->title, 0, 160), 'source_version' => $detail['modified_at'],
                'untrusted_notes' => $notes, 'notes_truncated' => strlen($notes) < strlen($detail['notes']),
                'source_status' => 'live', 'captured_at' => now('UTC')->toIso8601String()],
        ];
    }

    /** Rechecks access and source evidence without network calls inside a transaction. */
    public function revalidate(Tenant $tenant, User $user, array $proof): array
    {
        abort_unless(isset($proof['event_id'], $proof['binding']) && is_string($proof['event_id']) && is_string($proof['binding']), 422);
        $current = $this->capture($tenant, $user, $proof['event_id']);
        abort_unless(hash_equals($proof['binding'], $current['binding']), 409, 'Event or source access changed. Start a new chat.');

        return $current;
    }

    private function binding(Tenant $tenant, User $user, TeamOperationRecord $event, TeamProviderConnection $connection, array $detail): string
    {
        return hash('sha256', json_encode([
            (string) $tenant->id, (string) $user->id, $event->id, $event->area_id, $event->kind, $event->status,
            $event->visibility, $event->assigned_user_id, $event->title, $event->version, $event->data,
            $connection->id, $connection->tenant_id, $connection->area_id, $connection->user_id, $connection->provider,
            $connection->status, $connection->generation, $connection->settings,
            $detail['gid'], $detail['connection_id'], $detail['modified_at'], $detail['version'] ?? null,
            hash('sha256', $detail['notes']),
        ], JSON_THROW_ON_ERROR));
    }
}
