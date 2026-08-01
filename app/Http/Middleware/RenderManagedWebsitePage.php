<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RenderManagedWebsitePage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ((! $request->isMethod('GET') && ! $request->isMethod('HEAD'))
            || $request->expectsJson()
            || $response->getStatusCode() !== Response::HTTP_NOT_FOUND) {
            return $response;
        }

        $tenant = $request->attributes->get('host_tenant');
        if (! $tenant instanceof Tenant) {
            return $response;
        }

        $payload = app(ManagedWebsiteService::class)->publicPage($tenant, $request->path());
        if ($payload === null) {
            return $response;
        }
        if (! app(ManagedWebsiteService::class)->publicHostAllowed($payload['site'], $request->getHost())) {
            return $response;
        }

        return response()->view('managed-website.public', $payload + ['tenant' => $tenant]);
    }
}
