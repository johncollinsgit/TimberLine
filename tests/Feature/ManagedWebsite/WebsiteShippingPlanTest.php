<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantPaymentAccount;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteCartItem;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProduct;
use App\Models\WebsiteShippingPackage;
use App\Models\WebsiteShippingRateQuote;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCatalogService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsiteCommerceShippingService;
use App\Services\ManagedWebsite\WebsiteShippingPlanService;
use App\Services\ManagedWebsite\WebsiteShippingSetupService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WebsiteEasyPostFixture;
use Tests\Support\WebsiteStripeFixture;

beforeEach(function (): void {
    config()->set('managed_website.stripe_webhook_secret', 'whsec_website_fixture');
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Shipping pilot', 'slug' => 'shipping-pilot']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    TenantPaymentAccount::query()->create(['tenant_id' => $this->tenant->id, 'provider_account_id' => 'acct_pilot', 'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true]);
    config()->set([
        'managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true,
        'managed_website.commerce_enabled' => true, 'managed_website.commerce_shipping_enabled' => true,
        'managed_website.editor_tenant_ids' => [$this->tenant->id], 'managed_website.commerce_shipping_tenant_ids' => [$this->tenant->id],
        'services.stripe.secret' => 'sk_test_pilot',
        'commercial.billing_readiness.tenant_payments' => ['enabled' => true, 'tenant_slugs' => ['shipping-pilot'], 'connect_webhooks_verified' => true, 'automatic_tax_enabled' => true, 'tax_decision_confirmed' => true],
    ]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    $this->connection = IntegrationConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'easypost', 'status' => IntegrationConnection::STATUS_CONNECTED, 'access_token' => 'EZTK_private_fixture', 'external_account_secret' => 'fixture-carrier-webhook']);
    $this->setup = app(WebsiteShippingSetupService::class);
    $this->setup->savePolicy($this->site, ['mode' => 'test', 'services' => ['USPS:GroundAdvantage', 'USPS:Priority']], $this->actor);
    $this->setup->saveLocation($this->site, ['name' => 'Warehouse', 'street1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701', 'is_default' => true], $this->actor);
    $this->packageData = ['name' => 'Small parcel', 'length_inches' => 12, 'width_inches' => 10, 'height_inches' => 6, 'weight_ounces' => 3,
        'inner_length_inches' => 10, 'inner_width_inches' => 8, 'inner_height_inches' => 4, 'max_items' => 4, 'max_weight_ounces' => 64, 'is_default' => true];
    $this->package = $this->setup->savePackage($this->site, $this->packageData, $this->actor);
    $this->commerce = app(WebsiteCommerceService::class);
    $this->shipping = app(WebsiteCommerceShippingService::class);
    $this->plans = app(WebsiteShippingPlanService::class);
    $this->product = $this->commerce->saveProduct($this->site, ['title' => 'Notebook', 'product_type' => 'physical', 'status' => 'active', 'price' => '20.00',
        'shipping_weight_ounces' => 12, 'shipping_length_inches' => 3, 'shipping_width_inches' => 2, 'shipping_height_inches' => 1, 'is_available' => true]);
    $this->variant = $this->product->variants->first();
    $this->cart = $this->commerce->addToCart($this->commerce->cartFor($this->site, null), $this->variant, 1);
    $this->destination = ['name' => 'Buyer', 'street1' => '10 Market St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78702', 'country' => 'US'];
    $this->buyer = ['name' => 'Buyer', 'email' => 'buyer@example.test', 'fulfillment_method' => 'ship', 'shipping_address' => $this->destination];
    $this->carrierResponse = shippingPlanResponse();
    $this->afterRateRequest = null;
    Http::preventStrayRequests();
    Http::fake([...WebsiteStripeFixture::support(),
        'https://api.easypost.com/v2/shipments' => function () {
            if ($this->afterRateRequest) {
                ($this->afterRateRequest)();
            }

            return Http::response($this->carrierResponse);
        },
        'https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_pilot', 'url' => 'https://checkout.stripe.com/pilot']),
        'https://api.easypost.com/v2/shipments/shp_pilot' => fn () => Http::response(WebsiteEasyPostFixture::shipment(App\Models\WebsiteShipment::query()->where('provider_shipment_id', 'shp_pilot')->firstOrFail())),
        'https://api.easypost.com/v2/shipments/shp_pilot/buy' => fn () => Http::response(WebsiteEasyPostFixture::shipment(App\Models\WebsiteShipment::query()->where('provider_shipment_id', 'shp_pilot')->firstOrFail(), true)),
    ]);
});

