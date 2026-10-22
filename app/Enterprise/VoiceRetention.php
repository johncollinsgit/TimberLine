<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\DB;

/** Expiry scrubs content, preserving immutable metering and content-free audit evidence. */
class VoiceRetention
{
    public function prune(int $limit = 100, ?int $tenantId = null): int
    {
        $ids = TeamOperationRecord::query()->where('voice_expires_at', '<=', now())
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('voice_expires_at')->limit(max(1, min(1000, $limit)))->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $row = TeamOperationRecord::query()->whereKey($id)->lockForUpdate()->first();
                if (! $row || ! $row->voice_expires_at || now()->lt($row->voice_expires_at)) {
                    return 0;
                }
                $data = $row->data;
                if (in_array($row->kind, ['ent_voice_session', 'ent_voice_preview'], true)) {
                    $data = array_intersect_key($data, array_flip(['user_id', 'expires_at', 'session_id', 'input_hash']));
                    $row->status = 'expired';
                } elseif ($row->kind === 'ent_ai_usage' && ($data['purpose'] ?? null) === 'voice_answer') {
                    unset($data['value']);
                } else {
                    throw new \LogicException('Only private voice content may have voice expiry metadata.');
                }
                $row->data = $data + ['content_expired' => true];
                $row->voice_expires_at = null;
                $row->version++;
                $row->save();

                return 1;
            });
        }

        return $count;
    }
}
