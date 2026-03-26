<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\PreAuthTenantContextResolver;
use App\Support\Tenancy\HostTenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ResolveHostTenantContext
{
    public function __construct(
        protected PreAuthTenantContextResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->resolver->resolveForRequest($request);

        $request->attributes->set('host_tenant_context', $context);
        $request->attributes->set('host_tenant', $context->tenant);
        $request->attributes->set('host_tenant_id', $context->tenant ? (int) $context->tenant->id : null);
        $request->attributes->set('is_landlord_mode', $context->isLandlord());

        app()->instance(HostTenantContext::class, $context);
        app()->instance('host_tenant_context', $context);

        View::share('hostTenantContext', $context->toArray());
        View::share('hostTenant', $context->tenant);
        View::share('isLandlordMode', $context->isLandlord());

        return $next($request);
    }
}