function shippingPlanResponse(array $rates = []): array
{
    return ['id' => 'shp_pilot', 'mode' => 'test', 'rates' => $rates ?: [['id' => 'rate_pilot', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '5.25000', 'currency' => 'USD']]];
}

test('free customer shipping retains the carrier cost in the accepted order plan', function (): void {
    $this->setup->savePolicy($this->site, ['mode' => 'test', 'services' => ['USPS:GroundAdvantage'], 'charge_mode' => 'free', 'states' => ['TX']], $this->actor);
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    $order = $this->commerce->beginCheckout($this->cart, $this->buyer + ['shipping_rate_quote_id' => $quote->id], 'https://pilot.test/success', 'https://pilot.test/cart')['order'];
    expect($quote->amount_cents)->toBe(525)->and($order->shipping_cents)->toBe(0)
        ->and($order->shipping_rate_snapshot['amount_cents'])->toBe(525)->and($order->shipping_rate_snapshot['customer_shipping_cents'])->toBe(0)
        ->and(data_get($order->shipping_rate_snapshot, 'plan.mode'))->toBe('test');
});

test('shipping region changes invalidate unpaid quotes and reject excluded destinations before HTTP', function (): void {
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    $this->setup->savePolicy($this->site, ['mode' => 'test', 'services' => ['USPS:GroundAdvantage'], 'charge_mode' => 'calculated', 'states' => ['TX']], $this->actor);
    expect(fn () => $this->plans->selectedQuote($this->cart, $quote->id, $this->destination))->toThrow(ValidationException::class);
    expect(fn () => $this->shipping->quote($this->cart, array_replace($this->destination, ['state' => 'NY', 'zip' => '10001'])))->toThrow(ValidationException::class);
    Http::assertSentCount(1);
});

test('freight products cannot request a parcel rate or pay through parcel checkout', function (): void {
    $this->variant->update(['options' => ['shipping_mode' => 'ltl']]);
    expect(fn () => $this->shipping->quote($this->cart, $this->destination))->toThrow(ValidationException::class, 'freight arrangement');
    expect(fn () => $this->commerce->beginCheckout($this->cart, $this->buyer, 'https://pilot.test/success', 'https://pilot.test/cart'))->toThrow(ValidationException::class, 'freight arrangement');
    Http::assertNothingSent();
});

test('customer-charge policy cannot silently save with every shipping state unchecked', function (): void {
    expect(fn () => $this->setup->savePolicy($this->site, ['mode' => 'test', 'services' => ['USPS:GroundAdvantage'], 'charge_mode' => 'free'], $this->actor))->toThrow(ValidationException::class);
    expect(data_get($this->site->fresh()->settings, 'shipping.charge_mode'))->toBeNull();
});

test('carrier request uses the shipment envelope measured contents and package tare', function (): void {
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    Http::assertSent(fn ($request) => $request->url() === 'https://api.easypost.com/v2/shipments'
        && $request['shipment']['parcel'] === ['length' => 12, 'width' => 10, 'height' => 6, 'weight' => 15]
        && $request['shipment']['from_address']['zip'] === '78701' && ! isset($request['parcel']));
    expect($quote->amount_cents)->toBe(525)->and(data_get($quote->parcel, '_shipping_plan.package_id'))->toBe($this->package->id)
        ->and(json_encode($quote->parcel))->not->toContain('EZTK_private_fixture');
    $this->commerce->setCartQuantity($this->cart, $this->cart->items->first()->id, 3);
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    expect($quote->parcel['weight'])->toBe(39);
});

