<?php

use App\Console\Commands\EverbranchPreparePestControlDemo;
use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceVehicle;
use App\Models\FieldServiceWorkShift;
use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\Tenant;
use App\Models\TenantFleetTrackingSetting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('the fictional pest-control command creates an isolated tracking demonstration workspace', function (): void {
    $this->artisan('everbranch:prepare-pest-control-demo', ['--password' => EverbranchPreparePestControlDemo::DEFAULT_PASSWORD])
        ->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'green-shield-pest-control')->firstOrFail();
    $owner = User::query()->where('email', EverbranchPreparePestControlDemo::OWNER_EMAIL)->firstOrFail();

    expect($tenant->name)->toBe('Green Shield Pest Control')
        ->and(Hash::check(EverbranchPreparePestControlDemo::DEFAULT_PASSWORD, (string) $owner->password))->toBeTrue()
        ->and($owner->tenants()->pluck('tenants.id')->all())->toBe([(int) $tenant->id])
        ->and($tenant->users()->wherePivot('membership_active', true)->count())->toBe(4)
        ->and(FieldServiceJob::query()->forTenantId((int) $tenant->id)->count())->toBe(20)
        ->and(FieldServiceTask::query()->forTenantId((int) $tenant->id)->count())->toBe(41)
        ->and(FieldServiceJobNote::query()->forTenantId((int) $tenant->id)->count())->toBe(20)
        ->and(FieldServiceFinancialDocument::query()->forTenantId((int) $tenant->id)->where('source', 'fictional_demo')->count())->toBe(40)
        ->and(FieldServiceVehicle::query()->forTenantId((int) $tenant->id)->count())->toBe(2)
        ->and(FieldServiceWorkShift::query()->forTenantId((int) $tenant->id)->count())->toBe(11)
        ->and(FleetTrackingDevice::query()->forTenantId((int) $tenant->id)->where('provider', 'bouncie')->count())->toBe(1)
        ->and(FleetLocationPoint::query()->forTenantId((int) $tenant->id)->count())->toBe(5)
        ->and(TenantFleetTrackingSetting::query()->forTenantId((int) $tenant->id)->sole()->retention_days)->toBe(30);
});

test('the fictional pest-control command safely refreshes the same demonstration workspace', function (): void {
    $this->artisan('everbranch:prepare-pest-control-demo')->assertSuccessful();
    $this->artisan('everbranch:prepare-pest-control-demo')->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'green-shield-pest-control')->firstOrFail();

    expect(app(\App\Services\FieldService\FieldServiceOwnerHomeMetricsService::class)->build($tenant)['money_in'])->toBe(10875.0);

    expect(FieldServiceJob::query()->forTenantId((int) $tenant->id)->count())->toBe(20)
        ->and(FieldServiceTask::query()->forTenantId((int) $tenant->id)->count())->toBe(41)
        ->and(FieldServiceWorkShift::query()->forTenantId((int) $tenant->id)->count())->toBe(11)
        ->and(FleetLocationPoint::query()->forTenantId((int) $tenant->id)->count())->toBe(5);
});
