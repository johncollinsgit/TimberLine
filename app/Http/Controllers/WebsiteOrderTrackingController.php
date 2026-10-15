<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebsiteOrderTrackingController extends Controller
{
    public function show(Request $request, ManagedWebsiteService $websites): Response
    {
        $tenant = $request->attributes->get('host_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        $payload = $websites->publicPage($tenant, '');
        abort_unless($payload !== null && $websites->publicHostAllowed($payload['site'], $request->getHost()), 404);
        $site = $payload['site'];
        $number = $request->query('order');
        $token = $request->query('token');
        abort_unless(is_string($number) && strlen($number) <= 190 && is_string($token) && preg_match('/^[A-Za-z0-9]{56}$/D', $token), 404);
        $order = WebsiteOrder::query()->forTenant($tenant)->where('tenant_site_id', $site->id)
            ->where('number', $number)->where('lookup_token', $token)->with('lines', 'shipments')->firstOrFail();
        abort_unless(hash_equals((string) $order->lookup_token, $token), 404);

        return response()->view('managed-website.tracking', compact('site', 'order'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
