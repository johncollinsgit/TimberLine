<?php

namespace App\Services\Team\Studio;

use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Operations\OperationsAccess;
use Illuminate\Support\Facades\DB;

/** Sales summaries are an explicit, narrow grant; raw finance permissions stay unchanged. */
class EventSalesAccess
{
    public function __construct(private OperationsAccess $access) {}

    public function manager(Tenant $tenant, User $user): bool
    {
        return $this->access->admin($this->access->actor($tenant, $user));
    }

    public function event(Tenant $tenant, User $user, TeamOperationRecord $event): void
    {
        if ($event->kind === 'financial') {
            abort_unless($this->manager($tenant, $user), 403);
            $this->access->record($tenant, $user, $event);

            return;
        }
        abort_unless($event->kind === 'event', 404);
        $this->access->record($tenant, $user, $event);
        if ($this->manager($tenant, $user)) {
            return;
        }
        $ids = array_filter([$event->assigned_user_id, ...(is_array($event->data['staff_ids'] ?? null) ? $event->data['staff_ids'] : [])], fn ($id) => is_scalar($id) && ctype_digit((string) $id));
        if (in_array((string) $user->id, array_map('strval', $ids), true)) {
            return;
        }
        $data = $event->data;
        $assigned = ! empty($data['source_assignee_id']) && TeamProviderConnection::where(['tenant_id' => $tenant->id, 'area_id' => $event->area_id, 'user_id' => $user->id, 'provider' => 'asana', 'status' => 'connected'])
            ->get()->contains(fn ($connection) => (string) ($connection->settings['external_user_id'] ?? '') === (string) $data['source_assignee_id']
                && (string) ($connection->settings['source']['asana_workspace'] ?? '') === (string) ($data['source_workspace'] ?? ''));
        abort_unless($assigned, 403, 'Event sales are available to its assigned team and managers.');
    }

    /** Current issuer/source admission for the sales-only export, never raw payroll access. */
    public function exported(TeamOperationRecord $record, TeamOperationRecord $mapping): bool
    {
        if ($record->status === 'withdrawn' || (int) $record->tenant_id !== (int) $mapping->tenant_id || (string) $record->area_id !== (string) $mapping->area_id) {
            return false;
        }
        $reviewer = $mapping->data['reviewed_by'] ?? null;
        if (! $reviewer || ! DB::table('tenant_user')->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->where('tenant_user.tenant_id', $record->tenant_id)->where('tenant_user.user_id', $reviewer)->where('tenant_user.membership_active', true)
            ->whereIn('tenant_user.role', ['owner', 'tenant_owner', 'admin'])->where('users.is_active', true)->whereNotNull('users.email_verified_at')->exists()) {
            return false;
        }
        if (! $record->connection_id) {
            return true;
        }
        $connection = TeamProviderConnection::where(['id' => $record->connection_id, 'tenant_id' => $record->tenant_id, 'area_id' => $record->area_id, 'status' => 'connected'])->first();

        return $connection && ($record->data['_generation'] ?? null) === $connection->generation
            && (! $connection->expires_at || $connection->expires_at->isFuture());
    }
}
