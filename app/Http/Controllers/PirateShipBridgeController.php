<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteOrder;
use App\Models\WebsiteShipment;
use App\Services\ManagedWebsite\PirateShipSpreadsheetBridge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PirateShipBridgeController extends Controller
{
    public function index(Request $request, PirateShipSpreadsheetBridge $bridge): View
    {
        [$tenant, $site] = $this->context($request, $bridge);
        $readyCount = WebsiteOrder::query()->forTenant($tenant)->where('tenant_site_id', $site->id)
            ->where('payment_status', 'paid')->where('fulfillment_method', 'ship')
            ->where('fulfillment_status', 'unfulfilled')->whereDoesntHave('shipments')->count();
        $recentShipments = WebsiteShipment::query()->forTenant($tenant)->where('provider', 'pirate_ship')
            ->whereHas('events', fn ($query) => $query->where('event_type', 'tracking_imported'))
            ->latest()->limit(10)->get();

        return view('managed-website.commerce.pirate-ship', compact('site', 'readyCount', 'recentShipments'));
    }

    public function export(Request $request, PirateShipSpreadsheetBridge $bridge): StreamedResponse
    {
        [$tenant, $site] = $this->context($request, $bridge);

        return $bridge->export($tenant, $site);
    }

    public function import(Request $request, PirateShipSpreadsheetBridge $bridge): RedirectResponse
    {
        [$tenant, $site] = $this->context($request, $bridge);
        $file = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']])['file'];
        $result = $bridge->importTracking($tenant, $site, $request->user(), $file);

        return back()->with('status', "Imported {$result['imported']} tracking numbers; {$result['already_present']} already matched.");
    }

    /** @return array{Tenant,TenantSite} */
    private function context(Request $request, PirateShipSpreadsheetBridge $bridge): array
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && $bridge->enabledFor($tenant), 403);
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();

        return [$tenant, $site];
    }
}
