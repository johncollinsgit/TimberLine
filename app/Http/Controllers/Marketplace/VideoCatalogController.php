<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\MakerVideo;
use App\Models\Marketplace\VideoCatalogSource;
use App\Models\WebsiteProductVariant;
use App\Services\Marketplace\MakerVideoCatalog;
use App\Services\Marketplace\MakerVideoShopify;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Shopify\ShopifyHmacVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VideoCatalogController extends Controller
{
    public function show(Request $request, int $id, MarketplaceAccess $access, MakerVideoShopify $shopify)
    {
        $maker = $access->maker($request, $id);
        $input = $request->validate(['source' => 'nullable|integer|min:1', 'after' => 'nullable|string|max:2000']);
        $sources = VideoCatalogSource::where('maker_id', $maker->id)->orderBy('id')->get();
        $browse = null;
        $message = null;
        if (isset($input['source'])) {
            $source = $sources->firstWhere('id', $input['source']);
            abort_unless($source && $source->provider === 'shopify', 404);
            try {
                $browse = ['source' => $source, ...$shopify->variants($source, $input['after'] ?? null)];
            } catch (ValidationException $error) {
                $message = $error->errors()['shopify'][0];
            }
        }

        return view('marketplace.video-catalog', ['maker' => $maker, 'sources' => $sources, 'browse' => $browse,
            'connectionMessage' => $message, 'invited' => MakerVideo::invited($id) && $maker->status === 'approved', 'shopifyReady' => $shopify->configured()]);
    }

    public function export(Request $request, int $id, MarketplaceAccess $access, \App\Services\Marketplace\MakerVideoCatalog $catalog)
    {
        $maker = $access->maker($request, $id);
        $this->assertPilot($maker);
        $variants = collect($catalog->options($maker))->map(fn ($card) => [
            'variant_id' => $card['key'], 'label' => trim($card['label'].' '.$card['variant']),
        ])->values();

        return response()->json($variants)->header('Content-Disposition', 'attachment; filename="maker-video-catalog.json"');
    }

    public function authorizeShopify(Request $request, int $id, MarketplaceAccess $access, MakerVideoShopify $shopify)
    {
        $maker = $access->maker($request, $id);
        $this->assertPilot($maker);
        $data = $request->validate(['shop' => 'required|string|max:100', 'consent' => 'accepted']);
        $domain = $shopify->shopDomain($data['shop']);
        $state = Str::random(64);
        $url = $shopify->authorizationUrl($domain, $state);
        $request->session()->put('mm_catalog_oauth', ['state' => $state, 'shop' => $domain, 'maker' => $maker->id,
            'identity' => $access->identity($request)->id, 'until' => now()->addMinutes(10)->timestamp]);

        return redirect()->away($url);
    }

    public function callback(Request $request, MarketplaceAccess $access, MakerVideoShopify $shopify, ShopifyHmacVerifier $signature)
    {
        $data = $request->validate(['state' => 'required|string|max:100', 'shop' => 'required|string|max:100', 'code' => 'required|string|max:500']);
        $state = $request->session()->get('mm_catalog_oauth');
        abort_unless($shopify->configured() && $state && hash_equals($state['state'], $data['state']) && $state['until'] >= now()->timestamp
            && $state['shop'] === $data['shop'] && $state['identity'] === $access->identity($request)?->id
            && $signature->verifyQuery($request->query(), config('marketplace.video_shopify_client_secret')), 403);
        $maker = $access->maker($request, $state['maker']);
        $this->assertPilot($maker);
        $request->session()->forget('mm_catalog_oauth');
        $source = DB::transaction(function () use ($maker, $state, $data, $shopify) {
            $source = VideoCatalogSource::firstOrCreate(['provider' => 'shopify', 'reference' => $state['shop']], ['maker_id' => $maker->id]);
            $source = VideoCatalogSource::lockForUpdate()->findOrFail($source->id);
            abort_unless((int) $source->maker_id === $maker->id, 409, 'This shop is already connected to another maker.');
            $source->update(['credentials' => $shopify->exchange($state['shop'], ['code' => $data['code'], 'expiring' => 1])]);

            return $source;
        });

        return redirect()->route('marketplace.public.maker.video-catalog', ['id' => $maker->id, 'source' => $source->id]);
    }

    public function select(Request $request, int $id, int $source, MarketplaceAccess $access, MakerVideoShopify $shopify)
    {
        $maker = $access->maker($request, $id);
        $input = $request->validate(['variant' => ['required', 'regex:/^[1-9][0-9]{0,19}$/D'], 'action' => 'required|in:add,remove']);
        $connection = VideoCatalogSource::where('maker_id', $maker->id)->findOrFail($source);
        $variant = (string) $input['variant'];
        $snapshot = null;
        if ($input['action'] === 'add') {
            $this->assertPilot($maker);
            abort_unless($connection->provider === 'shopify', 403);
            $item = $shopify->variants($connection, id: $variant)['variants'][$variant] ?? null;
            abort_unless($item, 422, 'Choose a published variant from this shop.');
            $snapshot = ['label' => $item['label'], 'variant' => $item['variant']];
        }
        $this->changeSelection($connection, $variant, $snapshot);

        return back()->with('status', $snapshot ? 'Variant available in the video editor.' : 'Variant removed from video shopping.');
    }

    public function shareNative(Request $request, int $id, MarketplaceAccess $access)
    {
        $maker = Maker::findOrFail($id);
        $tenant = $access->assertWorkspaceManager($request->user(), (int) $maker->tenant_id);
        $input = $request->validate(['variant_id' => 'required|integer|min:1', 'action' => 'required|in:add,remove']);
        $variant = WebsiteProductVariant::forTenant($tenant)->with('product')
            ->whereHas('product', fn ($q) => $q->where('tenant_id', $tenant->id)->whereIn('product_type', ['physical', 'quote'])
                ->whereHas('site', fn ($site) => $site->where('tenant_id', $tenant->id)))->findOrFail($input['variant_id']);
        $product = $variant->product;
        $selection = null;
        if ($input['action'] === 'add') {
            $this->assertPilot($maker);
            abort_if($product->status !== 'active' || ! $product->site->public_enabled || $product->site->status !== 'published', 422, 'Publish this product and site before sharing it.');
            $selection = ['label' => $product->title, 'variant' => $variant->title];
            if ($product->product_type === 'quote') {
                $selection['quote_url'] = app(MakerVideoCatalog::class)->quoteDestination($product->site, $variant);
                abort_unless($selection['quote_url'], 422, 'Share a published quote product with one available option and an active quote form.');
            }
        }
        DB::transaction(function () use ($maker, $product, $variant, $selection) {
            $connection = VideoCatalogSource::firstOrCreate(['provider' => 'everbranch', 'reference' => (string) $product->tenant_site_id], ['maker_id' => $maker->id]);
            abort_unless((int) $connection->maker_id === $maker->id, 409, 'This site is connected to another maker.');
            $this->changeSelection($connection, (string) $variant->id, $selection);
        });

        return back()->with('status', $input['action'] === 'add' ? 'Variant shared with this maker for shoppable videos.' : 'Variant removed from video shopping.');
    }

    public function disconnect(Request $request, int $id, int $source, MarketplaceAccess $access)
    {
        $maker = $access->maker($request, $id);
        VideoCatalogSource::where('maker_id', $maker->id)->findOrFail($source)->delete();

        return redirect()->route('marketplace.public.maker.video-catalog', $maker->id)->with('status', 'Store disconnected. Its products are unavailable on videos.');
    }

    private function assertPilot(Maker $maker): void
    {
        abort_unless($maker->status === 'approved' && MakerVideo::invited($maker->id), 403, 'Catalog sharing is available to invited pilot makers.');
    }

    private function changeSelection(VideoCatalogSource $source, string $id, ?array $snapshot): void
    {
        DB::transaction(function () use ($source, $id, $snapshot): void {
            $locked = VideoCatalogSource::where('maker_id', $source->maker_id)->lockForUpdate()->findOrFail($source->id);
            $selected = $locked->selections ?? [];
            if ($snapshot === null) {
                unset($selected[$id]);
            } else {
                $selected[$id] = $snapshot;
            }
            $locked->update(['selections' => $selected]);
        });
    }
}
