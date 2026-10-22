<?php

namespace App\Services\AiBranch;

use App\Models\AiBranchAccount;
use App\Models\AiBranchConversation;
use App\Models\AiBranchMemory;
use App\Models\Tenant;
use App\Models\TenantAiUsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AiBranchChat
{
    public function __construct(private AiBranchAccess $access, private AiBranchContext $context, private AiBranchProvider $provider) {}

    public function conversation(Tenant $tenant, User $user, int $id): AiBranchConversation
    {
        $this->access->authorize($tenant, $user);
        $conversation = AiBranchConversation::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('expires_at', '>', now())->whereKey($id)->firstOrFail();
        abort_unless(hash_equals($conversation->context_hash, $this->context->scopeHash($tenant, $user)), 409, 'Workspace context or data access changed. Start a new chat.');
        if (app(AiWorkspaceContext::class)->enabled($tenant)) {
            app(AiWorkspacePage::class)->assertHistory($tenant, $user, (array) $conversation->messages);
        }

        return $conversation;
    }

    public function start(Tenant $tenant, User $user): AiBranchConversation
    {
        $this->access->authorize($tenant, $user);

        return DB::transaction(function () use ($tenant, $user): AiBranchConversation {
            AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            abort_if(AiBranchConversation::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('expires_at', '>', now())->count() >= 30, 422, 'Delete an old chat before starting another.');

            return AiBranchConversation::query()->create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'messages' => [],
                'context_hash' => $this->context->scopeHash($tenant, $user),
                'expires_at' => now()->addDays((int) config(app(AiWorkspaceWorkflows::class)->enabled($tenant, $user) ? 'ai_branch.workspace_history_days' : 'ai_branch.history_days')),
            ]);
        }, 3);
    }

    public function usage(Tenant $tenant): array
    {
        $events = TenantAiUsageEvent::query()->where('tenant_id', $tenant->id)->whereIn('feature', ['ai_branch_chat', 'ai_branch_audio'])
            ->when(! app(AiFictionalPilot::class)->reserved($tenant), fn ($query) => $query->where('occurred_at', '>=', now('UTC')->startOfMonth()));

        return ['responses' => (clone $events)->where('feature', 'ai_branch_chat')->count(), 'provider_micros' => (int) (clone $events)->sum('provider_cost_micros'), 'buyer_micros' => (int) $events->sum('buyer_charge_micros')];
    }

    public function ask(Tenant $tenant, User $user, int $conversationId, string $question, string $uuid, array $origin = []): array
    {
        return $this->run($tenant, $user, $conversationId, $question, $uuid, $origin, 'chat');
    }

    public function agentFollowup(Tenant $tenant, User $user, int $conversationId, string $question, string $uuid): array
    {
        return $this->run($tenant, $user, $conversationId, $question, $uuid, [], 'agent_followup');
    }

    public function briefing(Tenant $tenant, User $user, string $uuid, array $origin = []): array
    {
        app(AiWorkspaceContext::class)->authorize($tenant, $user);

        return $this->run($tenant, $user, null, AiAttentionBriefing::QUESTION, $uuid, $origin, 'briefing');
    }

    private function metric(Tenant $tenant, User $user, string $uuid, string $purpose, string $outcome, float $started, array $counts = [], array $usage = []): void
    {
        Log::info('ai_branch.request', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $uuid, 'purpose' => $purpose,
            'context_version' => AiWorkspaceContext::VERSION, 'adapter_counts' => $counts,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000), 'outcome' => $outcome,
            ...array_intersect_key($usage, array_flip(['input_tokens', 'output_tokens', 'provider_cost_micros'])),
        ]);
    }

    private function run(Tenant $tenant, User $user, ?int $conversationId, string $question, string $uuid, array $origin, string $purpose): array
    {
        $started = microtime(true);
        $tier = $this->access->authorize($tenant, $user);
        $enhanced = app(AiWorkspaceContext::class)->enabled($tenant);
        abort_unless(Str::isUuid($uuid) && trim($question) !== '' && mb_strlen($question) <= 3000, 422);
        abort_if(! $enhanced && $origin !== [], 422, 'Page context is unavailable.');
        $origin = $enhanced ? app(AiWorkspacePage::class)->normalize($origin) : [];
        $settings = $this->provider->settings($tenant);
        $settings['workspace_workflows'] = in_array($purpose, ['chat', 'agent_followup'], true) && app(AiWorkspaceWorkflows::class)->enabled($tenant, $user)
            && ! isset($settings['fictional_scope']);
        $fingerprint = hash('sha256', json_encode($enhanced
            ? [$user->id, $conversationId, $question, $purpose, $origin]
            : [$user->id, $conversationId, $question], JSON_THROW_ON_ERROR));
        try {
            $reservation = DB::transaction(function () use ($tenant, $user, $conversationId, $question, $uuid, $fingerprint, $settings, $origin, $purpose, $enhanced): array {
                $account = AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
                $tier = $this->access->authorize($tenant, $user);
                $existing = TenantAiUsageEvent::query()->where('tenant_id', $tenant->id)->where('client_uuid', $uuid)->first();
                if ($existing) {
                    abort_unless((int) $existing->user_id === (int) $user->id && hash_equals((string) data_get($existing->metadata, 'request_hash'), $fingerprint), 409, 'This request identifier is already in use.');
                    $conversation = $this->conversation($tenant, $user, (int) data_get($existing->metadata, 'conversation_id'));
                    $message = collect($conversation->messages)->first(fn ($m) => ($m['uuid'] ?? '') === $uuid && $m['role'] === 'assistant');
                    abort_unless($existing->status === 'settled' && $message, 409, 'This request is pending or requires usage review.');

                    return ['replay' => $message];
                }
                // New briefing conversation and usage reservation commit together.
                $conversation = $conversationId === null ? $this->start($tenant, $user) : $this->conversation($tenant, $user, $conversationId);
                $conversation = AiBranchConversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                abort_if($conversation->pending_uuid !== null, 409, 'This chat already has a pending request.');
                $messages = (array) $conversation->messages;
                abort_if(count($messages) >= 40, 422, 'Start a new chat to keep context manageable.');
                $snapshot = $enhanced ? app(AiWorkspaceContext::class)->capture($tenant, $user, $origin, $purpose) : null;
                $sources = $snapshot['sources'] ?? $this->context->sources($tenant, $user);
                $workflowContext = $settings['workspace_workflows'] ? app(AiWorkspaceWorkflows::class)->context($tenant, $user, $question, $messages) : null;
                if ($workflowContext) {
                    $sources = collect([...$sources, ...collect($workflowContext['customers'])->map(fn ($customer) => \Illuminate\Support\Arr::only($customer, ['id', 'ref', 'type', 'title', 'url']))->all(),
                        ...collect($workflowContext['customers'])->flatMap(fn ($customer) => $customer['jobs'])->all()])->unique('ref')->take(30)->values()->all();
                }
                $memories = AiBranchMemory::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('expires_at', '>', now())->orderBy('id')->limit(10)->get()->pluck('body')->all();
                $input = [['role' => 'user', 'content' => json_encode([
                    ...($snapshot ? ['untrusted_workspace_context' => $snapshot] : ['untrusted_workspace_evidence' => $sources]),
                    'untrusted_personal_memory' => $memories,
                    ...($workflowContext ? ['untrusted_workflow_context' => $workflowContext] : []),
                ], JSON_THROW_ON_ERROR)]];
                foreach ($messages as $message) {
                    $input[] = ['role' => $message['role'], 'content' => $message['text']];
                }
                $input[] = ['role' => 'user', 'content' => $question];
                $overhead = strlen(AiBranchProvider::INSTRUCTIONS) + ($settings['workspace_workflows'] ? strlen(AiWorkspaceWorkflows::INSTRUCTIONS) + strlen(json_encode(AiWorkspaceWorkflows::format(), JSON_THROW_ON_ERROR)) + 1024 : 2048);
                abort_if(strlen(json_encode($input, JSON_THROW_ON_ERROR)) + $overhead > $settings['max_input'], 422, 'Start a new chat or shorten the question and memory.');
                $usage = $this->usage($tenant);
                $reservedMicros = $this->provider->cost($settings['max_input'], $settings['max_output'], $settings);
                $cap = min($account->budget_cents, (int) $tier['ai_limits']['provider_budget_cents']) * 10000;
                if (isset($settings['pilot_budget_cents'])) {
                    $cap = min($cap, $settings['pilot_budget_cents'] * 10000);
                }
                $multiplier = (int) ($settings['buyer_multiplier'] ?? 0);
                $reservedBuyer = $reservedMicros * $multiplier;
                abort_unless($usage['responses'] < $tier['ai_limits']['responses'] && $cap > 0
                    && $cap >= ($multiplier ? $usage['buyer_micros'] + $reservedBuyer : $usage['provider_micros'] + $reservedMicros), 429, 'This workspace has reached its AI allowance.');
                $counts = array_map(fn ($entry) => $entry['returned'], $snapshot['coverage'] ?? []);
                $event = TenantAiUsageEvent::query()->create([
                    'tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => $uuid, 'feature' => 'ai_branch_chat',
                    'context' => 'workspace', 'model' => $settings['model'], 'status' => 'reserved', 'duration_seconds' => 0,
                    'provider_cost_micros' => $reservedMicros, 'buyer_charge_micros' => $reservedBuyer, 'occurred_at' => now('UTC'),
                    'metadata' => ['request_hash' => $fingerprint, 'conversation_id' => $conversation->id, 'settings' => $settings,
                        'billing_scope' => $multiplier ? 'ai_prepaid' : (isset($settings['fictional_scope']) ? (($settings['fictional_provider'] ?? '') === 'openai' ? 'fictional_capped_provider' : 'fictional_no_charge') : 'included_subscription'), 'purpose' => $purpose, 'context_version' => $snapshot['version'] ?? 1, 'adapter_counts' => $counts],
                ]);
                if ($multiplier) {
                    app(\App\Services\Marketing\Messaging\TenantMessagingUsageService::class)->reserveAi($event, $reservedBuyer, $settings);
                }
                $conversation->forceFill(['pending_uuid' => $uuid])->save();

                return compact('event', 'input', 'sources', 'snapshot', 'counts');
            }, 3);
        } catch (Throwable $exception) {
            if ($enhanced) {
                $this->metric($tenant, $user, $uuid, $purpose, 'rejected', $started);
            }
            throw $exception;
        }
        if (isset($reservation['replay'])) {
            if ($enhanced) {
                $this->metric($tenant, $user, $uuid, $purpose, 'replayed', $started);
            }

            return $reservation['replay'];
        }
        $conversationId = (int) data_get($reservation['event']->metadata, 'conversation_id');
        try {
            $result = $this->provider->complete($reservation['input'], $settings, $purpose);
        } catch (Throwable) {
            $reservation['event']->forceFill(['status' => 'uncertain'])->save();
            if ($enhanced) {
                $this->metric($tenant, $user, $uuid, $purpose, 'uncertain', $started, $reservation['counts']);
            }
            abort(503, 'AI could not confirm this response. Its allowance is reserved for review; start a new chat if needed.', ['X-AI-Conversation' => (string) $conversationId]);
        }
        $message = DB::transaction(function () use ($tenant, $user, $conversationId, $question, $uuid, $reservation, $result, $settings, $purpose): array {
            AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $conversation = AiBranchConversation::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereKey($conversationId)->lockForUpdate()->firstOrFail();
            $event = TenantAiUsageEvent::query()->where('tenant_id', $tenant->id)->whereKey($reservation['event']->id)->lockForUpdate()->firstOrFail();
            abort_unless($event->status === 'reserved' && $conversation->pending_uuid === $uuid, 409);
            $cost = $this->provider->cost($result['input_tokens'], $result['output_tokens'], $settings, $result['cached_input_tokens'] ?? 0);
            if (isset($settings['buyer_multiplier'])) {
                $event->provider_request_id = $result['provider_id'];
                app(\App\Services\Marketing\Messaging\TenantMessagingUsageService::class)->settleAi($event, $cost);
            }
            $workflowAction = null;
            $workflowNotice = null;
            if ($settings['workspace_workflows'] && ($result['draft']['kind'] ?? 'none') !== 'none') {
                try {
                    abort_if($purpose === 'agent_followup' && $result['draft']['kind'] !== 'customer_email', 422);
                    $workflowAction = app(AiWorkspaceWorkflows::class)->propose($tenant, $user, $result['draft'], (string) Str::uuid());
                } catch (\Illuminate\Validation\ValidationException|\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpException) {
                    // Generation was billed; an invalid proposal never loses the answer or replays the provider.
                    $workflowNotice = 'The proposed details need clarification. No action was executed. Check the required details and try again.';
                }
            }
            $message = ['role' => 'assistant', 'uuid' => $uuid, 'text' => $result['reply'], 'sources' => $reservation['sources'],
                ...($workflowAction ? ['workflow_action_id' => $workflowAction->id] : []), ...($workflowNotice ? ['workflow_notice' => $workflowNotice] : []),
                'conversation_id' => $conversationId, 'purpose' => $purpose, 'generated_at' => now('UTC')->toIso8601String(),
                ...(isset($settings['buyer_multiplier']) ? ['usage' => ['model' => $settings['model'], 'input_tokens' => $result['input_tokens'],
                    'cached_input_tokens' => $result['cached_input_tokens'] ?? 0, 'output_tokens' => $result['output_tokens'], 'charge_micros' => $cost * 2]] : []),
                ...($reservation['snapshot'] ? ['workspace_context' => $reservation['snapshot']] : [])];
            $conversation->forceFill(['messages' => [...(array) $conversation->messages, ['role' => 'user', 'uuid' => $uuid, 'text' => $question], $message], 'pending_uuid' => null,
                ...($settings['workspace_workflows'] ? ['expires_at' => now()->addDays((int) config('ai_branch.workspace_history_days'))] : [])])->save();
            $event->forceFill(['status' => 'settled', 'provider_request_id' => $result['provider_id'],
                'provider_cost_micros' => $cost, 'buyer_charge_micros' => $cost * (int) ($settings['buyer_multiplier'] ?? 0),
                'metadata' => [...$event->metadata, 'input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens'], 'cached_input_tokens' => $result['cached_input_tokens'] ?? 0],
            ])->save();

            return $message;
        }, 3);
        try {
            $this->conversation($tenant, $user, $conversationId);
        } catch (Throwable $exception) {
            if ($enhanced) {
                $this->metric($tenant, $user, $uuid, $purpose, 'access_changed', $started, $reservation['counts']);
            }
            throw $exception;
        }
        if ($enhanced) {
            $this->metric($tenant, $user, $uuid, $purpose, 'settled', $started, $reservation['counts'], [...$result, 'provider_cost_micros' => $this->provider->cost($result['input_tokens'], $result['output_tokens'], $settings, $result['cached_input_tokens'] ?? 0)]);
        }

        return $message;
    }
}
