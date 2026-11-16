<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderLine;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCatalogService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->tenant = Tenant::query()->create(['name' => 'Fictional rendering shop', 'slug' => 'fictional-blade-shop']);
    $this->owner = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->owner->tenants()->attach($this->tenant->id, ['role' => 'owner', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website',
        'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped',
        'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true,
        'managed_website.public_render_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id],
        'managed_website.commerce_enabled' => true]);
    $this->websites = app(ManagedWebsiteService::class);
    $this->site = $this->websites->createSite($this->tenant, $this->owner);
});

afterEach(function (): void {
    Http::assertNothingSent();
});

function websiteRenderedDocument(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML($html);

    return new DOMXPath($document);
}

test('populated carts compile the payment include and respect independent storefront controls', function (string $theme, bool $shopPayReady): void {
    $this->websites->applyTheme($this->site, $theme, $this->owner);
    $this->websites->publish($this->site->fresh(), $this->owner);
    $site = $this->site->fresh();
    $product = app(WebsiteCatalogService::class)->saveProduct($site, null, [
        'title' => 'Fictional packing kit', 'handle' => 'packing-kit', 'status' => 'active', 'product_type' => 'physical',
        'track_inventory' => true, 'media' => [], 'collection_ids' => [],
        'variants' => [['title' => 'Small', 'price' => '12.00', 'inventory_quantity' => 2, 'is_available' => true]],
    ], $this->owner);
    $commerce = app(WebsiteCommerceService::class);
    $cart = $commerce->addToCart($commerce->cartFor($site, null), $product->variants->sole(), 1);
    $html = view('managed-website.cart', [
        'site' => $site, 'cart' => $cart->load('items.variant.product'), 'shopPayReady' => $shopPayReady,
        'shippingEnabled' => true, 'checkoutReady' => true, 'checkoutAttempt' => null,
        'storefront' => ['can_account' => $shopPayReady, 'can_cart' => ! $shopPayReady],
        'errors' => new ViewErrorBag,
    ])->render();
    $xpath = websiteRenderedDocument($html);
    $form = '//form[@id="website-checkout" or @id="sn-checkout"]';
    expect($html)->not->toMatch('/@(include|if|endif|endforeach)\b/')
        ->and($xpath->query($form)->length)->toBe(1)
        ->and($xpath->query($form.'//input[@name="_token"]')->length)->toBe(1)
        ->and($xpath->query($form.'//select[@name="payment_provider"]')->length)->toBe($shopPayReady ? 1 : 0)
        ->and($xpath->query('//a[contains(@href,"/account")]')->length > 0)->toBe($shopPayReady)
        ->and($xpath->query('//a[contains(@href,"/cart")]')->length > 0)->toBe(! $shopPayReady);
    if ($shopPayReady) {
        expect($xpath->query($form.'//option[@value="stripe"]')->length)->toBe(1)
            ->and($xpath->query($form.'//option[@value="shop_pay"]')->length)->toBe(1);
    }
})->with([
    'native card only' => ['hvac-service', false], 'native wallet choice' => ['hvac-service', true],
    'Sawyer card only' => ['sawyer-naturals', false], 'Sawyer wallet choice' => ['sawyer-naturals', true],
]);

