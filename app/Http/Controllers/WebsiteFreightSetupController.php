<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteFulfillmentLocation;
use App\Services\ManagedWebsite\WebsiteFreightProviderService;
use App\Services\ManagedWebsite\WebsiteFreightService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WebsiteFreightSetupController extends Controller
{
    private function site(Request $request): TenantSite
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        app(WebsiteFreightService::class)->authorizeSite($site, $request->user());

        return $site;
    }

    public function show(Request $request, WebsiteFreightProviderService $provider): View
    {
        $site = $this->site($request);
        $freightConnection = $provider->currentForSite($site);
        $bookingEnabled = (bool) config('managed_website.freight_booking_enabled')
            && in_array($site->tenant_id, (array) config('managed_website.freight_booking_tenant_ids', []), true);
        $hasOrigin = WebsiteFulfillmentLocation::query()->forTenantId($site->tenant_id)
            ->where('tenant_site_id', $site->id)->where('active', true)->exists();

        return view('managed-website.commerce.freight-setup', compact('site', 'freightConnection', 'bookingEnabled', 'hasOrigin'));
    }

    public function store(Request $request, WebsiteFreightProviderService $provider): RedirectResponse
    {
        $site = $this->site($request);
        try {
            $provider->connectSite($site, $request->only('api_key', 'label', 'provider_account_id', 'billing_confirmed'), $request->user());
        } catch (ValidationException $error) {
            return back()->withErrors($error->errors())->withInput($request->only('label', 'provider_account_id'));
        }

        return redirect()->route('managed-website.commerce.freight')->with('status', 'Freight accounts checked and setup saved. No shipment was booked.');
    }
}
