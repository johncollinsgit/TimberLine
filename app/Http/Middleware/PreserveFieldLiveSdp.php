<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;

/** Keeps the byte-sensitive WebRTC offer intact on the single Field Live endpoint. */
class PreserveFieldLiveSdp extends TrimStrings
{
    public function handle($request, Closure $next)
    {
        if ($request instanceof Request
            && $request->isMethod('POST')
            && $request->is('api/mobile/v1/workspaces/*/field-service/web/assistant/live')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
