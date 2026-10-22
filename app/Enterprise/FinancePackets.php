<?php

namespace App\Enterprise;

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Restricted routing packets. Never authorizes payment, validates a fund, or calls accounting. */
class FinancePackets
{
    public const KIND = 'ent_finance_packet';

    public function __construct(private WorkspaceAccess $access, private RecordStore $store, private Planning $planning, private TeamWorkRepository $work, private WorkGraph $graph) {}

    private function actor(Tenant $tenant, User $user, bool $write = false): array
    {
        abort_unless(config('enterprise.finance_enabled'), 404);
        $actor = $this->access->actor($tenant, $user, $write);
        abort_unless(Planning::financial($actor), 404);

        return $actor;
    }

    private function project(Tenant $tenant, array $actor, string $areaId, string $id): array
    {
        $this->access->area($tenant, $actor, $areaId);
        $state = $this->work->state($tenant->id);
        abort_unless($this->graph->can($state, $actor, 'project', $id), 404);
        $project = $this->graph->read($state, $actor, 'project', $id);
        abort_unless(($project['enterprise_area_id'] ?? null) === $areaId && ! $project['archived'], 404);

        return $project;
    }

    private function eligible(array $actor, string $id): bool
    {
        return in_array($actor['active_roles'][$id] ?? '', ['owner', 'admin'], true)
            && in_array($id, $actor['verified_user_ids'] ?? [], true);
    }

    private function nextOwner(Tenant $tenant, array $actor, string $area, string $project, string $id): void
    {
        abort_unless($this->eligible($actor, $id), 422, 'Choose an active verified tenant owner or administrator.');
        $next = array_replace($actor, ['user_id' => $id, 'role' => $actor['active_roles'][$id]]);
        $this->project($tenant, $next, $area, $project);
    }

