<?php

namespace App\Enterprise;

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/** Exact, expiring own-mailbox approvals; chat has no route to dispatch automatically. */
class EmailDrafts
{
    public function __construct(private WorkspaceAccess $access, private RecordStore $store, private MailboxGateway $gateway, private AskEverbranch $ask) {}

    private function authorize(Tenant $tenant, User $user, string $areaId): array
    {
        abort_unless(config('enterprise.email_drafts_enabled'), 404);
        $actor = $this->access->actor($tenant, $user, true);
        $this->access->area($tenant, $actor, $areaId);
        abort_unless(WorkspaceAccess::permits($actor, $areaId, true), 403);

        return $actor;
    }

    public function mailboxes(Tenant $tenant, User $user, string $areaId): array
    {
        $this->authorize($tenant, $user, $areaId);
        $items = [];
        foreach ($this->gateway->mailboxes($tenant, $user, $areaId) as $row) {
            try {
                $binding = $this->gateway->authorize($tenant, $user, $areaId, $row['id']);
                $items[] = ['id' => $binding['id'], 'address' => $binding['address']];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                // A provider listing is never itself an authorization grant.
            }
        }

        $ready = $this->gateway->ready() && $items !== [];

        return ['mailboxes' => $items, 'delivery_ready' => $ready, 'status' => $ready
            ? 'Exact preview and confirmation required.' : 'No delivery transport connected for your authorized mailbox. No mailbox messages are read or sent.'];
    }

    private function sources(Tenant $tenant, User $user, string $areaId, array $refs): array
    {
        $context = $this->ask->context($tenant, $user, $areaId);
        $sources = [];
        foreach ($refs as $ref) {
            $found = array_values(array_filter($context['sources'], fn ($source) => $source['ref'] === $ref));
            abort_unless(count($found) === 1, 404);
            $sources[] = $found[0];
        }

        return ['identity' => $context['identity'], 'department' => $context['department'], 'sources' => $sources];
    }

    public function create(Tenant $tenant, User $user, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $this->authorize($tenant, $user, $input['area_id']);
            $binding = $this->gateway->authorize($tenant, $user, $input['area_id'], $input['mailbox_id']);
            $basis = $this->sources($tenant, $user, $input['area_id'], $input['source_refs'] ?? []);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'ent.email.draft' && hash_equals($receipt->input_hash, $hash), 409);

                return $this->preview($tenant, $user, $receipt->result['id']);
            }
            $payload = ['mailbox' => $binding, 'to' => $input['to'], 'subject' => $input['subject'], 'body' => $input['body'],
                'source_refs' => $input['source_refs'] ?? [], 'source_digest' => hash('sha256', json_encode($basis, JSON_THROW_ON_ERROR))];
            $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $record = TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'],
                'kind' => 'ent_email', 'source_key' => hash('sha256', 'ent-email:'.$input['request_id'].':'.$user->id), 'visibility' => 'area',
                'title' => 'Private email draft', 'status' => 'draft', 'version' => 1,
                'data' => ['user_id' => (string) $user->id, 'payload' => $payload, 'digest' => $digest, 'expires_at' => now()->addMinutes(15)->toIso8601String()]]);
            $this->store->audit($record, $user->id, 'email.drafted', ['digest' => $digest]);
            TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'],
                'record_id' => $record->id, 'user_id' => $user->id, 'kind' => 'ent.email.draft', 'status' => 'accepted',
                'request_id' => $input['request_id'], 'input_hash' => $hash, 'result' => ['id' => $record->id]]);

            return $this->preview($tenant, $user, $record->id);
        });
    }

    private function checked(Tenant $tenant, User $user, string $id): TeamOperationRecord
    {
        $record = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'ent_email'])->findOrFail($id);
        $this->authorize($tenant, $user, $record->area_id);
        $data = $record->data;
        abort_unless($data['user_id'] === (string) $user->id, 404);
        $binding = $this->gateway->authorize($tenant, $user, $record->area_id, $data['payload']['mailbox']['id']);
        abort_unless($binding === $data['payload']['mailbox'], 409, 'Your mailbox binding changed. Create a new draft.');
        $basis = $this->sources($tenant, $user, $record->area_id, $data['payload']['source_refs']);
        abort_unless(hash_equals($data['payload']['source_digest'], hash('sha256', json_encode($basis, JSON_THROW_ON_ERROR))), 409, 'The referenced records or your role changed. Create a new draft.');

        return $record;
    }

    public function preview(Tenant $tenant, User $user, string $id): array
    {
        $record = $this->checked($tenant, $user, $id);
        $data = $record->data;

        return ['id' => $record->id, 'area_id' => $record->area_id, 'status' => $record->status, 'digest' => $data['digest'], 'expires_at' => $data['expires_at'],
            'from' => $data['payload']['mailbox']['address'], ...array_intersect_key($data['payload'], array_flip(['to', 'subject', 'body'])),
            'delivery_ready' => $this->gateway->ready(), 'provider_receipt' => $data['provider_receipt'] ?? null];
    }

    public function confirm(Tenant $tenant, User $user, string $id, string $digest): array
    {
        $record = $this->store->locked($tenant, function () use ($tenant, $user, $id, $digest) {
            $record = $this->checked($tenant, $user, $id);
            $data = $record->data;
            abort_unless(hash_equals($data['digest'], $digest), 409, 'Confirm the exact previewed draft.');
            if ($record->status === 'accepted') {
                return $record;
            }
            abort_unless($record->status === 'draft', 409, 'Dispatch is unresolved or closed. Do not resend; reconcile the existing attempt.');
            abort_unless(now()->lt(\Carbon\CarbonImmutable::parse($data['expires_at'])), 409, 'This approval expired. Create a new draft.');
            abort_unless($this->gateway->ready(), 503, 'No email transport connected. No email was sent.');
            $record->status = 'dispatching';
            $record->version++;
            $record->save();
            $this->store->audit($record, $user->id, 'email.confirmed', ['digest' => $digest, 'dispatch_key' => $record->id]);

            return $record;
        });
        if ($record->status === 'accepted') {
            return $this->preview($tenant, $user, $id) + ['replayed' => true];
        }
        $outcome = null;
        try {
            $record = $this->checked($tenant, $user, $id); // Recheck immediately before adapter dispatch.
            $payload = $record->data['payload'];
            $outcome = $this->gateway->dispatch($tenant, $user, $payload['mailbox'], array_intersect_key($payload, array_flip(['to', 'subject', 'body'])), $record->id);
            if (($outcome['status'] ?? '') !== 'accepted' || ! is_string($outcome['id'] ?? null) || strlen($outcome['id']) < 1 || strlen($outcome['id']) > 120) {
                $outcome = null;
            }
        } catch (\Throwable) {
            // A provider may already have accepted the message. Never retry on uncertainty.
        }
        $this->store->locked($tenant, function () use ($tenant, $user, $id, $outcome): void {
            $record = TeamOperationRecord::query()->where('tenant_id', $tenant->id)->findOrFail($id);
            $data = $record->data;
            $record->status = $outcome ? 'accepted' : 'uncertain';
            $data['provider_receipt'] = $outcome['id'] ?? null;
            $record->data = $data;
            $record->version++;
            $record->save();
            $this->store->audit($record, $user->id, 'email.'.$record->status, ['provider_receipt' => $data['provider_receipt']]);
        });

        return $this->preview($tenant, $user, $id) + ['replayed' => false];
    }
}
