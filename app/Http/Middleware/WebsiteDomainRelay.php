<?php

namespace App\Http\Middleware;

use App\Services\ManagedWebsite\ManagedWebsiteDomainService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Authenticate an original public Website host, never a generic forwarded host. */
class WebsiteDomainRelay
{
    public const MAX_BODY_BYTES = 1024 * 1024;

    public const MAX_AGE_SECONDS = 60;

    public const FUTURE_SKEW_SECONDS = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $headers = ['X-Everbranch-Website-Host', 'X-Everbranch-Website-Timestamp', 'X-Everbranch-Website-Signature'];
        if (! collect($headers)->contains(fn (string $header): bool => $request->headers->has($header))) {
            return $next($request);
        }

        $secret = (string) config('managed_website.relay.secret', '');
        abort_unless((bool) config('managed_website.relay.enabled', false) && strlen($secret) >= 32, 404);

        // The Worker connects with both TLS SNI and HTTP Host set to the app.
        // An arbitrary client-forwarded host is never an ingress credential.
        $ingress = strtolower((string) config('tenancy.domains.canonical.landlord_host'));
        abort_unless($ingress !== '' && strtolower((string) $request->headers->get('host')) === $ingress && $request->isSecure(), 404);

        $host = (string) $request->header($headers[0], '');
        $timestamp = (string) $request->header($headers[1], '');
        $signature = (string) $request->header($headers[2], '');
        abort_unless(strlen($host) <= 253 && preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/', $host) === 1
            && ! filter_var($host, FILTER_VALIDATE_IP), 404);
        abort_unless(preg_match('/\A[0-9]{1,12}\z/', $timestamp) === 1 && preg_match('/\A[a-f0-9]{64}\z/', $signature) === 1, 404);
        $age = now()->timestamp - (int) $timestamp;
        abort_unless($age >= -self::FUTURE_SKEW_SECONDS && $age <= self::MAX_AGE_SECONDS, 404);

        $method = $request->getMethod();
        $body = $request->getContent();
        $uri = $request->getRequestUri();
        abort_unless(strlen($body) <= self::MAX_BODY_BYTES && str_starts_with($uri, '/') && ! preg_match('/[\x00-\x20#]/', $uri), 404);
        abort_unless((in_array($method, ['GET', 'HEAD'], true) && $body === '')
            || ($method === 'POST' && preg_match('#\A/website/forms/[1-9][0-9]*\z#', $request->getPathInfo()) === 1), 404);
        // API/health routes may not run the web host guard at all. Block
        // platform namespaces here as well as using its later route whitelist.
        if ($method !== 'POST') {
            $firstSegment = strtolower(explode('/', ltrim(rawurldecode($request->getPathInfo()), '/'))[0]);
            abort_if(in_array($firstSegment, [
                'api', 'sanctum', 'up', 'ready', 'auth', 'email', 'login', 'logout',
                'register', 'password', 'forgot-password', 'reset-password',
                'two-factor-challenge', 'dashboard', 'website', 'landlord',
                'workspaces', 'workspace', 'settings', 'workflows', 'shopify',
                'mobile', 'webhooks', 'account',
            ], true), 404);
        }
        $message = implode("\n", ['v1', $timestamp, $method, $host, $uri, hash('sha256', $body)]);
        abort_unless(hash_equals(hash_hmac('sha256', $message, $secret), $signature), 404);

        $domains = app(ManagedWebsiteDomainService::class);
        abort_unless((bool) config('managed_website.public_render_enabled', false)
            && ! $domains->isPlatformHost($host) && $domains->tenantForActiveHost($host) !== null, 404);

        // This runs before route matching, tenant resolution and sessions.
        // The web host guard subsequently allows only explicit public routes.
        foreach (array_keys($request->headers->all()) as $header) {
            if ($header === 'forwarded' || str_starts_with($header, 'x-forwarded-') || str_starts_with($header, 'x-everbranch-website-') || $header === 'authorization') {
                $request->headers->remove($header);
            }
        }
        $request->headers->set('host', $host);
        $request->server->set('HTTP_HOST', $host);
        $request->server->set('SERVER_NAME', $host);
        $request->server->set('HTTPS', 'on');
        $request->server->set('SERVER_PORT', 443);
        $request->attributes->set('managed_website_relay', true);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setVary('Host', false);

        return $response;
    }
}
