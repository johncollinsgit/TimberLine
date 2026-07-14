<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceJob;

class FieldServiceJobReadinessService
{
    /** @return array{ready:bool,missing:array<int,string>,missing_labels:array<int,string>} */
    public function forJob(FieldServiceJob $job): array
    {
        $missing = [];

        if (! $job->scheduled_for) {
            $missing[] = 'schedule';
        }
        if (blank($job->service_address_line_1)) {
            $missing[] = 'address';
        }
        if (blank($job->description)) {
            $missing[] = 'description';
        }
        if (blank($job->customer_phone) && blank($job->customer_email)) {
            $missing[] = 'customer_contact';
        }
        if (! $this->hasAssignedTeam($job)) {
            $missing[] = 'team';
        }

        $labels = [
            'schedule' => 'Schedule',
            'address' => 'Job-site address',
            'description' => 'Work description',
            'customer_contact' => 'Customer phone or email',
            'team' => 'Assigned technician or team member',
        ];

        return [
            'ready' => $missing === [],
            'missing' => $missing,
            'missing_labels' => array_values(array_map(fn (string $key): string => $labels[$key], $missing)),
        ];
    }

    private function hasAssignedTeam(FieldServiceJob $job): bool
    {
        if ($job->assigned_user_id) {
            return true;
        }

        return $job->relationLoaded('participants')
            ? $job->participants->isNotEmpty()
            : $job->participants()->exists();
    }
}