test('shopper account alerts never change the guest or signed-in branch', function (string $theme, bool $signedIn, bool $alerts): void {
    $this->websites->applyTheme($this->site, $theme, $this->owner);
    $this->websites->publish($this->site->fresh(), $this->owner);
    $customer = $signedIn ? WebsiteCustomer::query()->create(['tenant_id' => $this->tenant->id,
        'email' => 'fictional-buyer@example.test', 'first_name' => 'Fictional', 'status' => 'active']) : null;
    $errors = new ViewErrorBag;
    if ($alerts) {
        session()->put('account_status', 'Fictional saved status.');
        $errors->put('default', new MessageBag(['email' => 'Fictional validation error.']));
    }
    $html = view('managed-website.account', ['site' => $this->site->fresh(), 'customer' => $customer,
        'orders' => WebsiteOrder::query()->forTenant($this->tenant)->paginate(10), 'errors' => $errors])->render();
    $xpath = websiteRenderedDocument($html);
    expect($html)->not->toMatch('/@(if|endif)\b/')
        ->and($xpath->query('//form[contains(@action,"/account/link")]')->length)->toBe($signedIn ? 0 : 1)
        ->and($xpath->query('//form[contains(@action,"/account/profile")]')->length)->toBe($signedIn ? 1 : 0)
        ->and($xpath->query('//*[@role="alert"]')->length)->toBe($alerts ? 1 : 0)
        ->and(str_contains($html, 'Fictional saved status.'))->toBe($alerts)
        ->and(str_contains($html, 'Contact details'))->toBe($signedIn);
})->with([
    'native guest' => ['hvac-service', false, false], 'native guest alerts' => ['hvac-service', false, true],
    'native signed-in alerts' => ['hvac-service', true, true], 'Sawyer guest' => ['sawyer-naturals', false, false],
    'Sawyer guest alerts' => ['sawyer-naturals', false, true], 'Sawyer signed-in alerts' => ['sawyer-naturals', true, true],
]);

test('order confirmations explain stored charges and retain receipt actions in both designs', function (string $theme, int $tax, int $shipping): void {
    $this->websites->applyTheme($this->site, $theme, $this->owner);
    $this->websites->publish($this->site->fresh(), $this->owner);
    $order = WebsiteOrder::query()->create([
        'tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'number' => 'WEB-FICTIONAL-RECEIPT-1234567890123456',
        'lookup_token' => str_repeat('f', 56), 'source' => 'native', 'currency' => 'usd', 'payment_status' => 'paid',
        'fulfillment_method' => $shipping > 0 ? 'ship' : 'pickup', 'fulfillment_status' => 'unfulfilled', 'subtotal_cents' => 1200,
        'tax_cents' => $tax, 'shipping_cents' => $shipping, 'total_cents' => 1200 + $tax + $shipping,
    ]);
    $title = 'Fictional packing kit with an extra-long recyclable presentation sleeve';
    WebsiteOrderLine::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $order->id,
        'title' => $title, 'product_type' => 'physical', 'quantity' => 2, 'unit_price_cents' => 600,
        'line_total_cents' => 1200, 'snapshot' => []]);
    $html = view('managed-website.order', ['site' => $this->site->fresh(), 'order' => $order->fresh(),
        'storefront' => ['can_account' => false, 'can_cart' => false]])->render();
    $xpath = websiteRenderedDocument($html);
    $summary = '//section[@aria-label="Order summary"]';
    $isSawyer = $theme === 'sawyer-naturals';
    $prefix = $isSawyer ? 'sn' : 'store';
    $taxRow = $summary.'//div[span[normalize-space(.)="Tax"]]';
    $shippingRow = $summary.'//div[span[normalize-space(.)="Shipping"]]';
    expect($html)->not->toMatch('/@(include|if|endif|endforeach)\b/')
        ->and($xpath->query('//h1/span[normalize-space(.)="'.$order->number.'"]')->length)->toBe(1)
        ->and($xpath->query($summary)->length)->toBe(1)
        ->and($xpath->query($summary.'//div[@class="'.$prefix.'-order__details"]/strong[normalize-space(.)="'.$title.'"]')->length)->toBe(1)
        ->and($xpath->query($summary.'//div[@class="'.$prefix.'-order__details"]/span[normalize-space(.)="2 × $6.00"]')->length)->toBe(1)
        ->and($xpath->query($summary.'//strong[@class="'.$prefix.'-order__amount" and normalize-space(.)="$12.00"]')->length)->toBeGreaterThanOrEqual(1)
        ->and($xpath->query($taxRow)->length)->toBe($tax > 0 ? 1 : 0)
        ->and($xpath->query($shippingRow)->length)->toBe($shipping > 0 ? 1 : 0)
        ->and($xpath->query($summary.'//div[contains(@class,"'.$prefix.'-order__total")]/strong[2]')->item(0)->textContent)->toBe('$'.number_format($order->total_cents / 100, 2))
        ->and($xpath->query($summary.'//a | '.$summary.'//form')->length)->toBe(0)
        ->and($xpath->query('//div[@class="'.$prefix.'-order__actions"]/a[@href="'.route('managed-website.store.index').'"]')->length)->toBe(1)
        ->and(str_contains($html, $isSawyer ? 'Your payment is paid.' : 'Your payment status is <strong>Paid</strong>. We will coordinate '.$order->fulfillment_method.' directly.'))->toBeTrue();
    if ($tax > 0) {
        expect(trim($xpath->query($taxRow.'/span[2]')->item(0)->textContent))->toBe('$'.number_format($tax / 100, 2));
    }
    if ($shipping > 0) {
        expect(trim($xpath->query($shippingRow.'/span[2]')->item(0)->textContent))->toBe('$'.number_format($shipping / 100, 2));
    }
})->with([
    'native tax' => ['hvac-service', 107, 0], 'native shipping' => ['hvac-service', 0, 450],
    'native both charges' => ['hvac-service', 107, 450], 'native no charges' => ['hvac-service', 0, 0],
    'Sawyer tax' => ['sawyer-naturals', 107, 0], 'Sawyer shipping' => ['sawyer-naturals', 0, 450],
    'Sawyer both charges' => ['sawyer-naturals', 107, 450], 'Sawyer no charges' => ['sawyer-naturals', 0, 0],
]);

