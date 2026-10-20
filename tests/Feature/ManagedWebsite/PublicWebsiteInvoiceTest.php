<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsiteQuoteOrderService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\WebsiteStripeFixture;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional Carolina Craft', 'slug' => 'public-invoice-qa']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct']);
    TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'enabled_override' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    $this->account = TenantPaymentAccount::create(['tenant_id' => $this->tenant->id, 'provider_account_id' => 'acct_publicInvoiceFixture', 'status' => 'ready',
        'charges_enabled' => true, 'payouts_enabled' => true, 'platform_fee_bps' => 0, 'metadata' => ['routing_mode' => 'direct']]);
    config()->set([
        'managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true,
        'managed_website.public_render_enabled' => true, 'managed_website.commerce_enabled' => true, 'managed_website.invoices_enabled' => true,
        'managed_website.editor_tenant_ids' => [$this->tenant->id], 'managed_website.invoice_tenant_ids' => [$this->tenant->id],
        'managed_website.invoice_bank_payments_enabled' => false, 'managed_website.stripe_webhook_secret' => 'whsec_public_fixture',
        'managed_website.stripe_connect_webhook_secret' => 'whsec_connected_public_fixture', 'tenant_payments.stripe_direct_webhooks_verified' => true,
        'services.stripe.secret' => 'sk_test_public_invoice_fixture', 'services.stripe.account_id' => 'acct_platformPublicFixture',
        'tenant_payments.setup_enabled' => true, 'tenant_payments.tenant_slugs' => [$this->tenant->slug],
        'tenant_payments.stripe_embedded_enabled' => false, 'tenant_payments.stripe_oauth_client_id' => 'ca_fictional_oauth',
        'tenant_payments.paypal' => ['enabled' => true, 'environment' => 'sandbox', 'partner_approved' => false,
            'client_id' => 'fictional-public-client', 'client_secret' => 'private-paypal-fixture', 'partner_id' => 'PARTNER12345', 'bn_code' => 'fictional-bn'],
        'commercial.billing_readiness.tenant_payments' => ['enabled' => true, 'tenant_slugs' => [$this->tenant->slug],
            'connect_webhooks_verified' => true, 'automatic_tax_enabled' => true, 'tax_decision_confirmed' => true],
    ]);
    $websites = app(ManagedWebsiteService::class);
    $this->site = $websites->createSite($this->tenant, $this->actor);
    $websites->publish($this->site, $this->actor);
    $this->site->refresh();
    WebsiteStripeFixture::origin($this->site);
    $this->commerce = app(WebsiteCommerceService::class);
    $this->quotes = app(WebsiteQuoteOrderService::class);
    $this->product = $this->commerce->saveProduct($this->site, ['title' => 'Fictional finished oak barrel', 'product_type' => 'physical', 'status' => 'active',
        'price' => '100.00', 'track_inventory' => true, 'inventory_quantity' => 5, 'is_available' => true]);
    $this->variant = $this->product->variants->first();
    $this->input = ['request_id' => (string) Str::uuid(), 'workflow' => 'quote', 'customer_name' => 'Fictional Invoice Buyer', 'customer_email' => 'buyer@example.invalid',
        'lines' => [['website_product_variant_id' => $this->variant->id, 'quantity' => 2], ['title' => 'Fictional custom engraving', 'quantity' => 1, 'unit_price_cents' => 2500]],
        'manual_discount' => ['type' => 'percent', 'value' => 10, 'reason' => 'Fictional agreed discount'],
        'tax_cents' => 1500, 'manual_tax_confirmed' => true, 'tax_basis' => 'Fictional reviewed tax calculation', 'shipping_cents' => 3500,
        'fulfillment_method' => 'local_delivery', 'delivery_confirmed' => true, 'delivery_details' => 'Fictional local delivery on Friday after 3 PM; customer confirms access.',
        'deposit_type' => 'fixed', 'deposit_value' => 5000, 'note' => 'Private staff note must stay off the customer view'];
    $this->issued = function (bool $accepted = false): WebsiteOrder {
        $order = $this->quotes->issue($this->quotes->create($this->site, $this->input, $this->actor), $this->actor, 1);

        return $accepted ? $this->quotes->accept($order, 1) : $order;
    };
    $this->publicUrl = fn (WebsiteOrder $order): string => 'https://'.$this->site->subdomain.'.theeverbranch.com/invoices/'.$order->lookup_token;
    $this->enableBank = function (): void {
        config()->set('managed_website.invoice_bank_payments_enabled', true);
        $this->account->update(['metadata' => [...$this->account->metadata, 'bank_payments_ready' => true]]);
    };
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v1/customers') {
            return Http::response(['id' => 'cus_public_fixture']);
        }
        if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
            $id = $request['metadata']['website_payment_id'] ?? $request['metadata[website_payment_id]'];

            return Http::response(['id' => 'cs_public_fixture_'.$id, 'url' => 'https://checkout.stripe.com/c/pay/cs_public_fixture_'.$id]);
        }
        throw new RuntimeException('Unexpected fictional invoice request: '.$request->method().' '.$path);
    });
});

