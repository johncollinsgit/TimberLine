<?php

namespace App\Http\Middleware;

use App\Enterprise\Provisioning\ClientSilo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EnsureEnterpriseClientSilo
{
    public function handle(Request $request, Closure $next): Response
    {
        $silo = app(ClientSilo::class);
        try {
            if ($silo->enabled()) {
                $silo->assertReady();
            }
        } catch (Throwable) {
            return new Response('The client workspace is not configured.', 503, ['Cache-Control' => 'private, no-store']);
        }

        return $next($request);
    }
}
