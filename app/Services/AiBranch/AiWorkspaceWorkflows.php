<?php

namespace App\Services\AiBranch;

use App\Models\AiBranchAccount;
use App\Models\AiBranchAction;
use App\Models\FieldServiceJob;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\Email\TenantEmailDispatchService;
use App\Services\Marketing\Email\TenantEmailProviderResolver;
use App\Services\Marketing\MarketingEmailUnsubscribeService;
use App\Services\Tenancy\TenantBrandProfileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AiWorkspaceWorkflows
{
    public const KINDS = ['workspace_create_job', 'workspace_customer_email', 'workspace_create_followup_agent'];

    public function enabled(Tenant $tenant, User $user): bool
    {
        return (bool) config('ai_branch.workspace_workflows_enabled') && app(AiAssistantAccess::class)->allows($tenant, $user)
            && app(AiWorkspaceContext::class)->enabled($tenant) && ! app(AiFictionalPilot::class)->reserved($tenant)
            && ! is_array(config('ai_branch.fictional_request'));
    }

    public function authorize(Tenant $tenant, User $user): void
    {
        abort_unless($this->enabled($tenant, $user), 404);
        app(AiWorkspaceContext::class)->authorize($tenant, $user);
    }

    private function customer(Tenant $tenant, User $user, int $id): MarketingProfile
    {
        $this->authorize($tenant, $user);
        abort_unless(app(AiWorkspaceContext::class)->permissions($tenant, $user)['customers'], 403);

        return MarketingProfile::where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id')->findOrFail($id);
    }

    public function customers(Tenant $tenant, User $user, string $query): array
    {
        $this->authorize($tenant, $user);
        abort_unless(app(AiWorkspaceContext::class)->permissions($tenant, $user)['customers'], 403);
        $query = trim($query);
        abort_unless(mb_strlen($query) >= 2 && mb_strlen($query) <= 100, 422, 'Enter at least two letters of the customer name.');
        // Literal name matching, bounded before serialization; no cross-tenant identities.
        $words = array_slice(preg_split('/\s+/u', $query) ?: [], 0, 8);
        $profiles = MarketingProfile::where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id');
        foreach ($words as $word) {
            $needle = '%'.addcslashes($word, '%_\\').'%';
            $profiles->where(fn ($q) => $q->where('first_name', 'like', $needle)->orWhere('last_name', 'like', $needle));
        }

        return $profiles->orderBy('last_name')->orderBy('id')->limit(10)->get()->map(fn ($profile) => $this->customerEvidence($tenant, $user, $profile))->all();
    }

    private function customerEvidence(Tenant $tenant, User $user, MarketingProfile $profile): array
    {
        $jobs = app(AiWorkspaceSources::class)->jobsQuery($tenant, $user)->where('marketing_profile_id', $profile->id)
            ->latest('id')->limit(5)->get()->map(fn ($job) => app(AiWorkspaceSources::class)->jobRecord($job, $tenant))->all();

        return ['id' => $profile->id, 'name' => trim($profile->first_name.' '.$profile->last_name),
            'ref' => 'customer:'.$profile->id, 'type' => 'customer', 'title' => trim($profile->first_name.' '.$profile->last_name),
            'url' => route('marketing.customers.show', ['marketingProfile' => $profile->id, 'tenant' => $tenant->slug]),
            'jobs' => $jobs, 'notes' => mb_substr(strip_tags((string) $profile->notes), 0, 1000),
            'coverage' => 'Current customer record and up to five permitted current jobs. Other channels are not included.'];
    }

    public function context(Tenant $tenant, User $user, string $question, array $messages): array
    {
        $this->authorize($tenant, $user);
        $permissions = app(AiWorkspaceContext::class)->permissions($tenant, $user);
        $customers = [];
        if ($permissions['customers']) {
            // Current turn plus recent user turns supports pronouns without dumping the directory.
            $text = $question.' '.collect($messages)->where('role', 'user')->take(-2)->pluck('text')->implode(' ');
            $tokens = array_slice(array_values(array_unique(preg_split('/[^\pL\pN]+/u', mb_strtolower($text)) ?: [])), 0, 80);
            $ignored = explode(' ', 'the and for with that this what when where which about customer customers email send create job jobs please next friday monday tuesday wednesday thursday saturday sunday tomorrow today their them they her his our your can could would should have from');
            $tokens = array_values(array_filter($tokens, fn ($word) => mb_strlen($word) >= 3 && ! in_array($word, $ignored, true)));
            if ($tokens !== []) {
                $profiles = MarketingProfile::where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id')
                    ->where(function ($q) use ($tokens) {
                        foreach (array_slice($tokens, 0, 20) as $word) {
                            $q->orWhere('normalized_first_name', $word)->orWhere('normalized_last_name', $word);
                        }
                    })->orderBy('id')->limit(10)->get();
                $customers = $profiles->map(fn ($profile) => $this->customerEvidence($tenant, $user, $profile))->all();
            }
        }
        $brand = app(TenantBrandProfileService::class)->presentationFor($tenant);

        return ['customers' => $customers, 'workspace_memory' => app(AiWorkspaceMemory::class)->listing($tenant, $user),
            'business' => ['name' => $brand['display_name'] ?? $tenant->name, 'timezone' => app(AiWorkspaceContext::class)->timezone($tenant), 'today' => now(app(AiWorkspaceContext::class)->timezone($tenant))->toDateString()],
            'assignees' => $permissions['jobs'] ? $tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->orderBy('users.name')->limit(100)->get(['users.id', 'users.name'])->toArray() : [],
            'can_create_job' => $permissions['jobs'] && (bool) config('ai_branch.actions_enabled'),
            'can_prepare_email' => $permissions['customers'] && (bool) config('ai_branch.actions_enabled'),
            'can_create_followup_agent' => $permissions['customers'] && (bool) config('ai_branch.actions_enabled') && (bool) config('ai_branch.workspace_agents_enabled'),
            'agents' => $permissions['customers'] && config('ai_branch.actions_enabled') && config('ai_branch.workspace_agents_enabled')
                && app(AiBranchAccess::class)->authorize($tenant, $user)['ai_limits']['actions']
                ? collect(app(AiWorkspaceAgents::class)->listing($tenant, $user))->map(fn ($agent) => \Illuminate\Support\Arr::only($agent, ['id', 'name', 'type', 'active', 'cadence', 'time', 'timezone', 'runs']))->all() : [],
            'recent_actions' => collect($this->listing($tenant, $user))->take(5)->map(fn ($action) => ['kind' => $action['kind'], 'status' => $action['status'], 'result_id' => $action['result_id'], 'customer_name' => $action['payload']['customer_name'] ?? null, 'title' => $action['payload']['title'] ?? null])->all(),
            'coverage' => 'Matching customer names only, at most ten matches and five jobs each. Ask which customer when names are ambiguous. Email addresses are resolved by the server during review.'];
    }

    public static function format(): array
    {
        $fields = ['kind' => ['type' => 'string', 'enum' => ['none', 'create_job', 'customer_email', 'create_followup_agent']],
            'customer_id' => ['type' => ['integer', 'null']], 'job_id' => ['type' => ['integer', 'null']],
            'assignee_id' => ['type' => ['integer', 'null']]];
        foreach (['title', 'description', 'address', 'scheduled_local', 'scheduled_offset', 'subject', 'body', 'agent_name', 'agent_instructions', 'agent_cadence', 'agent_time'] as $field) {
            $fields[$field] = ['type' => ['string', 'null']];
        }

        return ['format' => ['type' => 'json_schema', 'name' => 'everbranch_workspace_reply', 'strict' => true,
            'schema' => ['type' => 'object', 'properties' => ['reply' => ['type' => 'string'],
                'draft' => ['type' => 'object', 'properties' => $fields, 'required' => array_keys($fields), 'additionalProperties' => false]],
                'required' => ['reply', 'draft'], 'additionalProperties' => false]]];
    }

    public const INSTRUCTIONS = ' Return a structured reply and optional draft. Never execute actions. Respect the supplied action availability flags. Only prepare a draft when the signed-in person explicitly requests it. Use only customer and assignee IDs supplied in current evidence, never guess IDs. If names are ambiguous or required details are missing, ask one brief clarifying question and set draft.kind=none. For create_job collect customer, title, work description and address; ask for missing schedule/time when requested, use scheduled_local YYYY-MM-DDTHH:MM in workspace timezone and scheduled_offset when DST is ambiguous. For email collect recipient, subject and complete plain-text body using the business context and saved workspace preferences. Customer email is marketing unless linked to an explicitly selected existing job for that same customer. Prepare one action at a time; finish creating the job before preparing its email. For create_followup_agent collect agent_name, agent_instructions and agent_cadence (manual or daily); daily also requires an explicit agent_time HH:MM. The only available agent is customer follow-up, which prepares drafts for review. Job coordinator and daily briefing agents are on the roadmap. User approval happens in the review card, never through speech. Set unused fields to null. Do not say an action is saved or sent until a server receipt is supplied.';

    public function propose(Tenant $tenant, User $user, array $input, string $uuid): AiBranchAction
    {
        $this->authorize($tenant, $user);
        abort_unless(config('ai_branch.actions_enabled') && app(AiBranchAccess::class)->authorize($tenant, $user)['ai_limits']['actions'], 403);
        if (($input['kind'] ?? '') === 'create_followup_agent') {
            return app(AiWorkspaceAgents::class)->propose($tenant, $user, $input, $uuid);
        }
        $data = Validator::make($input, ['kind' => 'required|in:create_job,customer_email', 'customer_id' => 'required|integer|min:1',
            'job_id' => 'nullable|integer|min:1', 'title' => 'nullable|string|max:255', 'description' => 'nullable|string|max:5000',
            'address' => 'nullable|string|max:255', 'scheduled_local' => 'nullable|date_format:Y-m-d\TH:i', 'scheduled_offset' => ['nullable', 'regex:/^[+-]\d{2}:\d{2}$/'],
            'assignee_id' => 'nullable|integer|min:1', 'subject' => ['nullable', 'string', 'max:200', 'not_regex:/[\r\n]/'], 'body' => 'nullable|string|max:5000'])->validate();
        abort_unless(Str::isUuid($uuid), 422);
        $customer = $this->customer($tenant, $user, $data['customer_id']);
        $payload = ['scope_hash' => app(AiWorkspaceContext::class)->stamp($tenant, $user), 'customer_id' => $customer->id, 'customer_name' => trim($customer->first_name.' '.$customer->last_name),
            'customer_hash' => hash('sha256', json_encode($customer->getRawOriginal(), JSON_THROW_ON_ERROR))];
        if ($data['kind'] === 'create_job') {
            abort_unless(app(AiWorkspaceContext::class)->permissions($tenant, $user)['jobs'], 403);
            abort_unless(filled($data['title'] ?? '') && filled($data['description'] ?? '') && filled($data['address'] ?? ''), 422, 'Provide the job title, work description and address.');
            $schedule = filled($data['scheduled_local'] ?? '') ? app(WorkspaceWallTime::class)->resolve($data['scheduled_local'], app(AiWorkspaceContext::class)->timezone($tenant), $data['scheduled_offset'] ?? null) : null;
            $assignee = empty($data['assignee_id']) ? null : $tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->findOrFail($data['assignee_id']);
            $payload += ['title' => trim($data['title']), 'description' => trim($data['description']), 'address' => trim($data['address']), 'schedule' => $schedule,
                'assignee_id' => $assignee?->id, 'assignee_name' => $assignee?->name];
        } else {
            abort_unless(filled($data['subject'] ?? '') && filled($data['body'] ?? '') && filter_var($customer->email, FILTER_VALIDATE_EMAIL), 422, 'A saved customer email, subject and message are required.');
            $job = empty($data['job_id']) ? null : app(AiBranchContext::class)->job($tenant, $user, $data['job_id']);
            abort_if($job && (int) $job->marketing_profile_id !== $customer->id, 422, 'The job must belong to the selected customer.');
            $sender = $this->sender($tenant);
            $brand = app(TenantBrandProfileService::class)->presentationFor($tenant);
            $payload += ['to' => $customer->email, 'subject' => trim($data['subject']), 'body' => trim($data['body']), 'sender' => $sender,
                'brand' => ['name' => $brand['display_name'] ?? $tenant->name, 'color' => $brand['primary_color'] ?? '#174f46'],
                'job_id' => $job?->id, 'job_title' => $job?->title, 'job_hash' => $job ? hash('sha256', json_encode($job->getRawOriginal(), JSON_THROW_ON_ERROR)) : null,
                'is_marketing' => $job === null];
        }
        $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $user, $uuid, $data, $payload, $digest) {
            AiBranchAccount::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $existing = AiBranchAction::where('tenant_id', $tenant->id)->where('client_uuid', $uuid)->first();
            if ($existing) {
                abort_unless($existing->user_id == $user->id && $existing->kind === 'workspace_'.$data['kind'] && hash_equals($existing->digest, $digest), 409);

                return $existing;
            }

            return AiBranchAction::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => $uuid,
                'kind' => 'workspace_'.$data['kind'], 'payload' => $payload, 'digest' => $digest, 'status' => 'pending', 'expires_at' => now()->addMinutes(15)]);
        }, 3);
    }

    private function sender(Tenant $tenant): array
    {
        $resolution = app(TenantEmailProviderResolver::class)->getEmailProviderForTenant($tenant->id);
        $settings = $resolution['settings'];

        // Never use another workspace's platform fallback sender.
        return ['provider' => $resolution['provider']->key(), 'from_email' => $settings['from_email'] ?? null,
            'from_name' => $settings['from_name'] ?? $tenant->name, 'reply_to_email' => $settings['reply_to_email'] ?? null,
            'ready' => ($settings['source'] ?? '') === 'tenant_email_settings' && ($settings['email_enabled'] ?? false)
                && ($resolution['configuration_issues'] ?? []) === []];
    }

    public function present(AiBranchAction $action): array
    {
        return ['id' => $action->id, 'kind' => $action->kind, 'status' => $action->status, 'digest' => $action->digest,
            'payload' => $action->payload, 'expires_at' => $action->expires_at?->toIso8601String(), 'result_id' => $action->result_id];
    }

    public function listing(Tenant $tenant, User $user): array
    {
        $this->authorize($tenant, $user);
        $scope = app(AiWorkspaceContext::class)->stamp($tenant, $user);

        return AiBranchAction::where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereIn('kind', self::KINDS)->latest('id')->limit(20)->get()
            ->filter(function ($action) use ($tenant, $user, $scope) {
                if (! hash_equals($scope, (string) ($action->payload['scope_hash'] ?? ''))) {
                    return false;
                }
                if ($action->kind === 'workspace_create_followup_agent') {
                    return (bool) config('ai_branch.workspace_agents_enabled');
                }
                try {
                    $this->customer($tenant, $user, (int) ($action->payload['customer_id'] ?? 0));

                    return true;
                } catch (HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return false;
                }
            })->map(fn ($action) => $this->present($action))->values()->all();
    }

    public function decide(Tenant $tenant, User $user, int $id, string $digest, bool $approve): AiBranchAction
    {
        $this->authorize($tenant, $user);
        abort_unless(config('ai_branch.actions_enabled') && app(AiBranchAccess::class)->authorize($tenant, $user)['ai_limits']['actions'], 403);
        if (AiBranchAction::where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereKey($id)->value('kind') === 'workspace_create_followup_agent') {
            return app(AiWorkspaceAgents::class)->decide($tenant, $user, $id, $digest, $approve);
        }
        $dispatch = DB::transaction(function () use ($tenant, $user, $id, $digest, $approve) {
            AiBranchAccount::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $action = AiBranchAction::where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereIn('kind', self::KINDS)->lockForUpdate()->findOrFail($id);
            abort_unless(hash_equals($action->digest, $digest) && hash_equals($digest, hash('sha256', json_encode($action->payload, JSON_THROW_ON_ERROR))), 409);
            abort_unless(hash_equals((string) ($action->payload['scope_hash'] ?? ''), app(AiWorkspaceContext::class)->stamp($tenant, $user)), 409, 'Workspace access changed. Prepare a new preview.');
            $customer = $this->customer($tenant, $user, $action->payload['customer_id']);
            if ($action->status !== 'pending') {
                abort_unless(in_array($action->status, $approve ? ['executed', 'sending', 'unknown', 'failed'] : ['rejected'], true), 409);

                return ['action' => $action];
            }
            abort_unless($action->expires_at->isFuture(), 409, 'The preview expired. Prepare another.');
            abort_unless(hash_equals($action->payload['customer_hash'], hash('sha256', json_encode($customer->getRawOriginal(), JSON_THROW_ON_ERROR))), 409, 'The customer changed. Prepare a fresh preview.');
            if (! $approve) {
                $action->forceFill(['status' => 'rejected', 'decided_at' => now()])->save();

                return ['action' => $action];
            }
            if ($action->kind === 'workspace_create_job') {
                $payload = $action->payload;
                abort_unless(app(AiWorkspaceContext::class)->permissions($tenant, $user)['jobs'], 403);
                $assigned = $payload['assignee_id'] ? $tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->findOrFail($payload['assignee_id']) : null;
                abort_unless(! $assigned || $assigned->name === $payload['assignee_name'], 409, 'Assignment changed. Prepare another preview.');
                abort_unless(! $payload['schedule'] || $payload['schedule']['timezone'] === app(AiWorkspaceContext::class)->timezone($tenant), 409, 'Workspace timezone changed.');
                $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'customer_name' => $payload['customer_name'],
                    'customer_email' => $customer->email, 'customer_phone' => $customer->phone, 'title' => $payload['title'], 'description' => $payload['description'],
                    'service_address_line_1' => $payload['address'], 'scheduled_for' => $payload['schedule']['utc'] ?? null, 'assigned_user_id' => $assigned?->id,
                    'priority' => 'normal', 'status' => 'open', 'status_source' => 'system', 'metadata' => ['ai_branch_action_id' => $action->id]]);
                $ids = array_unique([$user->id, ...($assigned ? [$assigned->id] : [])]);
                $job->participants()->sync(collect($ids)->mapWithKeys(fn ($member) => [$member => ['tenant_id' => $tenant->id, 'role' => 'member', 'following' => true]])->all());
                $job->load('participants');
                $job->forceFill(['operational_status' => app(\App\Services\FieldService\FieldServiceJobReadinessService::class)->forJob($job)['ready'] ? 'scheduled' : 'needs_details'])->save();
                $action->forceFill(['status' => 'executed', 'result_id' => $job->id, 'decided_at' => now()])->save();
                app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $user->id, 'ai_branch.job_create', targetType: 'ai_branch_action', targetId: $action->id,
                    confirmation: ['explicit' => true], result: ['job_id' => $job->id]);

                return ['action' => $action];
            }
            $p = $action->payload;
            abort_unless($p['sender'] === $this->sender($tenant) && $p['sender']['ready'], 409, 'Connect and verify this workspace email sender, then prepare a fresh preview.');
            if ($p['job_id']) {
                $job = app(AiBranchContext::class)->job($tenant, $user, $p['job_id']);
                abort_unless(hash_equals($p['job_hash'], hash('sha256', json_encode($job->getRawOriginal(), JSON_THROW_ON_ERROR))) && $job->marketing_profile_id === $customer->id, 409, 'The job changed. Prepare another email.');
            }
            if ($p['is_marketing']) {
                abort_unless($customer->accepts_email_marketing && ! app(MarketingEmailUnsubscribeService::class)->suppressed($tenant->id, $p['to']), 422, 'This customer has not permitted marketing email.');
            }
            $delivery = MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'batch_id' => 'ai-workspace:'.$action->id,
                'source_label' => 'ai_workspace', 'message_subject' => $p['subject'], 'provider' => $p['sender']['provider'], 'campaign_type' => $p['is_marketing'] ? 'direct_message' : 'job_update',
                'email' => $p['to'], 'status' => 'sending', 'metadata' => ['ai_branch_action_id' => $action->id, 'job_id' => $p['job_id'], 'approval_digest' => $digest]]);
            // Commit intent before provider HTTP. Replays/crashes cannot resend it.
            $action->forceFill(['status' => 'sending', 'result_id' => $delivery->id, 'decided_at' => now()])->save();

            return ['action' => $action, 'delivery' => $delivery];
        }, 3);
        $action = $dispatch['action'];
        if (! isset($dispatch['delivery'])) {
            return $action;
        }
        $p = $action->payload;
        $delivery = $dispatch['delivery'];
        try {
            $result = app(TenantEmailDispatchService::class)->sendEmail($p['to'], $p['subject'], $p['body'], [
                'tenant_id' => $tenant->id, 'delivery_id' => $delivery->id, 'customer_id' => $p['customer_id'],
                'idempotency_key' => 'ai-workspace-email:'.$action->id, 'ledger_source_type' => 'marketing_email_delivery', 'source_id' => $delivery->id,
                'no_provider_retry' => true,
                'is_marketing' => $p['is_marketing'], 'campaign_type' => $p['is_marketing'] ? 'direct_message' : 'job_update',
                'html_body' => view('emails.ai-workspace', ['email' => $p])->render(),
                'from_email' => $p['sender']['from_email'], 'from_name' => $p['sender']['from_name'], 'reply_to_email' => $p['sender']['reply_to_email'],
            ]);
            $status = ($result['success'] ?? false) && ! ($result['dry_run'] ?? false) && filled($result['message_id'] ?? null) ? 'executed' : 'unknown';
            // Conditional SQL update also protects a webhook arriving between refresh and save.
            MarketingEmailDelivery::where('tenant_id', $tenant->id)->whereKey($delivery->id)->whereIn('status', ['pending', 'sending', 'unknown'])
                ->update(['provider_message_id' => $result['message_id'] ?? null, 'status' => $status === 'executed' ? 'sent' : 'unknown', 'sent_at' => $status === 'executed' ? now() : null]);
        } catch (Throwable) {
            $status = 'unknown';
            MarketingEmailDelivery::where('tenant_id', $tenant->id)->whereKey($delivery->id)->whereIn('status', ['pending', 'sending'])->update(['status' => 'unknown']);
        }
        $action->forceFill(['status' => $status])->save();
        app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $user->id, 'ai_branch.customer_email', status: $status,
            targetType: 'ai_branch_action', targetId: $action->id, confirmation: ['explicit' => true], result: ['delivery_id' => $delivery->id]);

        return $action;
    }
}
