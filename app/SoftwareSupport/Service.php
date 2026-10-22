<?php

namespace App\SoftwareSupport;

use App\Models\Tenant;
use App\Models\TenantSupportTicket;
use App\Models\TenantSupportTicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Service
{
    public const STATUSES = ['open', 'in_progress', 'waiting_on_tenant', 'resolved', 'closed'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function create(array $context, User $user, array $data, array $files = []): Record
    {
        abort_if($context['owner'], 403);
        abort_unless(array_key_exists($data['department'], $context['departments']), 403);
        $paths = [];
        try {
            return DB::transaction(function () use ($context, $user, $data, $files, &$paths): Record {
                // Serialize submissions per client, including interrupted/repeated requests.
                Tenant::whereKey($context['tenant']->id)->lockForUpdate()->firstOrFail();
                $key = hash('sha256', $user->id.'|'.$data['request_key']);
                $fingerprint = $this->fingerprint($data, $files);
                $existing = Record::where('client_tenant_id', $context['tenant']->id)->where('kind', 'ticket')->where('request_key', $key)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->payload['fingerprint'], $fingerprint), 409, 'This submission was already used with different details.');

                    return $existing;
                }
                $ticket = TenantSupportTicket::withoutGlobalScopes()->create([
                    'tenant_id' => $context['tenant']->id, 'created_by_user_id' => $user->id,
                    'subject' => $data['subject'], 'category' => $data['category'], 'priority' => $data['priority'],
                    'status' => 'open', 'source_type' => 'software_service', 'last_activity_at' => now(),
                ]);
                $record = Record::create([
                    'id' => (string) Str::uuid(), 'owner_tenant_id' => $context['ownerId'],
                    'client_tenant_id' => $context['tenant']->id, 'ticket_id' => $ticket->id,
                    'created_by' => $user->id, 'department' => $data['department'], 'kind' => 'ticket', 'request_key' => $key,
                    'payload' => ['fingerprint' => $fingerprint, 'revision' => 1,
                        'response_expectation' => app(ResponseCalendar::class)->snapshot((int) $context['tenant']->id, \Carbon\CarbonImmutable::now()),
                        'initial_human_response_at' => null],
                ]);
                $this->message($record, $user, $data['body'], false);
                $this->files($record, $user, $files, $paths);
                $this->entry($record, $user, 'event', ['action' => 'submitted', 'status' => 'open', 'priority' => $ticket->priority]);
                foreach (['sms', 'email'] as $channel) {
                    $this->entry($record, $user, 'notification', [
                        'channel' => $channel, 'state' => 'pending_simulation', 'attempts' => 0,
                        'message' => 'Evergrove support: '.$record->id.' | '.strtoupper($ticket->priority).' | '.rtrim(config('app.url'), '/').route('software-support.show', ['workspace' => Tenant::findOrFail($context['ownerId'])->slug, 'reference' => $record->id], false),
                    ], hash('sha256', 'incoming|'.$record->id.'|'.$channel));
                }

                return $record;
            });
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    public function reply(Record $record, User $user, array $data, bool $owner, array $files = []): void
    {
        $paths = [];
        try {
            DB::transaction(function () use ($record, $user, $data, $owner, $files, &$paths): void {
                $record = Record::whereKey($record->id)->lockForUpdate()->firstOrFail();
                $key = hash('sha256', $record->id.'|'.$user->id.'|'.$data['request_key']);
                $fingerprint = $this->fingerprint($data, $files);
                $existing = Record::where('client_tenant_id', $record->client_tenant_id)->where('kind', 'reply')->where('request_key', $key)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->payload['fingerprint'], $fingerprint), 409, 'This reply was already used with different details.');

                    return;
                }
                $this->message($record, $user, $data['body'], $owner);
                $this->files($record, $user, $files, $paths);
                $this->entry($record, $user, 'reply', ['fingerprint' => $fingerprint], $key);
                if ($owner && ! ($record->payload['initial_human_response_at'] ?? null)) {
                    $record->update(['payload' => [...$record->payload, 'initial_human_response_at' => now()->toIso8601String()]]);
                }
                TenantSupportTicket::withoutGlobalScopes()->whereKey($record->ticket_id)->where('tenant_id', $record->client_tenant_id)->update(['last_activity_at' => now()]);
            });
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    public function triage(Record $record, User $user, array $data): void
    {
        DB::transaction(function () use ($record, $user, $data): void {
            $record = Record::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $ticket = $this->ticket($record);
            // Repeated identical updates are safe; stale differing changes require review.
            if ($ticket->status === $data['status'] && $ticket->priority === $data['priority']) {
                return;
            }
            abort_unless($record->payload['revision'] === (int) $data['revision'], 409, 'This ticket changed. Reload before updating it.');
            $this->entry($record, $user, 'event', ['action' => 'triaged', 'before' => ['status' => $ticket->status, 'priority' => $ticket->priority], 'status' => $data['status'], 'priority' => $data['priority']]);
            $ticket->forceFill(['status' => $data['status'], 'priority' => $data['priority'], 'last_activity_at' => now(), 'resolved_at' => in_array($data['status'], ['resolved', 'closed'], true) ? now() : null])->save();
            $record->update(['payload' => [...$record->payload, 'revision' => $record->payload['revision'] + 1]]);
        }, 3);
    }

    public function ticket(Record $record): TenantSupportTicket
    {
        return TenantSupportTicket::withoutGlobalScopes()->where('tenant_id', $record->client_tenant_id)->where('source_type', 'software_service')->findOrFail($record->ticket_id);
    }

    public function entries(Record $record, string $kind)
    {
        return Record::where('owner_tenant_id', $record->owner_tenant_id)->where('client_tenant_id', $record->client_tenant_id)->where('ticket_id', $record->ticket_id)->where('kind', $kind)->oldest()->orderBy('id')->get();
    }

    private function entry(Record $record, User $user, string $kind, array $payload, ?string $key = null): Record
    {
        return Record::create([
            'id' => (string) Str::uuid(), 'owner_tenant_id' => $record->owner_tenant_id,
            'client_tenant_id' => $record->client_tenant_id, 'ticket_id' => $record->ticket_id,
            'created_by' => $user->id, 'department' => $record->department, 'kind' => $kind,
            'request_key' => $key ?? hash('sha256', (string) Str::uuid()), 'payload' => $payload,
        ]);
    }

    private function message(Record $record, User $user, string $body, bool $owner): void
    {
        TenantSupportTicketMessage::withoutGlobalScopes()->create([
            'tenant_support_ticket_id' => $record->ticket_id, 'tenant_id' => $record->client_tenant_id,
            'user_id' => $user->id, 'author_context' => $owner ? 'landlord' : 'tenant', 'body' => $body,
        ]);
    }

    private function files(Record $record, User $user, array $files, array &$paths): void
    {
        foreach ($files as $file) {
            $entry = $this->entry($record, $user, 'attachment', [
                'name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
                'scan' => ['status' => 'quarantined', 'reason' => 'awaiting_scanner'],
            ]);
            $path = 'private/software-support/'.$entry->id;
            $paths[] = $path;
            abort_unless(Storage::disk('local')->put($path, file_get_contents($file->getRealPath())), 503, 'Attachment storage is unavailable. Try this submission again.');
        }
    }

    private function fingerprint(array $data, array $files): string
    {
        unset($data['request_key']);
        ksort($data);

        return hash('sha256', json_encode([$data, array_map(fn ($file) => [$file->getClientOriginalName(), hash_file('sha256', $file->getRealPath())], $files)], JSON_THROW_ON_ERROR));
    }
}
