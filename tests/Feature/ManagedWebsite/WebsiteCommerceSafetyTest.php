<?php

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Models\WebsiteStripeWebhookEvent;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsiteProductCsvService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('managed_website.commerce_enabled', true);
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
    config()->set('services.stripe.secret', 'sk_test_website');
    config()->set('commercial.billing_readiness.tenant_payments', ['enabled' => true, 'tenant_slugs' => ['website-pilot'], 'connect_webhooks_verified' => true, 'automatic_tax_enabled' => true, 'tax_decision_confirmed' => true]);
});

function commerceTenant(string $slug = 'website-pilot'): Tenant
{
    $tenant = Tenant::query()->create(['name' => 'Website Pilot', 'slug' => $slug]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_paid', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    TenantPaymentAccount::query()->create(['tenant_id' => $tenant->id, 'provider_account_id' => 'acct_'.str_replace('-', '_', $slug), 'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true]);

    return $tenant;
}

function commerceActor(Tenant $tenant): User
{
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $actor->tenants()->attach($tenant->id, ['role' => 'admin']);

    return $actor;
}

test('native Website Commerce creates its own records and never touches legacy orders', function (): void {
    $tenant = commerceTenant();
    $actor = commerceActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $ordersBefore = Order::query()->count();
    $site = app(ManagedWebsiteService::class)->createSite($tenant, $actor);
    $product = app(WebsiteCommerceService::class)->saveProduct($site, ['title' => 'Generator inspection', 'product_type' => 'service', 'description' => 'A fixed-price inspection.', 'status' => 'active', 'price' => '129.00', 'track_inventory' => false, 'is_available' => true]);

    expect($product->getTable())->toBe('website_products')
        ->and($product->variants->first()->getTable())->toBe('website_product_variants')
        ->and(Order::query()->count())->toBe($ordersBefore);
});

test('checkout is server-priced and Stripe delivery is idempotent', function (): void {
    $tenant = commerceTenant();
    $actor = commerceActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $website = app(ManagedWebsiteService::class);
    $site = $website->createSite($tenant, $actor);
    $commerce = app(WebsiteCommerceService::class);
    $product = $commerce->saveProduct($site, ['title' => 'Outdoor fire bowl', 'product_type' => 'physical', 'description' => 'A product.', 'status' => 'active', 'price' => '300.00', 'track_inventory' => true, 'inventory_quantity' => 3, 'is_available' => true]);
    $variant = $product->variants->firstOrFail();
    $cart = $commerce->cartFor($site, null);
    $cart = $commerce->addToCart($cart, $variant, 2);
    Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_website_1', 'url' => 'https://checkout.stripe.test/cs_website_1'], 200)]);
    $result = $commerce->beginCheckout($cart, ['name' => 'Buyer Name', 'email' => 'buyer@example.test', 'phone' => '555-0100', 'fulfillment_method' => 'pickup'], 'https://pilot.test/success', 'https://pilot.test/cart');
    $order = $result['order'];
    expect($order->total_cents)->toBe(60000)->and(WebsiteOrder::query()->count())->toBe(1);
    Http::assertSentCount(1);

    $event = ['id' => 'evt_website_paid', 'type' => 'checkout.session.completed', 'data' => ['object' => ['payment_status' => 'paid', 'payment_intent' => 'pi_website_1', 'amount_total' => 60000, 'customer_details' => ['email' => 'buyer@example.test'], 'total_details' => ['amount_tax' => 0], 'metadata' => ['website_order_id' => (string) $order->id]]]];
    $commerce->processStripeEvent($event);
    $commerce->processStripeEvent($event);
    expect($order->fresh()->payment_status)->toBe('paid')
        ->and(WebsiteProductVariant::query()->findOrFail($variant->id)->inventory_quantity)->toBe(1)
        ->and(Order::query()->count())->toBe(0)
        ->and(json_encode(WebsiteStripeWebhookEvent::query()->firstOrFail()->payload))->not->toContain('buyer@example.test');
});

test('another tenant cannot add a Website variant to its cart', function (): void {
    $tenant = commerceTenant();
    $other = commerceTenant('other-website');
    $actor = commerceActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id, $other->id]);
    $service = app(ManagedWebsiteService::class);
    $site = $service->createSite($tenant, $actor);
    $otherSite = $service->createSite($other, $actor);
    $commerce = app(WebsiteCommerceService::class);
    $product = $commerce->saveProduct($site, ['title' => 'Service', 'product_type' => 'service', 'status' => 'active', 'price' => '10.00', 'track_inventory' => false, 'is_available' => true]);
    $cart = $commerce->cartFor($otherSite, null);
    $commerce->addToCart($cart, $product->variants->firstOrFail(), 1);
})->throws(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

test('Website products support wholesale pricing, CSV round trips, and history-safe archival', function (): void {
    $tenant = commerceTenant();
    $actor = commerceActor($tenant);
    config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    $site = app(ManagedWebsiteService::class)->createSite($tenant, $actor);
    $commerce = app(WebsiteCommerceService::class);
    $product = $commerce->saveProduct($site, [
        'handle' => 'signature-five-stave-chair',
        'title' => 'Signature Five-Stave Chair',
        'product_type' => 'physical',
        'description' => 'Reclaimed barrel chair.',
        'status' => 'active',
        'price' => '500.00',
        'wholesale_price' => '350.00',
        'sku' => 'CBC-CHAIR-5',
        'media' => ['https://example.test/chair.jpg'],
        'track_inventory' => true,
        'inventory_quantity' => 4,
        'is_available' => true,
    ]);

    expect($product->handle)->toBe('signature-five-stave-chair')
        ->and($product->variants->firstOrFail()->price_cents)->toBe(50000)
        ->and($product->variants->firstOrFail()->wholesale_price_cents)->toBe(35000);

    $csv = app(WebsiteProductCsvService::class);
    ob_start();
    $csv->export($site)->sendContent();
    $export = (string) ob_get_clean();
    expect($export)->toContain('wholesale_price')
        ->and($export)->toContain('signature-five-stave-chair')
        ->and($export)->toContain('350.00');

    $file = UploadedFile::fake()->createWithContent('catalog.csv', implode("\n", [
        'handle,title,product_type,description,status,retail_price,wholesale_price,sku,image_url,track_inventory,inventory_quantity,is_available',
        'signature-five-stave-chair,Renamed Five-Stave Chair,physical,Updated chair,active,500.00,325.00,CBC-CHAIR-5,https://example.test/chair-new.jpg,1,6,1',
        'barrel-fire-table,Barrel Fire Table,physical,Outdoor gathering piece,draft,1500.00,1050.00,CBC-FIRE-1,https://example.test/fire.jpg,0,0,1',
    ]));
    $result = $csv->import($site, $file);

    expect($result)->toBe(['created' => 1, 'updated' => 1])
        ->and(WebsiteProduct::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->count())->toBe(2)
        ->and($product->fresh()->handle)->toBe('signature-five-stave-chair')
        ->and($product->fresh('variants')->variants->firstOrFail()->wholesale_price_cents)->toBe(32500);

    $archived = $commerce->archiveProduct($site, $product->fresh());
    expect($archived->status)->toBe('archived')
        ->and($archived->variants->firstOrFail()->is_available)->toBeFalse();
});
