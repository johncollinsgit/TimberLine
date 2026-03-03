<?php

namespace App\Livewire\Admin;

use App\Http\Controllers\AdminMasterDataController;
use Livewire\Component;

class AdminHome extends Component
{
    public string $tab = 'users';
    /** @var array<int,array{key:string,label:string,description:string}> */
    public array $masterDataResources = [];
    public string $masterDataActiveResource = 'scents';
    public string $masterDataBaseEndpoint = '';

    public function mount(): void
    {
        /** @var AdminMasterDataController $masterData */
        $masterData = app(AdminMasterDataController::class);
        $this->masterDataResources = $masterData->resourceTabs();
        $this->masterDataActiveResource = $masterData->defaultResourceKey((string) request()->query('resource', ''));
        $this->masterDataBaseEndpoint = url('/admin/master');

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

        if (! in_array($this->tab, $this->allowedTabs($isAdmin), true)) {
            $this->tab = $isAdmin ? 'users' : 'imports';
        }
    }

    public function render()
    {
        return view('livewire.admin.admin-home')
            ->layout('layouts.app');
    }

    /**
     * @return array<int,string>
     */
    protected function allowedTabs(bool $isAdmin): array
    {
        $tabs = [
            'imports',
            'scent-intake',
            'catalog',
            'sizes-wicks',
            'wholesale-custom',
            'blends',
            'candle-club',
            'oils',
            'master-data',
        ];

        if ($isAdmin) {
            array_unshift($tabs, 'users');
        }

        return $tabs;
    }
}