test('mixed measured products use a supported stack and never omit item or packing weight', function (): void {
    $product = $this->commerce->saveProduct($this->site, ['title' => 'Soap', 'product_type' => 'physical', 'status' => 'active', 'price' => '10.00',
        'shipping_weight_ounces' => 4, 'shipping_length_inches' => 2, 'shipping_width_inches' => 2, 'shipping_height_inches' => 2, 'is_available' => true]);
    $this->cart = $this->commerce->addToCart($this->cart, $product->variants->first(), 1);
    expect($this->shipping->quote($this->cart, $this->destination)[0]->parcel['weight'])->toBe(19);
});

test('unsupported contents stop before a carrier request', function (string $change): void {
    match ($change) {
        'dimensions' => $this->variant->update(['shipping_length_inches' => null]),
        'oversize' => $this->variant->update(['shipping_length_inches' => 50]),
        'weight' => $this->variant->update(['shipping_weight_ounces' => 80]),
        'quantity' => $this->cart->items->first()->update(['quantity' => 5]),
        'stack' => $this->variant->update(['shipping_length_inches' => 8, 'shipping_width_inches' => 8, 'shipping_height_inches' => 3]) && $this->cart->items->first()->update(['quantity' => 2]),
        'separate' => $this->variant->update(['options' => ['shipping_separately' => true]]) && $this->cart->items->first()->update(['quantity' => 2]),
        'unreviewed' => $this->site->update(['settings' => ['shipping' => ['mode' => 'test', 'services' => ['USPS:GroundAdvantage']]]]),
    };
    expect(fn () => $this->shipping->quote($this->cart, $this->destination))->toThrow($change === 'separate' ? HttpException::class : ValidationException::class);
    Http::assertNothingSent();
})->with(['dimensions', 'oversize', 'weight', 'quantity', 'stack', 'separate', 'unreviewed']);

test('merchant tested capacity supports missing dimensions without guessing measurements', function (): void {
    $this->variant->update(['shipping_length_inches' => null, 'shipping_width_inches' => null, 'shipping_height_inches' => null]);
    $data = $this->packageData + ['approved_variant_ids' => [$this->variant->id], 'confirmed_capacity' => true];
    $approved = $this->setup->savePackage($this->site, $data, $this->actor);
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    expect(data_get($quote->parcel, '_shipping_plan.package_id'))->toBe($approved->id)
        ->and($this->variant->fresh()->shipping_length_inches)->toBeNull();
});

test('rates exclude unapproved services foreign currency negative prices and wrong shipments', function (): void {
    $rate = shippingPlanResponse()['rates'][0];
    $this->carrierResponse = shippingPlanResponse([
        $rate, array_replace($rate, ['id' => 'rate_express', 'service' => 'Express']), array_replace($rate, ['id' => 'rate_cad', 'currency' => 'CAD']),
        array_replace($rate, ['id' => 'rate_negative', 'rate' => '-5']), array_replace($rate, ['id' => 'rate_other', 'shipment_id' => 'shp_other']),
    ]);
    expect($this->shipping->quote($this->cart, $this->destination))->toHaveCount(1);
});

test('carrier test and production modes cannot be mixed', function (): void {
    $this->carrierResponse['mode'] = 'production';
    expect(fn () => $this->shipping->quote($this->cart, $this->destination))->toThrow(HttpException::class);
    expect(WebsiteShippingRateQuote::query()->count())->toBe(0);
});

