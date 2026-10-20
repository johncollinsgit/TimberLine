<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\LedgerEntry;
use App\Models\Marketplace\OrderLine;
use App\Models\Marketplace\PickupLocation;
use App\Models\Marketplace\RateAgreement;
use App\Models\Marketplace\Statement;
use App\Models\Marketplace\WorkspaceLink;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Marketplace\MarketplaceCatalog;
use App\Services\Marketplace\MarketplaceFulfillment;
use App\Services\Marketplace\MarketplaceRates;
use App\Services\Marketplace\MarketplaceShopify;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MakerController extends Controller
{
    public function index(Request $request, MarketplaceAccess $access)
    {
        $identity = $access->identity($request);

        return view('marketplace.sell', ['identity' => $identity, 'memberships' => $identity?->memberships()->where('active', true)->with('maker')->get() ?? collect()]);
    }

    public function apply(Request $request, MarketplaceAccess $access, MarketplaceCatalog $catalog)
    {
        $identity = $access->identity($request);
        abort_unless($identity, 403);
        $data = $request->validate(['name' => 'required|string|max:160', 'city' => 'required|string|max:100', 'story' => 'required|string|max:5000']);
        $maker = $catalog->apply($identity, $data);

        return redirect('/maker/'.$maker->id)->with('status', 'Application received. You can prepare your listings while we review it.');
    }

    public function dashboard(Request $request, int $id, MarketplaceAccess $access, MarketplaceRates $rates)
    {
        $maker = $access->maker($request, $id);
        $listings = $maker->listings()->latest('id')->get();
        $agreements = collect([$rates->global(), ...$listings->map(fn ($listing) => $rates->resolve($listing))->all()]);
        if ($override = RateAgreement::query()->where('scope_key', 'maker:'.$maker->id)->latest('version')->first()) {
            $agreements->push($override);
        }

        return view('marketplace.maker-dashboard', ['markets' => \App\Models\Marketplace\Market::where('status', 'published')->where('ends_at', '>=', now())->with(['makers' => fn ($q) => $q->where('marketplace_makers.id', $maker->id)])->orderBy('starts_at')->get(), 'storeLinks' => \App\Models\Marketplace\StoreLink::where('maker_id', $maker->id)->where('status', 'shared')->get(), 'maker' => $maker, 'listings' => $listings, 'agreements' => $agreements->unique('id'), 'locations' => PickupLocation::query()->where('active', true)->get()->filter(fn ($location) => app(\App\Services\Marketplace\MarketplaceMarkets::class)->pickupAvailable($maker, $location->id)), 'lines' => OrderLine::query()->where('maker_id', $maker->id)->with('order')->latest('id')->paginate(20), 'statements' => Statement::query()->where('maker_id', $maker->id)->latest('id')->limit(30)->get(), 'balance' => LedgerEntry::query()->where('maker_id', $maker->id)->whereNull('statement_id')->sum('amount_cents')]);
    }

    public function save(Request $request, int $id, MarketplaceAccess $access, MarketplaceCatalog $catalog)
    {
        $maker = $access->maker($request, $id);
        $data = $request->validate([
            'listing_id' => 'nullable|integer', 'title' => 'required|string|max:160', 'variant_label' => 'nullable|string|max:160',
            'category' => 'required|string|max:80', 'description' => 'required|string|max:10000', 'price' => ['required', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/'],
            'shipping' => 'nullable|boolean', 'pickup_location_ids' => 'nullable|array|max:10', 'pickup_location_ids.*' => 'integer', 'lead_time' => 'nullable|string|max:160',
            'image_urls' => 'nullable|string|max:6000', 'photos' => 'nullable|array|max:6', 'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:8192',
        ]);
        $listing = empty($data['listing_id']) ? null : $maker->listings()->findOrFail($data['listing_id']);
        $data['price_cents'] = MarketplaceShopify::cents($data['price']);
        abort_unless($data['price_cents'] > 0, 422, 'Enter a price above zero.');
        $images = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $data['image_urls'] ?? ''))));
        foreach ($images as $url) {
            abort_unless(filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https', 422, 'Use public HTTPS image URLs.');
        }
        foreach ($request->file('photos', []) as $photo) {
            $images[] = '/storage/'.$photo->store('marketplace/'.$maker->id, 'public');
        }
        $data['images'] = $images ?: ($listing?->images ?? []);
        abort_unless(count($data['images']) > 0 && count($data['images']) <= 6, 422, 'Add one to six product photos.');
        $data['shipping'] = $request->boolean('shipping');
        $catalog->save($maker, $data, $listing);

        return back()->with('status', 'Listing saved for review.');
    }

    public function pause(Request $request, int $id, int $listingId, MarketplaceAccess $access, MarketplaceShopify $shopify)
    {
        $listing = $access->maker($request, $id)->listings()->findOrFail($listingId);
        $listing->forceFill(['status' => 'paused'])->save();
        $shopify->pause($listing);

        return back()->with('status', 'Listing paused.');
    }

    public function accept(Request $request, int $id, MarketplaceAccess $access, MarketplaceRates $rates)
    {
        $maker = $access->maker($request, $id);
        $data = $request->validate(['rate_id' => 'required|integer', 'accepted' => 'required|accepted']);
        $rates->accept($maker, $access->identity($request), RateAgreement::query()->findOrFail($data['rate_id']));

        return back()->with('status', 'Rate agreement accepted.');
    }

    public function inventory(Request $request, int $id, MarketplaceAccess $access, MarketplaceCatalog $catalog, MarketplaceShopify $shopify)
    {
        $maker = $access->maker($request, $id);
        $data = $request->validate(['listing_id' => 'required|integer', 'quantity' => 'required|integer|min:-100000|max:100000|not_in:0', 'location_gid' => 'required|string|max:100', 'uuid' => 'required|uuid']);
        $listing = $maker->listings()->findOrFail($data['listing_id']);
        $catalog->allocate($listing, $data['quantity'], $data['location_gid'], $data['uuid'], null, $access->identity($request));
        if (config('marketplace.checkout_provider') === 'shopify' && $listing->shopify_inventory_gid) {
            $shopify->syncInventory($listing);
        }

        return back()->with('status', 'Stock allocation saved.');
    }

    public function fulfill(Request $request, int $id, int $lineId, MarketplaceAccess $access, MarketplaceFulfillment $fulfillment)
    {
        $maker = $access->maker($request, $id);
        $line = OrderLine::query()->where('maker_id', $maker->id)->findOrFail($lineId);
        $data = $request->validate(['action' => 'required|in:ready,collected,shipped', 'uuid' => 'required|uuid', 'tracking_number' => 'nullable|string|max:160', 'tracking_url' => 'nullable|url:https|max:1000', 'pickup_code' => 'nullable|string|max:12']);
        $fulfillment->fulfill($line, $data['action'], $data, $data['uuid']);

        return back()->with('status', 'Fulfillment updated.');
    }

    public function connect(Request $request, int $id, MarketplaceAccess $access)
    {
        $maker = $access->maker($request, $id);
        $token = Str::random(64);
        WorkspaceLink::query()->create(['maker_id' => $maker->id, 'identity_id' => $access->identity($request)->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(15)]);

        return redirect()->away(rtrim(config('app.url'), '/').'/marketplace/connect/'.$token);
    }
}
