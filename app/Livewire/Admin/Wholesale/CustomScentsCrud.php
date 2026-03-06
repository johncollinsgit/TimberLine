<?php

namespace App\Livewire\Admin\Wholesale;

use App\Models\WholesaleCustomScent;
use App\Models\Scent;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class CustomScentsCrud extends Component
{
    use WithPagination;

    public string $search = '';
    public string $filter = 'all'; // all | mapped | unmapped
    public int $perPage = 25;
    public bool $showCreate = false;

    public array $create = [
        'account_name' => '',
        'custom_scent_name' => '',
        'canonical_scent_id' => null,
        'notes' => '',
        'active' => true,
    ];

    public bool $showEdit = false;
    public ?int $editingId = null;
    public array $edit = [];

    public bool $showDelete = false;
    public ?int $deletingId = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'filter' => ['except' => 'all'],
        'perPage' => ['except' => 25],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->showCreate = !$this->showCreate;
    }

    #[On('scentSelected')]
    public function handleScentSelected(string $key, ?int $scentId = null, ?string $scentName = null): void
    {
        if ($key === 'wholesale-create') {
            $this->create['canonical_scent_id'] = $scentId;
            return;
        }

        if ($key === 'wholesale-edit') {
            $this->edit['canonical_scent_id'] = $scentId;
        }
    }

    public function create(): void
    {
        $data = $this->validateCreate();
        $this->assertUnique($data['account_name'], $data['custom_scent_name']);

        WholesaleCustomScent::query()->create([
            'account_name' => trim($data['account_name']),
            'custom_scent_name' => trim($data['custom_scent_name']),
            'canonical_scent_id' => $data['canonical_scent_id'] ?: null,
            'notes' => blank($data['notes'] ?? null) ? null : trim($data['notes']),
            'active' => (bool) ($data['active'] ?? true),
        ]);

        $this->reset('create');
        $this->create['active'] = true;

        $this->dispatch('toast', ['message' => 'Wholesale custom scent added.', 'style' => 'success']);
    }

    public function openEdit(int $id): void
    {
        $record = WholesaleCustomScent::query()->findOrFail($id);
        $this->editingId = $id;
        $this->edit = [
            'account_name' => $record->account_name,
            'custom_scent_name' => $record->custom_scent_name,
            'canonical_scent_id' => $record->canonical_scent_id,
            'notes' => $record->notes,
            'active' => (bool) $record->active,
        ];
        $this->showEdit = true;
    }

    public function save(): void
    {
        if (!$this->editingId) {
            return;
        }

        $data = $this->validateEdit();
        $this->assertUnique($data['account_name'], $data['custom_scent_name'], $this->editingId);

        WholesaleCustomScent::query()->whereKey($this->editingId)->update([
            'account_name' => trim($data['account_name']),
            'custom_scent_name' => trim($data['custom_scent_name']),
            'canonical_scent_id' => $data['canonical_scent_id'] ?: null,
            'notes' => blank($data['notes'] ?? null) ? null : trim($data['notes']),
            'active' => (bool) ($data['active'] ?? true),
        ]);

        $this->showEdit = false;
        $this->editingId = null;

        $this->dispatch('toast', ['message' => 'Wholesale custom scent updated.', 'style' => 'success']);
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

        WholesaleCustomScent::query()->whereKey($this->deletingId)->delete();
        $this->showDelete = false;
        $this->dispatch('toast', ['message' => 'Wholesale custom scent deleted.', 'style' => 'success']);
    }

    protected function validateCreate(): array
    {
        return validator($this->create, [
            'account_name' => ['required', 'string', 'max:255'],
            'custom_scent_name' => ['required', 'string', 'max:255'],
            'canonical_scent_id' => ['nullable', 'exists:scents,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'active' => ['boolean'],
        ])->validate();
    }

    protected function validateEdit(): array
    {
        return validator($this->edit, [
            'account_name' => ['required', 'string', 'max:255'],
            'custom_scent_name' => ['required', 'string', 'max:255'],
            'canonical_scent_id' => ['nullable', 'exists:scents,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'active' => ['boolean'],
        ])->validate();
    }

    protected function assertUnique(string $account, string $custom, ?int $ignoreId = null): void
    {
        $accountNorm = WholesaleCustomScent::normalizeAccountName($account);
        $customNorm = WholesaleCustomScent::normalizeScentName($custom);

        $exists = WholesaleCustomScent::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get()
            ->first(function (WholesaleCustomScent $row) use ($accountNorm, $customNorm) {
                return WholesaleCustomScent::normalizeAccountName($row->account_name) === $accountNorm
                    && WholesaleCustomScent::normalizeScentName($row->custom_scent_name) === $customNorm;
            });

        if ($exists) {
            throw ValidationException::withMessages([
                'custom_scent_name' => 'A custom scent for this account already exists (case-insensitive).',
            ]);
        }
    }

    public function render()
    {
        $query = WholesaleCustomScent::query()->with('canonicalScent');

        if ($this->search !== '') {
            $s = '%' . $this->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('account_name', 'like', $s)
                  ->orWhere('custom_scent_name', 'like', $s);
            });
        }

        if ($this->filter === 'mapped') {
            $query->whereNotNull('canonical_scent_id');
        } elseif ($this->filter === 'unmapped') {
            $query->whereNull('canonical_scent_id');
        }

        $records = $query->orderBy('account_name')
            ->orderBy('custom_scent_name')
            ->paginate($this->perPage);

        return view('livewire.admin.wholesale.custom-scents', [
            'records' => $records,
        ])->layout('layouts.app');
    }
}
