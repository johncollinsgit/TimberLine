<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OperationsActions
{
    public function prepare(Tenant $tenant, User $user, TeamArea $area, Request $request): array
    {
        $input = $request->validate(['request_id' => 'required|uuid', 'kind' => 'required|in:gmail.send,sms.send,asana.create', 'record_id' => 'required|uuid', 'expected_record_version' => 'nullable|integer|min:1', 'connection_id' => 'nullable|uuid', 'recipient_user_id' => 'nullable|integer', 'recipient_profile_id' => 'nullable|integer', 'subject' => 'nullable|string|max:200|not_regex:/[\r\n]/', 'text' => 'nullable|string|max:20000', 'html' => 'nullable|string|max:40000', 'formatted' => 'sometimes|boolean', 'template_id' => 'nullable|uuid', 'starter_id' => 'nullable|string|max:100', 'cta_label' => 'nullable|string|max:100', 'cta_url' => 'nullable|url:https|max:2000', 'external' => 'sometimes|boolean', 'reviewed' => 'accepted']);
        $access = app(OperationsAccess::class);
        $access->area($tenant, $user, $area->id, true);
        if ($input['kind'] !== 'asana.create') {
            abort_unless(app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'messaging'), 403, 'Messaging is not enabled for this workspace.');
        }
        $record = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->findOrFail($input['record_id']);
        $access->record($tenant, $user, $record);
        abort_if(isset($input['expected_record_version']) && $record->version !== $input['expected_record_version'], 409, 'The work changed. Review a fresh message before preparing it.');
        $input['record_version'] = $record->version;
        if ($input['kind'] === 'asana.create') {
            abort_unless($record->kind === 'event' && ! $record->connection_id && empty($record->data['created_asana']), 422);
            $input['record_version'] = $record->version;
            $input['creation_reference'] = 'Teams reference: '.$input['request_id'];
        }
        if ($input['external'] ?? false) {
            $access->financial($tenant, $user);
        }
        if ($input['kind'] !== 'sms.send') {
            abort_unless(! empty($input['connection_id']), 422);
            $c = $access->connection($tenant, $user, $input['connection_id']);
            abort_unless($c->area_id === $area->id && $c->provider === ($input['kind'] === 'asana.create' ? 'asana' : 'gmail'), 422);
            $input['connection_generation'] = $c->generation;
            $input['from'] = $c->account_name;
        }
        if ($input['kind'] === 'gmail.send' || $input['kind'] === 'sms.send') {
            abort_unless(filled($input['text'] ?? '') || filled($input['html'] ?? ''), 422);
            if ($input['external'] ?? false) {
                $profile = \App\Models\MarketingProfile::query()->forTenantId($tenant->id)->findOrFail($input['recipient_profile_id'] ?? 0);
                $input['to'] = $input['kind'] === 'gmail.send' ? $profile->email : ($profile->normalized_phone ?: $profile->phone);
            } else {
                abort_unless(in_array((string) ($input['recipient_user_id'] ?? ''), $access->actor($tenant, $user)['verified_user_ids'], true), 422, 'Choose an active verified workspace recipient.');
                // Recipient must be authorized for this specific work, not merely employed by the company.
                $recipient = User::query()->findOrFail($input['recipient_user_id']);
                $access->record($tenant, $recipient, $record);
                $input['to'] = $input['kind'] === 'gmail.send' ? $recipient->email : $recipient->phone;
                if ($input['kind'] === 'sms.send') {
                    abort(409, 'Link a consented messaging recipient profile before sending SMS.');
                }
            }
            abort_unless(filled($input['to']), 422, 'Recipient channel is missing.');
        }
        if ($input['kind'] === 'gmail.send' && ($input['formatted'] ?? false)) {
            abort_if($input['external'] ?? false, 409, 'External templates use the campaign audience and approval workflow.');
            $rendered = app(OperationsTemplates::class)->render($tenant, $user, $area, $record, $recipient, $input);
            abort_unless(! $rendered['missing_fields'], 422, 'Complete these template fields first: '.implode(', ', $rendered['missing_fields']));
            $input = array_replace($input, array_intersect_key($rendered, array_flip(['subject', 'text', 'html', 'template_id', 'template_version'])));
        } elseif (! empty($input['html'])) {
            $input['html'] = $this->safeHtml($input['html']);
        }
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $attempt = TeamOperationAttempt::query()->firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']], ['id' => (string) Str::uuid(), 'area_id' => $area->id, 'record_id' => $record->id, 'connection_id' => $input['connection_id'] ?? null, 'kind' => $input['kind'], 'input_hash' => $hash, 'payload' => $input, 'status' => 'prepared']);
        abort_unless(hash_equals($attempt->input_hash, $hash), 409, 'This request ID already belongs to different content.');

        return ['id' => $attempt->id, 'status' => $attempt->status, 'preview' => $attempt->payload, 'notice' => 'Prepared only. Review and dispatch explicitly.'];
    }

    public function dispatch(Tenant $tenant, User $user, TeamArea $area, string $id, Request $request): array
    {
        $request->validate(['reviewed' => 'accepted']);
        $access = app(OperationsAccess::class);
        $access->area($tenant, $user, $area->id, true);
        $attempt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $user->id])->findOrFail($id);
        $record = TeamOperationRecord::query()->where('tenant_id', $tenant->id)->findOrFail($attempt->record_id);
        $access->record($tenant, $user, $record);
        $input = $attempt->payload;
        if ($attempt->kind !== 'asana.create') {
            abort_unless(app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'messaging'), 403);
        }
        if ($attempt->status !== 'prepared') {
            return ['id' => $attempt->id, 'status' => $attempt->status, 'result' => $attempt->result, 'notice' => 'Existing attempt retained; no provider request was replayed.'];
        }
        abort_unless($record->version === ($input['record_version'] ?? null), 409, 'This work changed after preparation. Review a new action; the previous draft has not been sent.');
        abort_unless(config($attempt->kind === 'asana.create' ? 'team_operations.asana_create_enabled' : 'team_operations.send_enabled'), 409, 'Live dispatch is disabled. The reviewed draft remains prepared.');
        $connection = $attempt->connection_id ? $access->connection($tenant, $user, $attempt->connection_id) : null;
        if ($connection) {
            abort_unless($connection->generation === $input['connection_generation'] && ($connection->settings['send_authorized'] ?? false), 409, 'The connection or its send consent changed. Prepare a new reviewed action.');
        }
        if ($input['external'] ?? false) {
            $access->financial($tenant, $user);
            $profile = \App\Models\MarketingProfile::query()->forTenantId($tenant->id)->findOrFail($input['recipient_profile_id']);
            if ($attempt->kind === 'sms.send') {
                $eligibility = app(\App\Services\Marketing\MarketingSmsEligibilityService::class)->evaluateProfile($profile, $tenant->id);
                abort_unless($eligibility['eligible'], 409, 'This recipient is not eligible for SMS.');
            } else {
                abort_unless((bool) $profile->accepts_email_marketing, 409, 'Recipient email consent is not available.');
                abort_if(app(\App\Services\Marketing\MarketingEmailUnsubscribeService::class)->suppressed($tenant->id, $profile->email), 409, 'Recipient is suppressed.');
            }
        } elseif ($attempt->kind !== 'asana.create') {
            $recipient = User::query()->findOrFail($input['recipient_user_id']);
            $access->record($tenant, $recipient, $record);
            abort_unless($recipient->email === $input['to'], 409, 'Recipient changed. Review a new draft.');
        }
        if ($attempt->kind === 'asana.create') {
            abort_unless($record->version === $input['record_version'] && empty($record->data['created_asana']), 409, 'Event changed. Review the new version before creation.');
        }
        $claimed = TeamOperationAttempt::query()->whereKey($id)->where('status', 'prepared')->update(['status' => 'executing', 'updated_at' => now()]);
        abort_unless($claimed === 1, 409);
        try {
            if ($attempt->kind === 'gmail.send') {
                abort_unless(filter_var($connection->account_name, FILTER_VALIDATE_EMAIL) && filter_var($input['to'], FILTER_VALIDATE_EMAIL), 422);
                $messageId = '<'.$attempt->id.'@teams.theeverbranch.com>';
                $boundary = 'teams-'.str_replace('-', '', $attempt->id);
                $raw = 'From: '.$connection->account_name."\r\nTo: ".$input['to']."\r\nSubject: =?UTF-8?B?".base64_encode($input['subject'] ?? $record->title)."?=\r\nMessage-ID: ".$messageId."\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=".$boundary."\r\n\r\n--".$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($input['text'] ?? strip_tags($input['html'] ?? '')))."\r\n--".$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($input['html'] ?? nl2br(e($input['text'] ?? ''))))."\r\n--".$boundary."--\r\n";
                $result = app(OperationsProviders::class)->http($connection, 'POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')]);
                abort_unless(filled($result['id'] ?? null), 502, 'Gmail did not return a message identity. Reconcile this attempt before sending again.');
                $result = ['provider_message_id' => $result['id'] ?? null, 'thread_id' => $result['threadId'] ?? null, 'message_id' => $messageId, 'delivery' => 'provider_accepted'];
            } elseif ($attempt->kind === 'asana.create') {
                $project = (string) ($connection->settings['source']['asana_project'] ?? '');
                abort_unless(ctype_digit($project), 422);
                app(OperationsProviders::class)->read($connection, 'records'); // Recheck target project/workspace immediately before mutation.
                $payload = ['name' => $record->title, 'projects' => [$project], 'notes' => $record->data['notes'] ?? '', 'start_on' => $record->starts_on?->toDateString(), 'due_on' => $record->ends_on?->toDateString()];
                foreach (['venue', 'address', 'city', 'state', 'timezone', 'organizer', 'email', 'phone', 'setup_time', 'loadout_time', 'event_hours', 'booth', 'instructions'] as $field) {
                    if (filled($record->data[$field] ?? null)) {
                        $payload['notes'] .= "\n".ucwords(str_replace('_', ' ', $field)).': '.$record->data[$field];
                    }
                }
                $payload['notes'] .= "\n\n".$input['creation_reference'];
                $reply = app(OperationsProviders::class)->http($connection, 'POST', 'https://app.asana.com/api/1.0/tasks', ['data' => array_filter($payload, fn ($v) => $v !== null)]);
                $gid = (string) ($reply['data']['gid'] ?? '');
                abort_unless(ctype_digit($gid), 502);
                $result = ['source_id' => $gid, 'source_url' => 'https://app.asana.com/0/'.$project.'/'.$gid];
                $projection = app(OperationsSync::class)->adoptCreatedEvent($record, $connection, array_replace($payload, $reply['data']));
                $result['projection_id'] = $projection->id;
            } else {
                $result = app(\App\Services\Marketing\Messaging\TenantMessagingGateway::class)->sendSms($tenant->id, $input['to'], $input['text'], ['idempotency_key' => $attempt->id, 'source_type' => 'team_operations', 'source_id' => null]);
                abort_unless($result['success'] ?? false, 503, 'SMS provider did not confirm acceptance.');
            }
            $attempt->update(['status' => 'accepted', 'result' => $result]);

            return ['id' => $id, 'status' => 'accepted', 'result' => $result];
        } catch (\Throwable $e) {
            $attempt->update(['status' => 'unknown', 'result' => ['notice' => 'Provider outcome is unconfirmed. Do not repeat; reconcile this attempt.']]);
            throw $e;
        }
    }

    public function safeHtml(string $html): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $allowed = ['html', 'body', 'div', 'p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'a'];
        foreach (iterator_to_array($dom->getElementsByTagName('*')) as $node) {
            if (! in_array(strtolower($node->nodeName), $allowed)) {
                $node->parentNode?->removeChild($node);

                continue;
            }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                if (! ($node->nodeName === 'a' && $attribute->name === 'href' && preg_match('#^(https://|mailto:)#i', trim($attribute->value)))) {
                    $node->removeAttribute($attribute->name);
                }
            }
            if ($node->nodeName === 'a') {
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        $result = '';
        foreach ($body?->childNodes ?? [] as $child) {
            $result .= $dom->saveHTML($child);
        }

        return $result;
    }
}
