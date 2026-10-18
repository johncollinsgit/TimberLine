<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Retail\AveryGuestAccess;
use Closure;
use Illuminate\Http\Request;

class RestrictAveryGuest
{
    public function handle(Request $request, Closure $next)
    {
        if (! AveryGuestAccess::granted($request) || $request->is('avery', 'avery/presenter')) {
            return $next($request);
        }
        $path = '/'.$request->path();
        $allowed = [
            'GET' => ['/retail/showroom', '/retail/showroom/pricing', '/retail/showroom/assistant', '/retail/showroom/concepts/avery-knifeworks', '/retail/showroom/api/bootstrap', '/retail/showroom/api/session', '/retail/showroom/api/voice/status', '/retail/showroom/api/avery/handoff'],
            'POST' => ['/retail/showroom/api/assistant', '/retail/showroom/api/voice/session', '/retail/showroom/api/avery/services', '/retail/showroom/api/avery-knifeworks/quote'],
        ];
        abort_unless(in_array($path, $allowed[$request->method()] ?? [], true), 404);
        foreach (['merchant', 'business_slug', 'tenant_id'] as $key) {
            if ($request->has($key)) {
                abort_unless($key === 'merchant' && $request->input($key) === 'avery-knifeworks', 404);
            }
        }
        if (in_array($path, ['/retail/showroom/api/assistant', '/retail/showroom/api/voice/session'], true)) {
            abort_unless($request->input('merchant') === 'avery-knifeworks', 404);
        }
        $actor = User::where('email', config('retail_showroom.avery_guest_email'))->where('is_active', true)->first();
        abort_unless($actor && $actor->hasVerifiedEmail(), 503);
        // Request-local identity only. Never log a visitor into the platform guard.
        $request->setUserResolver(fn ($guard = null) => $actor);
        $request->attributes->set('avery_guest', true);
        $response = $next($request);

        return $response->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
