<?php

namespace App\Livewire\Components;

use App\Models\BlendTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Modelable;
use Livewire\Component;

class BlendTemplateCombobox extends Component
{
    public string $query = '';
    #[Modelable]
    public ?int $selectedId = null;
    public string $selectedLabel = '';
    public bool $showDropdown = false;
    public string $placeholder = 'Search blend templates...';
    public string $emitEvent = 'blendTemplateSelected';
    public string $emitKey = '';
    public int $limit = 20;
    public bool $includeInactive = false;

    public function mount(?int $selectedId = null): void
    {
        if ($this->selectedId === null && $selectedId) {
            $this->selectedId = $selectedId;
        }

        if ($this->selectedId) {
            $blend = BlendTemplate::query()->find($this->selectedId);
            if ($blend) {
                $this->selectedLabel = (string) $blend->name;
                $this->query = $this->selectedLabel;
            }
        }
    }

    public function updatedSelectedId($value): void
    {
        $id = (int) $value;
        if ($id <= 0) {
            $this->selectedId = null;
            $this->selectedLabel = '';
            $this->showDropdown = false;
            if ($this->query !== '') {
                $this->query = '';
            }
            return;
        }

        $this->selectedId = $id;
        $blend = BlendTemplate::query()->find($id);
        if ($blend) {
            $this->selectedLabel = (string) $blend->name;
            $this->query = $this->selectedLabel;
        }
        $this->showDropdown = false;
    }

    public function updatedQuery($value): void
    {
        $query = trim((string) $value);

        if ($query === '') {
            $this->selectedId = null;
            $this->selectedLabel = '';
            $this->showDropdown = false;
            return;
        }

        if ($this->selectedLabel !== '' && mb_strtolower($query) === mb_strtolower($this->selectedLabel)) {
            $this->showDropdown = false;
            return;
        }

        if ($this->selectedId !== null) {
            $this->selectedId = null;
            $this->selectedLabel = '';
        }

        $this->showDropdown = true;
    }

    public function select(int $blendId): void
    {
        $blend = BlendTemplate::query()->find($blendId);
        if (! $blend) {
            return;
        }

        $this->selectedId = $blendId;
        $this->selectedLabel = (string) $blend->name;
        $this->query = $this->selectedLabel;
        $this->showDropdown = false;

        $this->dispatch($this->emitEvent, key: $this->emitKey, blendTemplateId: $blendId, blendTemplateName: $blend->name);
    }

    public function clear(): void
    {
        $this->selectedId = null;
        $this->selectedLabel = '';
        $this->query = '';
        $this->showDropdown = false;
        $this->dispatch($this->emitEvent, key: $this->emitKey, blendTemplateId: null, blendTemplateName: null);
    }

    public function openDropdownIfSearching(): void
    {
        $query = trim($this->query);
        if ($query === '') {
            $this->showDropdown = false;
            return;
        }

        if ($this->selectedLabel !== '' && mb_strtolower($query) === mb_strtolower($this->selectedLabel)) {
            $this->showDropdown = false;
            return;
        }

        $this->showDropdown = true;
    }

    public function closeDropdown(): void
    {
        $this->showDropdown = false;
    }

    public function selectOnlyMatch(): void
    {
        $options = $this->resolveOptions();
        if ($options->count() === 1) {
            $this->select((int) $options->first()->id);
        }
    }

    public function render()
    {
        return view('livewire.components.blend-template-combobox', [
            'options' => $this->resolveOptions(),
        ]);
    }

    protected function resolveOptions(): Collection
    {
        $query = trim($this->query);
        if ($query === '') {
            return collect();
        }

        $tokens = collect(explode(' ', $this->normalizeSearchText($query)))
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => $token !== '')
            ->values()
            ->all();

        if ($tokens === []) {
            return collect();
        }

        return BlendTemplate::query()
            ->when(! $this->includeInactive && Schema::hasColumn('blends', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->where(function ($builder) use ($tokens): void {
                foreach ($tokens as $token) {
                    $builder->whereRaw('lower(coalesce(name, \'\')) like ?', ['%'.$token.'%']);
                }
            })
            ->orderBy('name')
            ->limit($this->limit)
            ->get(Schema::hasColumn('blends', 'is_active') ? ['id', 'name', 'is_active'] : ['id', 'name']);
    }

    protected function normalizeSearchText(?string $value): string
    {
        $clean = strtolower(trim((string) $value));
        $clean = str_replace('&', 'and', $clean);
        $clean = preg_replace('/[^a-z0-9]+/i', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        return trim($clean);
    }
}
