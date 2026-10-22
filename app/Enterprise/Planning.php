<?php

namespace App\Enterprise;

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Support\Str;

/** Planned dates and manually recorded costs. Never a purchase, account or payment. */
class Planning
{
    public const KINDS = ['contractor' => 'ent_contractor', 'schedule' => 'ent_schedule', 'cost' => 'ent_cost'];

    public function __construct(private WorkspaceAccess $access, private RecordStore $store, private TeamWorkRepository $work, private WorkGraph $graph) {}

    public static function financial(array $actor): bool
    {
        return in_array($actor['role'], ['owner', 'admin'], true);
    }

    public function record(Tenant $tenant, array $actor, string $kind, string $id): TeamOperationRecord
    {
        $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::KINDS[$kind]])->findOrFail($id);
        $this->access->area($tenant, $actor, $row->area_id);
        abort_if($kind === 'cost' && ! self::financial($actor), 404);
        if ($kind !== 'contractor') {
            $this->project($tenant, $actor, $row->area_id, $row->data['project_id']);
        }

        return $row;
    }

    private function project(Tenant $tenant, array $actor, string $area, string $id): array
    {
        $state = $this->work->state($tenant->id);
        abort_unless($this->graph->can($state, $actor, 'project', $id), 404);
        $project = $this->graph->read($state, $actor, 'project', $id);
        abort_unless(($project['enterprise_area_id'] ?? null) === $area && ! $project['archived'], 404);

        return $project;
    }

    public function view(Tenant $tenant, array $actor, TeamOperationRecord $row): array
    {
        $kind = array_search($row->kind, self::KINDS, true);
        abort_unless($kind !== false, 404);
        $row = $this->record($tenant, $actor, $kind, $row->id);

        return ['id' => $row->id, 'kind' => $kind, 'area_id' => $row->area_id, 'title' => $row->title, 'status' => $row->status,
            'version' => $row->version, 'starts_on' => $row->starts_on?->toDateString(), 'ends_on' => $row->ends_on?->toDateString(),
            'data' => $row->data, 'updated_at' => $row->updated_at?->toIso8601String()];
    }

    public function listing(Tenant $tenant, User $user, string $areaId): array
    {
        abort_unless(config('enterprise.planning_enabled'), 404);
        $actor = $this->access->actor($tenant, $user);
        $this->access->area($tenant, $actor, $areaId);
        $kinds = self::financial($actor) ? self::KINDS : array_diff_key(self::KINDS, ['cost' => true]);
        $items = [];
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId])->whereIn('kind', $kinds)
            ->orderByDesc('updated_at')->orderBy('id')->limit(1000)->cursor();
        foreach ($rows as $row) {
            try {
                $items[] = $this->view($tenant, $actor, $row);
                if (count($items) > 200) {
                    break;
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (! in_array($e->getStatusCode(), [403, 404], true)) {
                    throw $e;
                }
            }
        }

        return ['items' => array_slice($items, 0, 200), 'partial' => true, 'has_more' => count($items) > 200, 'as_of' => now()->toIso8601String(),
            'can_coordinate' => WorkspaceAccess::permits($actor, $areaId, true, true), 'can_finance' => self::financial($actor) && config('enterprise.writes_enabled'),
            'basis' => 'Up to 200 authorized records from a bounded scan of at most 1000; never a complete report. Date-only plans within this department; no hourly capacity, purchasing, payment or accounting sync.'];
    }

    public function execute(Tenant $tenant, User $user, array $input): array
    {
        abort_unless(config('enterprise.planning_enabled'), 404);

        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $actor = $this->access->actor($tenant, $user, true);
            $area = $this->access->area($tenant, $actor, $input['area_id'], true);
            $kind = $input['kind'];
            abort_if($kind === 'cost' && ! self::financial($actor), 403);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'ent.planning' && hash_equals($receipt->input_hash, $hash), 409);

                return $this->view($tenant, $actor, $this->record($tenant, $actor, $kind, $input['id'])) + ['replayed' => true];
            }
            $existing = TeamOperationRecord::query()->find($input['id']);
            if ($existing) {
                $record = $this->record($tenant, $actor, $kind, $input['id']);
                abort_unless($record->area_id === $area->id && $record->version === $input['expected_version'], 409);
            } else {
                abort_unless($input['expected_version'] === 0, 409);
                $record = new TeamOperationRecord(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $area->id,
                    'kind' => self::KINDS[$kind], 'source_key' => hash('sha256', 'ent-planning:'.$input['id']), 'visibility' => 'area', 'version' => 0]);
            }
            $data = [];
            if ($kind !== 'contractor') {
                $this->project($tenant, $actor, $area->id, $input['project_id']);
                $data['project_id'] = $input['project_id'];
            }
            if ($kind === 'schedule') {
                $contractor = $this->record($tenant, $actor, 'contractor', $input['contractor_id']);
                abort_unless($contractor->area_id === $area->id && $contractor->status === 'active', 422);
                $data['contractor_id'] = $contractor->id;
                if ($input['status'] !== 'cancelled') {
                    $overlap = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => self::KINDS['schedule'], 'status' => 'planned'])
                        ->where('id', '!=', $record->id)->whereDate('starts_on', '<=', $input['ends_on'])->whereDate('ends_on', '>=', $input['starts_on'])
                        ->get()->contains(fn ($row) => $row->data['contractor_id'] === $contractor->id);
                    abort_if($overlap, 409, 'This contractor already has an overlapping planned date block in this department.');
                }
                $record->starts_on = $input['starts_on'];
                $record->ends_on = $input['ends_on'];
            }
            if ($kind === 'cost') {
                $data += ['amount_minor' => $input['amount_minor'], 'currency' => $input['currency'], 'basis' => $input['basis']];
            }
            $record->title = $input['title'];
            $record->status = $input['status'];
            $record->data = $data;
            $record->version++;
            $record->save();
            $this->store->audit($record, $user->id, $kind.'.save', ['after' => $this->view($tenant, $actor, $record)]);
            TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'record_id' => $record->id,
                'user_id' => $user->id, 'kind' => 'ent.planning', 'status' => 'accepted', 'request_id' => $input['request_id'], 'input_hash' => $hash, 'result' => ['id' => $record->id]]);

            return $this->view($tenant, $actor, $record) + ['replayed' => false];
        });
    }
}
