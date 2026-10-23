<?php

namespace App\Enterprise;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Do not save a provider code as an intended login URL or redirect it through account switching. */
class GmailCallbackSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();
        if (! $user || ! $user->is_active || $user->email_verified_at === null) {
            return new Response('Return to Enterprise, sign in and start a new Gmail connection.', 403,
                ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