test('declared carrier credential mode is checked before contacting the provider and successful rates verify health', function (): void {
    $this->connection->update(['metadata' => ['native_shipping' => ['mode' => 'production']]]);
    expect(fn () => $this->shipping->quote($this->cart, $this->destination))->toThrow(HttpException::class);
    Http::assertNothingSent();
    $this->connection->update(['metadata' => ['native_shipping' => ['mode' => 'test']]]);
    $fingerprint = $this->plans->accountFingerprint($this->connection->fresh());
    $this->shipping->quote($this->cart, $this->destination);
    expect(data_get($this->connection->fresh()->metadata, 'native_shipping.verified_at'))->not->toBeNull()
        ->and(data_get($this->connection->fresh()->metadata, 'native_shipping.verified_mode'))->toBe('test')
        ->and($this->plans->accountFingerprint($this->connection->fresh()))->toBe($fingerprint);
});

test('material changes invalidate a quote before payment', function (string $change): void {
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    match ($change) {
        'weight' => $this->variant->update(['shipping_weight_ounces' => 13]),
        'dimensions' => $this->variant->update(['shipping_length_inches' => 4]),
        'price' => $this->variant->update(['price_cents' => 2100]),
        'quantity' => $this->cart->items->first()->update(['quantity' => 2]),
        'package' => $this->package->update(['weight_ounces' => 4]),
        'origin' => WebsiteFulfillmentLocation::query()->first()->update(['address' => array_replace(WebsiteFulfillmentLocation::query()->first()->address, ['street1' => '2 Main St'])]),
        'account' => $this->connection->update(['access_token' => 'EZTK_new_key']),
        'policy' => $this->setup->savePolicy($this->site, ['mode' => 'test', 'services' => ['USPS:GroundAdvantage']], $this->actor),
    };
    expect(fn () => $this->commerce->beginCheckout($this->cart, $this->buyer + ['shipping_rate_quote_id' => $quote->id], 'https://pilot.test/success', 'https://pilot.test/cart'))->toThrow(ValidationException::class);
    expect(WebsiteOrder::query()->count())->toBe(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'stripe.com'));
})->with(['weight', 'dimensions', 'price', 'quantity', 'package', 'origin', 'account', 'policy']);

test('checkout rejects a changed destination and saves the accepted address and complete order plan', function (): void {
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    $buyer = array_replace($this->buyer, ['shipping_rate_quote_id' => $quote->id, 'shipping_address' => array_replace($this->destination, ['zip' => '90210'])]);
    expect(fn () => $this->commerce->beginCheckout($this->cart, $buyer, 'https://pilot.test/success', 'https://pilot.test/cart'))->toThrow(ValidationException::class);
    expect(WebsiteOrder::query()->count())->toBe(0);
    $buyer['shipping_address'] = array_map('strtolower', $this->destination);
    $result = $this->commerce->beginCheckout($this->cart, $buyer, 'https://pilot.test/success', 'https://pilot.test/cart');
    expect($result['order']->shipping_address)->toBe($quote->destination)
        ->and(data_get($result['order']->shipping_rate_snapshot, 'parcel.weight'))->toBe(15)
        ->and(data_get($result['order']->shipping_rate_snapshot, 'plan.connection_id'))->toBe($this->connection->id)
        ->and($result['order']->shipping_cents)->toBe(525);
});

test('paid carts are frozen and an expired quote still supplies the original label plan', function (): void {
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    $order = $this->commerce->beginCheckout($this->cart, $this->buyer + ['shipping_rate_quote_id' => $quote->id], 'https://pilot.test/success', 'https://pilot.test/cart')['order'];
    $snapshot = $order->shipping_rate_snapshot;
    $this->commerce->processStripeEvent(WebsiteStripeFixture::event($order));
    $order->refresh();
    expect(fn () => $this->commerce->setCartQuantity($this->cart, $this->cart->items->first()->id, 2))->toThrow(HttpException::class);
    $quote->update(['expires_at' => now()->subSecond()]);
    expect($quote->fresh()->expires_at->isPast())->toBeTrue()->and(WebsiteShippingRateQuote::query()->whereKey($quote->id)->exists())->toBeTrue();
    $this->variant->update(['shipping_weight_ounces' => 20]);
    $shipment = $this->shipping->purchaseLabel($order, $quote->fresh(), $this->actor);
    expect($shipment->parcel['weight'])->toBe(15)->and($order->fresh()->shipping_rate_snapshot)->toBe($snapshot);
});

