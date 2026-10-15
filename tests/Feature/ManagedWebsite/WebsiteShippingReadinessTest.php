<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantPaymentAccount;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProduct;
use App\Models\WebsiteShippingRateQuote;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCatalogService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsiteCommerceShippingService;
use App\Services\ManagedWebsite\WebsiteProductCsvService;
use App\Services\ManagedWebsite\WebsiteShippingReadinessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Retail weights', 'slug' => 'retail-weights']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set([
        'managed_website.editor_enabled' => true,
        'managed_website.publishing_enabled' => true,
        'managed_website.editor_tenant_ids' => [$this->tenant->id],
    ]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    $this->catalog = app(WebsiteCatalogService::class);
    $this->readiness = app(WebsiteShippingReadinessService::class);
    $this->commerce = app(WebsiteCommerceService::class);
    $this->actingAs($this->actor)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->get(route('managed-website.products.index', ['tenant' => $this->tenant->slug]))->assertOk();
});

function weightProduct(TenantSite $site, User $actor, string $handle, array $variants = [], array $extra = []): WebsiteProduct
{
    return app(WebsiteCatalogService::class)->saveProduct($site, null, array_replace([
        'title' => ucfirst($handle), 'handle' => $handle, 'status' => 'active', 'product_type' => 'physical',
        'track_inventory' => false, 'media' => [], 'collection_ids' => [],
        'variants' => $variants ?: [['title' => 'Default', 'price' => '15.00', 'is_available' => true]],
    ], $extra), $actor);
}

test('catalog warns about missing weights and filters only affected physical products', function (): void {
    $missing = weightProduct($this->site, $this->actor, 'soap');
    weightProduct($this->site, $this->actor, 'measured', [['title' => 'Default', 'price' => '10.00', 'is_available' => true, 'shipping_weight_ounces' => 12]]);
    weightProduct($this->site, $this->actor, 'service', [], ['product_type' => 'service']);
    weightProduct($this->site, $this->actor, 'retired', [], ['status' => 'archived']);
    weightProduct($this->site, $this->actor, 'unavailable', [['title' => 'Default', 'price' => '10.00', 'is_available' => false]]);
    $this->get(route('managed-website.products.index'))->assertOk()->assertSeeText('1 product needs a shipping weight.')->assertSeeText('Add weights');
    $this->get(route('managed-website.products.index', ['status' => 'all', 'shipping' => 'missing_weight']))
        ->assertOk()->assertSee(route('managed-website.products.edit', $missing), false)->assertDontSeeText('Measured')->assertDontSeeText('Service')->assertDontSeeText('Retired');
    $this->get(route('managed-website.products.shipping-weights'))->assertOk()->assertSeeText('Soap')->assertSeeText('Weight missing')->assertDontSeeText('Measured');
    expect($this->readiness->missingWeights($this->site)->count())->toBe(1);
});

test('bulk weights convert units preserve identities and reject stale edits atomically', function (): void {
    $product = weightProduct($this->site, $this->actor, 'soap', [
        ['title' => 'Small', 'sku' => 'SOAP-S', 'price' => '10.00', 'is_available' => true],
        ['title' => 'Large', 'sku' => 'SOAP-L', 'price' => '20.00', 'is_available' => true],
    ]);
    [$small, $large] = $product->variants;
    $rows = ['variants' => [
        ['id' => $small->id, 'revision' => 1, 'shipping_weight' => '100', 'shipping_weight_unit' => 'g'],
        ['id' => $large->id, 'revision' => 1, 'shipping_weight' => '1.25', 'shipping_weight_unit' => 'lb'],
    ]];
    $this->post(route('managed-website.products.shipping-weights.save'), $rows)->assertRedirect()->assertSessionHasNoErrors();
    expect($small->fresh()->shipping_weight_ounces)->toBe(4)->and($large->fresh()->shipping_weight_ounces)->toBe(20)
        ->and($small->fresh()->sku)->toBe('SOAP-S')->and($large->fresh()->price_cents)->toBe(2000)
        ->and(data_get($product->fresh()->service_details, 'catalog_revision'))->toBe(2);
    $this->get(route('managed-website.products.index'))->assertDontSeeText('needs a shipping weight');
    $this->get(route('managed-website.products.shipping-weights'))->assertSeeText('No missing weights in this view');
    $this->postJson(route('managed-website.products.shipping-weights.save'), $rows)->assertStatus(409);
    $rows['variants'][0]['revision'] = 2;
    $rows['variants'][0]['shipping_weight'] = '10';
    $rows['variants'][1]['revision'] = 2;
    $rows['variants'][1]['shipping_weight'] = '-1';
    $this->postJson(route('managed-website.products.shipping-weights.save'), $rows)->assertUnprocessable();
    expect($small->fresh()->shipping_weight_ounces)->toBe(4);
});