function exportPublicInvoiceQa(string $name, \Illuminate\Testing\TestResponse $response): void
{
    $directory = getenv('PUBLIC_INVOICE_QA_EXPORT_DIR');
    if (! $directory) {
        return;
    }
    abort_unless(app()->environment('testing') && config('database.connections.sqlite.database') === ':memory:', 500);
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($directory.'/'.$name.'.html', $response->getContent());
}

test('private public quote shows frozen delivery tax discount and deposit terms before accepting without any charge', function (): void {
    $order = ($this->issued)();
    $this->variant->update(['price_cents' => 99999]);
    $response = $this->get(($this->publicUrl)($order))->assertOk()->assertSeeText('Your quote')->assertSeeText('Fictional finished oak barrel')
        ->assertSeeText('Fictional local delivery on Friday after 3 PM')->assertSeeText('Deposit $50.00; remaining balance $202.50')
        ->assertSeeText('$252.50')->assertSeeText('Accept quote')->assertDontSeeText('Pay in full')->assertDontSeeText('Private staff note must stay off the customer view')
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    expect(WebsitePayment::count())->toBe(0)->and(WebsiteInventoryReservation::count())->toBe(0);
    Http::assertNothingSent();
    exportPublicInvoiceQa('public-quote', $response);
});

test('quote acceptance requires the issued version and agreement and never starts checkout', function (): void {
    $order = ($this->issued)();
    $url = ($this->publicUrl)($order);
    $this->postJson($url.'/accept', ['version' => 2, 'agree' => true])->assertConflict();
    $this->postJson($url.'/accept', ['version' => 1])->assertUnprocessable();
    $this->from($url)->post($url.'/accept', ['version' => 1, 'agree' => true])->assertRedirect($url);
    $this->from($url)->post($url.'/accept', ['version' => 1, 'agree' => true])->assertRedirect($url);
    expect($order->fresh()->order_status)->toBe('open')->and($order->events()->where('event_type', 'quote_accepted')->count())->toBe(1)
        ->and(WebsitePayment::count())->toBe(0);
    Http::assertNothingSent();
});

