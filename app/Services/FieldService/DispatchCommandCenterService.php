<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceAvailabilityException;
use App\Models\FieldServiceDispatchEvent;
use App\Models\FieldServiceDispatchSetting;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceTechnicianProfile;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DispatchCommandCenterService
{
    public function __construct(protected FieldServiceJobNotificationService $notifications) {}

    public function dispatch(Tenant $tenant, FieldServiceJob $job, User $actor, int $assigneeId, CarbonImmutable $startsAt, int $durationMinutes, array $requirements = []): FieldServiceJob
    {
        if ((int) $job->tenant_id !== (int) $tenant->id || ! $tenant->users()->whereKey($assigneeId)->wherePivot('membership_active', true)->exists()) {
            throw new InvalidArgumentException('The job or technician is not available in this workspace.');
        }
        $durationMinutes = min(720, max(15, $durationMinutes));
        $conflicts = $this->conflicts($tenant, $job, $assigneeId, $startsAt, $durationMinutes, $requirements);
        if ($conflicts !== []) {
            throw new InvalidArgumentException(implode(' ', $conflicts));
        }

        $scheduled = DB::transaction(function () use ($tenant, $job, $actor, $assigneeId, $startsAt, $durationMinutes, $requirements): FieldServiceJob {
            $job->refresh();
            $before = $this->scheduleState($job);
            $job->forceFill([
                'assigned_user_id' => $assigneeId,
                'scheduled_for' => $startsAt,
                'scheduled_end_at' => $startsAt->addMinutes($durationMinutes),
                'dispatch_duration_minutes' => $durationMinutes,
                'dispatch_requirements' => $this->normalizedRequirements($requirements),
                'operational_status' => 'scheduled',
                'status' => $job->status ?: 'scheduled',
            ])->save();
            $job->participants()->syncWithoutDetaching([$assigneeId => ['tenant_id' => (int) $tenant->id, 'role' => 'lead', 'following' => true]]);
            $vehicleIds = (array) data_get($this->normalizedRequirements($requirements), 'vehicle_ids', []);
            if ($vehicleIds !== []) {
                $job->vehicles()->syncWithoutDetaching(collect($vehicleIds)->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => (int) $tenant->id, 'assigned_by_user_id' => (int) $actor->id]])->all());
            }
            $after = $this->scheduleState($job);
            FieldServiceDispatchEvent::query()->create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'actor_user_id' => $actor->id, 'event_type' => 'scheduled', 'before' => $before, 'after' => $after, 'explanation' => $this->explain($tenant, $assigneeId, $startsAt, $durationMinutes, $requirements)]);

            return $job->fresh(['assignedUser', 'participants']);
        });

        $this->notifications->notifyJobEvent(
            $scheduled,
            $actor,
            'schedule_changed',
            'Schedule or team assignment changed for '.$scheduled->title.'.',
            'dispatch:'.$scheduled->id.':'.$scheduled->updated_at?->timestamp,
            [$assigneeId]
        );

        return $scheduled;
    }

    /** @return array<int,array<string,mixed>> */
    public function recommendations(Tenant $tenant, FieldServiceJob $job, CarbonImmutable $startsAt, int $durationMinutes): array
    {
        return FieldServiceTechnicianProfile::query()->forTenantId($tenant->id)->where('dispatch_active', true)->with('user:id,name,email')->get()->map(function (FieldServiceTechnicianProfile $profile) use ($tenant, $job, $startsAt, $durationMinutes): array {
            $conflicts = $this->conflicts($tenant, $job, (int) $profile->user_id, $startsAt, $durationMinutes, (array) $job->dispatch_requirements);
            $scheduled = FieldServiceJob::query()->forTenantId($tenant->id)->where('assigned_user_id', $profile->user_id)->whereDate('scheduled_for', $startsAt->toDateString())->sum(DB::raw('coalesce(dispatch_duration_minutes, 60)'));
            $capacity = max(1, (int) $profile->daily_capacity_minutes);

            return ['user_id' => (int) $profile->user_id, 'name' => $profile->user?->name ?? 'Technician', 'available' => $conflicts === [], 'score' => $conflicts === [] ? max(0, 100 - (int) round(($scheduled / $capacity) * 100)) : 0, 'reasons' => $conflicts === [] ? ['Available for the requested time.', sprintf('%d of %d scheduled minutes used.', $scheduled, $capacity)] : $conflicts];
        })->sortByDesc('score')->values()->all();
    }

    /** @return array<int,string> */
    public function conflicts(Tenant $tenant, FieldServiceJob $job, int $assigneeId, CarbonImmutable $startsAt, int $durationMinutes, array $requirements = []): array
    {
        $endsAt = $startsAt->addMinutes($durationMinutes);
        $buffer = (int) FieldServiceDispatchSetting::query()->forTenantId($tenant->id)->value('default_travel_buffer_minutes');
        $buffer = min(120, max(0, $buffer ?: 15));
        $conflicts = [];
        $profile = FieldServiceTechnicianProfile::query()->forTenantId($tenant->id)->where('user_id', $assigneeId)->first();
        if ($profile && ! $profile->dispatch_active) {
            $conflicts[] = 'Technician is inactive for dispatch.';
        }
        $requiredSkills = (array) ($requirements['skills'] ?? []);
        if ($requiredSkills !== [] && $profile && array_diff($requiredSkills, (array) $profile->skills) !== []) {
            $conflicts[] = 'Technician does not have every required skill.';
        }
        $serviceAreaId = (int) ($requirements['service_area_id'] ?? 0);
        if ($serviceAreaId > 0 && $profile && (array) $profile->service_area_ids !== [] && ! in_array($serviceAreaId, (array) $profile->service_area_ids, true)) {
            $conflicts[] = 'Technician is not configured for the selected service zone.';
        }
        $requiredVehicleIds = collect((array) ($requirements['vehicle_ids'] ?? []))->merge($job->vehicles()->pluck('field_service_vehicles.id'))->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($requiredVehicleIds !== [] && $profile && array_diff($requiredVehicleIds, (array) $profile->vehicle_ids) !== []) {
            $conflicts[] = 'Technician is not configured for every required vehicle.';
        }
        if (FieldServiceAvailabilityException::query()->forTenantId($tenant->id)->where('user_id', $assigneeId)->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->exists()) {
            $conflicts[] = 'Technician has an availability exception at this time.';
        }
        if (FieldServiceJob::query()->forTenantId($tenant->id)->where('assigned_user_id', $assigneeId)->where('id', '!=', $job->id)->whereNotNull('scheduled_for')->whereNotIn('operational_status', ['complete', 'canceled', 'history'])->where('scheduled_for', '<', $endsAt->addMinutes($buffer))->where(function ($query) use ($startsAt, $buffer): void {
            $query->where('scheduled_end_at', '>', $startsAt->subMinutes($buffer))->orWhereNull('scheduled_end_at');
        })->exists()) {
            $conflicts[] = 'Technician already has overlapping dispatched work or travel buffer.';
        }
        if ($requiredVehicleIds !== [] && FieldServiceJob::query()->forTenantId($tenant->id)->where('id', '!=', $job->id)->whereNotNull('scheduled_for')->whereNotIn('operational_status', ['complete', 'canceled', 'history'])->where('scheduled_for', '<', $endsAt->addMinutes($buffer))->where(function ($query) use ($startsAt, $buffer): void {
            $query->where('scheduled_end_at', '>', $startsAt->subMinutes($buffer))->orWhereNull('scheduled_end_at');
        })->whereHas('vehicles', fn ($vehicles) => $vehicles->whereIn('field_service_vehicles.id', $requiredVehicleIds))->exists()) {
            $conflicts[] = 'A required vehicle already has overlapping dispatched work or travel buffer.';
        }
        if ($profile) {
            $scheduledMinutes = (int) FieldServiceJob::query()->forTenantId($tenant->id)->where('assigned_user_id', $assigneeId)->where('id', '!=', $job->id)->whereDate('scheduled_for', $startsAt->toDateString())->whereNotIn('operational_status', ['complete', 'canceled', 'history'])->sum(DB::raw('coalesce(dispatch_duration_minutes, 60)'));
            if ($scheduledMinutes + $durationMinutes > (int) $profile->daily_capacity_minutes) {
                $conflicts[] = 'Technician daily capacity would be exceeded.';
            }
        }

        return $conflicts;
    }

    /** @return array<string,mixed> */
    protected function scheduleState(FieldServiceJob $job): array
    {
        return ['assigned_user_id' => $job->assigned_user_id, 'scheduled_for' => $job->scheduled_for?->toIso8601String(), 'scheduled_end_at' => $job->scheduled_end_at?->toIso8601String(), 'duration_minutes' => $job->dispatch_duration_minutes, 'requirements' => $job->dispatch_requirements];
    }

    /** @return array<string,mixed> */
    protected function explain(Tenant $tenant, int $assigneeId, CarbonImmutable $startsAt, int $durationMinutes, array $requirements): array
    {
        return ['selection' => 'dispatcher_confirmed', 'technician_id' => $assigneeId, 'starts_at' => $startsAt->toIso8601String(), 'duration_minutes' => $durationMinutes, 'requirements' => $this->normalizedRequirements($requirements), 'live_gps_used' => false];
    }

    /** @return array<string,mixed> */
    protected function normalizedRequirements(array $requirements): array
    {
        return [
            'skills' => collect((array) ($requirements['skills'] ?? []))->filter(fn ($skill): bool => is_string($skill) && trim($skill) !== '')->map(fn (string $skill): string => trim($skill))->unique()->values()->all(),
            'service_area_id' => is_numeric($requirements['service_area_id'] ?? null) && (int) $requirements['service_area_id'] > 0 ? (int) $requirements['service_area_id'] : null,
            'vehicle_ids' => collect((array) ($requirements['vehicle_ids'] ?? []))->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)->map(fn ($id): int => (int) $id)->unique()->values()->all(),
        ];
    }
}
