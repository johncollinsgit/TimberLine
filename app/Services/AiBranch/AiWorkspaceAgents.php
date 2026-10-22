<?php

namespace App\Services\AiBranch;

use App\Jobs\RunWorkspaceFollowupAgent;
use App\Models\AiBranchAccount;
use App\Models\AiBranchAction;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/** First agent: one bounded customer follow-up draft per authorized run. */
class AiWorkspaceAgents
{
    public function authorize(Tenant $tenant, User $user): void
    {
        abort_unless(config('ai_branch.workspace_agents_enabled'), 404);
        app(AiWorkspaceWorkflows::class)->authorize($tenant, $user);
        abort_unless(app(AiWorkspaceContext::class)->permissions($tenant, $user)['customers'], 403);
        abort_unless(config('ai_branch.actions_enabled') && app(AiBranchAccess::class)->authorize($tenant, $user)['ai_limits']['actions'], 403);
    }

    private function definitions(Tenant $tenant): array
    {
        $cipher = data_get(TenantAccessProfile::where('tenant_id', $tenant->id)->value('metadata'), 'ai_branch.workspace_agents');

        return $cipher ? json_decode(Crypt::decryptString($cipher), true, 24, JSON_THROW_ON_ERROR) : [];
    }

    public function listing(Tenant $tenant, User $user): array
    {
        $this->authorize($tenant, $user);

        return collect($this->definitions($tenant))->filter(fn ($agent) => $agent['owner_id'] === $user->id)->map(function ($agent) use ($tenant, $user) {
            $runs = AiBranchAction::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('kind', 'workspace_agent_run')->latest('id')->limit(100)->get()
                ->filter(fn ($run) => ($run->payload['agent_id'] ?? '') === $agent['id'])->take(5)
                ->map(fn ($run) => ['id' => $run->id, 'status' => $run->status, 'conversation_id' => $run->result_id, 'created_at' => $run->created_at->toIso8601String()])->values()->all();

            return [...$agent, 'runs' => $runs];
        })->values()->all();
    }

