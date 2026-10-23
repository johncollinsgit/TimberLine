<?php

namespace App\Proposals;

use Closure;
use Illuminate\Http\Request;

class ResponsePolicy
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('service_proposals.enabled'), 404);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        if ($request->attributes->get('service_proposal_campus_review') !== true || ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        }

        return $response;
    }
}
