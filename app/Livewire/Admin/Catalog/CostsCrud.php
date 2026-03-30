<?php

namespace App\Livewire\Admin\Catalog;

use App\Models\CatalogItemCost;
use App\Models\Scent;
use App\Models\Size;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class CostsCrud extends Component
{
    use WithPagination;

    public string $search = '';
    public string $sort = 'updated_at';
    public string $dir = 'desc';
    public int $perPage = 25;
    public bool $showCreate = false;
    public bool $showEdit = false;
    public ?int $editingId = null;
    public bool $catalogCostsAvailable = true;

    public array $create = [];
    public array $edit = [];

    protected $queryString = [
        'search' => ['except' => ''],
        'sort' => ['except' => 'updated_at'],
        'dir' => ['except' => 'desc'],
        'perPage' => ['except' => 25],
    ];

    public function mount(): void
    {
        $this->catalogCostsAvailable = Schema::hasTable('catalog_item_costs');
        $this->resetCreateForm();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setSort(string $field): void
    {
        if ($this->sort === $field) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sort = $field;
        $this->dir = 'asc';
    }

    public function openCreate(): void
    {
        if (! $this->catalogCostsAvailable) {
            $this->dispatchCatalogUnavailableToast();

            return;
        }

        $this->showCreate = ! $this->showCreate;

        if (! $this->showCreate) {
            $this->resetCreateForm();
        }
    }

    public function create(): void
    {
        if (! $this->catalogCostsAvailable) {
            $this->dispatchCatalogUnavailableToast();

            return;
        }

        $data = $this->validateCostPayload($this->create, 'create');

        CatalogItemCost::query()->create($data);

        $this->resetCreateForm();
        $this->showCreate = false;
        $this->dispatch('toast', ['message' => 'Cost saved.', 'style' => 'success']);
    }

    public function openEdit(int $id): void
    {
        if (! $this->catalogCostsAvailable) {
            $this->dispatchCatalogUnavailableToast();

            return;
        }

        $cost = CatalogItemCost::query()->findOrFail($id);

        $this->editingId = $cost->id;
        $this->edit = [
            'shopify_store_key' => (string) ($cost->shopify_store_key ?? ''),
            'shopify_product_id' => $cost->shopify_product_id ? (string) $cost->shopify_product_id : '',
            'shopify_variant_id' => $cost->shopify_variant_id ? (string) $cost->shopify_variant_id : '',
            'sku' => (string) ($cost->sku ?? ''),
            'scent_id' => $cost->scent_id ? (string) $cost->scent_id : '',
            'size_id' => $cost->size_id ? (string) $cost->size_id : '',
            'cost_amount' => number_format((float) $cost->cost_amount, 2, '.', ''),
            'currency_code' => (string) ($cost->currency_code ?? 'USD'),
            'effective_at' => $cost->effective_at?->format('Y-m-d\TH:i') ?? '',
            'is_active' => (bool) $cost->is_active,
            'notes' => (string) ($cost->notes ?? ''),
        ];
        $this->showEdit = true;
    }

    public function save(): void
    {
        if (! $this->catalogCostsAvailable) {
            $this->dispatchCatalogUnavailableToast();

            return;
        }

        if (! $this->editingId) {
            return;
        }

        $data = $this->validateCostPayload($this->edit, 'edit');

        CatalogItemCost::query()->whereKey($this->editingId)->update($data);

        $this->showEdit = false;
        $this->editingId = null;
        $this->edit = [];
        $this->dispatch('toast', ['message' => 'Cost updated.', 'style' => 'success']);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function validateCostPayload(array $payload, string $prefix): array
    {
        $validated = validator($payload, [
            'shopify_store_key' => ['nullable', 'string', 'max:80'],
            'shopify_product_id' => ['nullable', 'integer', 'min:1'],
            'shopify_variant_id' => ['nullable', 'integer', 'min:1'],
            'sku' => ['nullable', 'string', 'max:160'],
            'scent_id' => ['nullable', 'integer', 'exists:scents,id'],
            'size_id' => ['nullable', 'integer', 'exists:sizes,id'],
            'cost_amount' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'max:8'],
            'effective_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $hasMatcher = collect([
            $validated['shopify_variant_id'] ?? null,
            $validated['shopify_product_id'] ?? null,
            $validated['sku'] ?? null,
            $validated['scent_id'] ?? null,
            $validated['size_id'] ?? null,
        ])->contains(fn ($value) => $value !== null && $value !== '');

        if (! $hasMatcher) {
            throw ValidationException::withMessages([
                $prefix . '.shopify_variant_id' => 'Add at least one item matcher such as variant, product, SKU, scent, or size.',
            ]);
        }

        return [
            'shopify_store_key' => $this->nullableString($validated['shopify_store_key'] ?? null),
            'shopify_product_id' => $validated['shopify_product_id'] ?? null,
            'shopify_variant_id' => $validated['shopify_variant_id'] ?? null,
            'sku' => $this->nullableString($validated['sku'] ?? null),
            'scent_id' => $validated['scent_id'] ?? null,
            'size_id' => $validated['size_id'] ?? null,
            'cost_amount' => round((float) $validated['cost_amount'], 2),
            'currency_code' => strtoupper(trim((string) ($validated['currency_code'] ?? 'USD'))),
            'effective_at' => ! empty($validated['effective_at']) ? Carbon::parse((string) $validated['effective_at']) : null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'notes' => $this->nullableString($validated['notes'] ?? null),
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    protected function resetCreateForm(): void
    {
        $this->create = [
            'shopify_store_key' => 'retail',
            'shopify_product_id' => '',
            'shopify_variant_id' => '',
            'sku' => '',
            'scent_id' => '',
            'size_id' => '',
            'cost_amount' => '',
            'currency_code' => 'USD',
            'effective_at' => '',
            'is_active' => true,
            'notes' => '',
        ];
    }

    protected function dispatchCatalogUnavailableToast(): void
    {
        $this->dispatch('toast', [
            'message' => 'Catalog costs are unavailable in this environment until the catalog_item_costs table is migrated.',
            'style' => 'warning',
        ]);
    }

    public function render()
    {
        if (! $this->catalogCostsAvailable) {
            $costs = new LengthAwarePaginator(
                items: [],
                total: 0,
                perPage: $this->perPage,
                currentPage: max(1, (int) request()->query('page', 1)),
                options: ['path' => request()->url(), 'pageName' => 'page']
            );

            return view('livewire.admin.catalog.costs', [
                'costs' => $costs,
                'scentOptions' => collect(),
                'sizeOptions' => collect(),
                'catalogCostsAvailable' => false,
            ])->layout('layouts.app');
        }

        $costs = CatalogItemCost::query()
            ->with(['scent:id,name,display_name', 'size:id,code,label'])
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%' . trim($this->search) . '%';

                $query->where(function (Builder $builder) use ($search): void {
                    $builder
                        ->where('shopify_store_key', 'like', $search)
                        ->orWhere('sku', 'like', $search)
                        ->orWhere('shopify_product_id', 'like', $search)
                        ->orWhere('shopify_variant_id', 'like', $search)
                        ->orWhere('currency_code', 'like', $search)
                        ->orWhereHas('scent', fn (Builder $scentQuery) => $scentQuery
                            ->where('name', 'like', $search)
                            ->orWhere('display_name', 'like', $search))
                        ->orWhereHas('size', fn (Builder $sizeQuery) => $sizeQuery
                            ->where('code', 'like', $search)
                            ->orWhere('label', 'like', $search));
                });
            })
            ->orderBy($this->sort, $this->dir)
            ->paginate($this->perPage);

        return view('livewire.admin.catalog.costs', [
            'costs' => $costs,
            'scentOptions' => Scent::query()->orderByRaw('coalesce(display_name, name) asc')->get(['id', 'name', 'display_name']),
            'sizeOptions' => Size::query()->orderByRaw('coalesce(label, code) asc')->get(['id', 'code', 'label']),
            'catalogCostsAvailable' => true,
        ])->layout('layouts.app');
    }
}
