<?php

namespace App\Services\Automation\V2\Data;

use Carbon\CarbonImmutable;

final readonly class TriggerEvent
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public string $eventKey,
        public string $sourceSystem,
        public string $sourceId,
        public string $sourceFingerprint,
        public array $payload,
        public ?CarbonImmutable $occurredAt = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'event_key' => $this->eventKey,
            'source_system' => $this->sourceSystem,
            'source_id' => $this->sourceId,
            'source_fingerprint' => $this->sourceFingerprint,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt?->toIso8601String(),
        ];
    }
}
