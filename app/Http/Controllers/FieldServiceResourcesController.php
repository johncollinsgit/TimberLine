<?php

namespace App\Http\Controllers;

use App\Models\FieldInventoryMovement;
use App\Models\FieldMaterialCatalogItem;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobVehicleCrew;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceVehicle;
use App\Models\FieldServiceVehicleStock;
use App\Models\Tenant;
use App\Services\FieldService\FieldServiceAccessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FieldServiceResourcesController extends Controller
{
    public function __construct(protected FieldServiceAccessService $access) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $canManage = $this->access->canManageJobs($request->user(), $tenant);
        $items = FieldMaterialCatalogItem::query()
            ->forTenantId((int) $tenant->id)
            ->where('active', true)
            ->withSum('vehicleStocks as van_quantity', 'quantity')
            ->orderBy('name')
            ->get();
        $vehicles = FieldServiceVehicle::query()
            ->forTenantId((int) $tenant->id)
            ->with([
                'stocks' => fn ($stocks) => $stocks->where('quantity', '>', 0)->with('catalogItem')->orderByDesc('quantity'),
                'crewAssignments' => fn ($crew) => $crew->with(['job:id,title,operational_status', 'user:id,name'])->latest('id'),
            ])
            ->orderBy('name')
            ->get();
        $jobsQuery = FieldServiceJob::query()
            ->forTenantId((int) $tenant->id)
            ->whereIn('operational_status', ['needs_details', 'scheduled', 'active', 'blocked']);
        $this->access->scopeVisibleJobs($jobsQuery, $request->user(), $tenant);
        $jobs = $jobsQuery
            ->with([
                'assignedUser:id,name',
                'participants:id,name',
                'vehicles.stocks.catalogItem',
                'vehicleCrewAssignments.user:id,name',
            ])
            ->orderByRaw('scheduled_for is null')
            ->orderBy('scheduled_for')
            ->orderBy('title')
            ->get();
        $movements = FieldInventoryMovement::query()
            ->forTenantId((int) $tenant->id)
            ->when(! $canManage, function ($query) use ($jobs): void {
                $query->where(function ($visible) use ($jobs): void {
                    $visible->whereNull('field_service_job_id')
                        ->orWhereIn('field_service_job_id', $jobs->modelKeys());
                });
            })
            ->with(['catalogItem', 'vehicle', 'job', 'createdBy'])
            ->latest()
            ->limit(20)
            ->get();

        return view('field-service.resources', [
            'tenant' => $tenant,
            'items' => $items,
            'vehicles' => $vehicles,
            'jobs' => $jobs,
            'team' => $canManage
                ? $tenant->users()->wherePivot('membership_active', true)->orderBy('name')->get(['users.id', 'users.name', 'users.email'])
                : collect(),
            'movements' => $movements,
            'canManage' => $canManage,
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManager($request, $tenant);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:120', Rule::unique('field_material_catalog_items', 'sku')->where('tenant_id', $tenant->id)],
            'unit' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity_on_hand' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $tenant, $validated): void {
            $quantity = round((float) ($validated['quantity_on_hand'] ?? 0), 2);
            $item = FieldMaterialCatalogItem::query()->create([
                'tenant_id' => (int) $tenant->id,
                'name' => trim((string) $validated['name']),
                'sku' => filled($validated['sku'] ?? null) ? trim((string) $validated['sku']) : null,
                'unit' => filled($validated['unit'] ?? null) ? trim((string) $validated['unit']) : 'each',
                'description' => $validated['description'] ?? null,
                'quantity_on_hand' => $quantity,
                'reorder_level' => round((float) ($validated['reorder_level'] ?? 0), 2),
                'unit_cost' => $validated['unit_cost'] ?? null,
                'active' => true,
            ]);
            if ($quantity > 0) {
                $this->recordMovement($tenant, $item, $request, 'opening_stock', $quantity, notes: 'Opening warehouse quantity.');
            }
        });

        return back()->withFragment('inventory')->with('status', 'Inventory item created.');
    }

    public function adjustItem(Request $request, FieldMaterialCatalogItem $item): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManager($request, $tenant);
        $this->assertOwned($tenant, $item);
        $validated = $request->validate([
            'action' => ['required', 'in:receive,set'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $tenant, $item, $validated): void {
            $locked = FieldMaterialCatalogItem::query()->forTenantId((int) $tenant->id)->lockForUpdate()->findOrFail($item->id);
            $current = (float) $locked->quantity_on_hand;
            $entered = round((float) $validated['quantity'], 2);
            $next = $validated['action'] === 'receive' ? $current + $entered : $entered;
            $delta = round($next - $current, 2);
            $locked->update(['quantity_on_hand' => $next]);
            if ($delta !== 0.0) {
                $this->recordMovement($tenant, $locked, $request, $validated['action'] === 'receive' ? 'received' : 'warehouse_adjustment', $delta, notes: $validated['notes'] ?? null);
            }
        });

        return back()->withFragment('inventory')->with('status', 'Warehouse quantity updated.');
    }

    public function transferVehicleStock(Request $request, FieldServiceVehicle $vehicle): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManager($request, $tenant);
        $this->assertOwned($tenant, $vehicle);
        $validated = $request->validate([
            'field_material_catalog_item_id' => ['required', 'integer'],
            'direction' => ['required', 'in:load,unload'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $tenant, $vehicle, $validated): void {
            $item = FieldMaterialCatalogItem::query()->forTenantId((int) $tenant->id)->lockForUpdate()->findOrFail((int) $validated['field_material_catalog_item_id']);
            $stock = FieldServiceVehicleStock::query()->forTenantId((int) $tenant->id)
                ->where('field_service_vehicle_id', $vehicle->id)
                ->where('field_material_catalog_item_id', $item->id)
                ->lockForUpdate()
                ->first();
            $stock ??= FieldServiceVehicleStock::query()->create([
                'tenant_id' => (int) $tenant->id,
                'field_service_vehicle_id' => (int) $vehicle->id,
                'field_material_catalog_item_id' => (int) $item->id,
                'quantity' => 0,
            ]);
            $quantity = round((float) $validated['quantity'], 2);
            $warehouse = (float) $item->quantity_on_hand;
            $onVan = (float) $stock->quantity;
            if ($validated['direction'] === 'load') {
                abort_if($warehouse < $quantity, 422, 'The warehouse does not have enough stock to load that quantity.');
                $item->update(['quantity_on_hand' => $warehouse - $quantity]);
                $stock->update(['quantity' => $onVan + $quantity]);
                $type = 'loaded_to_van';
            } else {
                abort_if($onVan < $quantity, 422, 'The van does not have enough stock to unload that quantity.');
                $stock->update(['quantity' => $onVan - $quantity]);
                $item->update(['quantity_on_hand' => $warehouse + $quantity]);
                $type = 'unloaded_from_van';
            }
            $this->recordMovement($tenant, $item, $request, $type, $quantity, $vehicle, notes: $validated['notes'] ?? null);
        });

        return back()->withFragment('vans')->with('status', 'Van inventory updated.');
    }

    public function deployCrew(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManager($request, $tenant);
        $validated = $request->validate([
            'field_service_job_id' => ['required', 'integer'],
            'field_service_vehicle_id' => ['required', 'integer'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:30'],
            'employee_ids.*' => ['integer'],
        ]);
        $job = FieldServiceJob::query()->forTenantId((int) $tenant->id)->findOrFail((int) $validated['field_service_job_id']);
        $vehicle = FieldServiceVehicle::query()->forTenantId((int) $tenant->id)->findOrFail((int) $validated['field_service_vehicle_id']);
        $employeeIds = $tenant->users()->wherePivot('membership_active', true)->whereIn('users.id', $validated['employee_ids'])->pluck('users.id')->map(fn ($id): int => (int) $id);
        abort_unless($employeeIds->count() === count(array_unique($validated['employee_ids'])), 422, 'Every selected employee must belong to this workspace.');

        DB::transaction(function () use ($request, $tenant, $job, $vehicle, $employeeIds): void {
            $job->vehicles()->syncWithoutDetaching([
                $vehicle->id => ['tenant_id' => (int) $tenant->id, 'assigned_by_user_id' => (int) $request->user()->id],
            ]);
            FieldServiceJobVehicleCrew::query()->forTenantId((int) $tenant->id)
                ->where('field_service_job_id', $job->id)
                ->where('field_service_vehicle_id', $vehicle->id)
                ->delete();
            foreach ($employeeIds as $employeeId) {
                FieldServiceJobVehicleCrew::query()->create([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_job_id' => (int) $job->id,
                    'field_service_vehicle_id' => (int) $vehicle->id,
                    'user_id' => $employeeId,
                ]);
            }
            $job->participants()->syncWithoutDetaching($employeeIds->mapWithKeys(fn (int $employeeId): array => [
                $employeeId => ['tenant_id' => (int) $tenant->id, 'role' => 'member', 'following' => true],
            ])->all());
        });

        return back()->withFragment('deployments')->with('status', 'Crew and van assigned to the job.');
    }

    public function consumeVehicleStock(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManager($request, $tenant);
        $validated = $request->validate([
            'field_service_job_id' => ['required', 'integer'],
            'field_service_vehicle_id' => ['required', 'integer'],
            'field_material_catalog_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $job = FieldServiceJob::query()->forTenantId((int) $tenant->id)->findOrFail((int) $validated['field_service_job_id']);
        $vehicle = FieldServiceVehicle::query()->forTenantId((int) $tenant->id)->findOrFail((int) $validated['field_service_vehicle_id']);
        abort_unless($job->vehicles()->whereKey($vehicle->id)->exists(), 422, 'Assign this van to the job before using its stock.');

        DB::transaction(function () use ($request, $tenant, $job, $vehicle, $validated): void {
            $item = FieldMaterialCatalogItem::query()->forTenantId((int) $tenant->id)->findOrFail((int) $validated['field_material_catalog_item_id']);
            $stock = FieldServiceVehicleStock::query()->forTenantId((int) $tenant->id)
                ->where('field_service_vehicle_id', $vehicle->id)
                ->where('field_material_catalog_item_id', $item->id)
                ->lockForUpdate()
                ->firstOrFail();
            $quantity = round((float) $validated['quantity'], 2);
            abort_if((float) $stock->quantity < $quantity, 422, 'The van does not have enough of that item.');
            $stock->update(['quantity' => (float) $stock->quantity - $quantity]);

            $material = FieldServiceMaterial::query()->forTenantId((int) $tenant->id)
                ->where('field_service_job_id', $job->id)
                ->where('field_material_catalog_item_id', $item->id)
                ->first();
            if ($material) {
                $material->update([
                    'loaded_quantity' => (float) $material->loaded_quantity + $quantity,
                    'used_quantity' => (float) $material->used_quantity + $quantity,
                    'status' => 'used',
                ]);
            } else {
                FieldServiceMaterial::query()->create([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_job_id' => (int) $job->id,
                    'field_material_catalog_item_id' => (int) $item->id,
                    'name' => $item->name,
                    'quantity' => $quantity,
                    'loaded_quantity' => $quantity,
                    'used_quantity' => $quantity,
                    'unit' => $item->unit,
                    'unit_cost' => $item->unit_cost,
                    'status' => 'used',
                    'notes' => $validated['notes'] ?? null,
                ]);
            }
            $this->recordMovement($tenant, $item, $request, 'used_on_job', $quantity, $vehicle, $job, $validated['notes'] ?? null);
        });

        return back()->withFragment('deployments')->with('status', 'Van stock recorded on the job.');
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function authorizeManager(Request $request, Tenant $tenant): void
    {
        abort_unless($request->user() && $this->access->canManageJobs($request->user(), $tenant), 403);
    }

    private function assertOwned(Tenant $tenant, object $model): void
    {
        abort_unless((int) $model->tenant_id === (int) $tenant->id, 404);
    }

    private function recordMovement(
        Tenant $tenant,
        FieldMaterialCatalogItem $item,
        Request $request,
        string $type,
        float $quantity,
        ?FieldServiceVehicle $vehicle = null,
        ?FieldServiceJob $job = null,
        ?string $notes = null,
    ): void {
        FieldInventoryMovement::query()->create([
            'tenant_id' => (int) $tenant->id,
            'field_material_catalog_item_id' => (int) $item->id,
            'field_service_vehicle_id' => $vehicle?->id,
            'field_service_job_id' => $job?->id,
            'created_by_user_id' => $request->user()?->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'notes' => $notes,
        ]);
    }
}
