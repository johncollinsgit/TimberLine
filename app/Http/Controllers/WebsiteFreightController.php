<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\WebsiteFreightProviderService;
use App\Services\ManagedWebsite\WebsiteFreightService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        $provider = app(WebsiteFreightProviderService::class);
        $freightConnection = $provider->current($order);
        $bookingEnabled = $provider->bookingEnabled($order);

        return view('managed-website.commerce.freight', compact('tenant', 'order', 'locations', 'plan', 'freightConnection', 'bookingEnabled'));
    }

    public function save(Request $request, WebsiteOrder $order, WebsiteFreightService $freight): RedirectResponse
    {
        $this->authorizeOrder($request, $order, $freight);
        $freight->save($order, $request->all(), $request->user());

        return back()->with('status', 'Freight evidence saved. No carrier booking, payment, customer message, or fulfillment update was sent.');
    }

    public function connect(Request $request, WebsiteOrder $order, WebsiteFreightService $freight, WebsiteFreightProviderService $provider): RedirectResponse
    {
        $this->authorizeOrder($request, $order, $freight);
        try {
            $provider->connect($order, $request->only('api_key', 'label', 'provider_account_id', 'billing_confirmed'), $request->user());
        } catch (ValidationException $error) {
            // Never flash API keys, including when provider validation fails.
            return back()->withErrors($error->errors())->withInput($request->only('label', 'provider_account_id'));
        }

        return back()->with('status', 'Freight account checked. Select an active provider if needed. No freight was booked.');
    }

    public function quote(Request $request, WebsiteOrder $order, WebsiteFreightService $freight, WebsiteFreightProviderService $provider): RedirectResponse
    {
        $this->authorizeOrder($request, $order, $freight);
        $data = $request->validate(['version' => 'required|integer|min:0']);
        $provider->quote($order, (int) $data['version'], $request->user());

        return back()->with('status', 'Provider quotes checked. Review every service, accessorial, expiry, and exact price before approval.');
    }

    public function book(Request $request, WebsiteOrder $order, WebsiteFreightService $freight, WebsiteFreightProviderService $provider): RedirectResponse
    {
        $this->authorizeOrder($request, $order, $freight);
        $provider->book($order, $request->only('version', 'quote_id', 'amount_cents', 'confirmed', 'ready_time', 'close_time'), $request->user());

        return back()->with('status', 'Booking attempt recorded. Review its confirmed status below. If uncertain, check the original operation; do not book again.');
    }

    public function check(Request $request, WebsiteOrder $order, WebsiteFreightService $freight, WebsiteFreightProviderService $provider): RedirectResponse
    {
        $this->authorizeOrder($request, $order, $freight);
        $provider->refresh($order, $request->user());

        return back()->with('status', 'Original provider status checked using read-only requests. No new booking was sent.');
    }

    public function cancel(Request $request, WebsiteOrder $order, WebsiteFreightService $freight, WebsiteFreightProviderService $provider): RedirectResponse
    {
        $this->authorizeOrder($request, $order, $freight);
        $data = $request->validate(['version' => 'required|integer|min:0', 'confirmed' => 'required|accepted']);
        $provider->cancel($order, (int) $data['version'], $request->user());

        return back()->with('status', 'Cancellation requested once. Review provider status and pickup cancellation; fees and customer refunds require separate reconciliation.');
    }

    public function handoff(Request $request, WebsiteOrder $order, WebsiteFreightService $freight, WebsiteFreightProviderService $provider): RedirectResponse
    {
        $this->authorizeOrder($request, $order, $freight);
        $provider->handoff($order, $request->only('version', 'confirmed', 'evidence_reference'), $request->user());

        return back()->with('status', 'Staff-confirmed physical freight handoff recorded once. Pickup evidence is retained; delivery remains separate.');
    }

    public function document(Request $request, WebsiteOrder $order, int $index, WebsiteFreightService $freight, WebsiteFreightProviderService $provider): \Illuminate\Http\Response
    {
        $this->authorizeOrder($request, $order, $freight);

        return response($provider->document($order, $request->user(), $index), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="freight-document.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