test('a label cannot use another carts quote even within the same merchant', function (): void {
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    $order = $this->commerce->beginCheckout($this->cart, $this->buyer + ['shipping_rate_quote_id' => $quote->id], 'https://pilot.test/success', 'https://pilot.test/cart')['order'];
    $this->commerce->processStripeEvent(WebsiteStripeFixture::event($order));
    $order->refresh();
    $other = $this->commerce->addToCart($this->commerce->cartFor($this->site, null), $this->variant, 1);
    $otherQuote = $this->shipping->quote($other, $this->destination)[0];
    expect(fn () => $this->shipping->purchaseLabel($order, $otherQuote, $this->actor))->toThrow(HttpException::class);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/buy'));
});

test('a cart change while waiting for carrier rates cannot save a stale quote', function (): void {
    $this->afterRateRequest = function () {
        WebsiteCartItem::query()->where('website_cart_id', $this->cart->id)->update(['quantity' => 2]);

    };
    expect(fn () => $this->shipping->quote($this->cart, $this->destination))->toThrow(HttpException::class);
    expect(WebsiteShippingRateQuote::query()->count())->toBe(0);
});

test('package setup rejects foreign variants and missing tested capacity confirmation atomically', function (): void {
    $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-shipping']);
    $otherSite = TenantSite::query()->create(['tenant_id' => $other->id, 'subdomain' => 'other-shipping']);
    $product = WebsiteProduct::query()->create(['tenant_id' => $other->id, 'tenant_site_id' => $otherSite->id, 'handle' => 'foreign', 'title' => 'Foreign', 'product_type' => 'physical', 'status' => 'active']);
    $variant = $product->variants()->create(['tenant_id' => $other->id, 'title' => 'Default', 'price_cents' => 100, 'is_available' => true]);
    expect(fn () => $this->setup->savePackage($this->site, $this->packageData + ['approved_variant_ids' => [$variant->id], 'confirmed_capacity' => true], $this->actor))->toThrow(ValidationException::class);
    expect(fn () => $this->setup->savePackage($this->site, $this->packageData + ['approved_variant_ids' => [$this->variant->id]], $this->actor))->toThrow(ValidationException::class);
    expect(WebsiteShippingPackage::query()->count())->toBe(1)->and($this->package->fresh()->is_default)->toBeTrue();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    expect(fn () => $this->setup->savePolicy($this->site, ['mode' => 'test', 'services' => ['USPS:Priority']], $this->actor))->toThrow(HttpException::class);
});

test('an immutable order plan remains usable after the cart and quote are deleted', function (): void {
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    $order = $this->commerce->beginCheckout($this->cart, $this->buyer + ['shipping_rate_quote_id' => $quote->id], 'https://pilot.test/success', 'https://pilot.test/cart')['order'];
    $this->commerce->processStripeEvent(WebsiteStripeFixture::event($order));
    $order->refresh();
    $this->cart->delete();
    expect(WebsiteShippingRateQuote::query()->whereKey($quote->id)->exists())->toBeFalse();
    $snapshotQuote = $this->plans->quoteForOrder($order, $quote->id);
    expect($this->shipping->purchaseLabel($order, $snapshotQuote, $this->actor)->tracking_number)->toBe('TEST9400');
});

test('test shipping cannot start a production payment', function (): void {
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    config()->set('services.stripe.secret', 'sk_live_fixture');
    expect(fn () => $this->commerce->beginCheckout($this->cart, $this->buyer + ['shipping_rate_quote_id' => $quote->id], 'https://pilot.test/success', 'https://pilot.test/cart'))->toThrow(HttpException::class);
    expect(WebsiteOrder::query()->count())->toBe(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'stripe.com'));
});

