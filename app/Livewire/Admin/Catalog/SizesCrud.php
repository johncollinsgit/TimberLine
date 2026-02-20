<?php

namespace App\Livewire\Admin\Catalog;

use App\Models\OrderLine;
use App\Models\Size;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class SizesCrud extends Component
{
    use WithPagination;

    public string $search = '';
    public string $sort = 'label';
    public string $dir = 'asc';
    public int $perPage = 25;
    public bool $showCreate = false;

    public array $create = [
        'code' => '',
        'label' => '',
        'wholesale_price' => null,
        'retail_price' => null,
        'is_active' => true,
    ];

    public bool $showEdit = false;
    public ?int $editingId = null;
    public array $edit = [];

    public bool $showDelete = false;
    public ?int $deletingId = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'sort' => ['except' => 'label'],
        'dir' => ['except' => 'asc'],
        'perPage' => ['except' => 25],
    ];

    public function mount(): void
    {
        $this->ensureStandardSizes();
    }

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
        if (blank($data['code'] ?? null) && !blank($data['label'] ?? null)) {
            $data['code'] = $this->codeFromLabel($data['label']);
        }
        if (blank($data['code'] ?? null)) {
            throw ValidationException::withMessages([
                'code' => 'Code is required (auto-filled from label if provided).',
            ]);
        }
        $this->assertUniqueCode($data['code']);

        Size::query()->create([
            'code' => trim($data['code']),
            'label' => blank($data['label'] ?? null) ? null : trim($data['label']),
            'wholesale_price' => $data['wholesale_price'] ?? null,
            'retail_price' => $data['retail_price'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $this->reset('create');
        $this->create['is_active'] = true;
        $this->dispatch('toast', ['message' => 'Size created.', 'style' => 'success']);
    }

    public function openEdit(int $id): void
    {
        $size = Size::query()->findOrFail($id);
        $this->editingId = $id;
        $this->edit = [
            'code' => $size->code,
            'label' => $size->label,
            'wholesale_price' => $size->wholesale_price,
            'retail_price' => $size->retail_price,
            'is_active' => (bool) $size->is_active,
        ];
        $this->showEdit = true;
    }

    public function save(): void
    {
        if (!$this->editingId) {
            return;
        }
        $data = $this->validateEdit();
        $this->assertUniqueCode($data['code'], $this->editingId);

        Size::query()->whereKey($this->editingId)->update([
            'code' => trim($data['code']),
            'label' => blank($data['label'] ?? null) ? null : trim($data['label']),
            'wholesale_price' => $data['wholesale_price'] ?? null,
            'retail_price' => $data['retail_price'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        $this->showEdit = false;
        $this->editingId = null;
        $this->dispatch('toast', ['message' => 'Size updated.', 'style' => 'success']);
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

        $inUse = OrderLine::query()->where('size_id', $this->deletingId)->exists();
        if ($inUse) {
            $this->dispatch('toast', [
                'message' => 'Cannot delete: this size is used by existing order lines. Deactivate it instead.',
                'style' => 'warning',
            ]);
            $this->showDelete = false;
            return;
        }

        Size::query()->whereKey($this->deletingId)->delete();
        $this->showDelete = false;
        $this->dispatch('toast', ['message' => 'Size deleted.', 'style' => 'success']);
    }

    protected function validateCreate(): array
    {
        return validator($this->create, [
            'code' => ['nullable', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:255'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ])->validate();
    }

    protected function validateEdit(): array
    {
        return validator($this->edit, [
            'code' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:255'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ])->validate();
    }

    protected function assertUniqueCode(string $code, ?int $ignoreId = null): void
    {
        $normalized = Str::of($code)->lower()->trim()->value();
        $query = Size::query()->whereRaw('lower(code) = ?', [$normalized]);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => 'A size with this code already exists (case-insensitive).',
            ]);
        }
    }

    protected function codeFromLabel(string $label): string
    {
        $clean = Str::of($label)->lower()->trim()->value();
        $clean = str_replace('&', 'and', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = str_replace(' ', '-', $clean);
        return $clean;
    }

    public function render()
    {
        $sizes = Size::query()
            ->when($this->search !== '', function ($query) {
                $query->where('code', 'like', '%' . $this->search . '%')
                    ->orWhere('label', 'like', '%' . $this->search . '%');
            })
            ->orderBy($this->sort, $this->dir)
            ->paginate($this->perPage);

        return view('livewire.admin.catalog.sizes', [
            'sizes' => $sizes,
        ])->layout('layouts.app');
    }

    protected function ensureStandardSizes(): void
    {
        $standards = [
            ['code' => 'wax-melts', 'label' => 'Wax Melts', 'wholesale_price' => 3.00, 'retail_price' => 6.00],
            ['code' => 'room-sprays', 'label' => 'Room Sprays', 'wholesale_price' => 6.00, 'retail_price' => 12.00],
        ];

        foreach ($standards as $size) {
            $exists = Size::query()->whereRaw('lower(code) = ?', [strtolower($size['code'])])->exists();
            if ($exists) {
                continue;
            }
            Size::query()->create([
                'code' => $size['code'],
                'label' => $size['label'],
                'wholesale_price' => $size['wholesale_price'],
                'retail_price' => $size['retail_price'],
                'is_active' => true,
            ]);
        }
    }
}
