<?php

namespace App\Livewire\Components;

use App\Models\Scent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Modelable;
use Livewire\Component;

class ScentCombobox extends Component
{
    public string $query = '';
    #[Modelable]
    public ?int $selectedId = null;
    public string $selectedLabel = '';
    public bool $showDropdown = false;
    public string $placeholder = 'Start typing a scent…';
    public string $emitEvent = 'scentSelected';
    public string $emitKey = '';
    public int $limit = 20;
    public bool $allowWholesaleCustom = false;
    public bool $includeInactive = false;

    public function mount(?int $selectedId = null): void
    {
        if ($this->selectedId === null && $selectedId) {
            $this->selectedId = $selectedId;
        }

        if ($this->selectedId) {
            $scent = Scent::query()->find($this->selectedId);
            if ($scent) {
                $this->selectedLabel = (string) ($scent->display_name ?: $scent->name);
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
        $scent = Scent::query()->find($id);
        if ($scent) {
            $this->selectedLabel = (string) ($scent->display_name ?: $scent->name);
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

        // User changed text away from selected scent; require a new explicit selection.
        if ($this->selectedId !== null) {
            $this->selectedId = null;
            $this->selectedLabel = '';
        }

        $this->showDropdown = true;
    }

    public function select(int $scentId): void
    {
        $scent = Scent::query()->find($scentId);
        if (!$scent) {
            return;
        }

        $this->selectedId = $scentId;
        $this->selectedLabel = (string) ($scent->display_name ?: $scent->name);
        $this->query = $this->selectedLabel;
        $this->showDropdown = false;

        $this->dispatch($this->emitEvent, key: $this->emitKey, scentId: $scentId, scentName: $scent->name);
    }

    public function clear(): void
    {
        $this->selectedId = null;
        $this->selectedLabel = '';
        $this->query = '';
        $this->showDropdown = false;
        $this->dispatch($this->emitEvent, key: $this->emitKey, scentId: null, scentName: null);
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
        return view('livewire.components.scent-combobox', [
            'options' => $this->resolveOptions(),
        ]);
    }

    protected function resolveOptions(): Collection
    {
        $query = trim($this->query);
        if ($query !== '') {
            $tokens = collect(explode(' ', $this->normalizeSearchText($query)))
                ->map(fn (string $token): string => trim($token))
                ->filter(fn (string $token): bool => $token !== '')
                ->values()
                ->all();

            if ($tokens === []) {
                return collect();
            }

            $columns = ['name', 'display_name'];
            if (Schema::hasColumn('scents', 'abbreviation')) {
                $columns[] = 'abbreviation';
            }
            if (Schema::hasColumn('scents', 'oil_reference_name')) {
                $columns[] = 'oil_reference_name';
            }

            return Scent::query()
                ->when(! $this->includeInactive, function ($q) {
                    $q->where('is_active', true);
                })
                ->when(!$this->allowWholesaleCustom, function ($q) {
                    $q->where(function ($inner) {
                        $inner->whereNull('is_wholesale_custom')
                              ->orWhere('is_wholesale_custom', false);
                    });
                })
                ->where(function ($builder) use ($tokens, $columns): void {
                    foreach ($tokens as $token) {
                        $like = '%'.$token.'%';
                        $builder->where(function ($tokenQuery) use ($columns, $like): void {
                            foreach ($columns as $index => $column) {
                                if ($index === 0) {
                                    $tokenQuery->whereRaw("lower(coalesce({$column}, '')) like ?", [$like]);
                                } else {
                                    $tokenQuery->orWhereRaw("lower(coalesce({$column}, '')) like ?", [$like]);
                                }
                            }
                        });
                    }
                })
                ->orderByRaw('COALESCE(display_name, name)')
                ->limit($this->limit)
                ->get(['id', 'name', 'display_name', 'is_active']);
        }

        return collect();
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