test('another merchants cart cannot use these items or quotes', function (): void {
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    $otherTenant = Tenant::query()->create(['name' => 'Second pilot', 'slug' => 'second-pilot']);
    $otherSite = TenantSite::query()->create(['tenant_id' => $otherTenant->id, 'subdomain' => 'second-pilot']);
    $otherCart = $this->commerce->cartFor($otherSite, null);
    expect(fn () => $this->commerce->addToCart($otherCart, $this->variant, 1))->toThrow(HttpException::class);
    expect(fn () => $this->plans->selectedQuote($otherCart, $quote->id, $this->destination))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('catalog dimensions and separately shipped flags are validated while omitted measurements survive', function (): void {
    $data = ['title' => 'Notebook', 'handle' => 'notebook', 'status' => 'active', 'product_type' => 'physical', 'track_inventory' => false, 'media' => [], 'collection_ids' => [],
        'variants' => [['id' => $this->variant->id, 'title' => 'Default', 'price' => '20.00', 'is_available' => true, 'shipping_separately' => true,
            'shipping_length_inches' => 5, 'shipping_width_inches' => 4, 'shipping_height_inches' => 2]]];
    app(WebsiteCatalogService::class)->saveProduct($this->site, $this->product, $data, $this->actor);
    expect($this->variant->fresh()->shipping_weight_ounces)->toBe(12)->and($this->variant->fresh()->shipping_length_inches)->toBe(5)
        ->and(data_get($this->variant->fresh()->options, 'shipping_separately'))->toBeTrue();
    $data['variants'][0]['shipping_length_inches'] = -1;
    expect(fn () => app(WebsiteCatalogService::class)->saveProduct($this->site, $this->product, $data, $this->actor))->toThrow(ValidationException::class);
});

test('both storefront themes use the published shipping threshold and shared quote controls', function (): void {
    $version = $this->site->siteVersions()->create(['tenant_id' => $this->tenant->id, 'version_number' => 99, 'status' => 'published', 'settings' => ['free_shipping_threshold_cents' => 2000]]);
    $this->site->update(['published_site_version_id' => $version->id]);
    foreach (['standard', 'sawyer-naturals'] as $theme) {
        $site = $this->site->fresh();
        $site->settings = array_merge($site->settings ?? [], ['theme_key' => $theme]);
        $html = view('managed-website.cart', ['site' => $site, 'cart' => $this->cart, 'shippingEnabled' => true, 'checkoutReady' => true, 'missingShippingWeights' => collect(), 'errors' => new Illuminate\Support\ViewErrorBag])->render();
        expect($html)->toContain('data-find-rates')->toContain('data-threshold="2000"')->toContain('shipping_rate_quote_id')->not->toContain('$49.99');
    }
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    $order = $this->commerce->beginCheckout($this->cart, $this->buyer + ['shipping_rate_quote_id' => $quote->id], 'https://pilot.test/success', 'https://pilot.test/cart')['order'];
    expect($order->shipping_cents)->toBe(0)->and(data_get($order->shipping_rate_snapshot, 'amount_cents'))->toBe(525);
});

test('shipping setup routes require an editor and retain audited scoped package rules', function (): void {
    $this->actingAs($this->actor)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->get(route('managed-website.products.index', ['tenant' => $this->tenant->slug]))->assertOk();
    $this->get(route('managed-website.commerce.shipping'))->assertOk()->assertSeeText('Reviewed package presets')->assertSeeText('Maximum packed weight (oz)');
    $this->postJson(route('managed-website.commerce.shipping.policy.store'), ['mode' => 'test', 'services' => ['USPS:Express']])->assertRedirect();
    expect(data_get($this->site->fresh()->settings, 'shipping.services'))->toBe(['USPS:Express']);
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    $this->postJson(route('managed-website.commerce.shipping.policy.store'), ['mode' => 'production', 'services' => ['USPS:Express']])->assertForbidden();
    expect(data_get($this->site->fresh()->settings, 'shipping.mode'))->toBe('test');
});

test('an expired quote gives an actionable error and preserves its evidence', function (): void {
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    $quote->update(['expires_at' => now()->subSecond()]);
    expect(fn () => $this->commerce->beginCheckout($this->cart, $this->buyer + ['shipping_rate_quote_id' => $quote->id], 'https://pilot.test/success', 'https://pilot.test/cart'))->toThrow(ValidationException::class, 'expired');
    expect(WebsiteShippingRateQuote::query()->whereKey($quote->id)->exists())->toBeTrue()->and(WebsiteOrder::query()->count())->toBe(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'stripe.com'));
});

test('approved USPS UPS and FedEx services are priced in ascending order without exposing unsupported services', function (): void {
    $this->setup->savePolicy($this->site, ['mode' => 'test', 'services' => ['USPS:GroundAdvantage', 'UPS:Ground', 'FedEx:GROUND_HOME_DELIVERY']], $this->actor);
    $this->carrierResponse = shippingPlanResponse([
        ['id' => 'rate_usps', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '9.25', 'currency' => 'USD'],
        ['id' => 'rate_fedex', 'carrier' => 'FedEx', 'service' => 'GROUND_HOME_DELIVERY', 'rate' => '7.25', 'currency' => 'USD'],
        ['id' => 'rate_ups', 'carrier' => 'UPS', 'service' => 'Ground', 'rate' => '5.25', 'currency' => 'USD'],
        ['id' => 'rate_unapproved', 'carrier' => 'UPS', 'service' => 'NextDayAir', 'rate' => '3.25', 'currency' => 'USD'],
    ]);
    expect(collect($this->shipping->quote($this->cart, $this->destination))->pluck('carrier')->all())->toBe(['UPS', 'FedEx', 'USPS']);
});

test('each approved parcel carrier completes payment label purchase reprint evidence and confirmed handoff', function (string $carrier, string $service): void {
    $this->setup->savePolicy($this->site, ['mode' => 'test', 'services' => [$carrier.':'.$service]], $this->actor);
    $this->carrierResponse = shippingPlanResponse([['id' => 'rate_pilot', 'carrier' => $carrier, 'service' => $service, 'rate' => '5.25', 'currency' => 'USD']]);
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    $order = $this->commerce->beginCheckout($this->cart, $this->buyer + ['shipping_rate_quote_id' => $quote->id], 'https://pilot.test/success', 'https://pilot.test/cart')['order'];
    $this->commerce->processStripeEvent(WebsiteStripeFixture::event($order));
    $shipment = $this->shipping->purchaseLabel($order->fresh(), $quote, $this->actor);
    $lifecycle = app(\App\Services\ManagedWebsite\WebsiteShipmentLifecycleService::class);
    expect($shipment->carrier)->toBe($carrier)->and($shipment->service)->toBe($service)
        ->and($order->fresh()->fulfillment_status)->toBe('unfulfilled')
        ->and(data_get($lifecycle->providerEvidence($shipment), 'postage_label.label_size'))->toBe('4x6');
    expect($this->shipping->purchaseLabel($order->fresh(), $quote, $this->actor)->id)->toBe($shipment->id);
    $lifecycle->pack($shipment, $this->actor);
    $lifecycle->handoff($shipment, $this->actor);
    expect($order->fresh()->fulfillment_status)->toBe('fulfilled');
    expect(collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/buy')))->toHaveCount(1);
})->with([['USPS', 'GroundAdvantage'], ['UPS', 'Ground'], ['FedEx', 'GROUND_HOME_DELIVERY']]);

test('lowest cost comparison retains the exact measured package selected by the buyer', function (): void {
    $this->setup->savePolicy($this->site, ['mode' => 'test', 'services' => ['USPS:GroundAdvantage'], 'package_selection' => 'lowest_cost'], $this->actor);
    $small = $this->setup->savePackage($this->site, array_replace($this->packageData, ['name' => 'Compact', 'length_inches' => 8, 'width_inches' => 6,
        'height_inches' => 4, 'inner_length_inches' => 6, 'inner_width_inches' => 4, 'inner_height_inches' => 3, 'is_default' => false]), $this->actor);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.easypost.com/v2/shipments' => fn ($request) => Http::response([
        'id' => 'shp_size_'.$request['shipment']['parcel']['length'], 'mode' => 'test', 'rates' => [
            ['id' => 'rate_size_'.$request['shipment']['parcel']['length'], 'carrier' => 'USPS', 'service' => 'GroundAdvantage',
                'rate' => $request['shipment']['parcel']['length'] === 8 ? '4.50' : '8.50', 'currency' => 'USD'],
        ],
    ])]);
    $quotes = $this->shipping->quote($this->cart, $this->destination);
    expect($quotes)->toHaveCount(2)->and($quotes[0]->amount_cents)->toBe(450)
        ->and(data_get($quotes[0]->parcel, '_shipping_plan.package_id'))->toBe($small->id)
        ->and($this->plans->selectedQuote($this->cart, $quotes[0]->id, $this->destination)->id)->toBe($quotes[0]->id);
    $this->setup->setActive($this->site, 'packages', $small->id, false, $this->actor);
    expect(fn () => $this->plans->selectedQuote($this->cart, $quotes[0]->id, $this->destination))->toThrow(ValidationException::class);
});

