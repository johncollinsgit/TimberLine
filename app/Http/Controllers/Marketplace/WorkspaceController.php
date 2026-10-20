<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Membership;
use App\Models\Marketplace\WorkspaceLink;
use App\Models\WebsiteProductVariant;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Marketplace\MarketplaceCatalog;
use App\Services\Marketplace\MarketplaceShopify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    public function show(Request $request, string $token)
    {
        $link = $this->link($token);

        return view('marketplace.workspace-connect', ['token' => $token, 'maker' => Maker::query()->findOrFail($link->maker_id), 'tenants' => $request->user()->tenants()->wherePivot('membership_active', true)->wherePivotIn('role', ['owner', 'admin'])->get()]);
    }

    public function connect(Request $request, string $token, MarketplaceAccess $access)
    {
        $data = $request->validate(['tenant_id' => 'required|integer', 'consent' => 'required|accepted']);
        $tenant = $access->assertWorkspaceManager($request->user(), $data['tenant_id']);
        $maker = DB::transaction(function () use ($token, $tenant, $request) {
            $link = $this->link($token, true);
            abort_unless(Membership::query()->where('maker_id', $link->maker_id)->where('identity_id', $link->identity_id)->where('active', true)->where('role', 'owner')->exists(), 403);
            $maker = Maker::query()->lockForUpdate()->findOrFail($link->maker_id);
            abort_unless(! $maker->tenant_id || (int) $maker->tenant_id === (int) $tenant->id, 409, 'This maker is already connected to another workspace.');
            $maker->forceFill(['tenant_id' => $tenant->id, 'connected_by' => $request->user()->id])->save();
            $link->forceFill(['consumed_at' => now()])->save();
            Audit::query()->create(['actor_user_id' => $request->user()->id, 'identity_id' => $link->identity_id, 'action' => 'maker.workspace_connected', 'subject_id' => $maker->id, 'details' => ['tenant_id' => $tenant->id]]);

            return $maker;
        });

        return redirect('/marketplace/workspaces/'.$maker->id);
    }

    public function catalog(Request $request, int $id, MarketplaceAccess $access)
    {
        $maker = Maker::query()->findOrFail($id);
        $tenant = $access->assertWorkspaceManager($request->user(), (int) $maker->tenant_id);

        return view('marketplace.workspace-catalog', ['maker' => $maker, 'tenant' => $tenant, 'variants' => WebsiteProductVariant::query()->where('tenant_id', $tenant->id)->whereHas('product', fn ($q) => $q->where('tenant_id', $tenant->id)->where('product_type', 'physical'))->with('product')->paginate(30), 'listings' => $maker->listings()->with('levels')->get()]);
    }

    public function import(Request $request, int $id, MarketplaceCatalog $catalog)
    {
        $data = $request->validate(['variant_id' => 'required|integer']);
        $catalog->importNative(Maker::query()->findOrFail($id), $request->user(), $data['variant_id']);

        return back()->with('status', 'A marketplace draft was created. Photos, fulfillment, terms, and approval are still required.');
    }

    public function allocate(Request $request, int $id, MarketplaceAccess $access, MarketplaceCatalog $catalog, MarketplaceShopify $shopify)
    {
        $maker = Maker::query()->findOrFail($id);
        $access->assertWorkspaceManager($request->user(), (int) $maker->tenant_id);
        $data = $request->validate(['listing_id' => 'required|integer', 'quantity' => 'required|integer|min:-100000|max:100000|not_in:0', 'location_gid' => 'required|string|max:100', 'uuid' => 'required|uuid']);
        $listing = $maker->listings()->findOrFail($data['listing_id']);
        $catalog->allocate($listing, $data['quantity'], $data['location_gid'], $data['uuid'], $request->user(), null);
        if (config('marketplace.checkout_provider') === 'shopify' && $listing->shopify_inventory_gid) {
            $shopify->syncInventory($listing);
        }

        return back()->with('status', 'Native inventory allocation saved.');
    }

    private function link(string $token, bool $lock = false): WorkspaceLink
    {
        $query = WorkspaceLink::query()->where('token_hash', hash('sha256', $token))->whereNull('consumed_at')->where('expires_at', '>', now());

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }
}
