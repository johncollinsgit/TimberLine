<?php

namespace App\Livewire\Components;

use App\Models\Scent;
use Livewire\Component;

class ScentCombobox extends Component
{
    public string $query = '';
    public ?int $selectedId = null;
    public string $placeholder = 'Start typing a scent…';
    public string $emitEvent = 'scentSelected';
    public string $emitKey = '';
    public int $limit = 20;
    public bool $allowWholesaleCustom = false;

    public function mount(?int $selectedId = null): void
    {
        $this->selectedId = $selectedId;
        if ($selectedId) {
            $scent = Scent::query()->find($selectedId);
            if ($scent) {
                $this->query = $scent->name;
            }
        }
    }

    public function select(int $scentId): void
    {
        $scent = Scent::query()->find($scentId);
        if (!$scent) {
            return;
        }

        $this->selectedId = $scentId;
        $this->query = $scent->name;

        $this->dispatch($this->emitEvent, key: $this->emitKey, scentId: $scentId, scentName: $scent->name);
    }

    public function clear(): void
    {
        $this->selectedId = null;
        $this->query = '';
        $this->dispatch($this->emitEvent, key: $this->emitKey, scentId: null, scentName: null);
    }

    public function render()
    {
        $options = [];
        $query = trim($this->query);

        if ($query !== '') {
            $options = Scent::query()
                ->where('is_active', true)
                ->when(!$this->allowWholesaleCustom, function ($q) {
                    $q->where(function ($inner) {
                        $inner->whereNull('is_wholesale_custom')
                              ->orWhere('is_wholesale_custom', false);
                    });
                })
                ->where('name', 'like', '%'.$query.'%')
                ->orderBy('name')
                ->limit($this->limit)
                ->get(['id', 'name']);
        }

        return view('livewire.components.scent-combobox', [
            'options' => $options,
        ]);
    }
}
