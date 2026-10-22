<?php

namespace App\Enterprise;

use App\Models\Tenant;
use App\Models\User;

/** No real connection, credentials, mailbox grants or fallback sender in this runtime. */
class MailboxGateway
{
    public function mailboxes(Tenant $tenant, User $user, string $areaId): array
    {
        return [];
    }

    public function ready(): bool
    {
        return false;
    }

    public function readyFor(array $mailbox): bool
    {
        return $this->ready();
    }

    public function authorize(Tenant $tenant, User $user, string $areaId, string $id): array
    {
        foreach ($this->mailboxes($tenant, $user, $areaId) as $mailbox) {
            if ($mailbox['id'] === $id && $mailbox['tenant_id'] === (string) $tenant->id && $mailbox['user_id'] === (string) $user->id
                && $mailbox['area_id'] === $areaId && $mailbox['verified'] === true && $mailbox['permission'] === 'send') {
                return array_intersect_key($mailbox, array_flip(['id', 'tenant_id', 'user_id', 'area_id', 'address', 'binding_version']));
            }
        }
        abort(404);
    }

    public function dispatch(Tenant $tenant, User $user, array $mailbox, array $message, string $idempotencyKey): array
    {
        abort(503, 'A separately authorized mailbox transport is not connected. No email was sent.');
    }
}
