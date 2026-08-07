<?php

use App\Models\CommerceExternalRecord;
use App\Models\CommerceImportRun;
use App\Models\IntegrationConnection;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteProduct;
use App\Models\WebsiteShippingPackage;
use App\Services\Commerce\CommerceImportService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsiteCommerceShippingService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('managed_website.commerce_enabled', true);
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
    config()->set('services.stripe.secret', 'sk_test_website');
    config()->set('commercial.billing_readiness.tenant_payments', ['enabled' => true, 'tenant_slugs' => ['commerce-ops'], 'connect_webhooks_verified' => true, 'automatic_tax_enabled' => true, 'tax_decision_confirmed' => true]);
});

function commerceOpsTenant(string $slug = 'commerce-ops'): Tenant
{
    $tenant = Tenant::query()->create(['name' => 'Commerce Operations', 'slug' => $slug]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_paid', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    TenantPaymentAccount::query()->create(['tenant_id' => $tenant->id, 'provider_account_id' => 'acct_'.$slug, 'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true]);

    return $tenant;
}

function commerceOpsActor(Tenant $tenant): User
{
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $actor->tenants()->attach($tenant->id, ['role' => 'admin']);

    return $actor;
}

test('all four sources create capability-aware, read-only dry runs and normalize stable records', function (): void {
    $tenant = commerceOpsTenant();
    $actor = commerceOpsActor($tenant);
    config()->set('managed_website.commerce_imports_enabled', true);
    config()->set('managed_website.commerce_imports_tenant_ids', [$tenant->id]);
    $imports = app(CommerceImportService::class);
    $legacyOrders = Order::query()->count();
    $nativeProducts = WebsiteProduct::query()->count();

    foreach (['shopify', 'woocommerce', 'squarespace', 'wix'] as $provider) {
        $connection = IntegrationConnection::query()->create(['tenant_id' => $tenant->id, 'provider' => $provider, 'external_account_id' => $provider.'-account', 'status' => IntegrationConnection::STATUS_CONNECTED]);
        $run = $imports->createDryRun($tenant, $actor, $provider, ['catalog', 'customers', 'orders', 'content', 'consent'], $connection);
        expect($run->status)->toBe('completed')
            ->and(data_get($run->report, 'write_back'))->toBeFalse()
            ->and(data_get($run->report, 'native_website_tables'))->toBeFalse()
            ->and(data_get($run->report, 'marketing_enrollment'))->toBeFalse();

        $record = $imports->storeSourceRecord($run->source, 'catalog', match ($provider) {
            'shopify' => ['id' => '1001', 'title' => 'Shopify candle', 'updated_at' => '2026-08-07T12:00:00Z', 'access_token' => 'never-store'],
            'woocommerce' => ['id' => 1002, 'name' => 'Woo candle', 'date_modified_gmt' => '2026-08-07T12:00:00Z', 'consumer_secret' => 'never-store'],
            'squarespace' => ['id' => '1003', 'name' => 'Square candle', 'updatedOn' => '2026-08-07T12:00:00Z'],
            default => ['_id' => '1004', 'name' => 'Wix candle', '_updatedDate' => '2026-08-07T12:00:00Z'],
        });
        $again = $imports->storeSourceRecord($run->source, 'catalog', $record->snapshot);
        expect($again->id)->toBe($record->id)
            ->and(data_get($record->snapshot, 'access_token'))->toBeNull()
            ->and(data_get($record->snapshot, 'consumer_secret'))->toBeNull();
    }

    expect(CommerceImportRun::query()->forTenant($tenant)->count())->toBe(4)
        ->and(CommerceExternalRecord::query()->forTenant($tenant)->count())->toBe(4)
        ->and(Order::query()->count())->toBe($legacyOrders)
        ->and(WebsiteProduct::query()->count())->toBe($nativeProducts);
});

