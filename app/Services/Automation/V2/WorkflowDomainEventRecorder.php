<?php

namespace App\Services\Automation\V2;

use App\Models\AutomationWorkflowDomainEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class WorkflowDomainEventRecorder
{
    public function __construct(
        protected WorkflowStudioRuntimeAccess $runtimeAccess,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function record(
        int $tenantId,
        string $eventType,
        Model $subject,
        array $payload,
        ?string $eventVersion = null,
    ): ?AutomationWorkflowDomainEvent {
        if (! $this->runtimeAccess->allows($tenantId)) {
            return null;
        }

        $subjectType = $subject->getMorphClass();
        $subjectId = (string) $subject->getKey();
        // Update timestamps are not guaranteed to have sub-second precision.
        // Use the actual observation time for mutable events so two legitimate
        // state changes in the same database second cannot collapse into one.
        $version = $eventVersion ?? now()->format('Y-m-d\TH:i:s.uP');
        $eventKey = hash('sha256', implode('|', [$tenantId, $eventType, $subjectType, $subjectId, $version]));

        return AutomationWorkflowDomainEvent::query()->forAllTenants()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'event_key' => $eventKey,
            ],
            [
                'event_type' => $eventType,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'payload' => Arr::except($payload, [
                    'password',
                    'password_confirmation',
                    'access_token',
                    'refresh_token',
                    'client_secret',
                    'api_key',
                ]),
                'occurred_at' => now(),
            ],
        );
    }
}
