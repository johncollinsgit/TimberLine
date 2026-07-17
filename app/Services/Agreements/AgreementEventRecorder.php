<?php

namespace App\Services\Agreements;

use App\Models\Agreement;
use App\Models\AgreementEvent;
use App\Models\AgreementVersion;

class AgreementEventRecorder
{
    /** @param array<string,mixed> $metadata */
    public function record(Agreement $agreement, string $eventType, ?int $actorUserId = null, array $metadata = [], ?AgreementVersion $version = null): AgreementEvent
    {
        return AgreementEvent::query()->create([
            'agreement_id' => (int) $agreement->id,
            'agreement_version_id' => $version?->id ?? $agreement->current_version_id,
            'tenant_id' => (int) $agreement->tenant_id,
            'actor_user_id' => $actorUserId,
            'event_type' => strtolower(trim($eventType)),
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }
}