test('bulk updates reject foreign tenant and site variant ids without changing valid rows', function (): void {
    $local = weightProduct($this->site, $this->actor, 'local')->variants->first();
    $other = Tenant::query()->create(['name' => 'Other retailer', 'slug' => 'other-retailer']);
    $otherSite = TenantSite::query()->create(['tenant_id' => $other->id, 'subdomain' => 'other-retailer', 'status' => 'draft']);
    $foreignProduct = WebsiteProduct::query()->create(['tenant_id' => $other->id, 'tenant_site_id' => $otherSite->id, 'title' => 'Private item', 'handle' => 'private', 'status' => 'active', 'product_type' => 'physical']);
    $foreign = $foreignProduct->variants()->create(['tenant_id' => $other->id, 'title' => 'Default', 'is_available' => true, 'price_cents' => 100]);
    $this->get(route('managed-website.products.shipping-weights', ['show' => 'all']))->assertDontSeeText('Private item');
    $this->postJson(route('managed-website.products.shipping-weights.save'), ['variants' => [
        ['id' => $local->id, 'revision' => 1, 'shipping_weight' => 12, 'shipping_weight_unit' => 'oz'],
        ['id' => $foreign->id, 'revision' => 0, 'shipping_weight' => 20, 'shipping_weight_unit' => 'oz'],
    ]])->assertNotFound();
    expect($local->fresh()->shipping_weight_ounces)->toBeNull();
});

test('bulk setup respects editor and publishing freezes and blank fields preserve measurements', function (): void {
    $variant = weightProduct($this->site, $this->actor, 'soap', [['title' => 'Default', 'price' => '15.00', 'is_available' => true, 'shipping_weight_ounces' => 12]])->variants->first();
    $row = ['variants' => [['id' => $variant->id, 'revision' => 1, 'shipping_weight' => '', 'shipping_weight_unit' => 'oz']]];
    $this->post(route('managed-website.products.shipping-weights.save'), $row)->assertRedirect();
    expect($variant->fresh()->shipping_weight_ounces)->toBe(12);
    config()->set('managed_website.publishing_enabled', false);
    $row['variants'][0]['shipping_weight'] = 20;
    $this->postJson(route('managed-website.products.shipping-weights.save'), $row)->assertStatus(423);
    config()->set('managed_website.editor_enabled', false);
    $this->get(route('managed-website.products.shipping-weights'))->assertStatus(423);
    $this->postJson(route('managed-website.products.shipping-weights.save'), $row)->assertStatus(423);
});

test('ordinary product edits convert weights and preserve omitted shipping values', function (): void {
    $product = weightProduct($this->site, $this->actor, 'soap');
    $variant = $product->variants->first();
    $data = ['catalog_form' => 1, 'revision' => 1, 'title' => 'Soap', 'handle' => 'soap', 'status' => 'active', 'product_type' => 'physical',
        'track_inventory' => false, 'collection_ids' => [], 'variants' => [['id' => $variant->id, 'title' => 'Default', 'price' => '15.00', 'is_available' => true, 'shipping_weight' => '0.5', 'shipping_weight_unit' => 'lb']]];
    $this->putJson(route('managed-website.products.update', $product), $data)->assertRedirect();
    expect($variant->fresh()->shipping_weight_ounces)->toBe(8);
    $data['revision'] = 2;
    unset($data['variants'][0]['shipping_weight'], $data['variants'][0]['shipping_weight_unit']);
    $this->putJson(route('managed-website.products.update', $product), $data)->assertRedirect();
    expect($variant->fresh()->shipping_weight_ounces)->toBe(8);
    $data['revision'] = 3;
    $data['variants'][0]['shipping_weight'] = '';
    $this->putJson(route('managed-website.products.update', $product), $data)->assertRedirect();
    expect($variant->fresh()->shipping_weight_ounces)->toBeNull();
});

