<?php

namespace App\Enterprise\Conversation;

use App\Enterprise\RecordStore;
use App\Models\TeamOperationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Only explicitly retained private memory is scrubbed; metering/work/audit rows are never deleted. */
class MemoryRetention
{
    public function prune(int $limit = 100, ?int $tenantId = null): int
    {
        abort_unless(config('enterprise.runtime') === true, 503);
        app(\App\Enterprise\Provisioning\ClientSilo::class)->assertReady();
        $ids = TeamOperationRecord::query()->where('kind', 'ent_assistant_memory')->where('status', 'active')
            ->whereDate('ends_on', '<=', now()->toDateString())->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('ends_on')->orderBy('id')->limit(max(1, min(1000, $limit)))->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $row = TeamOperationRecord::query()->where('kind', 'ent_assistant_memory')->whereKey($id)->lockForUpdate()->first();
                if (! $row || $row->status !== 'active' || now()->lt(CarbonImmutable::parse($row->data['expires_at']))) {
                    return 0;
                }
                $row->data = ['expired_at' => now()->toIso8601String()];
                $row->status = 'expired';
                $row->version++;
                $row->save();
                app(RecordStore::class)->audit($row, (int) $row->assigned_user_id, 'assistant_memory.expired_content_scrubbed');

                return 1;
            });
        }

        return $count;
    }
}
