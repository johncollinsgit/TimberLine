<?php

namespace App\Services\Automation\V2\Operations\Native;

use App\Services\Automation\V2\Data\TriggerOperationContext;
use App\Services\Automation\V2\Data\TriggerPollResult;
use App\Services\Marketing\Desk\MarketingDeskAccess;

class MarketingEventTriggerOperation extends DomainEventTriggerOperation
{
    private string $event = '';

    protected function eventType(): string
    {
        return $this->event;
    }

    public function poll(TriggerOperationContext $context): TriggerPollResult
    {
        abort_unless(app(MarketingDeskAccess::class)->available($context->tenantId), 403);
        $this->event = 'everbranch.marketing.'.($context->config['audience'] ?? '').'.'.($context->config['event'] ?? '');
        $polled = parent::poll($context);
        $events = array_values(array_filter($polled->events, fn ($event) => (string) ($event->payload['store_key'] ?? '') === (string) ($context->config['store_key'] ?? '')));
        $result = new TriggerPollResult($events, $polled->nextCursor, $polled->hasMore, [...$polled->summary, 'accepted' => count($events)]);
        if (! $context->dryRun) {
            foreach ($result->events as $event) {
                $profileId = (int) ($event->payload['marketing_profile_id'] ?? 0);
                if (\App\Models\MarketingProfile::forTenantId($context->tenantId)->whereKey($profileId)->exists()) {
                    \App\Models\MarketingAutomationEnrollment::firstOrCreate([
                        'workflow_id' => $context->workflowId, 'marketing_profile_id' => $profileId,
                        'event_key' => (string) ($event->payload['marketing_event_key'] ?? hash('sha256', $event->eventKey)),
                    ], ['tenant_id' => $context->tenantId, 'workflow_version_id' => $context->workflowVersionId,
                        'audience' => $context->config['audience'], 'store_key' => $event->payload['store_key'] ?? '', 'started_at' => $event->occurredAt ?? now()]);
                }
            }
        }

        return $result;
    }
}
