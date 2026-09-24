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
use App\Models\TenantForm;
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
        ->and(FleetTrackingDevice::query()->forTenantId((int) $tenant->id)->where('provider', 'bouncie')->count())->toBe(2)
        ->and(FleetLocationPoint::query()->forTenantId((int) $tenant->id)->count())->toBe(9)
        ->and(data_get($tenant->moduleEntitlements()->where('module_key', 'field_service')->value('metadata'), 'experience_version'))->toBe(3)
        ->and(TenantForm::query()->forTenantId((int) $tenant->id)->where('slug', 'pest-prevention-reminders')->count())->toBe(1)
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
        ->and(FleetLocationPoint::query()->forTenantId((int) $tenant->id)->count())->toBe(9);
});

test('the fictional pest-control command grants an existing account tenant-scoped demo access', function (): void {
    $presenter = User::factory()->create(['email' => 'johncollinsemail@gmail.com']);
    $otherTenant = Tenant::query()->create(['name' => 'Other workspace', 'slug' => 'other-workspace']);
    $presenter->tenants()->attach((int) $otherTenant->id, ['role' => 'member', 'membership_active' => true]);

    $this->artisan('everbranch:prepare-pest-control-demo', [
        '--grant-email' => $presenter->email,
    ])->assertSuccessful();

    $demoTenant = Tenant::query()->where('slug', 'green-shield-pest-control')->firstOrFail();
    $membership = $presenter->tenants()->whereKey((int) $demoTenant->id)->firstOrFail();

    expect($presenter->tenants()->pluck('tenants.id')->sort()->values()->all())
        ->toBe([(int) $otherTenant->id, (int) $demoTenant->id])
        ->and($membership->pivot->role)->toBe('admin')
        ->and($membership->pivot->membership_active)->toBeTruthy();
});
