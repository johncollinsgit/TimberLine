<?php

namespace App\Services\CustomerLoop;

use App\Models\CustomerLoopAction;
use App\Models\CustomerLoopActivity;
use App\Models\MarketingProfile;
use App\Models\User;

class CustomerLoopService
{
    /** @return array<string,array{label:string,reason:string,draft:string}> */
    public function templates(): array
    {
        return [
            'follow_up' => ['label' => 'Follow up', 'reason' => 'A relationship needs a clear next step.', 'draft' => 'Hi {{customer}}, I wanted to follow up and make sure you have what you need.'],
            'review_request' => ['label' => 'Request a review', 'reason' => 'A completed experience is worth remembering and sharing.', 'draft' => 'Hi {{customer}}, thank you for choosing us. If the experience was helpful, would you be open to sharing a quick review?'],
            'email_draft' => ['label' => 'Prepare an email', 'reason' => 'This is a good moment for a thoughtful customer update.', 'draft' => 'Hi {{customer}},\n\nHere is a quick update from our team.'],
            'text_draft' => ['label' => 'Prepare a text', 'reason' => 'A short personal update may be useful here.', 'draft' => 'Hi {{customer}}, just checking in from our team.'],
            'social_draft' => ['label' => 'Prepare a social draft', 'reason' => 'This real business moment could become useful marketing.', 'draft' => 'A little of the work behind the work: {{customer}}.'],
        ];
    }

    public function record(int $tenantId, string $sourceType, ?string $sourceId, string $title, ?string $summary = null, ?MarketingProfile $profile = null, ?User $actor = null, array $safeContext = [], ?string $eventKey = null): CustomerLoopActivity
    {
        $eventKey ??= hash('sha256', implode('|', [$tenantId, $sourceType, $sourceId ?? 'manual', $title, now()->format('Y-m-d\\TH:i:s.uP')]));

        $attributes = [
            'tenant_id' => $tenantId, 'marketing_profile_id' => $profile?->id, 'actor_user_id' => $actor?->id,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'event_key' => $eventKey,
            'title' => $title, 'summary' => $summary, 'safe_context' => $safeContext, 'occurred_at' => now(),
        ];

        return CustomerLoopActivity::query()->forAllTenants()->firstOrCreate(
            ['tenant_id' => $tenantId, 'event_key' => $eventKey],
            $attributes,
        );
    }

    public function suggest(int $tenantId, string $template, string $title, ?MarketingProfile $profile = null, ?CustomerLoopActivity $activity = null, ?User $actor = null): CustomerLoopAction
    {
        $definition = $this->templates()[$template] ?? $this->templates()['follow_up'];
        $name = trim(collect([$profile?->first_name, $profile?->last_name])->filter()->join(' '));
        $name = $name !== '' ? $name : 'there';

        return CustomerLoopAction::query()->forAllTenants()->create([
            'tenant_id' => $tenantId, 'customer_loop_activity_id' => $activity?->id, 'marketing_profile_id' => $profile?->id,
            'created_by_user_id' => $actor?->id, 'action_type' => $template, 'status' => CustomerLoopAction::STATUS_SUGGESTED,
            'title' => $title, 'reason' => $definition['reason'], 'draft_body' => str_replace('{{customer}}', $name, $definition['draft']),
            'due_at' => now(), 'safe_context' => ['template' => $template, 'draft_only' => true],
        ]);
    }

    public function prepare(CustomerLoopAction $action): CustomerLoopAction
    {
        $action->forceFill(['status' => CustomerLoopAction::STATUS_PREPARED, 'prepared_at' => now(), 'snoozed_until' => null])->save();

        return $action->fresh();
    }

    /**
     * Create a review-only action from an active Workflow Studio run.
     *
     * @param  array<string,mixed>  $safeContext
     */
    public function prepareFromWorkflow(
        int $tenantId,
        User $actor,
        string $template,
        string $title,
        ?string $summary,
        string $eventKey,
        array $safeContext = [],
    ): CustomerLoopAction {
        $activity = $this->record(
            tenantId: $tenantId,
            sourceType: 'workflow_automation',
            sourceId: $eventKey,
            title: $title,
            summary: $summary,
            actor: $actor,
            safeContext: [...$safeContext, 'draft_only' => true],
            eventKey: $eventKey,
        );

        $existing = CustomerLoopAction::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('customer_loop_activity_id', $activity->id)
            ->where('action_type', $template)
            ->first();

        return $existing instanceof CustomerLoopAction
            ? $existing
            : $this->suggest($tenantId, $template, $title, activity: $activity, actor: $actor);
    }
}
