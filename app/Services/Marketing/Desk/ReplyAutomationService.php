<?php

namespace App\Services\Marketing\Desk;

use App\Models\MarketingConversationControl;
use App\Models\MarketingReplyPolicy;
use App\Models\MessagingConversation;
use App\Models\MessagingConversationMessage;
use App\Models\User;
use App\Services\Marketing\MessagingResponseInboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReplyAutomationService
{
    public function takeover(int $tenantId, int $conversationId, string $reason = 'human_takeover'): void
    {
        if (! app(MarketingDeskAccess::class)->available($tenantId) || app(MarketingReplyContext::class)->automatic) {
            return;
        }
        $conversation = MessagingConversation::forTenantId($tenantId)->findOrFail($conversationId);
        DB::transaction(function () use ($tenantId, $conversation, $reason): void {
            $control = MarketingConversationControl::firstOrCreate(['tenant_id' => $tenantId, 'conversation_id' => $conversation->id]);
            $control = MarketingConversationControl::whereKey($control->id)->lockForUpdate()->firstOrFail();
            $control->update(['mode' => 'human', 'revision' => $control->revision + 1, 'reason' => $reason]);
        }, 3);
        if ($conversation->marketing_profile_id) {
            app(MarketingWorkflowDelivery::class)->stop($tenantId, $conversation->marketing_profile_id, $reason);
        }
    }

    public function process(int $tenantId, int $messageId): void
    {
        if (! app(MarketingDeskAccess::class)->available($tenantId) || ! config('marketing_desk.ai_replies_enabled') || ! config('marketing_desk.delivery_enabled')) {
            return;
        }
        $message = MessagingConversationMessage::forTenantId($tenantId)->where('direction', 'inbound')->findOrFail($messageId);
        $conversation = MessagingConversation::forTenantId($tenantId)->findOrFail($message->conversation_id);
        $latest = $conversation->messages()->where('direction', 'inbound')->latest('received_at')->latest('id')->first();
        if ((int) $latest?->id !== $messageId || $conversation->status !== 'open' || $conversation->assigned_to) {
            return;
        }
        $policies = MarketingReplyPolicy::forTenantId($tenantId)->where('channel', $conversation->channel)->where('store_key', $conversation->store_key ?? '')->where('audience', 'general')->where('enabled', true)->get();
        $existing = MarketingConversationControl::forTenantId($tenantId)->where('conversation_id', $conversation->id)->first();
        $policy = $existing?->policy_id ? MarketingReplyPolicy::forTenantId($tenantId)->where('enabled', true)->find($existing->policy_id) : ($policies->count() === 1 ? $policies->first() : null);
        if (! $policy || $policy->channel !== $conversation->channel || (string) $policy->store_key !== (string) ($conversation->store_key ?? '')) {
            return;
        }
        $control = DB::transaction(function () use ($tenantId, $conversation, $messageId, $policy) {
            $control = MarketingConversationControl::firstOrCreate(['tenant_id' => $tenantId, 'conversation_id' => $conversation->id], ['mode' => 'auto', 'policy_id' => $policy->id]);
            $control = MarketingConversationControl::whereKey($control->id)->lockForUpdate()->firstOrFail();
            if ($control->mode !== 'auto' || (int) $control->last_message_id === $messageId) {
                return null;
            }
            $control->update(['mode' => 'processing', 'revision' => $control->revision + 1, 'last_message_id' => $messageId, 'policy_id' => $policy->id]);

            return $control;
        }, 3);
        if (! $control) {
            return;
        }
        try {
            $knowledge = app(BrandKnowledgeService::class)->approved($tenantId, $policy->knowledge_version_id, $policy->audience);
            $actor = User::findOrFail($policy->approved_by);
            app(MarketingDeskAccess::class)->ensureDelivery($tenantId, $actor);
            if (preg_match('/\b(refund|complaint|lawyer|cancel|unsubscribe|stop|order number|tracking|password)\b/i', $message->body)) {
                $this->handoff($control, 'needs_person');

                return;
            }
            $uuid = substr(hash('sha256', 'marketing-reply:'.$tenantId.':'.$messageId), 0, 32);
            $request = substr($uuid, 0, 8).'-'.substr($uuid, 8, 4).'-4'.substr($uuid, 13, 3).'-a'.substr($uuid, 17, 3).'-'.substr($uuid, 20, 12);
            $turn = app(MarketingAiService::class)->generate($tenantId, $actor, $request, (string) Str::uuid(), 'reply', [
                'question' => mb_substr($message->body, 0, 3000), 'knowledge' => [['source_id' => 'knowledge:'.$knowledge->id, ...$knowledge->content]],
            ]);
            if ($turn->status !== 'completed' || ($turn->result['handoff'] ?? true)) {
                $this->handoff($control, 'unsupported_answer');

                return;
            }
            DB::transaction(function () use ($tenantId, $conversation, $control, $policy, $knowledge, $turn, $actor, $messageId): void {
                $current = MarketingConversationControl::whereKey($control->id)->lockForUpdate()->firstOrFail();
                if ($current->mode !== 'processing' || (int) $current->revision !== (int) $control->revision) {
                    return;
                }
                $latest = $conversation->messages()->where('direction', 'inbound')->latest('received_at')->latest('id')->first();
                if ((int) $latest?->id !== $messageId) {
                    $current->update(['mode' => 'handoff', 'reason' => 'newer_message_received']);

                    return;
                }
                abort_unless($policy->fresh()?->enabled && (int) $policy->fresh()->revision === (int) $policy->revision && $knowledge->expires_at->isFuture() && config('marketing_desk.ai_replies_enabled'), 403);
                app(MarketingDeskAccess::class)->ensureDelivery($tenantId, $actor->fresh());
                $current->update(['mode' => 'sending']);
                $context = app(MarketingReplyContext::class);
                $context->automatic = true;
                try {
                    app(MessagingResponseInboxService::class)->reply($tenantId, $conversation->store_key, $conversation->id, ['body' => $turn->result['answer']], $actor);
                } finally {
                    $context->automatic = false;
                }
                $current->update(['mode' => 'auto', 'reason' => null]);
            }, 1);
        } catch (\Throwable $exception) {
            // A sender exception may follow provider acceptance. Pause for a person; never retry automatically.
            $this->handoff($control, 'ai_or_delivery_unavailable');
        }
    }

    private function handoff(MarketingConversationControl $control, string $reason): void
    {
        MarketingConversationControl::whereKey($control->id)->where('revision', $control->revision)->whereIn('mode', ['processing', 'sending'])->update(['mode' => 'handoff', 'reason' => $reason]);
    }
}
