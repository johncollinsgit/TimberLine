<?php

namespace App\Services\AiBranch;

use App\Models\AiBranchAction;
use App\Models\FieldServiceJobNote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiBranchActions
{
    public function __construct(private AiBranchAccess $access, private AiBranchContext $context) {}

    private function authorize(Tenant $tenant, User $user): void
    {
        $tier = $this->access->authorize($tenant, $user);
        abort_unless(config('ai_branch.actions_enabled') && $tier['ai_limits']['actions'], 403, 'Reviewed actions are unavailable.');
    }

    public function propose(Tenant $tenant, User $user, int $jobId, string $body, string $uuid): AiBranchAction
    {
        $this->authorize($tenant, $user);
        abort_unless(Str::isUuid($uuid) && trim($body) !== '' && mb_strlen($body) <= 3000, 422);
        $job = $this->context->job($tenant, $user, $jobId);
        $payload = ['job_id' => $job->id, 'job_title' => $job->title, 'target_hash' => hash('sha256', json_encode($job->getRawOriginal(), JSON_THROW_ON_ERROR)), 'body' => trim($body)];
        $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $user, $uuid, $payload, $digest): AiBranchAction {
            \App\Models\AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $existing = AiBranchAction::query()->where('tenant_id', $tenant->id)->where('client_uuid', $uuid)->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === (int) $user->id && hash_equals($existing->digest, $digest), 409);

                return $existing;
            }

            return AiBranchAction::query()->create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => $uuid, 'kind' => 'internal_job_note',
                'payload' => $payload, 'digest' => $digest, 'status' => 'pending', 'expires_at' => now()->addMinutes(15),
            ]);
        }, 3);
    }

    public function decide(Tenant $tenant, User $user, int $id, string $digest, bool $approve): AiBranchAction
    {
        $this->authorize($tenant, $user);

        return DB::transaction(function () use ($tenant, $user, $id, $digest, $approve): AiBranchAction {
            $action = AiBranchAction::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($action->kind === 'internal_job_note' && hash_equals($action->digest, $digest)
                && hash_equals($action->digest, hash('sha256', json_encode($action->payload, JSON_THROW_ON_ERROR))), 409);
            $this->authorize($tenant, $user);
            if ($action->status !== 'pending') {
                abort_unless($action->status === ($approve ? 'executed' : 'rejected'), 409);

                return $action;
            }
            abort_unless($action->expires_at->isFuture(), 409, 'The review window expired. Prepare a new action.');
            $job = $this->context->job($tenant, $user, (int) $action->payload['job_id']);
            $job = \App\Models\FieldServiceJob::query()->where('tenant_id', $tenant->id)->whereKey($job->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($action->payload['target_hash'], hash('sha256', json_encode($job->getRawOriginal(), JSON_THROW_ON_ERROR))), 409, 'The job changed. Prepare a new action.');
            if ($approve) {
                $note = FieldServiceJobNote::query()->create([
                    'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'created_by_user_id' => $user->id,
                    'body' => $action->payload['body'], 'noted_at' => now(),
                    'metadata' => ['visibility' => 'owner', 'ai_branch_action_id' => $action->id, 'approval_digest' => $action->digest],
                ]);
                $action->result_id = $note->id;
            }
            $action->forceFill(['status' => $approve ? 'executed' : 'rejected', 'decided_at' => now()])->save();

            return $action;
        }, 3);
    }
}
