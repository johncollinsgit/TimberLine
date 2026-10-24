<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Same serialization and encrypted revision contract as Enterprise intake. */
class RecordStore
{
    public function locked(Tenant $tenant, callable $callback): mixed
    {
        return DB::transaction(function () use ($tenant, $callback) {
            // MySQL duplicate-key INSERT IGNORE holds a shared lock; concurrent FOR UPDATE upgrades can deadlock.
            // A no-change upsert acquires the writer lock directly, including the first-row race.
            DB::table('team_work_locks')->upsert([['tenant_id' => $tenant->id]], ['tenant_id'], ['tenant_id']);
            DB::table('team_work_locks')->where('tenant_id', $tenant->id)->lockForUpdate()->first();

            return $callback();
        }, 3);
    }

    public function audit(TeamOperationRecord $record, int $userId, string $action, array $evidence = []): void
    {
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $record->tenant_id,
            'record_id' => $record->id, 'version' => $record->version, 'user_id' => $userId,
            'payload' => Crypt::encryptString(json_encode(['action' => $action, ...$evidence], JSON_THROW_ON_ERROR)), 'created_at' => now()]);
    }
}
