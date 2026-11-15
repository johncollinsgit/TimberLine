<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Marketplace\AccountPreviewController;
use App\Services\Onboarding\SelfServiceTrialService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admission only: never supplies or grants a tenant context. */
class ScopeSelfServiceTrialRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $request->route()) {
            return $next($request);
        }
        // This exact account-gated screen renders only in-memory examples.
        // Preserve its no-tenant-query contract without admitting a merchant
        // route, a write, or any other action named "preview".
        if (in_array($request->getRealMethod(), ['GET', 'HEAD'], true)
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && $request->routeIs('marketplace.preview')
            && $request->route()->getAction('uses') === AccountPreviewController::class.'@__invoke') {
            return $next($request);
        }
        // Public media and signed account recovery retain their original checks.
        // Only authenticated application routes need the additional admission fence.
        $middlewareStack = collect($request->route()->gatherMiddleware());
        if ($middlewareStack->contains('tenant.access')) {
            // Its resolved, authorized context invokes the same admission and
            // quota guard. Never use a client-selected workspace to bypass it.
            return $next($request);
        }
        $protected = $middlewareStack->contains(fn ($middleware) => is_string($middleware) && (preg_match('/^(auth|role|tenant\.access|mobile\.tenant)(:|$)/', $middleware)
                || str_contains($middleware, 'Authenticate')));
        if (! $protected || $request->routeIs('login', 'logout', 'verification.*', 'self-service.verify*', 'workspace.first-login*', 'password.*',
            'two-factor.*', 'user-profile-information.update', 'user-password.update', 'sanctum.csrf-cookie',
            'mobile.v1.auth.logout', 'mobile.v1.auth.sessions', 'mobile.v1.auth.sessions.revoke')) {
            return $next($request);
        }
        $trials = app(SelfServiceTrialService::class);
        $tenants = $trials->candidateTenantsForUser($user);
        foreach ($tenants as $tenant) {
            if ($trials->unpaidTrialForTenant($tenant)) {
                return app(EnforceSelfServiceTrial::class)->guard($request, $next, $tenant, false);
            }
        }

        return $next($request);
    }
}
