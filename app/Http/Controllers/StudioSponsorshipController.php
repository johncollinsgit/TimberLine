<?php

namespace App\Http\Controllers;

use App\Models\StudioOffer;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Services\ManagedWebsite\ManagedWebsiteDomainService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\StudioSponsorshipService;
use App\Services\ManagedWebsite\WebsiteQuoteOrderService;
use Illuminate\Http\Request;

class StudioSponsorshipController extends Controller
{
    private function site(Request $request, bool $public = false): TenantSite
    {
        $tenant = $request->attributes->get($public ? 'host_tenant' : 'current_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        $quotes = app(WebsiteQuoteOrderService::class);
        $site = TenantSite::forTenantId($tenant->id)->firstOrFail();
        if ($public) {
            $websites = app(ManagedWebsiteService::class);
            abort_unless($quotes->enabledFor($tenant) && $websites->publicPage($tenant, '') && $websites->publicHostAllowed($site, $request->getHost()), 404);
        } else {
            $quotes->assertEditor($site, $request->user());
        }

        return $site;
    }

    public function offers(Request $request)
    {
        $site = $this->site($request, true);
        $offers = StudioOffer::where('tenant_site_id', $site->id)->where('reserved', false)->where('closes_at', '>', now())->orderBy('closes_at')->paginate(20);

        return response()->view('studio.offers', compact('site', 'offers'))->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $request, int $offer, StudioSponsorshipService $studio)
    {
        $site = $this->site($request, true);
        $data = $request->validate(['request_id' => 'required|uuid', 'sponsor' => 'required|string|max:150', 'email' => 'required|email|max:254',
            'brief' => 'required|string|min:10|max:4000', 'social_clip' => 'sometimes|boolean']);
        $studio->submit($site, $offer, $data);

        return redirect()->route('studio.offers')->with('status', 'Brief received. The production team will review it and email an invoice if approved.');
    }

    public function editor(Request $request, StudioSponsorshipService $studio)
    {
        $site = $this->site($request);
        $offers = StudioOffer::where('tenant_site_id', $site->id)->with('briefs.order')->latest()->paginate(20);
        $origin = rtrim(app(ManagedWebsiteDomainService::class)->platformUrl($site), '/');

        return view('studio.sponsorship-editor', compact('site', 'offers', 'origin', 'studio'));
    }

    public function create(Request $request)
    {
        $site = $this->site($request);
        $data = $request->validate(['episode_id' => 'required|uuid', 'title' => 'required|string|max:155', 'kind' => 'required|in:host_read,production',
            'price_cents' => 'required|integer|min:50|max:100000000', 'clip_cents' => 'nullable|integer|min:50|max:100000000',
            'closes_at' => 'required|date_format:Y-m-d\TH:i|after:now']);
        if ($data['kind'] === 'production') {
            $data['clip_cents'] = null;
        }
        StudioOffer::create(['tenant_site_id' => $site->id, ...$data]);

        return back()->with('status', 'Booking is open.');
    }

    public function close(Request $request, int $offer)
    {
        StudioOffer::where('tenant_site_id', $this->site($request)->id)->findOrFail($offer)->update(['closes_at' => now()]);

        return back()->with('status', 'Booking closed.');
    }

    public function approve(Request $request, int $offer, int $brief, StudioSponsorshipService $studio)
    {
        $tax = $request->validate(['tax_cents' => 'required|integer|min:0|max:100000000', 'tax_basis' => 'required|string|max:255']);
        $studio->approve($this->site($request), $offer, $brief, $tax, $request->user());

        return back()->with('status', 'Invoice issued. Send the sponsor their checkout link below.');
    }

    public function proof(Request $request, int $offer, int $brief, StudioSponsorshipService $studio)
    {
        $offer = StudioOffer::where('tenant_site_id', $this->site($request)->id)->findOrFail($offer);
        $brief = $offer->briefs()->findOrFail($brief);
        abort_unless($studio->paid($brief), 409, 'Verify payment before recording delivery.');
        $data = $request->validate(['url' => 'required|url:https|max:2000', 'timestamp_seconds' => 'required|integer|min:0|max:86400',
            'clip_url' => [$brief->social_clip ? 'required' : 'nullable', 'url:https', 'max:2000']]);
        $brief->update(['proof' => $data]);

        return back()->with('status', 'Delivery recorded.');
    }

    public function rundown(Request $request, string $episode, StudioSponsorshipService $studio)
    {
        return response()->json($studio->rundown($this->site($request), $episode))->withHeaders([
            'Content-Disposition' => 'attachment; filename="sponsor-rundown.json"', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