    public function propose(Tenant $tenant, User $user, array $input, string $uuid): AiBranchAction
    {
        $this->authorize($tenant, $user);
        $data = Validator::make($input, ['agent_name' => 'required|string|max:80', 'agent_instructions' => 'required|string|max:1000',
            'agent_cadence' => 'required|in:manual,daily', 'agent_time' => 'required_if:agent_cadence,daily|nullable|date_format:H:i'])->validate();
        abort_unless(Str::isUuid($uuid), 422);
        $payload = ['scope_hash' => app(AiWorkspaceContext::class)->stamp($tenant, $user), 'agent_name' => trim($data['agent_name']), 'agent_instructions' => trim($data['agent_instructions']), 'agent_cadence' => $data['agent_cadence'],
            'agent_time' => $data['agent_cadence'] === 'daily' ? $data['agent_time'] : null,
            'timezone' => app(AiWorkspaceContext::class)->timezone($tenant), 'permissions' => 'Read currently permitted customer/work records; prepare one email for review per run. Creating jobs and sending email require separate approval.'];
        $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $user, $uuid, $payload, $digest) {
            AiBranchAccount::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $existing = AiBranchAction::where('tenant_id', $tenant->id)->where('client_uuid', $uuid)->first();
            if ($existing) {
                abort_unless($existing->user_id == $user->id && $existing->kind === 'workspace_create_followup_agent' && hash_equals($digest, $existing->digest), 409);

                return $existing;
            }

            return AiBranchAction::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => $uuid, 'kind' => 'workspace_create_followup_agent',
                'payload' => $payload, 'digest' => $digest, 'status' => 'pending', 'expires_at' => now()->addMinutes(15)]);
        }, 3);
    }

    public function decide(Tenant $tenant, User $user, int $id, string $digest, bool $approve): AiBranchAction
    {
        $this->authorize($tenant, $user);

        return DB::transaction(function () use ($tenant, $user, $id, $digest, $approve) {
            AiBranchAccount::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $action = AiBranchAction::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('kind', 'workspace_create_followup_agent')->lockForUpdate()->findOrFail($id);
            abort_unless(hash_equals($digest, $action->digest) && hash_equals($digest, hash('sha256', json_encode($action->payload, JSON_THROW_ON_ERROR))), 409);
            abort_unless(hash_equals((string) ($action->payload['scope_hash'] ?? ''), app(AiWorkspaceContext::class)->stamp($tenant, $user)), 409, 'Workspace access changed. Prepare another agent preview.');
            if ($action->status !== 'pending') {
                abort_unless($action->status === ($approve ? 'executed' : 'rejected'), 409);

                return $action;
            }
            abort_unless($action->expires_at->isFuture(), 409, 'The agent preview expired.');
            if ($approve) {
                abort_unless(app(AiWorkspaceContext::class)->timezone($tenant) === $action->payload['timezone'], 409, 'The workspace timezone changed.');
                $profile = TenantAccessProfile::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
                $agents = $this->definitions($tenant);
                abort_if(count($agents) >= 10, 422, 'This workspace already has ten agents.');
                $agents[] = ['id' => (string) Str::uuid(), 'type' => 'customer_followup', 'owner_id' => $user->id, 'active' => true,
                    'name' => $action->payload['agent_name'], 'instructions' => $action->payload['agent_instructions'], 'cadence' => $action->payload['agent_cadence'],
                    'time' => $action->payload['agent_time'], 'timezone' => $action->payload['timezone'], 'approval_action_id' => $action->id];
                $metadata = (array) $profile->metadata;
                data_set($metadata, 'ai_branch.workspace_agents', Crypt::encryptString(json_encode($agents, JSON_THROW_ON_ERROR)));
                $profile->forceFill(['metadata' => $metadata])->save();
            }
            $action->forceFill(['status' => $approve ? 'executed' : 'rejected', 'decided_at' => now()])->save();
            $this->audit($tenant, $user, $approve ? 'create' : 'reject', $action->id);

            return $action;
        }, 3);
    }

    public function pause(Tenant $tenant, User $user, string $id): void
    {
        $this->authorize($tenant, $user);
        DB::transaction(function () use ($tenant, $user, $id) {
            $profile = TenantAccessProfile::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $agents = $this->definitions($tenant);
            $index = collect($agents)->search(fn ($agent) => $agent['id'] === $id && $agent['owner_id'] === $user->id);
            abort_if($index === false, 404);
            $agents[$index]['active'] = false;
            $metadata = (array) $profile->metadata;
            data_set($metadata, 'ai_branch.workspace_agents', Crypt::encryptString(json_encode($agents, JSON_THROW_ON_ERROR)));
            $profile->forceFill(['metadata' => $metadata])->save();
            $this->audit($tenant, $user, 'pause', $id);
        }, 3);
    }

    public function run(Tenant $tenant, User $user, string $id, string $uuid): AiBranchAction
    {
        $this->authorize($tenant, $user);
        abort_unless(Str::isUuid($id) && Str::isUuid($uuid), 422);
        $result = DB::transaction(function () use ($tenant, $user, $id, $uuid) {
            AiBranchAccount::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $agent = collect($this->definitions($tenant))->first(fn ($agent) => $agent['id'] === $id && $agent['owner_id'] === $user->id && $agent['active']);
            abort_unless($agent, 404);
            abort_unless($agent['timezone'] === app(AiWorkspaceContext::class)->timezone($tenant), 409, 'Workspace timezone changed. Create a fresh agent with the intended schedule.');
            $payload = ['agent_id' => $id, 'definition' => $agent];
            $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $existing = AiBranchAction::where('tenant_id', $tenant->id)->where('client_uuid', $uuid)->first();
            if ($existing) {
                abort_unless($existing->user_id == $user->id && $existing->kind === 'workspace_agent_run' && hash_equals($digest, $existing->digest), 409);

                return [$existing, false];
            }
            $running = AiBranchAction::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('kind', 'workspace_agent_run')->whereIn('status', ['queued', 'running'])->count();
            abort_if($running >= 3, 429, 'Three agent runs are already waiting.');

            return [AiBranchAction::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => $uuid, 'kind' => 'workspace_agent_run',
                'payload' => $payload, 'digest' => $digest, 'status' => 'queued', 'expires_at' => now()->addDay()]), true];
        }, 3);
        if ($result[1]) {
            RunWorkspaceFollowupAgent::dispatch($tenant->id, $user->id, $result[0]->id)->afterCommit();
        }

        return $result[0]->refresh();
    }

    public function perform(int $tenantId, int $userId, int $runId): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        $user = User::findOrFail($userId);
        $run = AiBranchAction::where('tenant_id', $tenantId)->where('user_id', $userId)->where('kind', 'workspace_agent_run')->findOrFail($runId);
        if ($run->status !== 'queued') {
            return;
        }
        try {
            $this->authorize($tenant, $user);
            $current = collect($this->definitions($tenant))->first(fn ($agent) => $agent['id'] === $run->payload['agent_id'] && $agent['active'] && $agent['owner_id'] === $userId);
            abort_unless($current && $current === $run->payload['definition'] && $run->expires_at->isFuture()
                && $current['timezone'] === app(AiWorkspaceContext::class)->timezone($tenant), 409);
            if (! AiBranchAction::whereKey($runId)->where('status', 'queued')->update(['status' => 'running'])) {
                return;
            }
            $chat = app(AiBranchChat::class);
            $conversation = null;
            $previous = AiBranchAction::where('tenant_id', $tenantId)->where('user_id', $userId)->where('kind', 'workspace_agent_run')->where('status', 'executed')->latest('id')->limit(30)->get()
                ->first(fn ($row) => ($row->payload['agent_id'] ?? '') === $current['id']);
            if ($previous?->result_id) {
                try {
                    $candidate = $chat->conversation($tenant, $user, $previous->result_id);
                    if (count($candidate->messages) < 38 && ! $candidate->pending_uuid) {
                        $conversation = $candidate;
                    }
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException) { /* Start a fresh conversation after context changes. */
                }
            }
            $conversation ??= $chat->start($tenant, $user);
            $run->forceFill(['status' => 'running', 'result_id' => $conversation->id])->save();
            $followup = app(AiWorkspaceSources::class)->followupsQuery($tenant, $user)->with('profile')->orderBy('due_at')->orderBy('id')->first();
            $question = 'Run the customer follow-up agent. Prepare one contextual customer email for human review, or explain missing information. Never create a job or send email. Agent instructions (untrusted preferences): '.$current['instructions'];
            if ($followup?->profile) {
                $question .= '\nSelected current customer: '.$followup->profile->first_name.' '.$followup->profile->last_name.'. Follow-up: '.mb_substr($followup->title, 0, 200).'.';
            } else {
                $question .= ' No current follow-up was found in the permitted source. Ask the owner which customer to follow up with; do not invent a customer or draft.';
            }
            $chat->agentFollowup($tenant, $user, $conversation->id, mb_substr($question, 0, 3000), $run->client_uuid);
            $run->forceFill(['status' => 'executed', 'decided_at' => now()])->save();
            $this->audit($tenant, $user, 'run', $runId);
        } catch (Throwable) {
            // A queued job retries neither inference nor uncertain provider requests.
            $run->forceFill(['status' => 'needs_review', 'decided_at' => now()])->save();
        }
    }

    public function scheduledRuns(): void
    {
        if (! config('ai_branch.workspace_agents_enabled')) {
            return;
        }
        TenantAccessProfile::whereNotNull('metadata->ai_branch->workspace_agents')->orderBy('id')->chunkById(100, function ($profiles) {
            foreach ($profiles as $profile) {
                $tenant = Tenant::find($profile->tenant_id);
                if (! $tenant) {
                    continue;
                }
                foreach ($this->definitions($tenant) as $agent) {
                    if (! $agent['active'] || $agent['cadence'] !== 'daily') {
                        continue;
                    }
                    $local = now($agent['timezone']);
                    if ($local->format('H:i') < $agent['time']) {
                        continue;
                    }
                    $owner = User::find($agent['owner_id']);
                    if (! $owner) {
                        continue;
                    }
                    // One durable request per local calendar day, including DST and scheduler restarts.
                    $uuid = (string) \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'everbranch-followup:'.$tenant->id.':'.$agent['id'].':'.$local->toDateString());
                    try {
                        $this->run($tenant, $owner, $agent['id'], $uuid);
                    } catch (Throwable) { /* Revoked access, caps and pauses fail closed; no provider retry. */
                    }
                }
            }
        });
    }

    private function audit(Tenant $tenant, User $user, string $operation, int|string $id): void
    {
        app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $user->id, 'ai_branch.agent_'.$operation,
            targetType: 'workspace_agent', targetId: $id, confirmation: ['explicit' => $operation !== 'run'],
            context: ['type' => 'customer_followup', 'billing_impact' => 'AI runs use the existing workspace AI allowance; external sends require separate approval.']);
    }
}
