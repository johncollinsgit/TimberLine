<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Connection;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Operation;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\OrderLine;
use App\Models\Marketplace\PickupLocation;
use App\Models\Marketplace\RateAgreement;
use App\Models\Marketplace\Statement;
use App\Models\Marketplace\WebhookEvent;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Marketplace\MarketplaceCatalog;
use App\Services\Marketplace\MarketplaceCheckout;
use App\Services\Marketplace\MarketplaceFulfillment;
use App\Services\Marketplace\MarketplaceOrders;
use App\Services\Marketplace\MarketplaceRates;
use App\Services\Marketplace\MarketplaceShopify;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OperatorController extends Controller
{
    public function index(Request $request, MarketplaceAccess $access, MarketplaceCheckout $checkout, MarketplaceRates $rates)
    {
        $tab = $request->query('tab', 'overview');
        abort_unless(in_array($tab, ['overview', 'makers', 'listings', 'orders', 'rates', 'payments', 'statements', 'activity', 'customers', 'discounts', 'markets', 'pickups', 'connections'], true), 404);
        $rates->global();
        $settings = $access->settings();
        $report = app(\App\Services\Marketplace\MarketplaceReporting::class)->overview($request);
        $merchantStore = $access->store();
        $merchantTenant = $merchantStore?->tenant;
        $context = $merchantTenant ? app(\App\Services\Tenancy\TenantBlueprintProfileService::class)->companyContext($merchantTenant) : [];
        // Read existing branding only. presentationFor() may initialize a profile;
        // opening this console must not initialize or change business branding.
        $brandName = $merchantTenant
            ? ($merchantTenant->brandProfile?->display_name ?: app(\App\Services\Tenancy\TenantBrandProfileService::class)->defaultAttributes($merchantTenant)['display_name'])
            : 'Dedicated merchant';

        return view('marketplace.operator', [
            ...$report,
            'markets' => \App\Models\Marketplace\Market::with('makers', 'pickupLocation')->latest('starts_at')->limit(100)->get(),
            'tab' => $tab, 'settings' => $settings, 'connection' => Connection::query()->find($settings->connection_id), 'blockers' => $checkout->blockers(),
            'merchantStore' => $merchantStore, 'consoleBusinessName' => $brandName,
            'consoleBusinessHint' => filled($context['confirmed_at'] ?? null) ? ($context['specialty'] ?? null) : null,
            'walletConnection' => app(\App\Services\Marketplace\MarketplaceWallet::class)->connection(),
            'makers' => Maker::query()->with('memberships')->latest('id')->limit(100)->get(), 'listings' => Listing::query()->with('maker', 'levels')->latest('id')->limit(100)->get(),
            'orders' => $tab === 'orders' ? ($request->boolean('include_test') ? Order::with('lines.maker')->whereBetween('created_at', [$report['from']->utc(), $report['to']->utc()])->when($request->filled('maker'), fn ($q) => $q->whereHas('lines', fn ($line) => $line->where('maker_id', $request->integer('maker'))))->when($request->filled('customer'), fn ($q) => $q->where('email_hash', hash('sha256', strtolower(trim($request->query('customer'))))))->latest('id')->limit(2000)->get() : $report['salesOrders']) : Order::query()->with('lines.maker')->latest('id')->limit(50)->get(), 'statements' => Statement::query()->with('maker', 'entries')->latest('id')->limit(50)->get(),
            'rates' => RateAgreement::query()->latest('id')->limit(100)->get(), 'locations' => PickupLocation::query()->get(),
            'events' => WebhookEvent::query()->select('id', 'topic', 'status', 'failure_code', 'created_at')->latest('id')->limit(30)->get(),
            'operations' => Operation::query()->select('id', 'uuid', 'kind', 'subject_id', 'status', 'created_at')->latest('id')->limit(30)->get(),
            'nativeEvents' => \App\Models\Marketplace\PaymentEvent::select('id', 'provider', 'status', 'failure_code', 'created_at')->latest('id')->limit(30)->get(),
            'attempts' => \App\Models\Marketplace\Checkout::select('id', 'uuid', 'provider', 'status', 'failure_code', 'created_at')->latest('id')->limit(50)->get(),
            'audits' => Audit::query()->latest('id')->limit(30)->get(),
        ]);
    }

    public function makerDetail(Request $request, int $id)
    {
        $maker = Maker::with('memberships.identity')->findOrFail($id);
        $returnFilters = $request->only(['q', 'status', 'from', 'to', 'maker', 'customer', 'include_test', 'detail']);
        $request->query->set('maker', $id);

        return view('marketplace.operator-maker', ['returnFilters' => $returnFilters, ...app(\App\Services\Marketplace\MarketplaceReporting::class)->overview($request), 'maker' => $maker, 'listings' => $maker->listings()->with('levels')->get(), 'statements' => Statement::where('maker_id', $id)->latest('id')->get(), 'links' => \App\Models\Marketplace\StoreLink::where('maker_id', $id)->where('status', 'shared')->get(), 'markets' => \App\Models\Marketplace\Market::whereHas('makers', fn ($q) => $q->where('marketplace_makers.id', $id))->with(['makers' => fn ($q) => $q->where('marketplace_makers.id', $id)])->orderBy('starts_at')->get()]);
    }

    public function settings(Request $request, MarketplaceAccess $access)
    {
        $data = $request->validate(['shipping_policy' => 'nullable|string|max:20000', 'return_policy' => 'nullable|string|max:20000', 'privacy_policy' => 'nullable|string|max:20000', 'support_email' => 'nullable|email:rfc|max:254', 'promotion_subsidy_limit' => ['required', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/']]);
        $settings = $access->settings();
        $readiness = $settings->readiness ?? [];
        foreach (['merchant_setup', 'shipping', 'tax', 'guest_checkout', 'policies'] as $key) {
            $readiness[$key] = $request->boolean($key);
        }
        $readiness['promotion_subsidy_limit_cents'] = MarketplaceShopify::cents($data['promotion_subsidy_limit']);
        if (config('marketplace.checkout_provider') === 'everbranch') {
            $native = $request->validate(['shipping_per_maker' => ['required', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/'], 'promotion_percent' => ['required', 'numeric', 'min:0', 'max:100']]);
            $readiness['shipping_per_maker_cents'] = MarketplaceShopify::cents($native['shipping_per_maker']);
            $readiness['promotion_bps'] = MarketplaceShopify::cents((string) $native['promotion_percent']);
            $readiness['free_shipping_promotion'] = $request->boolean('free_shipping_promotion');
            if ($readiness['free_shipping_promotion']) {
                $request->validate(['free_shipping_credit' => ['required', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/'], 'free_shipping_approved' => 'required|accepted']);
                $readiness['free_shipping_credit_per_maker_cents'] = MarketplaceShopify::cents($request->input('free_shipping_credit'));
                abort_unless($readiness['free_shipping_credit_per_maker_cents'] > 0, 422, 'Define a positive maker shipping credit.');
                $readiness['free_shipping_approved_by'] = $request->user()->id;
            }
        }
        $readiness['methods'] = [];
        foreach (['shop_pay', 'apple_pay', 'google_pay', 'paypal', 'cards'] as $method) {
            $readiness['methods'][$method] = $request->boolean('methods.'.$method);
        }
        unset($data['promotion_subsidy_limit']);
        $settings->forceFill([...$data, 'readiness' => $readiness, 'checkout_enabled' => $request->boolean('checkout_enabled')])->save();
        Audit::query()->create(['actor_user_id' => $request->user()->id, 'action' => 'settings.updated', 'subject_id' => 1, 'details' => ['checkout_enabled' => $settings->checkout_enabled, 'readiness' => $readiness]]);

        return back()->with('status', 'Settings saved. Checkout still requires every launch check to pass.');
    }

    public function connection(Request $request, MarketplaceShopify $shopify)
    {
        $data = $request->validate(['shop_domain' => ['required', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/'], 'shop_gid' => ['required', 'regex:#^gid://shopify/Shop/[0-9]+$#'], 'publication_gid' => ['required', 'regex:#^gid://shopify/Publication/[0-9]+$#'], 'admin_token' => 'required|string|max:1000', 'storefront_token' => 'required|string|max:1000', 'webhook_secret' => 'required|string|max:1000']);
        $connection = Connection::query()->create(['uuid' => (string) Str::uuid(), 'shop_domain' => $data['shop_domain'], 'shop_gid' => $data['shop_gid'], 'publication_gid' => $data['publication_gid'], 'credentials' => array_intersect_key($data, array_flip(['admin_token', 'storefront_token', 'webhook_secret'])), 'test_mode' => $request->boolean('test_mode'), 'created_by' => $request->user()->id]);
        $shopify->verify($connection);

        return back()->with('status', 'Dedicated store verified. Checkout remains disabled until launch checks pass.');
    }

    public function maker(Request $request, int $id)
    {
        $status = $request->validate(['status' => 'required|in:approved,pending,suspended'])['status'];
        $maker = Maker::query()->findOrFail($id);
        abort_unless($maker->memberships()->where('active', true)->where('role', 'owner')->exists(), 422);
        $maker->forceFill(['status' => $status, 'approved_by' => $request->user()->id, 'approved_at' => $status === 'approved' ? now() : null])->save();
        if ($status !== 'approved') {
            foreach ($maker->listings()->whereNotNull('published_revision')->get() as $listing) {
                app(MarketplaceShopify::class)->pause($listing);
            }
        }
        Audit::query()->create(['actor_user_id' => $request->user()->id, 'action' => 'maker.'.$status, 'subject_id' => $id]);

        return back()->with('status', 'Maker status updated.');
    }

    public function listing(Request $request, int $id, MarketplaceCatalog $catalog, MarketplaceShopify $shopify)
    {
        $data = $request->validate(['action' => 'required|in:approve,publish,pause,location,sync_inventory', 'uuid' => 'nullable|uuid', 'location_gid' => ['nullable', 'regex:#^gid://shopify/Location/[0-9]+$#']]);
        $listing = Listing::query()->findOrFail($id);
        if (config('marketplace.checkout_provider') === 'everbranch') {
            if (in_array($data['action'], ['approve', 'publish'], true)) {
                $catalog->approve($listing, $request->user());
                $listing->forceFill(['published_revision' => $listing->revision, 'synced_at' => now()])->save();
            }
            if ($data['action'] === 'pause') {
                $listing->forceFill(['status' => 'paused', 'published_revision' => null])->save();
            }
            if ($data['action'] === 'location') {
                $fee = $request->validate(['shipping_fee' => ['nullable', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/']])['shipping_fee'] ?? null;
                $listing->forceFill(['shipping_fee_cents' => $fee !== null ? MarketplaceShopify::cents($fee) : null])->save();
                Audit::create(['actor_user_id' => $request->user()->id, 'action' => 'listing.shipping_rate_updated', 'subject_id' => $listing->id, 'details' => ['shipping_fee_cents' => $listing->shipping_fee_cents]]);
            }

            return back()->with('status', 'Listing updated.');
        }
        switch ($data['action']) {
            case 'approve': $catalog->approve($listing, $request->user());
                break;
            case 'publish': $shopify->publish($listing, $data['uuid'] ?? (string) Str::uuid(), $request->user()->id);
                break;
            case 'pause': $listing->forceFill(['status' => 'paused'])->save();
                $shopify->pause($listing);
                break;
            case 'sync_inventory': $shopify->syncInventory($listing);
                break;
            case 'location':
                $location = $this->locationIdentity($data['location_gid'] ?? '', $shopify);
                abort_if($listing->shipping_location_gid && $listing->shipping_location_gid !== $location && $listing->stock_quantity > 0, 422, 'Withdraw existing stock before changing shipping origins.');
                $listing->forceFill(['shipping_location_gid' => $location, 'published_revision' => null])->save();
                break;
        }

        return back()->with('status', 'Listing updated.');
    }

    public function pickup(Request $request, MarketplaceShopify $shopify)
    {
        if (config('marketplace.checkout_provider') === 'everbranch') {
            $data = $request->validate(['name' => 'required|string|max:160', 'address' => 'required|string|max:3000', 'instructions' => 'required|string|max:3000']);
            $tax = $request->validate(['tax_address' => 'nullable|array', 'tax_address.line1' => 'required_if:active,1|nullable|string|max:200', 'tax_address.city' => 'required_if:active,1|nullable|string|max:100', 'tax_address.state' => 'required_if:active,1|nullable|regex:/^[A-Z]{2}$/', 'tax_address.postal_code' => 'required_if:active,1|nullable|regex:/^[0-9]{5}(-[0-9]{4})?$/', 'tax_address.country' => 'required_if:active,1|nullable|in:US']);
            $location = PickupLocation::create([...$data, ...$tax, 'shopify_location_gid' => 'eb:'.Str::uuid(), 'active' => $request->boolean('active')]);
            $location->forceFill(['shopify_location_gid' => 'eb:pickup:'.$location->id])->save();

            return back()->with('status', 'Pickup location created.');
        }
        $data = $request->validate(['name' => 'required|string|max:160', 'address' => 'required|string|max:3000', 'instructions' => 'required|string|max:3000', 'shopify_location_gid' => 'required|string|max:100']);
        $data['shopify_location_gid'] = $this->locationIdentity($data['shopify_location_gid'], $shopify);
        PickupLocation::query()->create([...$data, 'active' => $request->boolean('active')]);

        return back()->with('status', 'Pickup location created. Confirm its pickup and inventory settings in Shopify.');
    }

    public function rate(Request $request, MarketplaceRates $rates)
    {
        $data = $request->validate(['scope_key' => 'required|string|max:80', 'kind' => 'required|in:percentage,fixed', 'value' => ['required', 'regex:/^[0-9]{1,7}(\.[0-9]{1,2})?$/'], 'terms' => 'required|string|max:5000']);
        $rates->revise($data['scope_key'], $data['kind'], MarketplaceShopify::cents($data['value']), $data['terms'], $request->user()->id);

        return back()->with('status', 'New rate terms saved. Makers must accept them before new sales.');
    }

    public function order(Request $request, int $id, MarketplaceOrders $orders)
    {
        $order = Order::query()->with('checkout')->findOrFail($id);
        $action = $request->validate(['action' => 'required|in:reconcile,shipping,shipping_refund,review,cost,pickup_update'])['action'];
        if ($action === 'reconcile') {
            if ($order->provider === 'paypal') {
                app(\App\Services\Marketplace\MarketplacePayPal::class)->reconcile($order->checkout);
            } elseif ($order->provider === 'stripe') {
                app(\App\Services\Marketplace\MarketplaceNativeCheckout::class)->reconcile($order->checkout);
            } else {
                $orders->reconcile(Connection::query()->findOrFail($order->connection_id), $order->provider_order_gid);
            }
        }
        if ($action === 'shipping') {
            $data = $request->validate(['credits' => 'required|array', 'credits.*' => ['required', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/'], 'subsidy_amount' => ['required', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/']]);
            $subsidy = MarketplaceShopify::cents($data['subsidy_amount']);
            if ($subsidy > 0) {
                abort_unless($request->boolean('subsidy_approved'), 422, 'Approve the marketplace-funded shipping amount.');
            }
            $orders->allocateShipping($order, array_map([MarketplaceShopify::class, 'cents'], $data['credits']), $subsidy, $request->user()->id);
        }
        if ($action === 'review') {
            abort_if($orders->providerWorkPending($order), 409, 'Reconcile outstanding original-provider operations before resolving this review.');
            abort_if(! empty($order->fulfillment_details['unallocated_refunds']), 409, 'Reconcile external refunds with their original line and supplier obligations before releasing this order.');
            abort_if($order->provider === 'paypal' && in_array($order->review_reason, ['PayPal tax posting needs reconciliation.', 'PayPal refund needs reconciliation.'], true), 409, 'Complete PayPal reconciliation before resolving this review.');
            abort_if($order->provider === 'shop_pay' && $order->review_reason === 'Wallet tax posting needs reconciliation.', 409, 'Complete the Wallet tax reconciliation first.');
            $reason = $request->validate(['reason' => 'required|string|min:12|max:3000'])['reason'];
            abort_unless($order->lines()->count() === count($order->checkout->snapshot['lines']), 422, 'Resolve the provider item mismatch through the original payment first.');
            $order->forceFill(['review_required' => false, 'review_reason' => null])->save();
            Audit::query()->create(['actor_user_id' => $request->user()->id, 'action' => 'order.review_resolved', 'subject_id' => $order->id, 'details' => ['reason' => $reason]]);
            $orders->releaseEarnings($order);
        }
        if ($action === 'pickup_update') {
            abort_unless($order->group_key !== 'shipping', 422);
            $pickup = $request->validate(['pickup_name' => 'required|string|max:160', 'pickup_address' => 'required|string|max:3000', 'pickup_instructions' => 'required|string|max:3000']);
            $order->forceFill(['fulfillment_details' => [...($order->fulfillment_details ?? []), 'pickup' => ['name' => $pickup['pickup_name'], 'address' => $pickup['pickup_address'], 'instructions' => $pickup['pickup_instructions']]]])->save();
            Audit::create(['actor_user_id' => $request->user()->id, 'action' => 'order.pickup_updated', 'subject_id' => $order->id, 'details' => ['review_required' => $order->review_required]]);
        }
        if ($action === 'cost') {
            $value = MarketplaceShopify::cents($request->validate(['processing_cost' => ['required', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/']])['processing_cost']);
            $order->forceFill(['processing_cost_cents' => $value])->save();
            Audit::query()->create(['actor_user_id' => $request->user()->id, 'action' => 'order.processing_cost_reconciled', 'subject_id' => $id, 'details' => ['cost_cents' => $value]]);
        }
        if ($action === 'shipping_refund') {
            $data = $request->validate(['refund_gid' => 'required|string|max:100', 'credits' => 'required|array', 'credits.*' => ['required', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/'], 'platform_cost' => ['required', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/']]);
            $orders->allocateShippingRefund($order, $data['refund_gid'], array_map([MarketplaceShopify::class, 'cents'], $data['credits']), MarketplaceShopify::cents($data['platform_cost']), $request->user()->id);
        }

        return back()->with('status', 'Order updated.');
    }

    public function previewRefund(Request $request, int $id, MarketplaceFulfillment $fulfillment)
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:99', 'shipping_amount' => ['required', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/']]);
        $line = OrderLine::query()->with('order')->findOrFail($id);
        $preview = $fulfillment->refundPreview($line, $data['quantity'], MarketplaceShopify::cents($data['shipping_amount']));

        return view('marketplace.refund-confirm', ['line' => $line, 'preview' => $preview]);
    }

    public function refund(Request $request, int $id, MarketplaceFulfillment $fulfillment)
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:99', 'shipping_cents' => 'required|integer|min:0', 'expected_cents' => 'required|integer|min:1', 'uuid' => 'required|uuid', 'confirm' => 'required|accepted']);
        $fulfillment->refund(OrderLine::query()->findOrFail($id), $data['quantity'], $data['shipping_cents'], $data['expected_cents'], $data['uuid'], $request->user()->id);

        return redirect('/marketplace/operations?tab=orders')->with('status', 'Refund sent to the original provider. Supplier adjustments follow verified refund completion.');
    }

    public function statement(Request $request, MarketplaceOrders $orders)
    {
        $data = $request->validate(['action' => 'required|in:create,approve,paid', 'maker_id' => 'nullable|integer', 'statement_id' => 'nullable|integer', 'bank_reference' => 'nullable|string|max:160', 'expected_cents' => 'nullable|integer']);
        if ($data['action'] === 'create') {
            $orders->statement(Maker::query()->findOrFail($data['maker_id'] ?? 0)->id, $request->user()->id);
        } else {
            $statement = Statement::query()->findOrFail($data['statement_id'] ?? 0);
            if ($data['action'] === 'approve') {
                $orders->approveStatement($statement, $request->user()->id);
            } else {
                $orders->recordPayment($statement, $request->user()->id, $data['bank_reference'] ?? '', $data['expected_cents'] ?? 0);
            }
        }

        return back()->with('status', 'Supplier statement updated.');
    }

    public function retryEvent(int $id, ShopifyWebhookController $webhooks)
    {
        $webhooks->process(WebhookEvent::query()->findOrFail($id));

        return back()->with('status', 'Stored webhook reconciliation attempted. No payment request was replayed.');
    }

    private function locationIdentity(string $gid, MarketplaceShopify $shopify): string
    {
        abort_unless(preg_match('#^gid://shopify/Location/[0-9]+$#', $gid), 422);
        $data = $shopify->graph($shopify->current(), 'query MarketplaceLocation($id: ID!) { location(id: $id) { id isActive } }', ['id' => $gid]);
        abort_unless(($data['location']['id'] ?? '') === $gid && ! empty($data['location']['isActive']), 422, 'Choose an active location in the dedicated Shopify store.');

        return $gid;
    }
}
