<?php

namespace App\Livewire\Admin\Catalog;

use App\Models\Wick;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class WicksCrud extends Component
{
    use WithPagination;

    public string $search = '';
    public string $sort = 'name';
    public string $dir = 'asc';
    public int $perPage = 25;
    public bool $showCreate = false;

    public array $create = [
        'name' => '',
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
        $this->assertUniqueName($data['name']);

        Wick::query()->create([
            'name' => trim($data['name']),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $this->reset('create');
        $this->create['is_active'] = true;
        $this->dispatch('toast', ['message' => 'Wick created.', 'style' => 'success']);
    }

    public function openEdit(int $id): void
    {
        $wick = Wick::query()->findOrFail($id);
        $this->editingId = $id;
        $this->edit = [
            'name' => $wick->name,
            'is_active' => (bool) $wick->is_active,
        ];
        $this->showEdit = true;
    }

    public function save(): void
    {
        if (!$this->editingId) {
            return;
        }
        $data = $this->validateEdit();
        $this->assertUniqueName($data['name'], $this->editingId);

        Wick::query()->whereKey($this->editingId)->update([
            'name' => trim($data['name']),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        $this->showEdit = false;
        $this->editingId = null;
        $this->dispatch('toast', ['message' => 'Wick updated.', 'style' => 'success']);
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

        Wick::query()->whereKey($this->deletingId)->delete();
        $this->showDelete = false;
        $this->dispatch('toast', ['message' => 'Wick deleted.', 'style' => 'success']);
    }

    protected function validateCreate(): array
    {
        return validator($this->create, [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ])->validate();
    }

    protected function validateEdit(): array
    {
        return validator($this->edit, [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ])->validate();
    }

    protected function assertUniqueName(string $name, ?int $ignoreId = null): void
    {
        $normalized = Str::of($name)->lower()->trim()->value();
        $query = Wick::query()->whereRaw('lower(name) = ?', [$normalized]);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A wick with this name already exists (case-insensitive).',
            ]);
        }
    }

    public function render()
    {
        $wicks = Wick::query()
            ->when($this->search !== '', function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%');
            })
            ->orderBy($this->sort, $this->dir)
            ->paginate($this->perPage);

        return view('livewire.admin.catalog.wicks', [
            'wicks' => $wicks,
        ])->layout('layouts.app');
    }
}
