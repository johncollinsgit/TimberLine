<?php

namespace App\Enterprise;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/** Explicit owned adapters only; a missing user connection never falls back to another sender. */
class EnterpriseMailboxGateway extends MailboxGateway
{
    public function __construct(private ConnectedMailboxGateway $smtp, private GmailMailboxGateway $gmail) {}

    public function mailboxes(Tenant $tenant, User $user, string $areaId): array
    {
        return [...$this->gmail->mailboxes($tenant, $user, $areaId), ...$this->smtp->mailboxes($tenant, $user, $areaId)];
    }

    public function ready(): bool
    {
        return $this->gmail->ready() || $this->smtp->ready();
    }

    public function readyFor(array $mailbox): bool
    {
        return $this->adapter($mailbox['id'])->readyFor($mailbox);
    }

    private function adapter(string $id): MailboxGateway
    {
        if (Str::isUuid($id)) {
            return $this->gmail;
        }
        abort_unless(preg_match('/^[1-9][0-9]{0,18}$/D', $id), 404);

        return $this->smtp;
    }

    public function authorize(Tenant $tenant, User $user, string $areaId, string $id): array
    {
        return $this->adapter($id)->authorize($tenant, $user, $areaId, $id);
    }

    public function dispatch(Tenant $tenant, User $user, array $mailbox, array $message, string $idempotencyKey): array
    {
        $binding = $this->authorize($tenant, $user, $mailbox['area_id'], $mailbox['id']);
        abort_unless($binding === $mailbox, 409, 'The approved sender binding changed.');

        return $this->adapter($mailbox['id'])->dispatch($tenant, $user, $mailbox, $message, $idempotencyKey);
    }
}
