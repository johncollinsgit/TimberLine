<?php

namespace App\Livewire\Admin;

use Livewire\Component;

class AdminHome extends Component
{
    public string $tab = 'users';

    public function mount(): void
    {
        $requested = request()->query('tab');
        if (is_string($requested) && $requested !== '') {
            $this->tab = $requested;
        }

        $user = auth()->user();
        $isManager = $user?->isManager() ?? false;
        $isAdmin = $user?->isAdmin() ?? true;

        if (!$isAdmin && $isManager && $this->tab === 'users') {
            $this->tab = 'imports';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->dispatch('admin-tab-changed');
    }

    public function render()
    {
        return view('livewire.admin.admin-home')
            ->layout('layouts.app');
    }
}
