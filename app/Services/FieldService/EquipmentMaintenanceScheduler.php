<?php

namespace App\Services\FieldService;

use App\Models\CustomerEquipment;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceTask;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class EquipmentMaintenanceScheduler
{
    public function __construct(protected FieldServiceJobNotificationService $notifications) {}

    /** @return array{equipment_scanned:int,jobs_created:int,alerts:array<string,int>} */
    public function scanTenant(Tenant $tenant): array
    {
        $summary = ['equipment_scanned' => 0, 'jobs_created' => 0, 'alerts' => ['in_app' => 0, 'push' => 0, 'sms_sent' => 0, 'sms_blocked' => 0, 'customer_sms_sent' => 0, 'customer_sms_blocked' => 0]];

        CustomerEquipment::query()
            ->forTenantId((int) $tenant->id)
            ->where('status', 'active')
            ->whereNotNull('next_service_due_at')
            ->whereDate('next_service_due_at', '<=', now()->addDays(30)->toDateString())
            ->with(['customer', 'assignedUser'])
            ->orderBy('id')
            ->each(function (CustomerEquipment $equipment) use (&$summary): void {
                $summary['equipment_scanned']++;
                [$job, $created] = DB::transaction(function () use ($equipment): array {
                    $eventKey = 'equipment:'.$equipment->id.':due:'.$equipment->next_service_due_at?->toDateString();
                    $customer = $equipment->customer;
                    $customerName = trim(($customer?->first_name ?? '').' '.($customer?->last_name ?? '')) ?: 'Customer';
                    $job = FieldServiceJob::query()->firstOrCreate([
                        'tenant_id' => (int) $equipment->tenant_id,
                        'external_source' => 'equipment_maintenance',
                        'external_id' => $eventKey,
                    ], [
                        'marketing_profile_id' => (int) $equipment->marketing_profile_id,
                        'customer_equipment_id' => (int) $equipment->id,
                        'assigned_user_id' => $equipment->assigned_user_id,
                        'title' => $equipment->name.' maintenance',
                        'status' => 'scheduled',
                        'operational_status' => 'scheduled',
                        'status_source' => 'equipment_maintenance',
                        'priority' => $equipment->next_service_due_at?->isPast() ? 'high' : 'normal',
                        'customer_name' => $customerName,
                        'customer_email' => $customer?->email,
                        'customer_phone' => $customer?->phone,
                        'service_address_line_1' => $customer?->address_line_1,
                        'service_address_line_2' => $customer?->address_line_2,
                        'service_city' => $customer?->city,
                        'service_state' => $customer?->state,
                        'service_postal_code' => $customer?->postal_code,
                        'service_country' => $customer?->country,
                        'description' => 'Interval-based maintenance for '.$equipment->name.'. Record work performed, notes, technician, and service photos before completing.',
                        'scheduled_for' => $equipment->next_service_due_at?->copy()->setTime(9, 0),
                        'metadata' => ['equipment_maintenance' => true, 'equipment_id' => (int) $equipment->id, 'due_date' => $equipment->next_service_due_at?->toDateString()],
                    ]);

                    if ($job->wasRecentlyCreated) {
                        FieldServiceTask::query()->create([
                            'tenant_id' => (int) $equipment->tenant_id,
                            'field_service_job_id' => (int) $job->id,
                            'assigned_user_id' => $equipment->assigned_user_id,
                            'title' => 'Complete '.$equipment->name.' interval maintenance',
                            'description' => 'Confirm service notes, technician, and photos; then complete the job to schedule the next interval.',
                            'status' => 'open',
                            'priority' => $job->priority,
                            'due_at' => $equipment->next_service_due_at?->copy()->endOfDay(),
                        ]);
                    }

                    return [$job, $job->wasRecentlyCreated];
                });

                if (! $created) {
                    return;
                }
                $summary['jobs_created']++;
                $delivery = $this->notifications->notifyMaintenanceDue($job->fresh(), $equipment);
                foreach ($summary['alerts'] as $key => $count) {
                    $summary['alerts'][$key] = $count + (int) ($delivery[$key] ?? 0);
                }
            });

        return $summary;
    }
}
