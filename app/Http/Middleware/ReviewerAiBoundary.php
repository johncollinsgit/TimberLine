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
        $workspace = is_string($path) && preg_match('~^/enterprise-owner/service-proposals/[A-Za-z0-9]{1,200}/campus/workspace/(?:projection|facts|commands|command-status|collaboration/(?:show|comment|status)|files/(?:show|begin|status|chunk|complete|cancel)|images/(?:metadata|thumbnail|preview)|downloads/(?:metadata|download))/?$~D', $path);
        $pdf = is_string($path) && preg_match('~^/enterprise-owner/service-proposals/[A-Za-z0-9]{1,200}/campus/workspace/downloads/(?:metadata|download)/?$~D', $path);
        $maintenance = is_string($path) && preg_match('~^/enterprise-owner/owner-workspaces/[A-Za-z0-9_-]{1,200}/proposals/(?:reviewer-maintenance/(?:graph|comments|quarantine)|reviewer-(?:initialization|preparation)/(?:preview|apply|status))/?$~D', $path);
        $workspace = $workspace || $maintenance;
        if (! $workspace && (! is_string($path) || ! preg_match('~^/enterprise-owner/service-proposals/[A-Za-z0-9]{1,200}/campus/ask/ai/(?:preview|confirm|status|cancel)/?$~D', $path))) {
            return $next($request);
        }
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
        } catch (Throwable $error) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode()
                : ($maintenance && $error instanceof \Illuminate\Auth\AuthenticationException ? 403 : 503);
            $response = new Response('', $status, $error instanceof HttpExceptionInterface ? $error->getHeaders() : []);
        }
        if ($maintenance && $status === 401) {
            $status = 403;
        }
        $handledImage = is_string($path) && preg_match('~^/enterprise-owner/service-proposals/[A-Za-z0-9]{1,200}/campus/workspace/images/(?:thumbnail|preview)/?$~D', $path)
            && $request->attributes->get('service_proposal_workspace_image_response') === true && $status === 200
            && in_array($response->headers->get('Content-Type'), ['image/jpeg', 'image/png'], true);
        $handledDownload = is_string($path) && preg_match('~^/enterprise-owner/service-proposals/[A-Za-z0-9]{1,200}/campus/workspace/downloads/download/?$~D', $path)
            && $request->attributes->get('service_proposal_workspace_download_response') === true && $status === 200
            && $response->headers->get('Content-Type') === 'application/octet-stream';
        $handledWorkspace = $handledImage || $handledDownload || $workspace && $request->attributes->get('service_proposal_workspace_response') === true
            && $response instanceof JsonResponse;
        if (! $handledWorkspace && ($workspace || $status >= 300 || ! str_contains((string) $response->headers->get('Content-Type'), 'application/json'))) {
            $status = in_array($status, [400, 403, 404, 405, 409, 410, 419, 422, 429, 503], true) ? $status : 503;
            $code = match ($status) {
                400, 405, 422 => 'invalid_request', 403, 419 => 'access_required', 404 => 'unavailable',
                409 => 'original_request_required', 410 => 'content_expired', 429 => 'temporarily_limited',
                default => 'reconciliation_required',
            };
            $response = new JsonResponse(['error' => $code, ...($workspace ? ['automatic_retry' => false] : ['provider_redispatch' => false])], $status,
                array_intersect_key($response->headers->all(), array_flip(['allow', 'retry-after'])));
        }
        $response->headers->set('Cache-Control', $pdf ? 'private, no-store, no-transform' : 'private, no-store');
        if ($pdf) {
            $response->headers->set('Content-Encoding', 'identity');
            $response->headers->set('Content-Length', (string) strlen($response->getContent()));
        }
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', ($handledDownload ? 'sandbox; ' : '')."default-src 'none'; base-uri 'none'; frame-ancestors 'none'");

        return $response;
    }
}
