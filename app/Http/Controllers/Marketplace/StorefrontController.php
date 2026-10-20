<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\PickupLocation;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Marketplace\MarketplaceCheckout;
use Illuminate\Http\Request;

class StorefrontController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:80', 'delivery' => 'nullable|in:shipping,pickup', 'city' => 'nullable|string|max:100']);
        $query = $this->listings();
        if ($search = $filters['q'] ?? null) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%')->orWhereHas('maker', fn ($m) => $m->where('name', 'like', '%'.$search.'%')));
        }
        if ($category = $filters['category'] ?? null) {
            $query->where('category', $category);
        }
        if (($filters['delivery'] ?? '') === 'shipping') {
            $query->where('shipping', true);
        }
        if (($filters['delivery'] ?? '') === 'pickup') {
            $query->whereNotNull('pickup_location_ids')->whereJsonLength('pickup_location_ids', '>', 0);
        }
        if ($city = $filters['city'] ?? null) {
            $query->whereHas('maker', fn ($m) => $m->where('city', $city));
        }

        return view('marketplace.home', ['listings' => $query->latest('id')->paginate(12)->withQueryString(), 'categories' => $this->listings()->distinct()->orderBy('category')->pluck('category'), 'cities' => Maker::query()->where('status', 'approved')->distinct()->orderBy('city')->pluck('city'), 'makers' => Maker::query()->where('status', 'approved')->limit(4)->get(), 'filters' => $filters]);
    }

    public function product(string $slug)
    {
        $listing = $this->listings()->where('slug', $slug)->firstOrFail();

        return view('marketplace.product', ['listing' => $listing, 'locations' => PickupLocation::query()->whereIn('id', $listing->pickup_location_ids ?? [])->where('active', true)->get()->filter(fn ($location) => app(\App\Services\Marketplace\MarketplaceMarkets::class)->pickupAvailable($listing->maker, $location->id))]);
    }

    public function maker(string $slug)
    {
        $maker = Maker::query()->where('slug', $slug)->where('status', 'approved')->firstOrFail();

        return view('marketplace.maker', ['maker' => $maker, 'listings' => $this->listings()->where('maker_id', $maker->id)->get()]);
    }

    public function cart(Request $request, MarketplaceCheckout $checkout)
    {
        return view('marketplace.cart', ['groups' => $checkout->groups($request), 'shopPayReady' => config('marketplace.checkout_provider') === 'everbranch' && app(\App\Services\Marketplace\MarketplaceWallet::class)->ready(), 'checkoutReady' => $checkout->blockers() === []]);
    }

    public function add(Request $request, MarketplaceCheckout $checkout)
    {
        $data = $request->validate(['listing_id' => 'required|integer', 'quantity' => 'required|integer|min:1|max:99', 'group' => 'required|string|max:80']);
        $checkout->add($request, Listing::query()->findOrFail($data['listing_id']), $data['quantity'], $data['group']);

        return redirect('/cart')->with('status', 'Added to your bag.');
    }

    public function remove(Request $request)
    {
        $data = $request->validate(['key' => 'required|string|max:120']);
        $cart = $request->session()->get('marketplace_cart', []);
        unset($cart[$data['key']]);
        $request->session()->put('marketplace_cart', $cart);

        return redirect('/cart');
    }

    public function checkout(Request $request, MarketplaceCheckout $checkout)
    {
        $data = $request->validate(['group' => 'required|string|max:80', 'uuid' => 'required|uuid', 'payment_method' => 'nullable|in:card,shop_pay']);
        $attempt = $checkout->begin($request, $data['group'], $data['uuid']);

        return redirect()->away($attempt->provider_cart['url']);
    }

    public function progress(Request $request, string $uuid, MarketplaceCheckout $checkout)
    {
        $attempt = Checkout::query()->where('uuid', $uuid)->where('session_hash', $checkout->owner($request))->with('order.lines')->firstOrFail();
        $checkout->cleanPaidGroups($request);

        return view('marketplace.checkout-status', ['attempt' => $attempt]);
    }

    public function policy(string $type, MarketplaceAccess $access)
    {
        abort_unless(in_array($type, ['shipping', 'return', 'privacy'], true), 404);

        return view('marketplace.policy', ['title' => ucfirst($type === 'return' ? 'Returns' : $type), 'body' => $access->settings()->getAttribute($type.'_policy'), 'supportEmail' => $access->settings()->support_email]);
    }

    private function listings()
    {
        return Listing::query()->with('maker')->where('status', 'approved')->whereHas('maker', fn ($q) => $q->where('status', 'approved'));
    }
}
