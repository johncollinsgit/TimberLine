<?php

namespace App\Livewire\Dashboard;

use App\Services\Dashboard\UnifiedDashboardService;
use App\Services\Search\GlobalSearchCoordinator;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use Livewire\Component;

class Launchpad extends Component
{
    public string $search = '';

    public function submitSearch()
    {
        $user = auth()->user();
        $request = request();
        $tenant = $user ? app(AuthenticatedTenantContextResolver::class)->resolveForRequest($request, $user) : null;
        $payload = app(GlobalSearchCoordinator::class)->search($this->search, [
            'tenant_id' => $tenant?->id,
            'user' => $user,
            'request' => $request,
            'surface' => 'marketing',
            'limit' => 1,
        ]);

        $first = $payload['results'][0] ?? null;
        $url = is_array($first) ? ($first['url'] ?? null) : null;
        if (is_string($url) && trim($url) !== '') {
            return $this->redirect($url, navigate: true);
        }

        return $this->redirect(route('dashboard'), navigate: true);
    }

    public function render()
    {
        $dashboard = app(UnifiedDashboardService::class)->forRequest(request(), auth()->user());

        return view('livewire.dashboard.launchpad', [
            'dashboard' => $dashboard,
        ])->layout('layouts.app', ['title' => 'Dashboard']);
    }
}
