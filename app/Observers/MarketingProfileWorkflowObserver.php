<?php

namespace App\Observers;

use App\Models\MarketingProfile;
use App\Services\Automation\V2\WorkflowDomainEventRecorder;

class MarketingProfileWorkflowObserver
{
    public function __construct(protected WorkflowDomainEventRecorder $events) {}

    public function created(MarketingProfile $profile): void
    {
        $this->events->record(
            (int) $profile->tenant_id,
            'everbranch.customer.created',
            $profile,
            [
                'customer_id' => (int) $profile->id,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'name' => trim((string) $profile->first_name.' '.(string) $profile->last_name),
                'email' => $profile->email,
                'phone' => $profile->phone,
                'address' => [
                    'line_1' => $profile->address_line_1,
                    'line_2' => $profile->address_line_2,
                    'city' => $profile->city,
                    'state' => $profile->state,
                    'postal_code' => $profile->postal_code,
                    'country' => $profile->country,
                ],
                'accepts_email_marketing' => (bool) $profile->accepts_email_marketing,
                'created_at' => $profile->created_at?->toIso8601String(),
            ],
            $profile->created_at?->format('Y-m-d\TH:i:s.uP'),
        );
    }
}
