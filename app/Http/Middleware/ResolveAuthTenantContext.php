<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\GuestAuthTenantContextResolver;
use App\Support\Auth\AuthTenantIntentStore;
use App\Support\Auth\AuthTenantPresentation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ResolveAuthTenantContext
{
    public function __construct(
        protected GuestAuthTenantContextResolver $resolver,
        protected AuthTenantIntentStore $intentStore,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->resolver->resolveForRequest($request);
        $presentation = AuthTenantPresentation::fromContext($context);
        $preserveExistingIntent = $this->shouldPreserveExistingIntent($request);

        $request->attributes->set('auth_tenant_context', $context);
        $request->attributes->set('auth_tenant_presentation', $presentation);

        View::share('authTenantContext', $context->toArray());
        View::share('authTenantPresentation', $presentation);
        $this->intentStore->rememberFromContext($request, $context, $preserveExistingIntent);
        $this->consumeLoginPreserveFlagAfterSubmit($request, $preserveExistingIntent);

        Log::debug('auth.tenant_context.resolved', [
            'category' => 'auth.tenant_context',
            'event' => 'resolved',
            'route_name' => $request->route()?->getName(),
            'path' => $request->path(),
            'host' => $context->host,
            'strategy' => $context->strategy,
            'classification' => $context->classification,
            'tenant_resolved' => $context->resolved(),
            'tenant_id' => $context->tenant?->id,
            'tenant_slug' => $context->tenant?->slug,
            'preserve_existing_intent' => $preserveExistingIntent,
        ]);

        return $next($request);
    }

    protected function shouldPreserveExistingIntent(Request $request): bool
    {
        if ($request->routeIs('login', 'login.store') && $this->intentStore->shouldPreserveOnLogin($request)) {
            return true;
        }

        return $request->routeIs(
            'auth.google.callback',
            'password.reset',
            'password.update',
            'two-factor.login',
            'two-factor.login.store',
        );
    }

    protected function consumeLoginPreserveFlagAfterSubmit(Request $request, bool $preserveExistingIntent): void
    {
        if (! $preserveExistingIntent) {
            return;
        }

        if (! $request->routeIs('login.store')) {
            return;
        }

        // Reset-continuation protection should be single-use once a login attempt is submitted.
        $this->intentStore->clearPreserveOnLogin($request);
    }
}
