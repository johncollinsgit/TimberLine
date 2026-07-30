<?php

namespace App\Services\Automation\V2\Data;

use InvalidArgumentException;

final readonly class TriggerPollResult
{
    /**
     * @param  array<int,TriggerEvent>  $events
     * @param  array<string,mixed>  $summary
     */
    public function __construct(
        public array $events,
        public ?string $nextCursor,
        public bool $hasMore = false,
        public array $summary = [],
    ) {
        foreach ($events as $event) {
            if (! $event instanceof TriggerEvent) {
                throw new InvalidArgumentException('Trigger poll results may only contain TriggerEvent instances.');
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'events' => array_map(static fn (TriggerEvent $event): array => $event->toArray(), $this->events),
            'next_cursor' => $this->nextCursor,
            'has_more' => $this->hasMore,
            'summary' => $this->summary,
        ];
    }
}
