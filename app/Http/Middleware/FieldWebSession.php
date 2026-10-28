<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** Keeps the installed web app's revocable mobile token out of JavaScript storage. */
class FieldWebSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('X-Everbranch-Field-Web') !== '1') {
            return $next($request);
        }
        $origin = $request->header('Origin');
        abort_if($origin !== null && rtrim($origin, '/') !== $request->getSchemeAndHttpHost(), 403);
        abort_if($request->header('Sec-Fetch-Site') === 'cross-site', 403);
        if ($cookie = $request->cookie('everbranch_field_session')) {
            try {
                $request->headers->set('Authorization', 'Bearer '.Crypt::decryptString($cookie));
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                // Invalid/expired browser state is handled by normal Sanctum authentication.
            }
        }
        $response = $next($request);
        if ($response instanceof \Illuminate\Http\JsonResponse && $response->isSuccessful()) {
            $data = $response->getData(true);
            if (isset($data['access_token']) && is_string($data['access_token'])) {
                $response->headers->setCookie(Cookie::create('everbranch_field_session')
                    ->withValue(Crypt::encryptString($data['access_token']))->withPath('/api/mobile/v1')
                    ->withExpires(now()->addDays(30))->withSecure(true)->withHttpOnly(true)->withSameSite('strict'));
                $data['access_token'] = 'field-cookie-session';
                $response->setData($data);
            }
        }
        if ($request->is('api/mobile/v1/auth/logout') || $response->getStatusCode() === 401) {
            $response->headers->setCookie(Cookie::create('everbranch_field_session')->withValue('')->withPath('/api/mobile/v1')
                ->withExpires(1)->withSecure(true)->withHttpOnly(true)->withSameSite('strict'));
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