test('legacy catalog files preserve weights dimensions and variant names', function (): void {
    $product = weightProduct($this->site, $this->actor, 'soap', [['title' => 'Large', 'price' => '15.00', 'is_available' => true, 'shipping_weight_ounces' => 12]]);
    $variant = $product->variants->first();
    $variant->update(['shipping_length_inches' => 8, 'shipping_width_inches' => 4, 'shipping_height_inches' => 2]);
    $file = UploadedFile::fake()->createWithContent('legacy.csv', "handle,title,retail_price,product_type,status\nsoap,Soap,17.00,physical,active\n");
    app(WebsiteProductCsvService::class)->import($this->site, $file);
    expect($variant->fresh()->shipping_weight_ounces)->toBe(12)->and($variant->fresh()->shipping_length_inches)->toBe(8)
        ->and($variant->fresh()->title)->toBe('Large')->and($variant->fresh()->price_cents)->toBe(1700);
});

test('multi variant CSV round trips each shipping measurement without changing identities', function (): void {
    $product = weightProduct($this->site, $this->actor, 'soap', [
        ['title' => 'Small', 'sku' => 'SOAP-S', 'price' => '10.00', 'compare_at_price' => '12.00', 'wholesale_price' => '6.00', 'is_available' => true, 'shipping_weight_ounces' => 4],
        ['title' => 'Large', 'sku' => 'SOAP-L', 'price' => '20.00', 'compare_at_price' => '25.00', 'is_available' => true, 'shipping_weight_ounces' => 12],
    ], ['media' => ['https://example.com/soap.jpg', 'https://example.com/soap-side.jpg'], 'seo_title' => 'Handmade soap', 'seo_description' => 'Measured retail soap.']);
    $product->variants->last()->update(['shipping_length_inches' => 8, 'shipping_width_inches' => 4, 'shipping_height_inches' => 2]);
    $fields = ['id', 'title', 'sku', 'price_cents', 'compare_at_price_cents', 'wholesale_price_cents', 'shipping_weight_ounces', 'shipping_length_inches', 'shipping_width_inches', 'shipping_height_inches'];
    $before = $product->fresh('variants')->variants->map->only($fields)->all();
    $csv = app(WebsiteProductCsvService::class);
    ob_start();
    $csv->export($this->site)->sendContent();
    $content = ob_get_clean();
    expect($content)->toContain('variant_id')->toContain('shipping_weight_ounces')->toContain('SOAP-S')->toContain('SOAP-L');
    $result = $csv->import($this->site, UploadedFile::fake()->createWithContent('roundtrip.csv', $content));
    expect($result)->toBe(['created' => 0, 'updated' => 1])
        ->and($product->fresh('variants')->variants->map->only($fields)->all())->toBe($before)
        ->and($product->fresh()->media)->toBe($product->media)
        ->and($product->fresh()->seo)->toBe($product->seo);
});

test('invalid CSV weights roll back the whole file and foreign variant ids are rejected', function (): void {
    $product = weightProduct($this->site, $this->actor, 'soap', [['title' => 'Default', 'price' => '15.00', 'is_available' => true, 'shipping_weight_ounces' => 12]]);
    $csv = app(WebsiteProductCsvService::class);
    $file = UploadedFile::fake()->createWithContent('bad.csv', "handle,title,retail_price,shipping_weight_ounces\nsoap,Soap,19.00,20\nbad,Bad,10.00,-1\n");
    try {
        $csv->import($this->site, $file);
        $this->fail('Invalid weight should reject the file.');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('catalog');
    }
    expect($product->variants->first()->fresh()->shipping_weight_ounces)->toBe(12)->and(WebsiteProduct::query()->where('handle', 'bad')->exists())->toBeFalse();
    $other = weightProduct($this->site, $this->actor, 'other');
    $file = UploadedFile::fake()->createWithContent('foreign-variant.csv', "handle,title,retail_price,variant_id\nsoap,Soap,19.00,".$other->variants->first()->id."\n");
    expect(fn () => $csv->import($this->site, $file))->toThrow(ValidationException::class);
    config()->set('managed_website.publishing_enabled', false);
    $file = UploadedFile::fake()->createWithContent('frozen.csv', "handle,title,retail_price,product_type,status,shipping_weight_ounces\nsoap,Soap,19.00,physical,active,20\n");
    expect(fn () => $csv->import($this->site, $file))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($product->variants->first()->fresh()->shipping_weight_ounces)->toBe(12);
});