test('residential classification is sent to the carrier and cannot change after rate selection', function (): void {
    $address = $this->destination + ['residential' => '1'];
    $quote = $this->shipping->quote($this->cart, $address)[0];
    Http::assertSent(fn ($request) => $request['shipment']['to_address']['residential'] === true);
    expect($this->plans->selectedQuote($this->cart, $quote->id, $address)->id)->toBe($quote->id);
    expect(fn () => $this->plans->selectedQuote($this->cart, $quote->id, array_replace($address, ['residential' => '0'])))->toThrow(ValidationException::class);
});

test('tenant scoped preset edits reuse the record and preserve purchased order evidence', function (): void {
    $updated = $this->setup->savePackage($this->site, array_replace($this->packageData, ['package_id' => $this->package->id, 'name' => 'Updated box', 'weight_ounces' => 4]), $this->actor);
    expect($updated->id)->toBe($this->package->id)->and(WebsiteShippingPackage::query()->count())->toBe(1)->and($updated->weight_ounces)->toBe(4);
    $this->actingAs($this->actor)->get(route('managed-website.commerce.shipping', ['tenant' => $this->tenant->id, 'package' => $updated->id]))
        ->assertOk()->assertSeeText('Editing Updated box')->assertSee('value="4"', false);
    $other = Tenant::query()->create(['name' => 'Other store', 'slug' => 'other-boxes']);
    $otherSite = TenantSite::query()->create(['tenant_id' => $other->id, 'subdomain' => 'other-boxes']);
    $box = WebsiteShippingPackage::query()->create(['tenant_id' => $other->id, 'tenant_site_id' => $otherSite->id, 'name' => 'Private', 'length_inches' => 1, 'width_inches' => 1, 'height_inches' => 1, 'weight_ounces' => 1]);
    expect(fn () => $this->setup->savePackage($this->site, $this->packageData + ['package_id' => $box->id], $this->actor))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($box->fresh()->name)->toBe('Private');
});

test('JSON object key reordering does not invalidate carrier or order evidence', function (): void {
    $reorder = function (array $value) use (&$reorder): array {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $reorder($item);
            }
        }

        return array_is_list($value) ? $value : array_reverse($value, true);
    };
    $quote = $this->shipping->quote($this->cart, $this->destination)[0];
    $quote->update(['parcel' => $reorder($quote->parcel)]);
    $order = $this->commerce->beginCheckout($this->cart, $this->buyer + ['shipping_rate_quote_id' => $quote->id], 'https://pilot.test/success', 'https://pilot.test/cart')['order'];
    $this->commerce->processStripeEvent(WebsiteStripeFixture::event($order));
    $order->refresh();
    $order->update(['shipping_rate_snapshot' => $reorder($order->shipping_rate_snapshot)]);
    expect($this->shipping->purchaseLabel($order, $quote->fresh(), $this->actor)->tracking_number)->toBe('TEST9400');
});
