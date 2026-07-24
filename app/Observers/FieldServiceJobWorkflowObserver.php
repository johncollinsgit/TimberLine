<?php

namespace App\Observers;

use App\Models\FieldServiceJob;
use App\Services\Automation\V2\WorkflowDomainEventRecorder;

class FieldServiceJobWorkflowObserver
{
    public function __construct(protected WorkflowDomainEventRecorder $events) {}

    public function created(FieldServiceJob $job): void
    {
        $this->events->record(
            (int) $job->tenant_id,
            'everbranch.job.created',
            $job,
            $this->payload($job),
            $job->created_at?->format('Y-m-d\TH:i:s.uP'),
        );
    }

    public function updated(FieldServiceJob $job): void
    {
        if (! $job->wasChanged(['operational_status', 'status'])) {
            return;
        }

        $this->events->record(
            (int) $job->tenant_id,
            'everbranch.job.status_changed',
            $job,
            [
                ...$this->payload($job),
                'previous_status' => (string) ($job->getOriginal('operational_status') ?: $job->getOriginal('status')),
                'status' => (string) ($job->operational_status ?: $job->status),
            ],
        );
    }

    /** @return array<string,mixed> */
    protected function payload(FieldServiceJob $job): array
    {
        return [
            'job_id' => (int) $job->id,
            'title' => $job->title,
            'status' => $job->status,
            'operational_status' => $job->operational_status,
            'priority' => $job->priority,
            'customer_id' => $job->marketing_profile_id ? (int) $job->marketing_profile_id : null,
            'customer_name' => $job->customer_name,
            'customer_email' => $job->customer_email,
            'customer_phone' => $job->customer_phone,
            'description' => $job->description,
            'scheduled_start_at' => $job->scheduled_for?->toIso8601String(),
            'service_address' => [
                'line_1' => $job->service_address_line_1,
                'line_2' => $job->service_address_line_2,
                'city' => $job->service_city,
                'state' => $job->service_state,
                'postal_code' => $job->service_postal_code,
                'country' => $job->service_country,
            ],
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];
    }
}