test('commerce import gates and source records are tenant isolated', function (): void {
    $tenant = commerceOpsTenant();
    $other = commerceOpsTenant('commerce-ops-other');
    $actor = commerceOpsActor($tenant);
    config()->set('managed_website.commerce_imports_enabled', true);
    config()->set('managed_website.commerce_imports_tenant_ids', [$tenant->id]);
    $imports = app(CommerceImportService::class);
    expect($imports->enabledFor($tenant))->toBeTrue()->and($imports->enabledFor($other))->toBeFalse();
    $run = $imports->createDryRun($tenant, $actor, 'shopify', ['catalog']);
    expect($run->tenant_id)->toBe($tenant->id)
        ->and(CommerceImportRun::query()->forTenant($other)->count())->toBe(0);
});

test('native US shipping quotes, purchases labels, voids safely, and leaves legacy orders alone', function (): void {
    $tenant = commerceOpsTenant();
    $actor = commerceOpsActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    config()->set('managed_website.commerce_shipping_enabled', true);
    config()->set('managed_website.commerce_shipping_tenant_ids', [$tenant->id]);
    IntegrationConnection::query()->create(['tenant_id' => $tenant->id, 'provider' => 'easypost', 'status' => IntegrationConnection::STATUS_CONNECTED, 'access_token' => 'EZTK_test_tenant_owned']);
    $site = app(ManagedWebsiteService::class)->createSite($tenant, $actor);
    WebsiteFulfillmentLocation::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'name' => 'Warehouse', 'address' => ['name' => 'Warehouse', 'street1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701', 'country' => 'US'], 'is_default' => true, 'active' => true]);
    WebsiteShippingPackage::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'name' => 'Standard', 'length_inches' => 10, 'width_inches' => 8, 'height_inches' => 4, 'weight_ounces' => 8, 'is_default' => true, 'active' => true]);
    $commerce = app(WebsiteCommerceService::class);
    $product = $commerce->saveProduct($site, ['title' => 'Field notebook', 'product_type' => 'physical', 'status' => 'active', 'price' => '20.00', 'track_inventory' => true, 'inventory_quantity' => 3, 'shipping_weight_ounces' => 12, 'is_available' => true]);
    $cart = $commerce->addToCart($commerce->cartFor($site, null), $product->variants->firstOrFail(), 1);
    Http::fake([
        'https://api.easypost.com/v2/shipments' => Http::response(['id' => 'shp_rate_1', 'rates' => [['id' => 'rate_1', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '5.25', 'currency' => 'USD', 'delivery_days' => 3]]]),
        'https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_shipping_1', 'url' => 'https://checkout.stripe.test/shipping']),
        'https://api.easypost.com/v2/shipments/shp_rate_1/buy' => Http::response(['id' => 'shp_bought_1', 'tracking_code' => '9400TEST', 'tracker' => ['public_url' => 'https://track.test/9400TEST'], 'selected_rate' => ['carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '5.25', 'currency' => 'USD'], 'postage_label' => ['label_url' => 'https://label.test/9400TEST']]),
        'https://api.easypost.com/v2/shipments/shp_bought_1/refund' => Http::response(['id' => 'shp_bought_1']),
    ]);
    $shipping = app(WebsiteCommerceShippingService::class);
    $quote = $shipping->quote($cart, ['name' => 'Buyer', 'street1' => '10 Market St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78702', 'country' => 'US'])[0];
    $result = $commerce->beginCheckout($cart, ['name' => 'Buyer', 'email' => 'buyer@example.test', 'fulfillment_method' => 'ship', 'shipping_rate_quote_id' => $quote->id, 'shipping_address' => $quote->destination], 'https://pilot.test/success', 'https://pilot.test/cart');
    $order = $result['order'];
    $commerce->processStripeEvent(['id' => 'evt_shipping_paid', 'type' => 'checkout.session.completed', 'data' => ['object' => ['payment_status' => 'paid', 'payment_intent' => 'pi_shipping_1', 'amount_total' => 2525, 'total_details' => ['amount_tax' => 0], 'metadata' => ['website_order_id' => (string) $order->id]]]]);
    $shipment = $shipping->purchaseLabel($order->fresh(), $quote, $actor);
    expect($order->fresh()->total_cents)->toBe(2525)
        ->and($shipment->tracking_number)->toBe('9400TEST')
        ->and($shipment->events()->count())->toBe(1)
        ->and(Order::query()->count())->toBe(0);
    $shipping->voidLabel($shipment, $actor);
    expect($shipment->fresh()->status)->toBe('voided');
});
