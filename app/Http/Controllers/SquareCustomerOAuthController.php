<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Marketing\SquareCustomerOAuth;
use Illuminate\Http\Request;

final class SquareCustomerOAuthController extends Controller
{
    public function start(Request $request, SquareCustomerOAuth $oauth)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return redirect()->away($oauth->begin($tenant, $request->user(), $request))
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function callback(Request $request, SquareCustomerOAuth $oauth)
    {
        $result = $oauth->complete($request->user(), $request);
        $url = route('customers.square.index', ['tenant' => $result['tenant']->slug]);
        if ($request->expectsJson()) {
            return response()->json(['connection' => $result['connection'], 'redirect_url' => $url, 'source_only' => true])
                ->header('Cache-Control', 'private, no-store');
        }

        return redirect($url)->with('toast', ['style' => 'success', 'message' => 'Square history connected for read-only customer review. Checkout and rewards settings are unchanged.']);
    }

    public function disconnect(Request $request, SquareCustomerOAuth $oauth)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $connection = $oauth->disconnect($tenant, $request->user(), $request->only(['request_id', 'expected_generation']));
        if ($request->expectsJson()) {
            return response()->json(['connection' => $connection, 'notice' => 'Square source reads are disconnected in Everbranch. Original history is retained. Manage the provider grant in Square.'])
                ->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('customers.square.index', ['tenant' => $tenant->slug])
            ->with('toast', ['style' => 'success', 'message' => 'Square source reads disconnected in Everbranch. Original history is retained.']);
    }
}
