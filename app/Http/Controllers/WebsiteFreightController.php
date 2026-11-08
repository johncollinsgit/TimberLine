<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\WebsiteFreightService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebsiteFreightController extends Controller
{
    private function authorizeOrder(Request $request, WebsiteOrder $order, WebsiteFreightService $freight): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && $order->tenant_id === $tenant->id, 404);
        $freight->authorize($order, $request->user());

        return $tenant;
    }

    public function show(Request $request, WebsiteOrder $order, WebsiteFreightService $freight): View
    {
        $tenant = $this->authorizeOrder($request, $order, $freight);
        $locations = WebsiteFulfillmentLocation::query()->forTenant($tenant)->where('tenant_site_id', $order->tenant_site_id)->where('active', true)->get();
        $plan = (array) data_get($order->service_request, 'freight', []);

        return view('managed-website.commerce.freight', compact('tenant', 'order', 'locations', 'plan'));
    }

    public function save(Request $request, WebsiteOrder $order, WebsiteFreightService $freight): RedirectResponse
    {
        $this->authorizeOrder($request, $order, $freight);
        $freight->save($order, $request->all(), $request->user());

        return back()->with('status', 'Freight evidence saved. No carrier booking, payment, customer message, or fulfillment update was sent.');
    }
}
