<?php

namespace App\Enterprise\Trial;

use App\Enterprise\RecordStore;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A configured synthetic storage anchor, never a new organization or access grant. */
class TrialStore
{
    public function tenant(): Tenant
    {
        abort_unless(config('enterprise.runtime') === true && config('enterprise.trial.enabled'), 404);
        abort_unless((is_int(config('enterprise.trial.tenant_id')) || (is_string(config('enterprise.trial.tenant_id')) && ctype_digit(config('enterprise.trial.tenant_id'))))
            && (int) config('enterprise.trial.tenant_id') > 0
            && is_string(config('enterprise.trial.area_id')) && Str::isUuid(config('enterprise.trial.area_id')), 503);
        $tenant = Tenant::query()->findOrFail(config('enterprise.trial.tenant_id'));
        $area = TeamArea::query()->where(['tenant_id' => $tenant->id, 'id' => config('enterprise.trial.area_id')])->first();
        abort_unless($area && in_array($area->profile, ['enterprise_shared', 'enterprise_department'], true)
            && ($area->settings['synthetic_fixture'] ?? null) === true, 503, 'A separately marked synthetic trial storage anchor must be configured.');

        return $tenant;
    }

    public function locked(callable $callback): mixed
    {
        $tenant = $this->tenant();
        // This campaign's cap is global, including accidental anchor reconfiguration.
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql) {
            abort_unless((int) DB::selectOne("SELECT GET_LOCK('everbranch-reviewer-trial-budget', 5) AS acquired")->acquired === 1, 409, 'The trial is busy. Reuse the same request key.');
        }
        try {
            return app(RecordStore::class)->locked($tenant, fn () => $callback($tenant));
        } finally {
            if ($mysql) {
                DB::selectOne("SELECT RELEASE_LOCK('everbranch-reviewer-trial-budget') AS released");
            }
        }
    }

    public function create(string $kind, string $source, array $data, string $status = 'active'): TeamOperationRecord
    {
        return TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant()->id,
            'area_id' => config('enterprise.trial.area_id'), 'kind' => $kind, 'source_key' => hash('sha256', $source),
            'visibility' => 'area', 'title' => 'Private synthetic reviewer trial', 'status' => $status, 'version' => 1, 'data' => $data]);
    }

    public function row(string $kind, string $id): TeamOperationRecord
    {
        return TeamOperationRecord::query()->where(['tenant_id' => $this->tenant()->id, 'area_id' => config('enterprise.trial.area_id'), 'kind' => $kind])->findOrFail($id);
    }

    public function save(TeamOperationRecord $row, array $data, ?string $status = null): void
    {
        $row->data = $data;
        if ($status !== null) {
            $row->status = $status;
        }
        $row->version++;
        $row->save();
    }

    public function audit(TeamOperationRecord $row, string $event, array $evidence = []): void
    {
        // Native audit schema explicitly permits a null employee actor. Never fabricate employee user 0.
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'record_id' => $row->id,
            'version' => $row->version, 'user_id' => null, 'payload' => Crypt::encryptString(json_encode(['action' => $event,
                'actor_type' => 'controlled_reviewer_trial', ...$evidence], JSON_THROW_ON_ERROR)), 'created_at' => now()]);
    }
}