test('shipping checks refresh cart weights and services never require a weight', function (): void {
    $product = weightProduct($this->site, $this->actor, 'soap', [['title' => 'Default', 'price' => '15.00', 'is_available' => true, 'shipping_weight_ounces' => 12]]);
    $service = weightProduct($this->site, $this->actor, 'appointment', [], ['product_type' => 'service']);
    $cart = $this->commerce->addToCart($this->commerce->cartFor($this->site, null), $product->variants->first(), 1);
    $cart = $this->commerce->addToCart($cart, $service->variants->first(), 1);
    expect($this->readiness->missingCartWeights($cart))->toHaveCount(0);
    $product->variants->first()->update(['shipping_weight_ounces' => null]);
    expect($this->readiness->missingCartWeights($cart))->toHaveCount(1);
    expect(fn () => $this->readiness->assertCartCanShip($cart))->toThrow(ValidationException::class);
});

test('weight conversions round upward and reject zero invalid units and oversized input', function (): void {
    expect($this->readiness->normalizeWeight(['shipping_weight' => '0.1', 'shipping_weight_unit' => 'oz'])['shipping_weight_ounces'])->toBe(1);
    foreach ([[0, 'oz'], [-1, 'lb'], [1, 'invalid'], [1000000, 'lb']] as [$weight, $unit]) {
        expect(fn () => $this->readiness->normalizeWeight(['shipping_weight' => $weight, 'shipping_weight_unit' => $unit]))->toThrow(ValidationException::class);
    }
});

test('missing weights stop carrier requests and shipping checkout but do not stop pickup', function (): void {
    config()->set([
        'managed_website.commerce_enabled' => true, 'managed_website.commerce_shipping_enabled' => true,
        'managed_website.commerce_shipping_tenant_ids' => [$this->tenant->id], 'services.stripe.secret' => 'sk_test_weights',
        'commercial.billing_readiness.tenant_payments' => ['enabled' => true, 'tenant_slugs' => [$this->tenant->slug], 'connect_webhooks_verified' => true, 'automatic_tax_enabled' => true, 'tax_decision_confirmed' => true],
    ]);
    TenantPaymentAccount::query()->create(['tenant_id' => $this->tenant->id, 'provider_account_id' => 'acct_weights', 'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true]);
    IntegrationConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'easypost', 'status' => 'connected', 'access_token' => 'EZTK_test_weights']);
    $product = weightProduct($this->site, $this->actor, 'soap');
    $cart = $this->commerce->addToCart($this->commerce->cartFor($this->site, null), $product->variants->first(), 1);
    Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_weights', 'url' => 'https://checkout.example.test/weights'])]);
    expect(fn () => app(WebsiteCommerceShippingService::class)->quote($cart, []))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    $location = WebsiteFulfillmentLocation::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'name' => 'Warehouse', 'address' => []]);
    $quote = WebsiteShippingRateQuote::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'website_cart_id' => $cart->id, 'website_fulfillment_location_id' => $location->id, 'provider_shipment_id' => 'shp_old', 'provider_rate_id' => 'rate_old', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'amount_cents' => 500, 'destination' => [], 'parcel' => [], 'expires_at' => now()->addMinutes(20)]);
    $buyer = ['name' => 'Buyer', 'email' => 'buyer@example.test', 'fulfillment_method' => 'ship', 'shipping_rate_quote_id' => $quote->id, 'shipping_address' => ['country' => 'US']];
    expect(fn () => $this->commerce->beginCheckout($cart, $buyer, 'https://example.test/success', 'https://example.test/cart'))->toThrow(ValidationException::class);
    expect(WebsiteOrder::query()->count())->toBe(0);
    Http::assertNothingSent();
    $buyer['fulfillment_method'] = 'pickup';
    $result = $this->commerce->beginCheckout($cart, $buyer, 'https://example.test/success', 'https://example.test/cart');
    expect($result['order']->fulfillment_method)->toBe('pickup')->and($product->fresh()->status)->toBe('active');
});
