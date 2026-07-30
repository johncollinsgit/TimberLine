<?php

namespace App\Services\Automation\V2\Operations\Native;

use App\Models\AutomationWorkflowDomainEvent;
use App\Services\Automation\V2\Contracts\TriggerOperation;
use App\Services\Automation\V2\Data\TriggerEvent;
use App\Services\Automation\V2\Data\TriggerOperationContext;
use App\Services\Automation\V2\Data\TriggerPollResult;
use Carbon\CarbonImmutable;

abstract class DomainEventTriggerOperation implements TriggerOperation
{
    abstract protected function eventType(): string;

    public function poll(TriggerOperationContext $context): TriggerPollResult
    {
        $base = AutomationWorkflowDomainEvent::query()
            ->forTenantId($context->tenantId)
            ->where('event_type', $this->eventType());

        if ($context->dryRun) {
            $sample = (clone $base)->latest('id')->first();

            return new TriggerPollResult(
                events: $sample ? [$this->event($sample)] : [],
                nextCursor: $context->cursor,
                summary: [
                    'event_type' => $this->eventType(),
                    'sample_found' => $sample !== null,
                    'dry_run' => true,
                ],
            );
        }

        if ($context->cursor === null || ! ctype_digit($context->cursor)) {
            $latestId = (int) ((clone $base)->max('id') ?? 0);

            return new TriggerPollResult(
                events: [],
                nextCursor: (string) $latestId,
                summary: [
                    'event_type' => $this->eventType(),
                    'initialized_at_event_id' => $latestId,
                    'accepted' => 0,
                ],
            );
        }

        $rows = $base
            ->where('id', '>', (int) $context->cursor)
            ->orderBy('id')
            ->limit(max(1, min(500, $context->limit)))
            ->get();
        $nextCursor = $rows->isEmpty()
            ? $context->cursor
            : (string) $rows->last()->id;

        return new TriggerPollResult(
            events: $rows->map(fn (AutomationWorkflowDomainEvent $row): TriggerEvent => $this->event($row))->all(),
            nextCursor: $nextCursor,
            hasMore: $rows->count() === max(1, min(500, $context->limit)),
            summary: [
                'event_type' => $this->eventType(),
                'accepted' => $rows->count(),
            ],
        );
    }

    protected function event(AutomationWorkflowDomainEvent $row): TriggerEvent
    {
        $payload = (array) $row->payload;

        return new TriggerEvent(
            eventKey: (string) $row->event_key,
            sourceSystem: 'everbranch',
            sourceId: (string) $row->subject_id,
            sourceFingerprint: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            payload: $payload,
            occurredAt: $row->occurred_at
                ? CarbonImmutable::instance($row->occurred_at)
                : null,
        );
    }
}
