<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\TenantModuleStoreActionRequest;
use App\Services\Tenancy\TenantModuleCatalogService;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class MarketingModuleStoreController extends Controller
{
    public function index(Request $request, TenantModuleCatalogService $catalogService): View
    {
        $tenantId = $this->requireTenantId($request);
        Gate::authorize('view-tenant-module-store', $tenantId);
        $sections = MarketingSectionRegistry::sections();

        return view('marketing.modules', [
            'currentSectionKey' => 'modules',
            'currentSection' => $sections['modules'],
            'sections' => $this->buildNavigation($sections),
            'moduleStorePayload' => $catalogService->tenantStorePayload($tenantId, 'marketing'),
        ]);
    }

    public function activate(
        TenantModuleStoreActionRequest $request,
        string $moduleKey,
        TenantModuleCatalogService $catalogService
    ): RedirectResponse {
        $tenantId = $this->requireTenantId($request);
        Gate::authorize('mutate-tenant-module-store', $tenantId);
        $result = $catalogService->activateModuleForTenant(
            tenantId: $tenantId,
            moduleKey: (string) $request->validated('moduleKey', $moduleKey),
            actorId: $request->user()?->id,
            source: 'marketing_module_store'
        );

        return redirect()
            ->route('marketing.modules', ['module' => strtolower(trim((string) $request->validated('moduleKey', $moduleKey)))])
            ->with(($result['ok'] ?? false) ? 'toast' : 'toast', [
                'style' => ($result['ok'] ?? false) ? 'success' : 'warning',
                'message' => (string) ($result['message'] ?? 'Module action completed.'),
            ]);
    }

    public function requestAccess(
        TenantModuleStoreActionRequest $request,
        string $moduleKey,
        TenantModuleCatalogService $catalogService
    ): RedirectResponse {
        $tenantId = $this->requireTenantId($request);
        Gate::authorize('mutate-tenant-module-store', $tenantId);
        $result = $catalogService->requestModuleAccessForTenant(
            tenantId: $tenantId,
            moduleKey: (string) $request->validated('moduleKey', $moduleKey),
            actorId: $request->user()?->id,
            source: 'marketing_module_store_request'
        );

        return redirect()
            ->route('marketing.modules', ['module' => strtolower(trim((string) $request->validated('moduleKey', $moduleKey)))])
            ->with('toast', [
                'style' => ($result['ok'] ?? false) ? 'success' : 'warning',
                'message' => (string) ($result['message'] ?? 'Module request completed.'),
            ]);
    }

    /**
     * @param  array<string,array{label:string,route:string,description:string,hint_title:string,hint_text:string,coming_next:array<int,string>,group:string}>  $sections
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function buildNavigation(array $sections): array
    {
        $items = [];
        foreach ($sections as $key => $section) {
            $items[] = [
                'key' => $key,
                'label' => $section['label'],
                'href' => route($section['route']),
                'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'].'.*'),
            ];
        }

        return $items;
    }

    protected function requireTenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('current_tenant_id');
        abort_unless(is_numeric($tenantId) && (int) $tenantId > 0, 403, 'Tenant context is required.');

        return (int) $tenantId;
    }
}
