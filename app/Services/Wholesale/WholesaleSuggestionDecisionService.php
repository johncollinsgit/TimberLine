<?php

namespace App\Services\Wholesale;

use App\Models\User;
use App\Models\WholesaleFollowUp;
use App\Models\WholesaleSuggestion;
use App\Models\WholesaleSuggestionDecision;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WholesaleSuggestionDecisionService
{
    public function __construct(protected LandlordOperatorActionAuditService $audit) {}

    /** @param array<string,mixed> $input */
    public function decide(int $tenantId, string $publicId, User $actor, array $input): WholesaleSuggestionDecision
    {
        $action = strtolower(trim((string) ($input['action'] ?? '')));
        if (! in_array($action, ['accept', 'create_follow_up', 'snooze', 'dismiss', 'already_handled', 'mark_incorrect', 'request_review'], true)) {
            throw new DomainException('Unsupported suggestion decision.');
        }

        $suggestion = WholesaleSuggestion::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('public_id', $publicId)
            ->firstOrFail();
        if ($action === 'dismiss' && blank($input['dismissal_reason'] ?? null)) {
            throw new DomainException('A dismissal reason is required.');
        }
        if ($action === 'snooze' && blank($input['snoozed_until'] ?? null)) {
            throw new DomainException('Choose when this suggestion should return.');
        }

        $before = $suggestion->only(['status', 'priority', 'snoozed_until']);

        return DB::transaction(function () use ($tenantId, $suggestion, $actor, $input, $action, $before): WholesaleSuggestionDecision {
            $followUp = null;
            $status = match ($action) {
                'accept', 'create_follow_up' => 'accepted',
                'dismiss' => 'dismissed',
                'snooze' => 'snoozed',
                'already_handled' => 'already_handled',
                'mark_incorrect' => 'data_review',
                'request_review' => 'account_review',
            };
            $suggestion->forceFill([
                'status' => $status,
                'snoozed_until' => $action === 'snooze' ? $input['snoozed_until'] : null,
                'priority' => $input['priority'] ?? $suggestion->priority,
            ])->save();

            if ($action === 'create_follow_up') {
                $followUp = WholesaleFollowUp::query()->forAllTenants()
                    ->where('tenant_id', $tenantId)
                    ->where('wholesale_suggestion_id', $suggestion->id)
                    ->whereIn('status', ['open', 'in_progress'])
                    ->first();

                $followUp ??= WholesaleFollowUp::query()->create([
                    'tenant_id' => $tenantId,
                    'public_id' => (string) Str::uuid(),
                    'wholesale_suggestion_id' => $suggestion->id,
                    'target_type' => $suggestion->target_type,
                    'target_key' => $suggestion->target_key,
                    'follow_up_type' => 'sales_review',
                    'title' => $suggestion->title,
                    'status' => 'open',
                    'priority' => $input['priority'] ?? $suggestion->priority,
                    'assigned_user_id' => $input['assigned_user_id'] ?? $actor->id,
                    'created_by_user_id' => $actor->id,
                    'due_at' => $input['due_at'] ?? $suggestion->suggested_follow_up_at ?? now()->addWeekday(),
                    'notes' => $input['note'] ?? null,
                ]);
            }

            $decision = WholesaleSuggestionDecision::query()->create([
                'tenant_id' => $tenantId,
                'wholesale_suggestion_id' => $suggestion->id,
                'actor_user_id' => $actor->id,
                'action' => $action,
                'note' => $input['note'] ?? null,
                'dismissal_reason' => $input['dismissal_reason'] ?? null,
                'resulting_follow_up_id' => $followUp?->id,
                'original_suggestion' => [
                    'public_id' => $suggestion->public_id,
                    'type' => $suggestion->suggestion_type,
                    'title' => $suggestion->title,
                    'recommended_action' => $suggestion->recommended_action,
                    'priority' => $before['priority'],
                    'confidence' => $suggestion->confidence,
                    'supporting_evidence' => $suggestion->supporting_evidence,
                    'status' => $before['status'],
                ],
                'decided_at' => now(),
            ]);

            $this->audit->record($tenantId, $actor->id, 'wholesale.suggestion.'.$action,
                targetType: 'wholesale_suggestion', targetId: $suggestion->id,
                context: ['surface' => 'shopify_embedded_wholesale', 'resulting_follow_up_id' => $followUp?->id],
                beforeState: $before,
                afterState: $suggestion->only(['status', 'priority', 'snoozed_until']));

            return $decision;
        });
    }
}
