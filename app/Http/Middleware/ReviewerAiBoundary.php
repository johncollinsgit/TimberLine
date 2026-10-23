<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Private error envelopes around the existing web/runtime guards, never an access exemption. */
final class ReviewerAiBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = parse_url($request->getRequestUri(), PHP_URL_PATH);
        // Routing decodes path characters and accepts a trailing slash. Apply the
        // same envelope to those equivalent requests without changing admission.
        $path = is_string($path) ? rawurldecode($path) : null;
        if (! is_string($path) || ! preg_match('~^/enterprise-owner/service-proposals/[A-Za-z0-9]{1,200}/campus/ask/ai/(?:preview|confirm|status|cancel)/?$~D', $path)) {
            return $next($request);
        }
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
        } catch (Throwable $error) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 503;
            $response = new Response('', $status);
        }
        if ($status >= 300 || ! str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
            $status = in_array($status, [400, 403, 404, 405, 409, 410, 419, 422, 429, 503], true) ? $status : 503;
            $code = match ($status) {
                400, 405, 422 => 'invalid_request', 403, 419 => 'access_required', 404 => 'unavailable',
                409 => 'original_request_required', 410 => 'content_expired', 429 => 'temporarily_limited',
                default => 'reconciliation_required',
            };
            $response = new JsonResponse(['error' => $code, 'provider_redispatch' => false], $status);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");

        return $response;
    }
}
