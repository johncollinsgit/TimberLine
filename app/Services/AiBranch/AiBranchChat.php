<?php

namespace App\Services\AiBranch;

use App\Models\AiBranchAccount;
use App\Models\AiBranchConversation;
use App\Models\AiBranchMemory;
use App\Models\Tenant;
use App\Models\TenantAiUsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        abort_unless(hash_equals($conversation->context_hash, $this->context->scopeHash($tenant, $user)), 409, 'Data access changed. Start a new chat.');

        return $conversation;
    }

    public function start(Tenant $tenant, User $user): AiBranchConversation
    {
        $this->access->authorize($tenant, $user);

        abort_if(AiBranchConversation::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('expires_at', '>', now())->count() >= 30, 422, 'Delete an old chat before starting another.');

        return AiBranchConversation::query()->create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'messages' => [],
            'context_hash' => $this->context->scopeHash($tenant, $user),
            'expires_at' => now()->addDays((int) config('ai_branch.history_days')),
        ]);
    }

    public function usage(Tenant $tenant): array
    {
        $events = TenantAiUsageEvent::query()->where('tenant_id', $tenant->id)->where('feature', 'ai_branch_chat')
            ->where('occurred_at', '>=', now('UTC')->startOfMonth());

        return ['responses' => (clone $events)->count(), 'provider_micros' => (int) $events->sum('provider_cost_micros')];
    }

    public function ask(Tenant $tenant, User $user, int $conversationId, string $question, string $uuid): array
    {
        $tier = $this->access->authorize($tenant, $user);
        abort_unless(Str::isUuid($uuid) && trim($question) !== '' && mb_strlen($question) <= 3000, 422);
        $settings = $this->provider->settings();
        $fingerprint = hash('sha256', json_encode([$user->id, $conversationId, $question], JSON_THROW_ON_ERROR));
        $reservation = DB::transaction(function () use ($tenant, $user, $conversationId, $question, $uuid, $fingerprint, $settings, $tier): array {
            $account = AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($tenant, $user);
            $conversation = $this->conversation($tenant, $user, $conversationId);
            $conversation = AiBranchConversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $existing = TenantAiUsageEvent::query()->where('tenant_id', $tenant->id)->where('client_uuid', $uuid)->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === (int) $user->id && hash_equals((string) data_get($existing->metadata, 'request_hash'), $fingerprint), 409, 'This request identifier is already in use.');
                $message = collect($conversation->messages)->first(fn ($m) => ($m['uuid'] ?? '') === $uuid && $m['role'] === 'assistant');
                abort_unless($existing->status === 'settled' && $message, 409, 'This request is pending or requires usage review.');

                return ['replay' => $message];
            }
            abort_if($conversation->pending_uuid !== null, 409, 'This chat already has a pending request.');
            $messages = (array) $conversation->messages;
            abort_if(count($messages) >= 40, 422, 'Start a new chat to keep context manageable.');
            $sources = $this->context->sources($tenant, $user);
            $memories = AiBranchMemory::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('expires_at', '>', now())->orderBy('id')->limit(10)->get()->pluck('body')->all();
            // Context is lower-trust input, never interpolated into instructions.
            $input = [['role' => 'user', 'content' => json_encode(['untrusted_workspace_evidence' => $sources, 'untrusted_personal_memory' => $memories], JSON_THROW_ON_ERROR)]];
            foreach ($messages as $message) {
                $input[] = ['role' => $message['role'], 'content' => $message['text']];
            }
            $input[] = ['role' => 'user', 'content' => $question];
            // A UTF-8 byte ceiling is conservative for token reservation; allow
            // framing overhead and instructions rather than estimating by words.
            abort_if(strlen(json_encode($input, JSON_THROW_ON_ERROR)) + strlen(AiBranchProvider::INSTRUCTIONS) + 2048 > $settings['max_input'], 422, 'Start a new chat or shorten the question and memory.');
            $usage = $this->usage($tenant);
            $reservedMicros = $this->provider->cost($settings['max_input'], $settings['max_output'], $settings);
            $cap = min($account->budget_cents, (int) $tier['ai_limits']['provider_budget_cents']) * 10000;
            abort_unless($usage['responses'] < $tier['ai_limits']['responses'] && $cap > 0 && $cap >= $usage['provider_micros'] + $reservedMicros, 429, 'This workspace has reached its AI allowance.');
            $event = TenantAiUsageEvent::query()->create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => $uuid, 'feature' => 'ai_branch_chat',
                'context' => 'workspace', 'model' => $settings['model'], 'status' => 'reserved', 'duration_seconds' => 0,
                'provider_cost_micros' => $reservedMicros, 'buyer_charge_micros' => 0, 'occurred_at' => now('UTC'),
                'metadata' => ['request_hash' => $fingerprint, 'conversation_id' => $conversation->id, 'settings' => $settings, 'billing_scope' => 'included_subscription'],
            ]);
            $conversation->forceFill(['pending_uuid' => $uuid])->save();

            return compact('event', 'input', 'sources');
        }, 3);
        if (isset($reservation['replay'])) {
            return $reservation['replay'];
        }
        try {
            $result = $this->provider->complete($reservation['input'], $settings);
        } catch (Throwable) {
            // Keep reservation and pending marker. Never assume a timeout was free.
            $reservation['event']->forceFill(['status' => 'uncertain'])->save();
            abort(503, 'AI could not confirm this response. Its allowance is reserved for review; start a new chat if needed.');
        }

        $message = DB::transaction(function () use ($tenant, $user, $conversationId, $question, $uuid, $reservation, $result, $settings): array {
            // Always account for an already dispatched request, including after
            // opt-out or membership revocation. Do not send a second provider call.
            AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $conversation = AiBranchConversation::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereKey($conversationId)->lockForUpdate()->firstOrFail();
            $event = TenantAiUsageEvent::query()->where('tenant_id', $tenant->id)->whereKey($reservation['event']->id)->lockForUpdate()->firstOrFail();
            abort_unless($event->status === 'reserved' && $conversation->pending_uuid === $uuid, 409);
            $message = ['role' => 'assistant', 'uuid' => $uuid, 'text' => $result['reply'], 'sources' => $reservation['sources']];
            $conversation->forceFill(['messages' => [...(array) $conversation->messages, ['role' => 'user', 'uuid' => $uuid, 'text' => $question], $message], 'pending_uuid' => null])->save();
            $event->forceFill(['status' => 'settled', 'provider_request_id' => $result['provider_id'],
                'provider_cost_micros' => $this->provider->cost($result['input_tokens'], $result['output_tokens'], $settings),
                'metadata' => [...$event->metadata, 'input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens']],
            ])->save();

            return $message;
        }, 3);
        $this->conversation($tenant, $user, $conversationId);

        return $message;
    }
}
