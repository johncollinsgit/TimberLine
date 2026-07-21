<?php

use App\Models\FieldInventoryMovement;
use App\Models\FieldMaterialCatalogItem;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobVehicleCrew;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceVehicle;
use App\Models\FieldServiceVehicleStock;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;

beforeEach(function (): void {
    $this->withoutVite();
});

test('manager can create stock load a van deploy employees and use material on a job', function (): void {
    [$tenant, $owner, $employee] = fieldResourceWorkspace();
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Generator installation',
        'status' => 'open',
        'operational_status' => 'scheduled',
        'customer_name' => 'Pat Customer',
    ]);

    $this->actingAs($owner)
        ->get(route('field-service.resources', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSee('Inventory & work vans', false)
        ->assertSeeText('Job deployments');

    $this->post(route('field-service.resources.inventory.store', ['tenant' => $tenant->slug]), [
        'name' => '12/2 Romex',
        'sku' => 'WIRE-12-2',
        'unit' => 'ft',
        'quantity_on_hand' => 500,
        'reorder_level' => 100,
        'unit_cost' => 0.62,
    ])->assertRedirect();
    $item = FieldMaterialCatalogItem::query()->where('tenant_id', $tenant->id)->where('sku', 'WIRE-12-2')->sole();

    $this->post(route('field-service.vehicles.store', ['tenant' => $tenant->slug]), [
        'name' => 'Service Van 1',
        'identifier' => 'CUE-01',
    ])->assertRedirect();
    $vehicle = FieldServiceVehicle::query()->where('tenant_id', $tenant->id)->where('identifier', 'CUE-01')->sole();

    $this->post(route('field-service.resources.vans.stock', ['tenant' => $tenant->slug, 'vehicle' => $vehicle]), [
        'field_material_catalog_item_id' => $item->id,
        'direction' => 'load',
        'quantity' => 150,
    ])->assertRedirect();

    expect((float) $item->fresh()->quantity_on_hand)->toBe(350.0)
        ->and((float) FieldServiceVehicleStock::query()->where('field_service_vehicle_id', $vehicle->id)->sole()->quantity)->toBe(150.0);

    $this->post(route('field-service.resources.vans.stock', ['tenant' => $tenant->slug, 'vehicle' => $vehicle]), [
        'field_material_catalog_item_id' => $item->id,
        'direction' => 'load',
        'quantity' => 999,
    ])->assertStatus(422);
    expect((float) $item->fresh()->quantity_on_hand)->toBe(350.0);

    $this->post(route('field-service.resources.deployments.store', ['tenant' => $tenant->slug]), [
        'field_service_job_id' => $job->id,
        'field_service_vehicle_id' => $vehicle->id,
        'employee_ids' => [$owner->id, $employee->id],
    ])->assertRedirect();

    expect($job->vehicles()->whereKey($vehicle->id)->exists())->toBeTrue()
        ->and(FieldServiceJobVehicleCrew::query()->where('field_service_job_id', $job->id)->where('field_service_vehicle_id', $vehicle->id)->count())->toBe(2)
        ->and($job->participants()->whereKey($employee->id)->exists())->toBeTrue();

    $this->post(route('field-service.resources.deployments.use-stock', ['tenant' => $tenant->slug]), [
        'field_service_job_id' => $job->id,
        'field_service_vehicle_id' => $vehicle->id,
        'field_material_catalog_item_id' => $item->id,
        'quantity' => 25,
        'notes' => 'Generator feeder run.',
    ])->assertRedirect();

    $material = FieldServiceMaterial::query()->where('field_service_job_id', $job->id)->where('field_material_catalog_item_id', $item->id)->sole();
    expect((float) FieldServiceVehicleStock::query()->where('field_service_vehicle_id', $vehicle->id)->sole()->quantity)->toBe(125.0)
        ->and((float) $material->used_quantity)->toBe(25.0)
        ->and($material->status)->toBe('used')
        ->and(FieldInventoryMovement::query()->where('movement_type', 'used_on_job')->where('field_service_job_id', $job->id)->exists())->toBeTrue();
});

test('resource operations reject records owned by another workspace', function (): void {
    [$tenant, $owner] = fieldResourceWorkspace('resource-owner');
    [$otherTenant] = fieldResourceWorkspace('resource-other');
    $foreignVehicle = FieldServiceVehicle::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'Other van', 'status' => 'active']);
    $item = FieldMaterialCatalogItem::query()->create(['tenant_id' => $tenant->id, 'name' => 'Breaker', 'quantity_on_hand' => 10, 'active' => true]);

    $this->actingAs($owner)
        ->post(route('field-service.resources.vans.stock', ['tenant' => $tenant->slug, 'vehicle' => $foreignVehicle]), [
            'field_material_catalog_item_id' => $item->id,
            'direction' => 'load',
            'quantity' => 1,
        ])
        ->assertNotFound();
});

test('members see only participating deployments and cannot mutate resources', function (): void {
    [$tenant, $owner, $employee] = fieldResourceWorkspace('resource-member');
    $visibleJob = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $employee->id,
        'title' => 'Employee service call',
        'status' => 'open',
        'operational_status' => 'active',
    ]);
    $hiddenJob = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $owner->id,
        'title' => 'Owner-only service call',
        'status' => 'open',
        'operational_status' => 'active',
    ]);
    $item = FieldMaterialCatalogItem::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Hidden job material',
        'quantity_on_hand' => 0,
        'active' => false,
    ]);
    FieldInventoryMovement::query()->create([
        'tenant_id' => $tenant->id,
        'field_material_catalog_item_id' => $item->id,
        'field_service_job_id' => $hiddenJob->id,
        'created_by_user_id' => $owner->id,
        'movement_type' => 'used_on_job',
        'quantity' => 1,
        'notes' => 'Private owner-job movement',
    ]);

    $this->actingAs($employee)
        ->get(route('field-service.resources', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText($visibleJob->title)
        ->assertDontSeeText('Owner-only service call')
        ->assertDontSeeText('Hidden job material')
        ->assertDontSeeText('Create item');

    $this->post(route('field-service.resources.inventory.store', ['tenant' => $tenant->slug]), [
        'name' => 'Unauthorized breaker',
    ])->assertForbidden();
});

/** @return array{0:Tenant,1:User,2:User} */
function fieldResourceWorkspace(string $slug = 'field-resource-workspace'): array
{
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => $slug]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $employee = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
    $employee->tenants()->attach($tenant->id, ['role' => 'member']);

    return [$tenant, $owner, $employee];
}
