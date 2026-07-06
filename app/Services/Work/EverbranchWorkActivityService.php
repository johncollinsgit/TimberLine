<?php

namespace App\Services\Work;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceTask;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkActivityEvent;

class EverbranchWorkActivityService
{
    /**
     * @param  array<string,mixed>  $metadata
     */
    public function record(
        Tenant $tenant,
        string $itemType,
        int $itemId,
        string $eventType,
        string $title,
        ?string $body = null,
        ?User $actor = null,
        array $metadata = []
    ): WorkActivityEvent {
        return WorkActivityEvent::query()->create([
            'tenant_id' => (int) $tenant->id,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'title' => $title,
            'body' => $body,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function recordJob(Tenant $tenant, FieldServiceJob $job, string $eventType, string $title, ?string $body = null, ?User $actor = null, array $metadata = []): WorkActivityEvent
    {
        return $this->record($tenant, 'field_service_job', (int) $job->id, $eventType, $title, $body, $actor, $metadata);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function recordTask(Tenant $tenant, FieldServiceTask $task, string $eventType, string $title, ?string $body = null, ?User $actor = null, array $metadata = []): WorkActivityEvent
    {
        return $this->record($tenant, 'field_service_task', (int) $task->id, $eventType, $title, $body, $actor, $metadata);
    }
}
