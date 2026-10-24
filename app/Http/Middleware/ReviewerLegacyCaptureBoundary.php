<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Exact capture path envelope only. All existing runtime/auth/session/CSRF middleware still execute. */
final class ReviewerLegacyCaptureBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = parse_url($request->getRequestUri(), PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '';
        if (! preg_match('~^/enterprise-owner/owner-workspaces/[A-Za-z0-9_-]{1,200}/proposals/legacy-capture/[a-fA-F0-9-]{36}(/authorize)?/?$~D', $path, $match)) {
            return $next($request);
        }
        $authorize = ($match[1] ?? '') === '/authorize';
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
        } catch (Throwable $error) {
            if ($error instanceof AuthenticationException && ! $authorize && $request->isMethod('GET') && ! $request->expectsJson()) {
                $response = redirect()->guest(route('login'));
                $status = $response->getStatusCode();
            } else {
                $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode()
                    : ($error instanceof AuthenticationException ? 401 : ($error instanceof ModelNotFoundException ? 404 : 503));
                $response = new Response('', $status, $error instanceof HttpExceptionInterface ? $error->getHeaders() : []);
            }
        }
        $success = $request->attributes->get('service_proposal_legacy_capture_response') === true && $status === 200
            && ($authorize ? $response instanceof JsonResponse : str_starts_with((string) $response->headers->get('Content-Type'), 'text/html'));
        $loginRedirect = ! $authorize && $request->isMethod('GET') && $status === 302
            && $response->headers->get('Location') === route('login');
        if (! $success && ! $loginRedirect) {
            $status = in_array($status, [400, 401, 403, 404, 405, 409, 410, 419, 422, 429, 503], true) ? $status : 503;
            $response = new JsonResponse(['error' => 'capture_unavailable'], $status,
                array_intersect_key($response->headers->all(), array_flip(['allow', 'retry-after'])));
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (! $success || $authorize) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
        }

        return $response;
    }
}
