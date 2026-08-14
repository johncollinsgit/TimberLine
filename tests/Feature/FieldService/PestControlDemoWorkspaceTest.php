<?php

use App\Console\Commands\EverbranchPreparePestControlDemo;
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
        ->and(FieldServiceWorkShift::query()->forTenantId((int) $tenant->id)->count())->toBe(1)
        ->and(FleetTrackingDevice::query()->forTenantId((int) $tenant->id)->where('provider', 'bouncie')->count())->toBe(1)
        ->and(FleetLocationPoint::query()->forTenantId((int) $tenant->id)->count())->toBe(5)
        ->and(TenantFleetTrackingSetting::query()->forTenantId((int) $tenant->id)->sole()->retention_days)->toBe(30);
});

test('the fictional pest-control command safely refreshes the same demonstration workspace', function (): void {
    $this->artisan('everbranch:prepare-pest-control-demo')->assertSuccessful();
    $this->artisan('everbranch:prepare-pest-control-demo')->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'green-shield-pest-control')->firstOrFail();

    expect(FieldServiceWorkShift::query()->forTenantId((int) $tenant->id)->count())->toBe(1)
        ->and(FleetLocationPoint::query()->forTenantId((int) $tenant->id)->count())->toBe(5);
});
