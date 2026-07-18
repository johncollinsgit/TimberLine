<?php

namespace App\Http\Controllers;

use App\Models\PlantInventoryAdjustment;
use App\Models\PlantInventoryItem;
use App\Models\Tenant;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlantInventoryController extends Controller
{
    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver,
    ) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', 'active'));

        $items = PlantInventoryItem::query()
            ->forTenantId((int) $tenant->id)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%')
                        ->orWhere('category', 'like', '%'.$search.'%')
                        ->orWhere('vendor_source', 'like', '%'.$search.'%');
                });
            })
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $recentAdjustments = PlantInventoryAdjustment::query()
            ->forTenantId((int) $tenant->id)
            ->with('item:id,name')
            ->latest()
            ->limit(8)
            ->get();

        return view('plant-inventory.index', [
            'tenant' => $tenant,
            'items' => $items,
            'recentAdjustments' => $recentAdjustments,
            'search' => $search,
            'status' => $status,
            'adjustmentTypes' => PlantInventoryAdjustment::types(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $this->validatedItem($request, $tenant);

        $item = PlantInventoryItem::query()->create([
            ...$data,
            'tenant_id' => (int) $tenant->id,
            'reserved_quantity' => min((int) ($data['reserved_quantity'] ?? 0), (int) ($data['quantity_on_hand'] ?? 0)),
        ]);

        if ((int) $item->quantity_on_hand !== 0 || (int) $item->reserved_quantity !== 0) {
            $this->recordAdjustment($item, 'correction', 0, 0, 'Opening inventory snapshot.', $request);
        }

        return back()->with('status', 'Plant inventory item added.');
    }

    public function update(Request $request, PlantInventoryItem $item): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->assertOwned($tenant, $item);
        $data = $this->validatedItem($request, $tenant, $item);
        $data['reserved_quantity'] = min((int) ($data['reserved_quantity'] ?? 0), (int) ($data['quantity_on_hand'] ?? 0));
        $item->update($data);

        return back()->with('status', 'Plant inventory item updated.');
    }

    public function archive(Request $request, PlantInventoryItem $item): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->assertOwned($tenant, $item);
        $item->update(['status' => 'archived']);

        return back()->with('status', 'Plant inventory item archived.');
    }

    public function adjust(Request $request, PlantInventoryItem $item): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->assertOwned($tenant, $item);
        $data = $request->validate([
            'adjustment_type' => ['required', Rule::in(PlantInventoryAdjustment::types())],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $quantity = (int) $data['quantity'];
        $type = (string) $data['adjustment_type'];
        [$quantityDelta, $reservedDelta] = match ($type) {
            PlantInventoryAdjustment::TYPE_RECEIVED => [$quantity, 0],
            PlantInventoryAdjustment::TYPE_SOLD => [-$quantity, -min($quantity, (int) $item->reserved_quantity)],
            PlantInventoryAdjustment::TYPE_HELD => [0, $quantity],
            PlantInventoryAdjustment::TYPE_RELEASED => [0, -$quantity],
            PlantInventoryAdjustment::TYPE_DAMAGED => [-$quantity, -min($quantity, (int) $item->reserved_quantity)],
            PlantInventoryAdjustment::TYPE_CORRECTION => [$quantity - (int) $item->quantity_on_hand, 0],
            default => [0, 0],
        };

        abort_if($reservedDelta > 0 && ((int) $item->reserved_quantity + $reservedDelta) > (int) $item->quantity_on_hand, 422, 'Held quantity cannot exceed on-hand quantity.');
        abort_if($reservedDelta < 0 && ((int) $item->reserved_quantity + $reservedDelta) < 0, 422, 'Released quantity cannot be more than the held quantity.');
        abort_if($quantityDelta < 0 && ((int) $item->quantity_on_hand + $quantityDelta) < 0, 422, 'Adjustment cannot reduce inventory below zero.');

        $this->recordAdjustment($item, $type, $quantityDelta, $reservedDelta, $data['notes'] ?? null, $request);

        return back()->with('status', 'Plant inventory adjustment recorded.');
    }

    protected function tenant(Request $request): Tenant
    {
        $attributeTenant = $request->attributes->get('current_tenant');
        $tenant = $attributeTenant instanceof Tenant
            ? $attributeTenant
            : $this->tenantContextResolver->resolveForRequest($request, $request->user());

        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    protected function assertOwned(Tenant $tenant, PlantInventoryItem $item): void
    {
        abort_unless((int) $item->tenant_id === (int) $tenant->id, 404);
    }

    /** @return array<string,mixed> */
    protected function validatedItem(Request $request, Tenant $tenant, ?PlantInventoryItem $item = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'sku' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('tenant_plant_inventory_items', 'sku')
                    ->where('tenant_id', (int) $tenant->id)
                    ->ignore($item?->id),
            ],
            'vendor_source' => ['nullable', 'string', 'max:255'],
            'purchased_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'sell_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'quantity_on_hand' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reserved_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'square_id' => ['nullable', 'string', 'max:255'],
            'shopify_product_id' => ['nullable', 'string', 'max:255'],
            'shopify_variant_id' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'draft', 'archived'])],
        ]);
    }

    protected function recordAdjustment(
        PlantInventoryItem $item,
        string $type,
        int $quantityDelta,
        int $reservedDelta,
        ?string $notes,
        Request $request
    ): PlantInventoryAdjustment {
        return DB::transaction(function () use ($item, $type, $quantityDelta, $reservedDelta, $notes, $request): PlantInventoryAdjustment {
            $item->refresh();
            $beforeQuantity = (int) $item->quantity_on_hand;
            $beforeReserved = (int) $item->reserved_quantity;
            $afterQuantity = max(0, $beforeQuantity + $quantityDelta);
            $afterReserved = max(0, min($afterQuantity, $beforeReserved + $reservedDelta));

            $item->forceFill([
                'quantity_on_hand' => $afterQuantity,
                'reserved_quantity' => $afterReserved,
            ])->save();

            return PlantInventoryAdjustment::query()->create([
                'tenant_id' => (int) $item->tenant_id,
                'plant_inventory_item_id' => (int) $item->id,
                'performed_by_user_id' => $request->user()?->id,
                'adjustment_type' => $type,
                'quantity_delta' => $afterQuantity - $beforeQuantity,
                'reserved_delta' => $afterReserved - $beforeReserved,
                'before_quantity_on_hand' => $beforeQuantity,
                'after_quantity_on_hand' => $afterQuantity,
                'before_reserved_quantity' => $beforeReserved,
                'after_reserved_quantity' => $afterReserved,
                'notes' => $notes,
                'metadata' => [
                    'available_quantity' => max(0, $afterQuantity - $afterReserved),
                    'source' => 'front_yard_foods_workspace',
                ],
            ]);
        });
    }
}
