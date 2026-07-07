<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Onboarding\TenantOnboardingCompletionService;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use App\Support\Auth\HomeRedirect;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver,
        protected TenantOnboardingCompletionService $onboardingCompletionService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $tenant = $this->tenantContextResolver->resolveForRequest($request, $user);
        if (! $tenant) {
            if (Tenant::query()->count() === 0) {
                return $next($request);
            }

            // A signed-in, non-operator user who has no workspace yet is guided to
            // create one instead of hitting a dead-end 403.
            if ($user instanceof \App\Models\User
                && ! HomeRedirect::isPlatformOperator($user)
                && ! $user->tenants()->exists()) {
                return redirect()->route('workspace.first-login');
            }

            abort(403, 'Tenant access is not configured for this user.');
        }

        $this->assertRouteModelTenantAccess($request, (int) $tenant->id);
        $this->assertStoreAccess($request, (int) $tenant->id);

        if ($this->shouldRedirectForIncompleteOnboarding($request, $tenant, $user)) {
            return redirect()->route('app.start', ['tenant' => (string) $tenant->slug]);
        }

        $request->attributes->set('current_tenant', $tenant);
        $request->attributes->set('current_tenant_id', (int) $tenant->id);
        View::share('currentTenant', $tenant);

        return $next($request);
    }

    protected function assertStoreAccess(Request $request, int $tenantId): void
    {
        $storeCandidates = [
            $request->route('store'),
            $request->route('store_key'),
            $request->query('store'),
            $request->query('store_key'),
            $request->input('store'),
            $request->input('store_key'),
        ];

        foreach ($storeCandidates as $candidate) {
            $storeKey = strtolower(trim((string) $candidate));
            if ($storeKey === '') {
                continue;
            }

            if (! $this->tenantContextResolver->isStoreAccessibleToTenant($storeKey, $tenantId)) {
                abort(403, 'The selected store is outside your tenant scope.');
            }
        }
    }

    protected function assertRouteModelTenantAccess(Request $request, int $tenantId): void
    {
        foreach ((array) $request->route()?->parameters() as $value) {
            if (! $value instanceof Model) {
                continue;
            }

            if (! array_key_exists('tenant_id', $value->getAttributes())) {
                continue;
            }

            $modelTenantId = is_numeric($value->getAttribute('tenant_id'))
                ? (int) $value->getAttribute('tenant_id')
                : null;

            if ($modelTenantId === null || $modelTenantId !== $tenantId) {
                abort(404);
            }
        }
    }

    protected function shouldRedirectForIncompleteOnboarding(Request $request, Tenant $tenant, mixed $user): bool
    {
        if ($user instanceof \App\Models\User && HomeRedirect::isPlatformOperator($user)) {
            return false;
        }

        if ($this->onboardingCompletionService->isComplete($tenant)) {
            return false;
        }

        if ($request->routeIs('app.start', 'app.setup-status.update', 'onboarding.*', 'landlord.*')) {
            return false;
        }

        return true;
    }
}
