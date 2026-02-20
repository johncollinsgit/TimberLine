<?php

namespace App\Livewire\Admin\Oils;

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class OilBlendsCrud extends Component
{
    use WithPagination;

    public string $search = '';
    public int $perPage = 25;
    public bool $showCreate = false;

    public array $create = [
        'name' => '',
        'components' => [],
    ];

    public bool $showEdit = false;
    public ?int $editingId = null;
    public array $edit = [];

    public bool $showDelete = false;
    public ?int $deletingId = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'perPage' => ['except' => 25],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->showCreate = !$this->showCreate;
        if ($this->showCreate && empty($this->create['components'])) {
            $this->create['components'] = [
                ['base_oil_id' => null, 'ratio_weight' => 1],
            ];
        }
    }

    public function addComponent(string $target): void
    {
        if ($target === 'create') {
            $this->create['components'][] = ['base_oil_id' => null, 'ratio_weight' => 1];
            return;
        }

        if ($target === 'edit') {
            $this->edit['components'][] = ['base_oil_id' => null, 'ratio_weight' => 1];
        }
    }

    public function removeComponent(string $target, int $index): void
    {
        if ($target === 'create') {
            unset($this->create['components'][$index]);
            $this->create['components'] = array_values($this->create['components']);
            return;
        }

        if ($target === 'edit') {
            unset($this->edit['components'][$index]);
            $this->edit['components'] = array_values($this->edit['components']);
        }
    }

    public function create(): void
    {
        $data = $this->validateCreate();
        $this->assertUnique($data['name']);

        $blend = Blend::query()->create([
            'name' => trim($data['name']),
            'is_blend' => true,
        ]);

        $this->syncComponents($blend, $data['components'] ?? []);

        $this->reset('create');
        $this->dispatch('toast', ['message' => 'Blend created.', 'style' => 'success']);
    }

    public function openEdit(int $id): void
    {
        $blend = Blend::query()->with('components')->findOrFail($id);
        $this->editingId = $id;
        $this->edit = [
            'name' => $blend->name,
            'components' => $blend->components->map(fn ($c) => [
                'base_oil_id' => $c->base_oil_id,
                'ratio_weight' => $c->ratio_weight,
            ])->all(),
        ];

        if (empty($this->edit['components'])) {
            $this->edit['components'] = [['base_oil_id' => null, 'ratio_weight' => 1]];
        }

        $this->showEdit = true;
    }

    public function save(): void
    {
        if (!$this->editingId) {
            return;
        }

        $data = $this->validateEdit();
        $this->assertUnique($data['name'], $this->editingId);

        $blend = Blend::query()->findOrFail($this->editingId);
        $blend->update([
            'name' => trim($data['name']),
            'is_blend' => true,
        ]);

        $this->syncComponents($blend, $data['components'] ?? []);

        $this->showEdit = false;
        $this->editingId = null;

        $this->dispatch('toast', ['message' => 'Blend updated.', 'style' => 'success']);
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

        Blend::query()->whereKey($this->deletingId)->delete();
        $this->showDelete = false;
        $this->dispatch('toast', ['message' => 'Blend deleted.', 'style' => 'success']);
    }

    protected function syncComponents(Blend $blend, array $components): void
    {
        $blend->components()->delete();

        foreach ($components as $component) {
            $baseOilId = $component['base_oil_id'] ?? null;
            $ratio = $component['ratio_weight'] ?? null;

            if (!$baseOilId || !$ratio) {
                continue;
            }

            BlendComponent::query()->create([
                'blend_id' => $blend->id,
                'base_oil_id' => (int) $baseOilId,
                'ratio_weight' => (int) $ratio,
            ]);
        }
    }

    protected function validateCreate(): array
    {
        $payload = $this->create;
        $payload['name'] = trim((string) ($payload['name'] ?? ''));
        $payload['components'] = $this->normalizeComponents($payload['components'] ?? []);

        return validator($payload, [
            'name' => ['required', 'string', 'max:255'],
            'components' => ['array'],
            'components.*.base_oil_id' => ['nullable', 'exists:base_oils,id'],
            'components.*.ratio_weight' => ['nullable', 'integer', 'min:1'],
        ])->validate();
    }

    protected function validateEdit(): array
    {
        $payload = $this->edit;
        $payload['name'] = trim((string) ($payload['name'] ?? ''));
        $payload['components'] = $this->normalizeComponents($payload['components'] ?? []);

        return validator($payload, [
            'name' => ['required', 'string', 'max:255'],
            'components' => ['array'],
            'components.*.base_oil_id' => ['nullable', 'exists:base_oils,id'],
            'components.*.ratio_weight' => ['nullable', 'integer', 'min:1'],
        ])->validate();
    }

    protected function assertUnique(string $name, ?int $ignoreId = null): void
    {
        $normalized = strtolower(trim($name));
        $query = Blend::query()->whereRaw('lower(name) = ?', [$normalized]);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A blend with this name already exists (case-insensitive).',
            ]);
        }
    }

    protected function normalizeComponents(array $components): array
    {
        return array_map(function ($component) {
            $baseOilId = $component['base_oil_id'] ?? null;
            $ratioWeight = $component['ratio_weight'] ?? null;

            return [
                'base_oil_id' => $baseOilId === '' ? null : $baseOilId,
                'ratio_weight' => $ratioWeight === '' ? null : $ratioWeight,
            ];
        }, $components);
    }

    public function render()
    {
        $baseOils = BaseOil::query()->orderBy('name')->get();

        $blends = Blend::query()
            ->when($this->search !== '', function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%');
            })
            ->with('components.baseOil')
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.admin.oils.blends', [
            'blends' => $blends,
            'baseOils' => $baseOils,
        ])->layout('layouts.app');
    }
}
