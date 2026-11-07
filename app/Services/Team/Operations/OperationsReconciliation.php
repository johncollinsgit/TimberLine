<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;

class OperationsReconciliation
{
    public function attempt(Tenant $tenant, User $user, TeamArea $area, string $id): array
    {
        $access = app(OperationsAccess::class);
        $access->area($tenant, $user, $area->id, true);
        $attempt = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $user->id])->whereIn('kind', ['gmail.send', 'asana.create', 'sms.send'])->findOrFail($id);
        $record = TeamOperationRecord::where('tenant_id', $tenant->id)->findOrFail($attempt->record_id);
        $access->record($tenant, $user, $record);
        $finishAdoption = $attempt->kind === 'asana.create' && $attempt->status === 'accepted'
            && ($attempt->result['adoption_status'] ?? null) === 'needs_review' && empty($attempt->result['projection_id']);
        if (! in_array($attempt->status, ['unknown', 'executing'], true) && ! $finishAdoption) {
            return ['id' => $id, 'status' => $attempt->status, 'result' => $attempt->result];
        }
        $result = null;
        if ($attempt->kind === 'sms.send') {
            $entry = app(\App\Services\Marketing\Messaging\TenantMessagingUsageService::class)->settledEntry($tenant->id, $attempt->id);
            if ($entry && ($entry->metadata['provider_result']['success'] ?? false)) {
                $result = array_intersect_key($entry->metadata['provider_result'], array_flip(['provider', 'provider_message_id', 'delivery_status', 'status']));
            }
        } else {
            $c = $access->connection($tenant, $user, $attempt->connection_id);
            abort_unless($c->generation === ($attempt->payload['connection_generation'] ?? null) && $c->status === 'connected', 409, 'Restore the original source context before reconciliation.');
            $provider = app(OperationsProviders::class);
            if ($attempt->kind === 'gmail.send') {
                $messageId = $id.'@teams.theeverbranch.com';
                // The dispatch identifier is stable even when its HTTP response is lost.
                $reply = $provider->http($c, 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages', ['q' => 'in:sent rfc822msgid:'.$messageId, 'maxResults' => 2]);
                if (count($reply['messages'] ?? []) === 1 && empty($reply['nextPageToken'])) {
                    $message = $provider->http($c, 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode($reply['messages'][0]['id']), ['format' => 'metadata']);
                    $headers = collect($message['payload']['headers'] ?? [])->mapWithKeys(fn ($h) => [strtolower($h['name']) => $h['value']]);
                    if (trim($headers['message-id'] ?? '', '<> ') === $messageId && strtolower(trim($headers['from'] ?? '')) === strtolower($c->account_name) && strtolower(trim($headers['to'] ?? '')) === strtolower($attempt->payload['to'] ?? '')) {
                        $result = ['provider_message_id' => $message['id'], 'thread_id' => $message['threadId'] ?? null, 'message_id' => '<'.$messageId.'>'];
                    }
                }
            } else {
                $matches = [];
                $cursor = null;
                $pages = 0;
                $reference = $attempt->payload['creation_reference'] ?? null;
                if (! $reference) {
                    return ['id' => $id, 'status' => 'unknown', 'notice' => 'This creation has no durable source reference. Review it manually.'];
                }
                $knownId = (string) ($attempt->result['source_id'] ?? '');
                if ($finishAdoption && ctype_digit($knownId)) {
                    $task = $provider->verifyAsanaRecord($c, $knownId, $c->settings['source']['asana_project']);
                    if ($this->hasCreationReference($task['notes'] ?? '', $reference)) {
                        $matches[$knownId] = $task;
                    }
                } else {
                    do {
                        $page = $provider->read($c, 'records', $cursor);
                        foreach ($page['items'] as $task) {
                            if ($this->hasCreationReference($task['notes'] ?? '', $reference)) {
                                $matches[(string) $task['gid']] = $task;
                            }
                        } $cursor = $page['next_cursor'];
                        $pages++;
                    } while ($cursor && $pages < 100);
                }
                if (! $cursor && count($matches) === 1) {
                    $gid = (string) array_key_first($matches);
                    if (! $finishAdoption) {
                        $provider->verifyAsanaRecord($c, $gid, $c->settings['source']['asana_project']);
                    }
                    $result = ['source_id' => $gid, 'source_url' => 'https://app.asana.com/0/'.$c->settings['source']['asana_project'].'/'.$gid];
                    $projection = app(OperationsSync::class)->adoptCreatedEvent($record, $c, $matches[$gid]);
                    $result['projection_id'] = $projection->id;
                    if (($projection->data['_teams_enrichment']['source_adoption']['status'] ?? null) === 'needs_review') {
                        $result['adoption_status'] = 'needs_review';
                        $result['notice'] = 'The original Asana task is linked. Review the retained native proposal before using its calendar or budget.';
                    }
                }
            }
        }
        if ($result) {
            $attempt->update(['status' => 'accepted', 'result' => [...$result, 'reconciled_at' => now()->toIso8601String(), 'delivery' => 'provider_accepted']]);

            return ['id' => $id, 'status' => 'accepted', 'result' => $attempt->result];
        }

        return ['id' => $id, 'status' => $attempt->status, 'result' => $attempt->result,
            'notice' => $finishAdoption ? 'The source identity is retained, but local adoption still needs review. Nothing was created again.' : 'The provider outcome remains unconfirmed. Nothing was resent.'];
    }

    private function hasCreationReference(string $notes, string $reference): bool
    {
        return preg_match('/(?:\A|\R)\h*'.preg_quote($reference, '/').'\h*(?:\R|\z)/u', $notes) === 1;
    }
}