test('public lookup rejects another published tenant unknown hosts malformed tokens and unissued drafts', function (): void {
    $order = ($this->issued)();
    $other = Tenant::create(['name' => 'Fictional Other Merchant', 'slug' => 'other-public-invoice-qa']);
    $this->actor->tenants()->attach($other, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $other->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set('managed_website.editor_tenant_ids', [$this->tenant->id, $other->id]);
    config()->set('managed_website.invoice_tenant_ids', [$this->tenant->id, $other->id]);
    $websites = app(ManagedWebsiteService::class);
    $otherSite = $websites->createSite($other, $this->actor);
    $websites->publish($otherSite, $this->actor);
    $foreign = 'https://'.$other->slug.'.theeverbranch.com/invoices/'.$order->lookup_token;
    foreach ([$foreign, 'https://unknown-invoice-host.example/invoices/'.$order->lookup_token] as $url) {
        $this->get($url)->assertNotFound()->assertDontSeeText('Fictional Invoice Buyer');
        $this->postJson($url.'/pay', ['version' => 1, 'installment' => 'full'])->assertNotFound();
    }
    $this->get('https://unknown-invoice-host.example/invoices/'.$order->lookup_token.'?tenant='.$this->tenant->slug)->assertNotFound();
    $this->get('https://'.$this->site->subdomain.'.theeverbranch.com/invoices/invalid-token')->assertNotFound();
    $draft = $this->quotes->create($this->site, [...$this->input, 'request_id' => (string) Str::uuid()], $this->actor);
    $this->get(($this->publicUrl)($draft))->assertNotFound();
    Http::assertNothingSent();
});

test('unaccepted or stale invoices and forged bank choices cannot start provider checkout', function (): void {
    $order = ($this->issued)();
    $url = ($this->publicUrl)($order);
    $this->postJson($url.'/pay', ['version' => 1, 'installment' => 'full'])->assertUnprocessable();
    $this->quotes->accept($order, 1);
    $this->postJson($url.'/pay', ['version' => 2, 'installment' => 'full'])->assertConflict();
    $this->postJson($url.'/pay', ['version' => 1, 'installment' => 'full', 'payment_method' => 'us_bank_account'])->assertStatus(423);
    $this->postJson($url.'/pay', ['version' => 1, 'installment' => 'full', 'payment_method' => 'crypto'])->assertUnprocessable();
    expect(WebsitePayment::count())->toBe(0)->and(WebsiteInventoryReservation::count())->toBe(0);
    Http::assertNothingSent();
});

test('card payment redirects to the provider URL and ignores user supplied destination amount and merchant', function (): void {
    $order = ($this->issued)(true);
    $url = ($this->publicUrl)($order);
    $this->post($url.'/pay', ['version' => 1, 'installment' => 'deposit', 'payment_method' => 'card',
        'return_url' => 'https://unrelated.example/private', 'success_url' => 'https://unrelated.example/private',
        'amount_cents' => 1, 'merchant_id' => 'acct_unrelated'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_public_fixture_1')->assertHeader('Referrer-Policy', 'no-referrer');
    expect(WebsitePayment::sole()->amount_cents)->toBe(5000)->and(data_get(WebsitePayment::sole()->metadata, 'account_id'))->toBe('acct_publicInvoiceFixture');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/checkout/sessions') && $request->method() === 'POST'
        && str_starts_with($request['success_url'], 'https://'.$this->site->subdomain.'.theeverbranch.com/invoices/')
        && ! str_contains($request['success_url'], 'unrelated.example'));
});

test('bank choice appears only after both rollout and verified merchant capability are ready', function (): void {
    $order = ($this->issued)(true);
    $url = ($this->publicUrl)($order);
    $card = $this->get($url)->assertOk()->assertDontSee('name="payment_method"', false)->assertSeeText('Pay deposit $50.00');
    exportPublicInvoiceQa('public-card-invoice', $card);
    config()->set('managed_website.invoice_bank_payments_enabled', true);
    $this->get($url)->assertOk()->assertDontSee('name="payment_method"', false);
    ($this->enableBank)();
    $bank = $this->get($url)->assertOk()->assertSee('value="us_bank_account"', false)->assertSeeText('Bank payments show Processing');
    exportPublicInvoiceQa('public-bank-invoice', $bank);
    expect(WebsitePayment::count())->toBe(0);
    Http::assertNothingSent();
});

test('card collection stays blocked when the merchant account is no longer verified', function (): void {
    $order = ($this->issued)(true);
    $this->account->update(['status' => 'onboarding', 'charges_enabled' => false]);
    $url = ($this->publicUrl)($order);
    $response = $this->get($url)->assertOk()->assertSeeText('$252.50')->assertSeeText('Online payment is temporarily unavailable')
        ->assertSeeText('Your invoice is saved')->assertDontSeeText('Pay in full')->assertDontSeeText('Pay deposit');
    exportPublicInvoiceQa('public-card-unready', $response);
    $this->postJson($url.'/pay', ['version' => 1, 'installment' => 'full', 'payment_method' => 'card'])->assertStatus(423);
    expect(WebsitePayment::count())->toBe(0)->and(WebsiteInventoryReservation::count())->toBe(0);
    Http::assertNothingSent();
});

test('pending processing and refund review surfaces never claim paid or offer another new charge', function (): void {
    ($this->enableBank)();
    $order = ($this->issued)(true);
    $url = ($this->publicUrl)($order);
    $payment = WebsitePayment::create(['tenant_id' => $this->tenant->id, 'website_order_id' => $order->id, 'provider' => 'stripe', 'status' => 'processing', 'amount_cents' => $order->total_cents,
        'currency' => 'usd', 'metadata' => ['checkout_version' => 2, 'paid_confirmed' => false, 'installment' => 'full', 'payment_method' => 'us_bank_account']]);
    $order->update(['payment_status' => 'processing']);
    $processing = $this->get($url.'?payment=paid&status=paid&success=1')->assertOk()->assertSeeText('Payment confirmation is pending')
        ->assertSeeText('Check payment status')->assertDontSeeText('Paid in full')->assertDontSeeText('Pay in full')->assertDontSeeText('Continue secure payment');
    exportPublicInvoiceQa('public-processing-invoice', $processing);
    expect($order->fresh()->payment_status)->toBe('processing');
    $payment->update(['status' => 'pending']);
    $this->get($url)->assertOk()->assertSeeText('Continue secure payment')->assertDontSeeText('Pay in full');
    $this->account->update(['status' => 'onboarding', 'charges_enabled' => false]);
    $this->get($url)->assertOk()->assertSeeText('Check payment status')->assertDontSeeText('Continue secure payment')->assertDontSeeText('Pay in full');
    $payment->update(['status' => 'paid', 'metadata' => [...$payment->metadata, 'paid_confirmed' => true, 'refunds' => ['fictional-refund' => ['amount_cents' => 1000, 'status' => 'requesting']]]]);
    $order->update(['payment_status' => 'payment_review']);
    $review = $this->get($url)->assertOk()->assertSeeText('review this invoice before making another payment')->assertDontSeeText('Paid in full')->assertDontSeeText('Pay in full');
    exportPublicInvoiceQa('public-refund-review', $review);
    Http::assertNothingSent();
});

test('admin quote invoice coupon and payment setup forms render without provider secrets or automatic calls', function (): void {
    $order = ($this->issued)();
    $this->actingAs($this->actor);
    foreach (['admin-create' => '/website/invoices/create', 'admin-discounts' => '/website/invoices/discounts', 'admin-review' => '/website/invoices/'.$order->id, 'admin-payment-setup' => '/payments'] as $name => $path) {
        // Each browser fixture represents a fresh document request.
        app('livewire')->flushState();
        $response = $this->get('http://localhost'.$path.'?tenant='.$this->tenant->slug)->assertOk()
            ->assertDontSee('sk_test_public_invoice_fixture')->assertDontSee('private-paypal-fixture');
        expect($response->getContent())->not->toMatch('/<input[^>]+name=["\x27](?:api_key|client_secret|access_token|refresh_token|private_key)["\x27]/i');
        exportPublicInvoiceQa($name, $response);
    }
    $this->account->delete();
    app('livewire')->flushState();
    $setup = $this->get('http://localhost/payments?tenant='.$this->tenant->slug)->assertOk()
        ->assertSeeText('Sign in to Stripe')->assertSeeText('Create a Stripe account')->assertSeeText('Connect PayPal')
        ->assertDontSee('sk_test_public_invoice_fixture')->assertDontSee('private-paypal-fixture');
    exportPublicInvoiceQa('admin-payment-setup-new', $setup);
    Http::assertNothingSent();
});
