<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Only an exact confirmed native EmailDraft may cross the fixed send-only Gmail endpoint. */
class GmailMailboxGateway extends MailboxGateway
{
    public const SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public function __construct(private GmailConnections $connections, private RecordStore $store) {}

    public function ready(): bool
    {
        return ! config('enterprise.conversation.enabled') && $this->connections->configured() && (bool) config('enterprise.gmail.send_enabled');
    }

    public function readyFor(array $mailbox): bool
    {
        if (! $this->ready()) {
            return false;
        }
        try {
            $tenant = Tenant::query()->findOrFail($mailbox['tenant_id']);
            $user = User::query()->findOrFail($mailbox['user_id']);

            return $this->authorize($tenant, $user, $mailbox['area_id'], $mailbox['id']) === $mailbox;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            return false;
        }
    }

    public function mailboxes(Tenant $tenant, User $user, string $areaId): array
    {
        return $this->connections->mailboxes($tenant, $user, $areaId);
    }

    private function confirmed(Tenant $tenant, User $user, array $mailbox, array $message, string $id): TeamOperationRecord
    {
        abort_unless(Str::isUuid($id), 422);
        $draft = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $mailbox['area_id'], 'kind' => 'ent_email'])->findOrFail($id);
        $data = $draft->data;
        abort_unless($data['user_id'] === (string) $user->id && $draft->status === 'dispatching' && $data['payload']['mailbox'] === $mailbox
            && array_intersect_key($data['payload'], array_flip(['to', 'subject', 'body'])) === $message
            && now()->lt(\Carbon\CarbonImmutable::parse($data['expires_at'])), 409, 'Gmail requires the exact still-valid confirmed draft.');
        abort_unless($this->readyFor($mailbox), 503, 'Gmail sending is not approved for this mailbox.');
        abort_unless(filter_var($message['to'] ?? '', FILTER_VALIDATE_EMAIL) && is_string($message['subject'] ?? null)
            && trim($message['subject']) !== '' && mb_strlen($message['subject']) <= 200 && ! preg_match('/[\r\n]/', $message['subject'])
            && is_string($message['body'] ?? null) && trim($message['body']) !== '' && mb_strlen($message['body']) <= 10000, 422);

        return $draft;
    }

    public function dispatch(Tenant $tenant, User $user, array $mailbox, array $message, string $idempotencyKey): array
    {
        $this->confirmed($tenant, $user, $mailbox, $message, $idempotencyKey);
        $source = hash('sha256', 'gmail-send:'.$idempotencyKey);
        $inputHash = hash('sha256', json_encode([$mailbox, $message], JSON_THROW_ON_ERROR));
        $claim = $this->store->locked($tenant, function () use ($tenant, $user, $mailbox, $message, $idempotencyKey, $source, $inputHash): array {
            $this->confirmed($tenant, $user, $mailbox, $message, $idempotencyKey);
            $existing = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'ent_gmail_send', 'source_key' => $source])->first();
            if ($existing) {
                abort_unless($existing->data['user_id'] === (string) $user->id && hash_equals($existing->data['input_hash'], $inputHash), 409);
                abort_unless($existing->status === 'accepted', 409, 'The Gmail send is pending or uncertain. Never resend it automatically.');

                return ['record' => $existing, 'replayed' => true];
            }
            $record = TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $mailbox['area_id'],
                'kind' => 'ent_gmail_send', 'source_key' => $source, 'visibility' => 'area', 'title' => 'Private Gmail dispatch', 'status' => 'sending', 'version' => 1,
                'data' => ['user_id' => (string) $user->id, 'input_hash' => $inputHash, 'draft_id' => $idempotencyKey]]);
            $this->store->audit($record, $user->id, 'gmail.send_claimed', ['draft_id' => $idempotencyKey]);

            return ['record' => $record, 'replayed' => false];
        });
        $record = $claim['record'];
        if ($claim['replayed']) {
            return ['status' => 'accepted', 'id' => $record->data['provider_id']];
        }
        try {
            $connection = $this->connections->connection($tenant, $user, $mailbox['area_id'], $mailbox['id']);
            $token = $this->connections->accessToken($tenant, $user, $mailbox['area_id'], $mailbox['id']);
            $this->confirmed($tenant, $user, $mailbox, $message, $idempotencyKey);
            $email = (new Email)->from(new Address($mailbox['address']))->to($message['to'])->subject($message['subject'])->text($message['body']);
            $email->getHeaders()->addIdHeader('Message-ID', $idempotencyKey.'@'.explode('@', $mailbox['address'], 2)[1]);
            $mime = $email->toString();
            abort_if(strlen($mime) > 131072, 422);
            $response = Http::withToken($token)->acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20)
                ->post(self::SEND_URL, ['raw' => GmailConnections::base64url($mime)]);
            if (in_array($response->status(), [401, 403], true)) {
                $this->connections->invalidate($tenant, $user, $mailbox['id'], $connection->data['generation'], 'send_authorization_lost');
            }
            $value = $response->throw()->json();
            abort_unless($response->status() === 200 && is_array($value) && is_string($value['id'] ?? null)
                && preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $value['id']), 503);
            $providerId = 'gmail:'.$value['id'];
            $this->finish($tenant, $user, $record->id, 'accepted', $providerId);

            return ['status' => 'accepted', 'id' => $providerId];
        } catch (\Throwable) {
            $this->finish($tenant, $user, $record->id, 'uncertain', null);
            abort(503, 'Gmail dispatch failed or is uncertain. No automatic retry was made; reconcile this existing draft.');
        }
    }

    private function finish(Tenant $tenant, User $user, string $id, string $status, ?string $providerId): void
    {
        $this->store->locked($tenant, function () use ($tenant, $user, $id, $status, $providerId): void {
            $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'ent_gmail_send'])->findOrFail($id);
            $row->data = array_replace($row->data, ['provider_id' => $providerId]);
            $row->status = $status;
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'gmail.send_'.$status, ['provider_id' => $providerId]);
        });
    }
}
