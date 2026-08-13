<?php

use App\Models\FieldServiceJob;
use App\Models\FieldServiceTechnicianProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\DispatchCommandCenterService;
use Carbon\CarbonImmutable;

test('dispatch records an explainable schedule and rejects overlapping work', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Dispatch Tenant', 'slug' => 'dispatch-tenant']);
    $manager = User::factory()->create(['role' => 'manager']);
    $technician = User::factory()->create(['role' => 'admin']);
    $manager->tenants()->attach($tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $technician->tenants()->attach($tenant->id, ['role' => 'employee', 'membership_active' => true]);
    FieldServiceTechnicianProfile::query()->create(['tenant_id' => $tenant->id, 'user_id' => $technician->id, 'skills' => ['electrical'], 'daily_capacity_minutes' => 480, 'dispatch_active' => true]);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Panel inspection', 'status' => 'open', 'operational_status' => 'needs_details', 'priority' => 'normal']);
    $second = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Generator service', 'status' => 'open', 'operational_status' => 'needs_details', 'priority' => 'normal']);
    $service = app(DispatchCommandCenterService::class);
    $startsAt = CarbonImmutable::parse('2026-08-03 09:00:00');

    $scheduled = $service->dispatch($tenant, $job, $manager, $technician->id, $startsAt, 90, ['skills' => ['electrical']]);

    expect($scheduled->assigned_user_id)->toBe($technician->id)
        ->and($scheduled->dispatch_duration_minutes)->toBe(90)
        ->and($service->recommendations($tenant, $second, $startsAt->addHours(3), 60)[0]['available'])->toBeTrue();
    expect(fn () => $service->dispatch($tenant, $second, $manager, $technician->id, $startsAt->addMinutes(30), 60, ['skills' => ['electrical']]))->toThrow(InvalidArgumentException::class);
});

test('dispatch rejects capacity and skill conflicts before changing a job', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Capacity Tenant', 'slug' => 'capacity-tenant']);
    $manager = User::factory()->create(['role' => 'manager']);
    $technician = User::factory()->create(['role' => 'admin']);
    $manager->tenants()->attach($tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $technician->tenants()->attach($tenant->id, ['role' => 'employee', 'membership_active' => true]);
    FieldServiceTechnicianProfile::query()->create(['tenant_id' => $tenant->id, 'user_id' => $technician->id, 'skills' => ['electrical'], 'daily_capacity_minutes' => 60, 'dispatch_active' => true]);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Capacity test', 'status' => 'open', 'operational_status' => 'needs_details', 'priority' => 'normal']);
    $service = app(DispatchCommandCenterService::class);
    $startsAt = CarbonImmutable::parse('2026-08-03 09:00:00');

    expect(fn () => $service->dispatch($tenant, $job, $manager, $technician->id, $startsAt, 90, ['skills' => ['generator']]))->toThrow(InvalidArgumentException::class);
    expect($job->fresh()->assigned_user_id)->toBeNull();
    expect(fn () => $service->dispatch($tenant, $job, $manager, $technician->id, $startsAt, 90, ['skills' => ['electrical']]))->toThrow(InvalidArgumentException::class);
});
