<?php

namespace App\Livewire\Admin;

use App\Models\Scent;
use App\Models\Size;
use Livewire\Component;

class Catalog extends Component
{
    public string $tab = 'scents'; // scents | sizes

    // Create forms
    public string $newScentName = '';
    public string $newSizeCode = '';
    public string $newSizeLabel = '';

    // Inline edit state
    public array $editScent = []; // [id => ['name'=>..., 'is_active'=>bool]]
    public array $editSize  = []; // [id => ['code'=>..., 'label'=>..., 'is_active'=>bool]]

    public function mount(): void
    {
        // hydrate edits once
        $this->rehydrateEdits();
    }

    private function rehydrateEdits(): void
    {
        $this->editScent = Scent::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn($s) => [
                $s->id => [
                    'name' => $s->name,
                    'is_active' => (bool) $s->is_active,
                ]
            ])->toArray();

        $this->editSize = Size::query()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn($s) => [
                $s->id => [
                    'code' => $s->code,
                    'label' => $s->label,
                    'is_active' => (bool) $s->is_active,
                ]
            ])->toArray();
    }

    // --- SCENTS ---
    public function createScent(): void
    {
        $validated = validator(
            ['name' => $this->newScentName],
            ['name' => ['required', 'string', 'max:255', 'unique:scents,name']]
        )->validate();

        Scent::query()->create([
            'name' => trim($validated['name']),
            'is_active' => true,
        ]);

        $this->newScentName = '';
        $this->rehydrateEdits();
    }

    public function saveScent(int $id): void
    {
        $data = $this->editScent[$id] ?? null;
        abort_unless(is_array($data), 404);

        $validated = validator($data, [
            'name' => ['required', 'string', 'max:255', 'unique:scents,name,' . $id],
            'is_active' => ['boolean'],
        ])->validate();

        Scent::query()->whereKey($id)->update([
            'name' => trim($validated['name']),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        $this->rehydrateEdits();
    }

    // --- SIZES ---
    public function createSize(): void
    {
        $validated = validator(
            ['code' => $this->newSizeCode, 'label' => $this->newSizeLabel],
            [
                'code' => ['required', 'string', 'max:64', 'unique:sizes,code'],
                'label' => ['nullable', 'string', 'max:255'],
            ]
        )->validate();

        Size::query()->create([
            'code' => trim($validated['code']),
            'label' => blank($validated['label'] ?? null) ? null : trim($validated['label']),
            'is_active' => true,
        ]);

        $this->newSizeCode = '';
        $this->newSizeLabel = '';
        $this->rehydrateEdits();
    }

    public function saveSize(int $id): void
    {
        $data = $this->editSize[$id] ?? null;
        abort_unless(is_array($data), 404);

        $validated = validator($data, [
            'code' => ['required', 'string', 'max:64', 'unique:sizes,code,' . $id],
            'label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ])->validate();

        Size::query()->whereKey($id)->update([
            'code' => trim($validated['code']),
            'label' => blank($validated['label'] ?? null) ? null : trim($validated['label']),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        $this->rehydrateEdits();
    }

    public function render()
    {
        return view('livewire.admin.catalog');
    }
}
