<?php

use App\Models\TenantPaymentAccount;
use App\Models\TenantSite;
use App\Models\TenantSiteDomain;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderLine;
use App\Models\WebsiteOrderNotification;
use App\Models\WebsitePayment;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsiteOrderNotificationService;
use App\Services\ManagedWebsite\WebsiteStorefrontPresentation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\SelfServiceWebsitePurchaseFixture;
use Tests\Support\WebsiteStripeFixture;

beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    Queue::fake();
    Http::preventStrayRequests();
    config()->set(['logging.default' => 'null', 'self_service.trial_enabled' => true,
        'managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [],
        'managed_website.publishing_enabled' => true, 'managed_website.public_render_enabled' => true,
        'managed_website.commerce_enabled' => true, 'managed_website.custom_domains_enabled' => true,
        'managed_website.custom_domain_activation_enabled' => true,
        'managed_website.stripe_connect_webhook_secret' => 'whsec_fictional_included_store',
        'tenant_payments.self_service_collection_enabled' => true,
        'commercial.billing_readiness.tenant_payments' => ['enabled' => true, 'tenant_slugs' => [],
            'automatic_tax_enabled' => true, 'tax_decision_confirmed' => true],
        'tenancy.domains.canonical.base_domain' => 'theeverbranch.com', 'app.url' => 'https://app.theeverbranch.com']);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'role' => 'self_service_pending',
        'requested_via' => 'self_service_registration', 'onboarding_guide_answers' => ['business_template' => 'candle_maker']]);
    $this->actingAs($this->owner)->post(route('workspace.first-login.store'), ['workspace_name' => 'Fictional included shop', 'template_key' => 'generic', 'start_path' => 'self'])->assertRedirect();
    $this->tenant = $this->owner->tenants()->sole();
    $this->actingAs($this->owner->refresh());
    SelfServiceWebsitePurchaseFixture::confirm($this->tenant, $this->owner);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    config()->set(['services.stripe.secret' => 'sk_test_included_store', 'services.stripe.account_id' => 'acct_FictionalIncludedPlatform']);
    TenantPaymentAccount::query()->create(['tenant_id' => $this->tenant->id, 'provider_account_id' => 'acct_FictionalIncludedMerchant',
        'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true, 'metadata' => ['routing_mode' => 'direct']]);
    $this->post(route('managed-website.setup.save', ['tenant' => $this->tenant->slug]), ['theme_key' => 'starter-candle_maker',
        'contact_name' => 'Fictional included shop', 'contact_email' => 'website-fixture@gmail.com'])->assertRedirect()->assertSessionHasNoErrors();
    $this->site = TenantSite::query()->where('tenant_id', $this->tenant->id)->sole();
    $commerce = app(WebsiteCommerceService::class);
    $this->product = $commerce->saveProduct($this->site, ['title' => 'Fictional packing kit', 'product_type' => 'physical', 'status' => 'active',
        'price' => '12.00', 'track_inventory' => true, 'inventory_quantity' => 4, 'is_available' => true]);
    $this->variant = $this->product->variants->sole();
    WebsiteStripeFixture::origin($this->site);
    $this->post(route('managed-website.publish', ['tenant' => $this->tenant->slug]))->assertRedirect()->assertSessionHasNoErrors();
    $this->site->refresh();
    $this->included = 'https://'.$this->site->subdomain.'.theeverbranch.com';
    $this->custom = 'https://www.fictional-included-shop.example';
});

function connectIncludedStoreTestDomain(object $test): void
{
    TenantSiteDomain::query()->create(['tenant_id' => $test->tenant->id, 'tenant_site_id' => $test->site->id,
        'hostname' => 'www.fictional-included-shop.example', 'status' => 'active', 'is_primary' => true,
        'verification_token' => 'fictional-domain-proof', 'verified_at' => now(), 'activated_at' => now()]);
}

test('normal paid retail setup can shop cart and checkout at its included address without editing address settings', function (): void {
    expect($this->site->setup->domain_choice)->toBe('everbranch_subdomain')
        ->and($this->site->publishedSiteVersion->settings)->not->toHaveKey('domain_choice');
    $this->get($this->included.'/shop')->assertOk()->assertSeeText($this->product->title);
    $this->get($this->included.'/products/'.$this->product->handle)->assertOk()->assertSee('data-variant-cart-form', false)->assertDontSeeText('Shop securely');
    $this->post($this->included.'/cart/items/'.$this->variant->id, ['quantity' => 1])->assertRedirect();
    $this->get($this->included.'/cart')->assertOk()->assertSeeText($this->product->title);
    Http::fake(['https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_FictionalIncludedBuyer']),
        'https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_FictionalIncludedCheckout', 'url' => 'https://checkout.stripe.com/c/pay/FictionalIncludedCheckout'])]);
    $this->post($this->included.'/checkout/website', ['name' => 'Fictional buyer', 'email' => 'fictional-receipt@theeverbranch.com', 'fulfillment_method' => 'pickup'])
        ->assertRedirect('https://checkout.stripe.com/c/pay/FictionalIncludedCheckout');
    expect(WebsitePayment::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(config('commercial.billing_readiness.tenant_payments.tenant_slugs'))->toBe([]);
    Http::assertSentCount(2);
});

