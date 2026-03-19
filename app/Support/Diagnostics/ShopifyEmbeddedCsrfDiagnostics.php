<?php

namespace App\Support\Diagnostics;

use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ShopifyEmbeddedCsrfDiagnostics
{
    public static function shouldLog(?Request $request, ?Throwable $exception = null): bool
    {
        if (! $request instanceof Request) {
            return false;
        }

        $isCustomerPost = in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && (
                $request->is('shopify/app/customers/manage/*')
                || $request->is('customers/manage/*')
            );

        if ($isCustomerPost) {
            return true;
        }

        if ($exception instanceof TokenMismatchException) {
            return true;
        }

        return $exception instanceof HttpExceptionInterface
            && $exception->getStatusCode() === 419;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    public static function forRequest(Request $request, array $extra = []): array
    {
        $routeName = $request->route()?->getName();
        $routeMode = 'other';

        if (is_string($routeName) && str_starts_with($routeName, 'shopify.app.')) {
            $routeMode = 'canonical_embedded';
        } elseif (is_string($routeName) && str_starts_with($routeName, 'shopify.embedded.')) {
            $routeMode = 'legacy_embedded';
        }

        $inputToken = trim((string) $request->input('_token', ''));
        $headerToken = trim((string) $request->header('X-CSRF-TOKEN', ''));
        $headerXsrfToken = trim((string) $request->header('X-XSRF-TOKEN', ''));
        $cookieName = trim((string) config('session.cookie', ''));
        $cookieNames = array_keys($request->cookies->all());

        $hasSession = $request->hasSession();
        $sessionStarted = false;
        $sessionId = '';
        $sessionToken = '';

        if ($hasSession) {
            try {
                $session = $request->session();
                $sessionStarted = $session->isStarted();
                $sessionId = (string) $session->getId();
                $sessionToken = (string) $session->token();
            } catch (Throwable) {
                $sessionStarted = false;
                $sessionId = '';
                $sessionToken = '';
            }
        }

        $profileParameter = $request->route()?->parameter('marketingProfile');
        $profileId = null;
        if (is_object($profileParameter) && method_exists($profileParameter, 'getKey')) {
            $profileId = $profileParameter->getKey();
        } elseif (is_scalar($profileParameter)) {
            $profileId = $profileParameter;
        }

        return array_merge([
            'route_name' => $routeName,
            'route_mode' => $routeMode,
            'path' => '/' . ltrim($request->path(), '/'),
            'full_url' => $request->fullUrl(),
            'method' => $request->method(),
            'referer' => self::truncate($request->headers->get('referer')),
            'origin' => self::truncate($request->headers->get('origin')),
            'user_agent' => self::truncate($request->userAgent(), 220),
            'secure' => $request->isSecure(),
            'scheme' => $request->getScheme(),
            'forwarded_proto' => $request->headers->get('x-forwarded-proto'),
            'forwarded_host' => $request->headers->get('x-forwarded-host'),
            'forwarded_port' => $request->headers->get('x-forwarded-port'),
            'shopify_context_hint' => [
                'has_shop' => filled($request->query('shop')),
                'has_host' => filled($request->query('host')),
                'has_hmac' => filled($request->query('hmac')),
                'has_embedded' => (string) $request->query('embedded', '') === '1',
                'has_id_token' => filled($request->query('id_token')),
                'has_locale' => filled($request->query('locale')),
                'has_session_query' => filled($request->query('session')),
            ],
            'input_keys' => array_values(array_keys($request->except(['_token']))),
            'has_input_token' => $inputToken !== '',
            'input_token_length' => $inputToken !== '' ? strlen($inputToken) : 0,
            'has_x_csrf_token' => $headerToken !== '',
            'x_csrf_token_length' => $headerToken !== '' ? strlen($headerToken) : 0,
            'has_x_xsrf_token' => $headerXsrfToken !== '',
            'x_xsrf_token_length' => $headerXsrfToken !== '' ? strlen($headerXsrfToken) : 0,
            'has_session' => $hasSession,
            'session_is_started' => $sessionStarted,
            'session_id_present' => $sessionId !== '',
            'session_id_length' => $sessionId !== '' ? strlen($sessionId) : 0,
            'session_cookie_name' => $cookieName !== '' ? $cookieName : null,
            'session_cookie_present' => $cookieName !== '' ? $request->cookies->has($cookieName) : false,
            'cookies_seen' => $cookieNames,
            'has_session_token' => $sessionToken !== '',
            'session_token_length' => $sessionToken !== '' ? strlen($sessionToken) : 0,
            'submitted_token_matches_session' => $inputToken !== '' && $sessionToken !== ''
                ? hash_equals($sessionToken, $inputToken)
                : false,
            'profile_id' => $profileId,
        ], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    public static function renderState(Request $request): array
    {
        return [
            'has_session' => $request->hasSession(),
            'session_cookie_name' => (string) config('session.cookie', ''),
            'session_cookie_present' => $request->cookies->has((string) config('session.cookie', '')),
            'session_id_present' => $request->hasSession() && (string) $request->session()->getId() !== '',
            'session_token_present' => $request->hasSession() && (string) $request->session()->token() !== '',
        ];
    }

    private static function truncate(?string $value, int $limit = 300): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : Str::limit($trimmed, $limit, '…');
    }
}
