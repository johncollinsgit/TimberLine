<?php

namespace App\Livewire\Admin\Catalog;

use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Blend;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class ScentsCrud extends Component
{
    use WithPagination;

    public string $search = '';
    public string $sort = 'name';
    public string $dir = 'asc';
    public int $perPage = 25;
    public bool $showCreate = false;

    public array $create = [
        'name' => '',
        'display_name' => '',
        'abbreviation' => '',
        'oil_reference_name' => '',
        'is_blend' => false,
        'oil_blend_id' => null,
        'blend_oil_count' => null,
        'is_active' => true,
    ];

    public bool $showEdit = false;
    public ?int $editingId = null;
    public array $edit = [];

    public bool $showDelete = false;
    public ?int $deletingId = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'sort' => ['except' => 'name'],
        'dir' => ['except' => 'asc'],
        'perPage' => ['except' => 25],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setSort(string $field): void
    {
        if ($this->sort === $field) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $field;
            $this->dir = 'asc';
        }
    }

    public function openCreate(): void
    {
        $this->showCreate = !$this->showCreate;
    }

    public function create(): void
    {
        $data = $this->validateCreate();
        $data['name'] = $this->normalizeScentName($data['name']);
        $this->assertUniqueName($data['name']);

        Scent::query()->create([
            'name' => trim($data['name']),
            'display_name' => blank($data['display_name'] ?? null) ? null : trim($data['display_name']),
            'abbreviation' => blank($data['abbreviation'] ?? null) ? null : trim($data['abbreviation']),
            'oil_reference_name' => blank($data['oil_reference_name'] ?? null) ? null : trim($data['oil_reference_name']),
            'is_blend' => (bool) ($data['is_blend'] ?? false),
            'oil_blend_id' => $data['oil_blend_id'] ?? null,
            'blend_oil_count' => $data['is_blend'] ? ($data['blend_oil_count'] ?? null) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $this->reset('create');
        $this->create['is_active'] = true;
        $this->dispatch('toast', ['message' => 'Scent created.', 'style' => 'success']);
    }

    public function openEdit(int $id): void
    {
        $scent = Scent::query()->findOrFail($id);
        $this->editingId = $id;
        $this->edit = [
            'name' => $scent->name,
            'display_name' => $scent->display_name,
            'abbreviation' => $scent->abbreviation,
            'oil_reference_name' => $scent->oil_reference_name,
            'is_blend' => (bool) $scent->is_blend,
            'oil_blend_id' => $scent->oil_blend_id,
            'blend_oil_count' => $scent->blend_oil_count,
            'is_active' => (bool) $scent->is_active,
        ];
        $this->showEdit = true;
    }

    public function save(): void
    {
        if (!$this->editingId) {
            return;
        }
        $data = $this->validateEdit();
        $data['name'] = $this->normalizeScentName($data['name']);
        $this->assertUniqueName($data['name'], $this->editingId);

        Scent::query()->whereKey($this->editingId)->update([
            'name' => trim($data['name']),
            'display_name' => blank($data['display_name'] ?? null) ? null : trim($data['display_name']),
            'abbreviation' => blank($data['abbreviation'] ?? null) ? null : trim($data['abbreviation']),
            'oil_reference_name' => blank($data['oil_reference_name'] ?? null) ? null : trim($data['oil_reference_name']),
            'is_blend' => (bool) ($data['is_blend'] ?? false),
            'oil_blend_id' => $data['oil_blend_id'] ?? null,
            'blend_oil_count' => $data['is_blend'] ? ($data['blend_oil_count'] ?? null) : null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        $this->showEdit = false;
        $this->editingId = null;
        $this->dispatch('toast', ['message' => 'Scent updated.', 'style' => 'success']);
    }

    public function openDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->showDelete = true;
    }

    public function destroy(): void
    {
        if (!$this->deletingId) {
            return;
        }

        $inUse = OrderLine::query()->where('scent_id', $this->deletingId)->exists();
        if ($inUse) {
            $this->dispatch('toast', [
                'message' => 'Cannot delete: this scent is used by existing order lines. Deactivate it instead.',
                'style' => 'warning',
            ]);
            $this->showDelete = false;
            return;
        }

        Scent::query()->whereKey($this->deletingId)->delete();
        $this->showDelete = false;
        $this->dispatch('toast', ['message' => 'Scent deleted.', 'style' => 'success']);
    }

    protected function validateCreate(): array
    {
        $payload = $this->normalizedPayload($this->create);

        return validator($payload, [
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'abbreviation' => ['nullable', 'string', 'max:64'],
            'oil_reference_name' => ['nullable', 'string', 'max:255'],
            'is_blend' => ['boolean'],
            'oil_blend_id' => [
                Rule::excludeIf(! (bool) ($payload['is_blend'] ?? false)),
                'nullable',
                'exists:blends,id',
            ],
            'blend_oil_count' => [
                Rule::excludeIf(! (bool) ($payload['is_blend'] ?? false)),
                'nullable',
                'integer',
                'min:1',
            ],
            'is_active' => ['boolean'],
        ])->validate();
    }

    protected function validateEdit(): array
    {
        $payload = $this->normalizedPayload($this->edit);

        return validator($payload, [
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'abbreviation' => ['nullable', 'string', 'max:64'],
            'oil_reference_name' => ['nullable', 'string', 'max:255'],
            'is_blend' => ['boolean'],
            'oil_blend_id' => [
                Rule::excludeIf(! (bool) ($payload['is_blend'] ?? false)),
                'nullable',
                'exists:blends,id',
            ],
            'blend_oil_count' => [
                Rule::excludeIf(! (bool) ($payload['is_blend'] ?? false)),
                'nullable',
                'integer',
                'min:1',
            ],
            'is_active' => ['boolean'],
        ])->validate();
    }

    public function updatedCreateIsBlend($value): void
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOL)) {
            $this->create['oil_blend_id'] = null;
            $this->create['blend_oil_count'] = null;
        }
    }

    public function updatedEditIsBlend($value): void
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOL)) {
            $this->edit['oil_blend_id'] = null;
            $this->edit['blend_oil_count'] = null;
        }
    }

    protected function assertUniqueName(string $name, ?int $ignoreId = null): void
    {
        $normalized = Scent::normalizeName($name);
        $query = Scent::query()->whereRaw('lower(name) = ?', [$normalized]);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A scent with this name already exists (case-insensitive).',
            ]);
        }
    }

    public function render()
    {
        $scents = Scent::query()
            ->when($this->search !== '', function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('display_name', 'like', '%' . $this->search . '%')
                    ->orWhere('abbreviation', 'like', '%' . $this->search . '%');
            })
            ->orderBy($this->sort, $this->dir)
            ->paginate($this->perPage);

        return view('livewire.admin.catalog.scents', [
            'scents' => $scents,
            'blends' => Blend::query()->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app');
    }

    protected function normalizeScentName(string $name): string
    {
        return Scent::normalizeName($name);
    }

    protected function normalizedPayload(array $payload): array
    {
        $normalized = $payload;
        $normalized['is_blend'] = (bool) ($payload['is_blend'] ?? false);
        $normalized['is_active'] = (bool) ($payload['is_active'] ?? true);

        $oilBlendId = $payload['oil_blend_id'] ?? null;
        $normalized['oil_blend_id'] = blank($oilBlendId) ? null : (int) $oilBlendId;

        $blendOilCount = $payload['blend_oil_count'] ?? null;
        $normalized['blend_oil_count'] = blank($blendOilCount) ? null : (int) $blendOilCount;

        if (! $normalized['is_blend']) {
            $normalized['oil_blend_id'] = null;
            $normalized['blend_oil_count'] = null;
        }

        return $normalized;
    }
}
