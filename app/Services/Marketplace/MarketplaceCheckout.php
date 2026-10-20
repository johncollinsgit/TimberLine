<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\Connection;
use App\Models\Marketplace\InventoryAllocation;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\PickupLocation;
use App\Models\Marketplace\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MarketplaceCheckout
{
    public function __construct(private MarketplaceAccess $access, private MarketplaceRates $rates, private MarketplaceShopify $shopify) {}

    public function blockers(?Connection $connection = null): array
    {
        if (config('marketplace.checkout_provider') === 'everbranch') {
            return app(MarketplaceNativeCheckout::class)->blockers();
        }
        $settings = $this->access->settings();
        $connection ??= Connection::query()->find($settings->connection_id);
        $reasons = [];
        if (! config('marketplace.enabled') || ! config('marketplace.checkout_enabled') || ! $settings->checkout_enabled) {
            $reasons[] = 'Checkout is not enabled for the launch yet.';
        }
        if (! $connection?->verified_at) {
            $reasons[] = 'Connect and verify the dedicated Shopify store.';
        }
        foreach (['merchant_setup', 'shipping', 'tax', 'guest_checkout', 'policies'] as $key) {
            if (empty($settings->readiness[$key])) {
                $reasons[] = 'Complete '.str_replace('_', ' ', $key).' setup.';
            }
        }
        if (! $settings->shipping_policy || ! $settings->return_policy || ! $settings->privacy_policy || ! filter_var($settings->support_email, FILTER_VALIDATE_EMAIL)) {
            $reasons[] = 'Publish shipping, returns, privacy, and support information.';
        }
        if (empty($connection?->credentials['webhook_secret'])) {
            $reasons[] = 'Configure signed Shopify webhooks.';
        }
        if ($connection && ! $connection->test_mode && ($settings->readiness['test_paid_shop_gid'] ?? null) !== $connection->shop_gid) {
            $reasons[] = 'Complete and reconcile a signed test checkout for this store first.';
        }
        if ($connection && ! $connection->test_mode && ($settings->readiness['signed_test_secret_hash'] ?? '') !== hash('sha256', $connection->credentials['webhook_secret'] ?? '')) {
            $reasons[] = 'Verify a signed test payment using this installed app’s webhook secret.';
        }

        return $reasons;
    }

    public function owner(Request $request): string
    {
        if (! $request->session()->has('marketplace_cart_owner')) {
            $request->session()->put('marketplace_cart_owner', Str::random(64));
        }

        return hash('sha256', $request->session()->get('marketplace_cart_owner'));
    }

    public function cleanPaidGroups(Request $request): void
    {
        $done = $request->session()->get('marketplace_cleared_checkouts', []);
        $cart = $request->session()->get('marketplace_cart', []);
        foreach (Checkout::query()->where('session_hash', $this->owner($request))->where('status', 'paid')->whereNotIn('id', $done)->get() as $checkout) {
            foreach ($checkout->snapshot['lines'] as $line) {
                $key = $line['cart_key'];
                if (isset($cart[$key])) {
                    $cart[$key]['quantity'] -= $line['quantity'];
                    if ($cart[$key]['quantity'] <= 0) {
                        unset($cart[$key]);
                    }
                }
            }
            $done[] = $checkout->id;
        }
        $request->session()->put('marketplace_cart', $cart);
        // These are server-side session records. Dropping older IDs would apply
        // an old paid checkout again to a shopper's newly added products.
        $request->session()->put('marketplace_cleared_checkouts', $done);
    }

    public function groups(Request $request): array
    {
        $this->cleanPaidGroups($request);
        $groups = [];
        foreach ($request->session()->get('marketplace_cart', []) as $key => $entry) {
            $listing = Listing::query()->with('maker')->whereKey($entry['listing_id'])->first();
            if (! $listing) {
                continue;
            }
            $group = $entry['group'];
            $groups[$group] ??= ['key' => $group, 'label' => $group === 'shipping' ? 'Ship to you' : (PickupLocation::query()->find((int) substr($group, 7))?->name ?? 'Pickup unavailable'), 'lines' => [], 'subtotal_cents' => 0];
            $groups[$group]['lines'][] = ['key' => $key, 'listing' => $listing, 'quantity' => (int) $entry['quantity']];
            $groups[$group]['subtotal_cents'] += $listing->price_cents * $entry['quantity'];
        }

        return $groups;
    }

    public function add(Request $request, Listing $listing, int $quantity, string $group): void
    {
        $this->assertAvailable($listing, $group);
        $cart = $request->session()->get('marketplace_cart', []);
        $key = $listing->id.':'.$group;
        $total = (int) ($cart[$key]['quantity'] ?? 0) + $quantity;
        abort_unless($quantity > 0 && $total <= 99 && count($cart) < 100, 422);
        $cart[$key] = ['listing_id' => $listing->id, 'quantity' => $total, 'group' => $group];
        $request->session()->put('marketplace_cart', $cart);
    }

    public function begin(Request $request, string $group, string $uuid): Checkout
    {
        if (config('marketplace.checkout_provider') === 'everbranch') {
            return app(MarketplaceNativeCheckout::class)->begin($request, $group, $uuid);
        }
        if ($blockers = $this->blockers()) {
            throw ValidationException::withMessages(['checkout' => 'Checkout is being prepared. Please try again when the marketplace opens.']);
        }
        abort_unless(Str::isUuid($uuid), 422);
        $connection = $this->shopify->current();
        $owner = $this->owner($request);
        $groups = $this->groups($request);
        abort_unless(isset($groups[$group]) && count($groups[$group]['lines']) <= 100, 422, 'This checkout group is empty.');
        $selection = array_map(fn ($line) => ['cart_key' => $line['key'], 'listing_id' => $line['listing']->id, 'quantity' => $line['quantity']], $groups[$group]['lines']);
        $fingerprint = hash('sha256', json_encode($selection));
        $checkout = DB::transaction(function () use ($request, $group, $uuid, $connection, $owner, $selection, $fingerprint): Checkout {
            Setting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $existing = Checkout::query()->where('uuid', $uuid)->first();
            if ($existing) {
                abort_unless(hash_equals($owner, $existing->session_hash) && $existing->group_key === $group && ($existing->snapshot['fingerprint'] ?? '') === $fingerprint, 409);

                return $existing;
            }
            foreach (Checkout::query()->where('session_hash', $owner)->where('group_key', $group)->whereIn('status', ['creating', 'ready', 'unknown'])->where('expires_at', '>', now())->latest('id')->get() as $pending) {
                if (($pending->snapshot['fingerprint'] ?? '') === $fingerprint) {
                    return $pending;
                }
            }
            $lines = [];
            foreach ($selection as $entry) {
                $listing = Listing::query()->with('maker')->lockForUpdate()->findOrFail($entry['listing_id']);
                $this->assertAvailable($listing, $group);
                abort_unless((int) $listing->connection_id === (int) $connection->id && $listing->shopify_variant_gid && $listing->published_revision === $listing->revision, 422, 'An item is waiting for catalog synchronization.');
                abort_if(InventoryAllocation::query()->where('listing_id', $listing->id)->whereNull('applied_at')->exists(), 422, 'An item’s stock is being updated.');
                $rate = $this->rates->resolve($listing);
                abort_unless($this->rates->accepted($listing, $rate), 422, 'A maker is reviewing updated terms.');
                $levels = $this->shopify->inventory($listing);
                $location = $group === 'shipping' ? $listing->shipping_location_gid : PickupLocation::query()->findOrFail((int) substr($group, 7))->shopify_location_gid;
                abort_unless(($levels[$location] ?? 0) >= $entry['quantity'], 422, 'An item no longer has enough stock for this delivery option.');
                $lines[] = [...$entry, ...$this->rates->amounts($listing->price_cents, $rate), 'maker_id' => $listing->maker_id, 'title' => $listing->title, 'variant_gid' => $listing->shopify_variant_gid, 'location_gid' => $location];
            }

            return Checkout::query()->create(['uuid' => $uuid, 'session_hash' => $owner, 'identity_id' => $this->access->identity($request)?->id, 'connection_id' => $connection->id, 'group_key' => $group, 'status' => 'creating', 'snapshot' => ['fingerprint' => $fingerprint, 'lines' => $lines, 'currency' => 'USD', 'subsidy_limit_cents' => (int) ($this->access->settings()->readiness['promotion_subsidy_limit_cents'] ?? 0)], 'expires_at' => now()->addDays(10)]);
        });
        if ($checkout->status === 'ready') {
            return $checkout;
        }
        if (! $checkout->wasRecentlyCreated) {
            throw ValidationException::withMessages(['checkout' => 'Your checkout is being reconciled. A second checkout will not be created.']);
        }
        $input = ['attributes' => [['key' => '_mm_checkout', 'value' => $checkout->uuid]], 'buyerIdentity' => ['countryCode' => 'US'], 'lines' => array_map(fn ($line) => ['merchandiseId' => $line['variant_gid'], 'quantity' => $line['quantity'], 'attributes' => [['key' => '_mm_line', 'value' => $line['cart_key']]]], $checkout->snapshot['lines'])];
        if ($identity = $this->access->identity($request)) {
            $input['buyerIdentity']['email'] = $identity->email;
        }
        if ($group !== 'shipping') {
            $input['buyerIdentity']['preferences']['delivery'] = ['deliveryMethod' => ['PICK_UP'], 'pickupHandle' => [$checkout->snapshot['lines'][0]['location_gid']]];
        }
        try {
            $result = $this->shopify->graph($connection, 'mutation MarketplaceCart($input: CartInput!) { cartCreate(input: $input) { cart { id checkoutUrl cost { subtotalAmount { amount currencyCode } } lines(first: 100) { nodes { quantity merchandise { ... on ProductVariant { id } } } } } userErrors { field message } } }', ['input' => $input], true, $request->ip());
            $cart = $result['cartCreate']['cart'] ?? null;
            if (! empty($result['cartCreate']['userErrors']) || ! $cart || ! $this->shopify->validCheckoutUrl($connection, $cart['checkoutUrl'] ?? '') || ($cart['cost']['subtotalAmount']['currencyCode'] ?? '') !== 'USD') {
                throw ValidationException::withMessages(['checkout' => 'Shopify could not prepare this cart.']);
            }
            $expectedLines = collect($checkout->snapshot['lines'])->keyBy('variant_gid');
            $providerLines = collect($cart['lines']['nodes'] ?? []);
            $matched = $providerLines->count() === $expectedLines->count() && $providerLines->every(fn ($line) => isset($expectedLines[$line['merchandise']['id'] ?? '']) && $expectedLines[$line['merchandise']['id']]['quantity'] === $line['quantity']);
            $gross = $expectedLines->sum(fn ($line) => $line['price_cents'] * $line['quantity']);
            $margin = $expectedLines->sum(fn ($line) => $line['fee_cents'] * $line['quantity']);
            $subtotal = MarketplaceShopify::cents($cart['cost']['subtotalAmount']['amount']);
            if (! $matched || $subtotal > $gross || $subtotal < $gross - $margin - $checkout->snapshot['subsidy_limit_cents']) {
                throw ValidationException::withMessages(['checkout' => 'The provider cart changed. Please ask us to review the items or promotion before paying.']);
            }
            $checkout->forceFill(['status' => 'ready', 'provider_cart' => ['id' => $cart['id'], 'url' => $cart['checkoutUrl']]])->save();
        } catch (Throwable $error) {
            $checkout->forceFill(['status' => 'unknown', 'failure_code' => 'cart_not_confirmed'])->save();
            throw $error;
        }

        return $checkout;
    }

    private function assertAvailable(Listing $listing, string $group): void
    {
        abort_unless($listing->status === 'approved' && $listing->maker->status === 'approved', 422, 'This item is not available.');
        if ($group === 'shipping') {
            abort_unless($listing->shipping, 422, 'This item is available for pickup only.');
        } else {
            abort_unless(app(MarketplaceMarkets::class)->pickupAvailable($listing->maker, (int) substr($group, 7)), 422, 'This market pickup is no longer available.');
            abort_unless(preg_match('/^pickup:[1-9][0-9]*$/', $group) && in_array((int) substr($group, 7), array_map('intval', $listing->pickup_location_ids ?? []), true) && PickupLocation::query()->whereKey((int) substr($group, 7))->where('active', true)->exists(), 422, 'Select an available pickup location.');
        }
    }
}
