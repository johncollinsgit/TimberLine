<?php

namespace App\Livewire\Admin;

use App\Models\ShopifyImportRun;
use App\Models\User;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use Livewire\Component;
use Livewire\WithPagination;

class ImportRuns extends Component
{
    use WithPagination;

    public int $page = 1;

    protected $queryString = [
        'page' => ['except' => 1],
    ];

    public function render()
    {
        $tenantId = $this->activeTenantId();
        $runs = ShopifyImportRun::query()
            ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
            ->when($tenantId === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.admin.import-runs', [
            'runs' => $runs,
        ]);
    }

    protected function activeTenantId(): ?int
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }

        $tenant = app(AuthenticatedTenantContextResolver::class)->resolveForRequest(request(), $user);

        return $tenant ? (int) $tenant->id : null;
    }
}
