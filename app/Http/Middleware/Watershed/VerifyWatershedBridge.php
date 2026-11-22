<?php

namespace App\Http\Middleware\Watershed;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VerifyWatershedBridge
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless(config('watershed.enabled'), 404);
        $secret = (string) config('watershed.bridge_secret');
        abort_unless(strlen($secret) >= 32, 503);
        $ts = $r->header('X-Watershed-Time', '');
        $nonce = $r->header('X-Watershed-Nonce', '');
        $canonical = $r->method()."\n".$r->getPathInfo()."\n".$ts."\n".$nonce."\n".hash('sha256', $r->getContent());
        abort_unless(ctype_digit($ts) && abs(time() - (int) $ts) < 120 && strlen($nonce) >= 16 && strlen($nonce) <= 80 && hash_equals(hash_hmac('sha256', $canonical, $secret), $r->header('X-Watershed-Signature', '')), 401);
        abort_unless(Cache::add('watershed-bridge:'.hash('sha256', $nonce), true, 180), 409);
        $t = Tenant::where('slug', config('watershed.tenant_slug'))->firstOrFail();
        abort_unless(app(\App\Services\Soccer\SoccerAccess::class)->enabled($t), 404);
        $r->attributes->set('watershed_tenant', $t);
        app(\App\Support\Tenancy\TenantContext::class)->set($t->id);

        return $next($r)->header('Cache-Control','private, no-store');
    }
}
