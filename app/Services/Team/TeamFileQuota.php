<?php

namespace App\Services\Team;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TeamFileQuota
{
    public function operationBytes(int $tenantId): int
    {
        $disk = Storage::disk('local');
        $bytes = 0;
        foreach ($disk->allFiles('team-operations/'.$tenantId) as $file) {
            $bytes += $disk->size($file);
        }

        return $bytes;
    }

    public function store(int $tenantId, string $key, string $bytes): void
    {
        abort_unless(str_starts_with($key, 'team-operations/'.$tenantId.'/') && strlen($bytes) <= config('team_work.file_max_bytes'), 422);
        DB::transaction(function () use ($tenantId, $key, $bytes) {
            DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $tenantId]);
            DB::table('team_work_locks')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $disk = Storage::disk('local');
            if ($disk->exists($key)) {
                abort_unless(hash_equals(hash('sha256', $bytes), hash_file('sha256', $disk->path($key))), 409);

                return;
            }
            $reserved = (int) DB::table('team_file_uploads')->where('tenant_id', $tenantId)->where('status', '!=', 'cancelled')->sum('expected_size');
            abort_if($reserved + $this->operationBytes($tenantId) + strlen($bytes) > config('team_work.file_tenant_quota_bytes'), 422, 'Workspace file quota reached. No document was published.');
            abort_unless($disk->put($key, $bytes), 503, 'Private file storage is unavailable.');
        }, 3);
    }
}