test('custom storefronts hand off by owned GET links and retain their checkout write restrictions', function (): void {
    connectIncludedStoreTestDomain($this);
    $this->get($this->custom.'/shop')->assertOk()->assertSeeText('Shop securely')->assertSee($this->included.'/shop', false);
    $this->get($this->custom.'/products/'.$this->product->handle)->assertOk()->assertSeeText('Shop securely')
        ->assertSee($this->included.'/products/'.$this->product->handle, false)
        ->assertDontSee('data-variant-cart-form', false);
    $this->post($this->custom.'/cart/items/'.$this->variant->id, ['quantity' => 1])->assertNotFound();
    $this->post($this->custom.'/checkout/website', ['name' => 'Fictional buyer'])->assertNotFound();
    expect(WebsitePayment::query()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
});

test('new receipt links use the included tracking route even with a verified primary custom domain', function (): void {
    connectIncludedStoreTestDomain($this);
    config()->set(['managed_website.order_notifications_enabled' => true, 'managed_website.order_notifications_sender_approved' => true,
        'managed_website.order_notifications_mailer' => 'native_test', 'mail.mailers.native_test' => ['transport' => 'array'],
        'managed_website.order_notifications_from_address' => 'orders@everbranch.example', 'managed_website.order_notifications_from_name' => 'Fictional shop']);
    $order = WebsiteOrder::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id, 'source' => 'native',
        'number' => 'WEB-INCLUDED-FIXTURE', 'lookup_token' => Str::random(56), 'currency' => 'usd', 'payment_status' => 'paid',
        'fulfillment_status' => 'unfulfilled', 'subtotal_cents' => 1200, 'tax_cents' => 107, 'total_cents' => 1307,
        'customer_snapshot' => ['email' => 'fictional-buyer@example.test']]);
    WebsiteOrderLine::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $order->id, 'title' => 'Fictional kit',
        'product_type' => 'physical', 'quantity' => 1, 'unit_price_cents' => 1200, 'line_total_cents' => 1200, 'snapshot' => []]);
    $notifications = app(WebsiteOrderNotificationService::class);
    expect($notifications->readiness($this->tenant)['ready'])->toBeTrue();
    DB::transaction(fn () => $notifications->record($order, 'included-host-fixture', 'confirmed'));
    $row = WebsiteOrderNotification::query()->where('tenant_id', $this->tenant->id)->sole();
    $url = $row->snapshot['order_url'];
    expect($url)->toStartWith($this->included.'/orders/track?');
    $this->get($url)->assertOk()->assertSeeText($order->number)->assertHeader('Referrer-Policy', 'no-referrer');
    $oldSnapshot = [...$row->snapshot, 'order_url' => str_replace($this->included, $this->custom, $url)];
    $row->update(['snapshot' => $oldSnapshot]);
    $notifications->deliver($row->id);
    expect($row->fresh()->snapshot)->toBe($oldSnapshot)->and($row->fresh()->status)->toBe('blocked');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('trusted quote-only and private previews never offer a commerce handoff', function (): void {
    connectIncludedStoreTestDomain($this);
    $request = Request::create($this->custom.'/shop');
    $request->attributes->set('managed_website_relay', true);
    $presentation = app(WebsiteStorefrontPresentation::class);
    expect($presentation->capabilities($this->site, $request, true)['secure_store_url'])->toBeNull();
    // This is an existing trusted policy, not a newly submitted owner setting.
    $draft = $this->site->draftSiteVersion;
    $draft->update(['settings' => [...$draft->settings, 'domain_choice' => 'everbranch_subdomain']]);
    app(ManagedWebsiteService::class)->publish($this->site->fresh(), $this->owner);
    $this->get($this->custom.'/shop')->assertOk()->assertDontSeeText('Shop securely');
    $this->post($this->included.'/cart/items/'.$this->variant->id, ['quantity' => 1])->assertNotFound();
    expect($presentation->capabilities($this->site->fresh(), $request)['secure_store_url'])->toBeNull();
    Http::assertNothingSent();
});