test('published card and gallery conditions render only their own saved fields', function (): void {
    $this->websites->applyTheme($this->site, 'hvac-service', $this->owner);
    $home = $this->site->pages()->where('slug', '/')->firstOrFail();
    $this->websites->saveDraft($this->site->fresh(), $home, ['title' => 'Fictional photo cards', 'blocks' => [
        ['type' => 'service_cards', 'items' => [
            ['image_url' => '/images/public-site/guide-retail.webp', 'image_alt' => 'Fictional packing photo', 'heading' => 'Heading only'],
            ['body' => 'Body without an image or heading.'],
        ]],
        ['type' => 'gallery', 'items' => [
            ['image_url' => '/images/public-site/guide-retail.webp', 'image_alt' => 'Fictional gallery photo'], ['image_url' => ''],
        ]],
        ['type' => 'faq_list', 'heading' => 'After the gallery', 'items' => [['heading' => 'A fictional question', 'body' => 'A saved answer.']]],
    ], 'seo' => []], $this->owner);
    $this->websites->publish($this->site->fresh(), $this->owner);
    $response = $this->get('https://'.$this->site->subdomain.'.theeverbranch.com/')->assertOk();
    $xpath = websiteRenderedDocument($response->getContent());
    expect($response->getContent())->not->toMatch('/@(if|endif|endforeach)\b/')
        ->and($xpath->query('//article[@data-eb-item-index="0"]//img')->length)->toBe(1)
        ->and($xpath->query('//article[@data-eb-item-index="0"]//h3[normalize-space(.)="Heading only"]')->length)->toBe(1)
        ->and($xpath->query('//article[@data-eb-item-index="1"]//img | //article[@data-eb-item-index="1"]//h3')->length)->toBe(0)
        ->and($xpath->query('//article[@data-eb-item-index="1"]//p[normalize-space(.)="Body without an image or heading."]')->length)->toBe(1)
        ->and($xpath->query('//div[@class="gallery"]/img')->length)->toBe(1)
        ->and($xpath->query('//summary[normalize-space(.)="A fictional question"]')->length)->toBe(1);
});
