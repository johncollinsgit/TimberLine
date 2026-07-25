<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class LandlordBranchPreviewController extends Controller
{
    public function __invoke(Request $request, TenantModuleCatalogService $catalogService): View
    {
        Gate::authorize('manage-landlord-commercial');

        /** @var Collection<int,Tenant> $tenants */
        $tenants = Tenant::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $selectedTenant = $this->selectedTenant($request, $tenants);
        $payload = $selectedTenant instanceof Tenant
            ? $catalogService->tenantStorePayload((int) $selectedTenant->id, 'marketing')
            : [
                'tenant_id' => null,
                'surface' => 'marketing',
                'current_plan' => ['key' => 'starter', 'label' => 'Starter', 'operating_mode' => 'direct'],
                'modules' => [],
                'blueprint_recommendations' => [],
                'sections' => ['active' => [], 'available' => [], 'upgrade' => [], 'request' => []],
            ];

        return view('landlord.branches.index', [
            'tenants' => $tenants,
            'selectedTenant' => $selectedTenant,
            'moduleStorePayload' => $payload,
            'customerModuleStoreUrl' => $selectedTenant instanceof Tenant
                ? $this->customerModuleStoreUrl($request, $selectedTenant)
                : null,
        ]);
    }

    /**
     * @param  Collection<int,Tenant>  $tenants
     */
    protected function selectedTenant(Request $request, Collection $tenants): ?Tenant
    {
        $tenantToken = trim((string) $request->query('tenant', ''));
        if ($tenantToken !== '') {
            $selected = $tenants->first(function (Tenant $tenant) use ($tenantToken): bool {
                return (string) $tenant->getKey() === $tenantToken
                    || strtolower((string) $tenant->slug) === strtolower($tenantToken);
            });

            if ($selected instanceof Tenant) {
                return $selected;
            }
        }

        return $tenants->first();
    }

    protected function customerModuleStoreUrl(Request $request, Tenant $tenant): string
    {
        $baseDomain = collect((array) config('tenancy.domains.tenant_base_domains', ['theeverbranch.com']))
            ->map(fn (mixed $domain): string => strtolower(trim((string) $domain)))
            ->filter()
            ->first() ?: 'theeverbranch.com';

        return $request->getScheme().'://'.strtolower((string) $tenant->slug).'.'.$baseDomain.'/marketing/modules';
    }
}
