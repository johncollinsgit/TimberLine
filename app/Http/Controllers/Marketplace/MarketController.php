<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Market;
use App\Models\Marketplace\PickupLocation;
use App\Models\Marketplace\StoreLink;
use App\Services\Marketplace\MarketplaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketController extends Controller
{
    public function index()
    {
        return view('marketplace.markets', ['markets' => Market::where('status', 'published')->where('ends_at', '>=', now())->with(['makers' => fn ($q) => $q->where('marketplace_market_makers.status', 'approved')->where('marketplace_makers.status', 'approved')])->orderBy('starts_at')->paginate(20)]);
    }

    public function save(Request $request)
    {
        $data = $request->validate(['market_id' => 'nullable|integer', 'name' => 'required|string|max:160', 'kind' => 'required|in:market,show,popup', 'status' => 'required|in:draft,published,cancelled,completed', 'description' => 'nullable|string|max:10000', 'address' => 'required|string|max:3000', 'image_url' => 'nullable|url:https|max:1000', 'timezone' => 'required|timezone', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'pickup_cutoff_at' => 'nullable|date|before_or_equal:ends_at', 'pickup_instructions' => 'nullable|string|max:3000', 'tax_address' => 'nullable|array', 'tax_address.line1' => 'nullable|string|max:200', 'tax_address.city' => 'nullable|string|max:100', 'tax_address.state' => 'nullable|string|size:2', 'tax_address.postal_code' => 'nullable|regex:/^[0-9]{5}(-[0-9]{4})?$/', 'tax_address.country' => 'nullable|in:US']);
        if ($request->boolean('offer_pickup') && $data['status'] === 'published') {
            $request->validate(['tax_address.line1' => 'required|string|max:200', 'tax_address.city' => 'required|string|max:100', 'tax_address.state' => 'required|regex:/^[A-Z]{2}$/', 'tax_address.postal_code' => 'required|regex:/^[0-9]{5}(-[0-9]{4})?$/', 'tax_address.country' => 'required|in:US']);
        }
        DB::transaction(function () use ($data, $request): void {
            $market = empty($data['market_id']) ? new Market(['slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)), 'created_by' => $request->user()->id]) : Market::query()->lockForUpdate()->findOrFail($data['market_id']);
            foreach (['starts_at', 'ends_at', 'pickup_cutoff_at'] as $key) {
                $data[$key] = empty($data[$key]) ? null : CarbonImmutable::parse($data[$key], $data['timezone'])->utc();
            }
            $before = $market->exists ? $market->only(['starts_at', 'ends_at', 'address', 'status']) : [];
            $market->fill(array_diff_key($data, array_flip(['market_id', 'tax_address'])))->save();
            if ($request->boolean('offer_pickup')) {
                $pickup = $market->pickup_location_id ? PickupLocation::findOrFail($market->pickup_location_id) : new PickupLocation(['shopify_location_gid' => 'eb:market:'.$market->id]);
                $pickup->fill(['tax_address' => array_filter($data['tax_address'] ?? []), 'name' => $market->name, 'address' => $market->address, 'instructions' => ($data['pickup_instructions'] ?? '')."\n".$market->starts_at->setTimezone($market->timezone)->format('M j, Y g:i a').'–'.$market->ends_at->setTimezone($market->timezone)->format('g:i a T'), 'active' => $market->status === 'published'])->save();
                $market->forceFill(['pickup_location_id' => $pickup->id])->save();
            } elseif ($market->pickup_location_id) {
                PickupLocation::whereKey($market->pickup_location_id)->update(['active' => false]);
            }
            if ($before && $market->pickup_location_id && $before != $market->only(['starts_at', 'ends_at', 'address', 'status'])) {
                \App\Models\Marketplace\Order::where('group_key', 'pickup:'.$market->pickup_location_id)->whereHas('lines', fn ($q) => $q->whereNull('fulfilled_at')->whereColumn('refunded_quantity', '<', 'quantity'))->update(['review_required' => true, 'review_reason' => 'The market pickup schedule or location changed. Contact the customer and record a reschedule or refund before fulfillment.']);
            }
            Audit::create(['actor_user_id' => $request->user()->id, 'action' => 'market.saved', 'subject_id' => $market->id, 'details' => ['status' => $market->status]]);
        });

        return back()->with('status', 'Market saved.');
    }

    public function participant(Request $request, int $id)
    {
        $data = $request->validate(['maker_id' => 'required|integer', 'status' => 'required|in:invited,applied,approved,declined,withdrawn', 'booth' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:3000']);
        $market = Market::findOrFail($id);
        $maker = Maker::findOrFail($data['maker_id']);
        abort_if($data['status'] === 'approved' && $maker->status !== 'approved', 422, 'Approve the maker before their market participation.');
        DB::table('marketplace_market_makers')->updateOrInsert(['market_id' => $market->id, 'maker_id' => $maker->id], ['status' => $data['status'], 'booth' => $data['booth'] ?? null, 'notes' => $data['notes'] ?? null, 'approved_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        Audit::create(['actor_user_id' => $request->user()->id, 'action' => 'market.maker_updated', 'subject_id' => $market->id, 'details' => ['maker_id' => $maker->id, 'status' => $data['status']]]);

        return back()->with('status', 'Maker participation updated.');
    }

    public function apply(Request $request, int $id, MarketplaceAccess $access)
    {
        $maker = $access->maker($request, $id);
        $data = $request->validate(['market_id' => 'required|integer', 'action' => 'required|in:apply,withdraw']);
        $market = Market::where('status', 'published')->where('ends_at', '>', now())->findOrFail($data['market_id']);
        DB::transaction(function () use ($maker, $market, $data): void {
            $market = Market::query()->lockForUpdate()->findOrFail($market->id);
            $old = DB::table('marketplace_market_makers')->where('market_id', $market->id)->where('maker_id', $maker->id)->first();
            if ($data['action'] === 'apply' && $old && in_array($old->status, ['approved', 'applied'], true)) {
                return;
            }DB::table('marketplace_market_makers')->updateOrInsert(['market_id' => $market->id, 'maker_id' => $maker->id], ['status' => $data['action'] === 'apply' ? 'applied' : 'withdrawn', 'updated_at' => now(), 'created_at' => $old->created_at ?? now()]);
        });

        return back()->with('status', 'Market participation request saved.');
    }

    public function link(Request $request, int $id, MarketplaceAccess $access)
    {
        $maker = $access->maker($request, $id);
        $data = $request->validate(['platform' => 'required|in:shopify,wix,square,etsy,woocommerce,other', 'url' => 'required|url:https|max:1000', 'label' => 'nullable|string|max:160']);
        $url = parse_url($data['url']);
        abort_if(isset($url['user']) || isset($url['pass']), 422, 'Use the public storefront URL.');
        StoreLink::create([...$data, 'maker_id' => $maker->id, 'identity_id' => $access->identity($request)->id, 'status' => 'shared']);

        return back()->with('status', 'Storefront shared with Markets and Makers.');
    }

    public function unlink(Request $request, int $id, int $linkId, MarketplaceAccess $access)
    {
        $maker = $access->maker($request, $id);
        StoreLink::where('maker_id', $maker->id)->findOrFail($linkId)->update(['status' => 'removed']);

        return back()->with('status', 'Storefront link removed.');
    }

    public function details(Request $request, int $id, MarketplaceAccess $access)
    {
        $maker = $access->maker($request, $id);
        $data = $request->validate(['legal_business_name' => 'nullable|string|max:160', 'contact_name' => 'nullable|string|max:160', 'phone' => 'nullable|string|max:40', 'business_address' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:3000']);
        $maker->update(['private_details' => $data]);

        return back()->with('status','Private business details saved for Markets and Makers.');
    }
}