    private function row(Tenant $tenant, array $actor, string $id): TeamOperationRecord
    {
        $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::KIND])->findOrFail($id);
        $data = $row->data;
        $this->project($tenant, $actor, $row->area_id, $data['project_id']);
        if ($data['contractor_id'] !== null) {
            $contact = $this->planning->record($tenant, $actor, 'contractor', $data['contractor_id']);
            abort_unless($contact->area_id === $row->area_id, 404);
        }

        return $row;
    }

    private function dto(Tenant $tenant, array $actor, TeamOperationRecord $row): array
    {
        $row = $this->row($tenant, $actor, $row->id);
        $data = $row->data;
        $eligible = $this->eligible($actor, $data['next_owner_id']);

        return ['id' => $row->id, 'area_id' => $row->area_id, 'title' => $row->title, 'status' => $row->status, 'version' => $row->version,
            ...$data, 'next_owner_eligible' => $eligible,
            'can_manage' => (bool) config('enterprise.writes_enabled') && $row->status !== 'externally_completed',
            'can_decide' => (bool) config('enterprise.writes_enabled') && $row->status === 'submitted' && $eligible && $data['next_owner_id'] === $actor['user_id'],
            'updated_at' => $row->updated_at?->toIso8601String(),
            'authority' => 'Manual references and internal routing review only. Verify fund restrictions and external approval in the authoritative Finance process. No payment is executed or inferred.'];
    }

    public function listing(Tenant $tenant, User $user, string $area): array
    {
        $actor = $this->actor($tenant, $user);
        $this->access->area($tenant, $actor, $area);
        $items = [];
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => self::KIND])->orderByDesc('updated_at')->orderBy('id')->limit(1000)->cursor() as $row) {
            try {
                $items[] = $this->dto($tenant, $actor, $row);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
                if ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException && ! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }

                continue;
            }
            if (count($items) > 200) {
                break;
            }
        }
        $eligibleIds = array_values(array_filter(array_keys($actor['active_roles']), fn ($id) => $this->eligible($actor, (string) $id)));
        $ownerRows = $tenant->users()->wherePivot('membership_active', true)->whereIn('users.id', $eligibleIds)->where('is_active', true)->whereNotNull('email_verified_at')
            ->orderBy('users.name')->orderBy('users.id')->limit(201)->get();
        $owners = $ownerRows->take(200)->map(fn ($user) => ['id' => (string) $user->id, 'name' => $user->name])->values()->all();
        $projects = [];
        $state = $this->work->state($tenant->id);
        foreach ($state['projects'] as $id => $project) {
            if (($project['enterprise_area_id'] ?? null) === $area && ! ($project['archived'] ?? false) && $this->graph->can($state, $actor, 'project', $id)) {
                $projects[] = ['id' => $id, 'name' => $project['name']];
            }
        }
        $contractors = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => Planning::KINDS['contractor'], 'status' => 'active'])
            ->orderBy('title')->orderBy('id')->limit(200)->get(['id', 'title'])->toArray();

        return ['items' => array_slice($items, 0, 200), 'approvers' => $owners, 'has_more_approvers' => $ownerRows->count() > 200,
            'projects' => array_slice($projects, 0, 200), 'has_more_projects' => count($projects) > 200, 'contractors' => $contractors, 'can_manage' => (bool) config('enterprise.writes_enabled'),
            'partial' => true, 'has_more' => count($items) > 200, 'coverage' => 'Up to 200 currently authorized packets from at most 1000 records; not a ledger or campus total.', 'as_of' => now()->toIso8601String()];
    }

    public function show(Tenant $tenant, User $user, string $id): array
    {
        $actor = $this->actor($tenant, $user);

        return $this->dto($tenant, $actor, $this->row($tenant, $actor, $id));
    }

    public function history(Tenant $tenant, User $user, string $id): array
    {
        $actor = $this->actor($tenant, $user);
        $this->row($tenant, $actor, $id);
        $items = DB::table('team_operation_revisions')->where(['tenant_id' => $tenant->id, 'record_id' => $id])->orderByDesc('version')->limit(100)->get()
            ->map(fn ($row) => ['version' => $row->version, 'actor_id' => $row->user_id, 'at' => $row->created_at,
                'event' => json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR)])->all();

        return ['items' => $items, 'coverage' => 'Latest 100 encrypted packet revisions, authorized at download.'];
    }

    private function validate(array $input): array
    {
        $rules = ['action' => ['required', 'in:packet.save,packet.submit,packet.review,packet.handoff,packet.reopen,packet.external_complete'],
            'id' => ['required', 'uuid'], 'area_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0']];
        $rules += match ($input['action'] ?? '') {
            'packet.save' => ['title' => ['required', 'string', 'max:100'], 'project_id' => ['required', 'uuid'], 'contractor_id' => ['present', 'nullable', 'uuid'],
                'amount_minor' => ['required', 'integer', 'min:0', 'max:1000000000'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/D'],
                'document_reference' => ['present', 'nullable', 'string', 'max:500'], 'fund_code' => ['present', 'nullable', 'string', 'max:100'],
                'restriction_reference' => ['present', 'nullable', 'string', 'max:1000'], 'source_reference' => ['present', 'nullable', 'string', 'max:500'],
                'next_owner_id' => ['required', 'integer', 'min:1']],
            'packet.review' => ['decision' => ['required', 'in:approved,needs_changes'], 'reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['required', 'accepted']],
            'packet.handoff' => ['next_owner_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['required', 'accepted']],
            'packet.external_complete' => ['external_reference' => ['required', 'string', 'max:500'], 'reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['required', 'accepted']],
            default => ['reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['required', 'accepted']],
        };
        abort_unless(array_diff(array_keys($input), array_keys($rules)) === [], 422, 'Unexpected packet command fields.');
        $input = Validator::make($input, $rules)->validate();
        $input['expected_version'] = (int) $input['expected_version'];
        foreach (['title', 'reason', 'external_reference'] as $field) {
            abort_if(isset($input[$field]) && trim($input[$field]) === '', 422, 'Enter nonblank packet text.');
        }
        foreach (['next_owner_id', 'amount_minor'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = (int) $input[$field];
            }
        }
        if (isset($input['confirmed'])) {
            $input['confirmed'] = true;
        }

        return $input;
    }

    public function execute(Tenant $tenant, User $user, array $input): array
    {
        $this->actor($tenant, $user, true);
        $input = $this->validate($input);

        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $actor = $this->actor($tenant, $user, true);
            $this->access->area($tenant, $actor, $input['area_id'], true);
            $row = TeamOperationRecord::query()->whereKey($input['id'])->exists() ? $this->row($tenant, $actor, $input['id']) : null;
            abort_if($row && $row->area_id !== $input['area_id'], 404);
            abort_unless($row || $input['action'] === 'packet.save', 404);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === self::KIND && $receipt->record_id === $row?->id && hash_equals($receipt->input_hash, $hash), 409);

                return $this->dto($tenant, $actor, $row) + ['replayed' => true];
            }
            abort_unless(($row?->version ?? 0) === $input['expected_version'], 409, 'This packet changed. Review the current version.');
            abort_if($row?->status === 'externally_completed', 409, 'Completed external handoffs preserve their evidence. Create a separate correction packet.');
            $data = $row?->data;
            $before = $row ? ['title' => $row->title, 'status' => $row->status, 'data' => $data] : null;
            $stamp = ['by' => (string) $user->id, 'at' => now()->utc()->toIso8601String(), 'reason' => trim($input['reason'] ?? '')];
            if ($input['action'] === 'packet.save') {
                abort_unless(! $row || in_array($row->status, ['draft', 'needs_changes'], true), 409, 'Reopen a submitted or approved packet before editing its contents.');
                abort_if($row && $data['project_id'] !== $input['project_id'], 409, 'The project binding is immutable.');
                $this->project($tenant, $actor, $input['area_id'], $input['project_id']);
                if ($input['contractor_id'] !== null) {
                    $contact = $this->planning->record($tenant, $actor, 'contractor', $input['contractor_id']);
                    abort_unless($contact->area_id === $input['area_id'] && $contact->status === 'active', 404);
                }
                $this->nextOwner($tenant, $actor, $input['area_id'], $input['project_id'], (string) $input['next_owner_id']);
                if (! $row) {
                    $row = new TeamOperationRecord(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'], 'kind' => self::KIND,
                        'version' => 0, 'visibility' => 'manager', 'source_key' => hash('sha256', 'finance-packet:'.$input['id'])]);
                }
                $row->title = trim($input['title']);
                $row->status = 'draft';
                $data = array_intersect_key($input, array_flip(['project_id', 'contractor_id', 'amount_minor', 'currency', 'document_reference', 'fund_code', 'restriction_reference', 'source_reference']));
                foreach (['document_reference', 'fund_code', 'restriction_reference', 'source_reference'] as $field) {
                    $data[$field] = trim($data[$field] ?? '') ?: null;
                }
                $data += ['next_owner_id' => (string) $input['next_owner_id'], 'review' => null, 'submission' => null, 'external_completion' => null];
            } elseif ($input['action'] === 'packet.submit') {
                abort_unless(in_array($row->status, ['draft', 'needs_changes'], true), 409);
                abort_unless($data['document_reference'] && $data['fund_code'] && $data['source_reference'], 422, 'Record the original document, fund code and authoritative source reference before submission.');
                $this->nextOwner($tenant, $actor, $row->area_id, $data['project_id'], $data['next_owner_id']);
                $row->status = 'submitted';
                $data['submission'] = $stamp;
                $data['review'] = null;
            } elseif ($input['action'] === 'packet.review') {
                abort_unless($row->status === 'submitted' && $data['next_owner_id'] === $actor['user_id'], 409, 'Only the current assigned reviewer can decide a submitted packet.');
                $this->nextOwner($tenant, $actor, $row->area_id, $data['project_id'], $actor['user_id']);
                $row->status = $input['decision'];
                $data['review'] = $stamp + ['decision' => $input['decision'], 'version_reviewed' => $row->version];
            } elseif ($input['action'] === 'packet.handoff') {
                $this->nextOwner($tenant, $actor, $row->area_id, $data['project_id'], (string) $input['next_owner_id']);
                $data['next_owner_id'] = (string) $input['next_owner_id'];
                $data['handoff'] = $stamp;
            } elseif ($input['action'] === 'packet.reopen') {
                abort_unless(in_array($row->status, ['submitted', 'approved', 'needs_changes'], true), 409);
                $row->status = 'draft';
                $data['review'] = null;
                $data['submission'] = null;
                $data['reopened'] = $stamp;
            } elseif ($input['action'] === 'packet.external_complete') {
                abort_unless($row->status === 'approved' && $data['next_owner_id'] === $actor['user_id'], 409, 'Only the assigned owner may record the external outcome of a reviewed packet.');
                $this->nextOwner($tenant, $actor, $row->area_id, $data['project_id'], $actor['user_id']);
                $row->status = 'externally_completed';
                $data['external_completion'] = $stamp + ['reference' => trim($input['external_reference']), 'verification' => 'manually_recorded_external_process'];
            }
            $row->data = $data;
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, $input['action'], ['before' => $before, 'after' => ['title' => $row->title, 'status' => $row->status, 'data' => $data]]);
            TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $row->area_id, 'record_id' => $row->id,
                'user_id' => $user->id, 'kind' => self::KIND, 'status' => 'accepted', 'request_id' => $input['request_id'], 'input_hash' => $hash, 'result' => ['id' => $row->id]]);

            return $this->dto($tenant, $actor, $row) + ['replayed' => false];
        });
    }
}
