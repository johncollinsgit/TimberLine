<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/** Trial pages use explicit session-backed meta/form tokens, not the retail XSRF cookie. */
class EnterpriseCsrfToken extends ValidateCsrfToken
{
    protected function addCookieToResponse($request, $response)
    {
        $path = $request->getPathInfo();
        $prefix = config('enterprise_runtime.base_path', '');
        if ($path === '/enterprise/trial' || str_starts_with($path, '/enterprise/trial/')
            || (is_string($prefix) && $prefix !== '' && $request->getBaseUrl() === $prefix)) {
            return $response;
        }

        return parent::addCookieToResponse($request, $response);
    }
}
