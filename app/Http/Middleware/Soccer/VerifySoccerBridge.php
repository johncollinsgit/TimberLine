<?php

namespace App\Http\Middleware\Soccer;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VerifySoccerBridge
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless(config('soccer.enabled'), 404);
        $secret = (string) config('soccer.bridge_secret');
        abort_unless(strlen($secret) >= 32, 503);
        $ts = $r->header('X-Wren-Time', '');
        $nonce = $r->header('X-Wren-Nonce', '');
        $signature = $r->header('X-Wren-Signature', '');
        $canonical = $r->method()."\n".$r->getPathInfo()."\n".$ts."\n".$nonce."\n".hash('sha256', $r->getContent());
        abort_unless(ctype_digit($ts) && abs(time() - (int) $ts) < 120 && strlen($nonce) >= 16 && strlen($nonce) <= 80 && hash_equals(hash_hmac('sha256', $canonical, $secret), $signature), 401);
        abort_unless(Cache::add('wren-bridge:'.hash('sha256', $nonce), true, 180), 409);
        $t = Tenant::where('slug', config('soccer.tenant_slug'))->firstOrFail();
        $r->attributes->set('soccer_tenant', $t);
        abort_unless(app(\App\Services\Soccer\SoccerAccess::class)->enabled($t), 404);
        app(\App\Support\Tenancy\TenantContext::class)->set($t->id);

        return $next($r)->header('Cache-Control', 'private, no-store');
    }
}
