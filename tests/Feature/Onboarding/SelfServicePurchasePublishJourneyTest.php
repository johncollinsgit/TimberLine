<?php

use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingReceipt;
use App\Models\TenantCommercialOverride;
use App\Models\TenantSite;
use App\Models\User;
use App\Notifications\SelfServiceVerifyEmail;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsitePilotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

test('a verified public buyer purchases once and publishes a general website without operator rollout', function (): void {
    $this->withoutVite();
    Notification::fake();
    Http::preventStrayRequests();
    config()->set('self_service.registration_enabled', true);
    config()->set('self_service_purchase.enabled', true);
    config()->set('self_service_purchase.webhook_verified', true);
    config()->set('self_service_purchase.automatic_tax_enabled', true);
    config()->set('services.stripe.secret', 'sk_test_journey_fixture');
    config()->set('services.stripe.account_id', 'acct_journey');
    config()->set('services.stripe.webhook_secret', 'whsec_journey');
    config()->set('services.stripe.api_base', 'https://api.stripe.com');
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
    config()->set('managed_website.editor_tenant_ids', []);

    $this->post(route('platform.build.save'), ['template' => 'custom', 'continue' => 1])->assertRedirect(route('self-service.signup'));
    $this->post(route('self-service.signup.store'), [
        'name' => 'Fictional Studio Owner', 'email' => 'journey-owner@example.test',
        'password' => 'a-long-fictional-password', 'password_confirmation' => 'a-long-fictional-password', 'terms' => 1,
    ])->assertRedirect(route('self-service.verify.notice'));
    $user = User::query()->where('email', 'journey-owner@example.test')->firstOrFail();
    $verificationUrl = null;
    Notification::assertSentTo($user, SelfServiceVerifyEmail::class, function ($notification) use ($user, &$verificationUrl): bool {
        $verificationUrl = $notification->toMail($user)->actionUrl;

        return true;
    });
    $this->get($verificationUrl)->assertRedirect(route('workspace.first-login'));
    $this->post(route('workspace.first-login.store'), [
        'workspace_name' => 'Fictional Journey Studio', 'template_key' => 'generic', 'start_path' => 'self',
    ])->assertRedirectContains('/workspace/purchase');
    $tenant = $user->tenants()->firstOrFail();
    expect($tenant->pivot->role)->toBe('owner');
    // Rehydrate the authenticated account as the following real HTTP request
    // does after the provisioner promotes its previously pending role.
    $this->actingAs($user->refresh());
    $this->get(route('managed-website.index', ['tenant' => $tenant->slug]))->assertStatus(403);
    $page = $this->get(route('self-service.purchase', ['tenant' => $tenant->slug]))->assertOk();
    $quote = $page->viewData('quote');
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeFalse();

    $intent = fn (): array => (array) data_get(TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()?->billing_mapping, 'self_service_purchase');
    $session = function (bool $paid) use ($intent, $tenant): array {
        $saved = $intent();

        return ['id' => 'cs_journey', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/journey',
            'mode' => 'subscription', 'status' => $paid ? 'complete' : 'open', 'payment_status' => $paid ? 'paid' : 'unpaid',
            'client_reference_id' => $saved['id'], 'currency' => 'usd', 'amount_subtotal' => 79800, 'livemode' => false,
            'customer' => $paid ? 'cus_journey' : null, 'invoice' => $paid ? 'in_journey' : null, 'subscription' => $paid ? 'sub_journey' : null,
            'metadata' => ['purpose' => 'self_service_standard', 'purchase_id' => $saved['id'], 'quote_hash' => $saved['quote']['quote_hash'], 'tenant_id' => (string) $tenant->id],
        ];
    };
    Http::fake(function ($request) use ($intent, $session) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $result = match ($path) {
            '/v1/account' => ['id' => 'acct_journey', 'charges_enabled' => true],
            '/v1/tax/settings' => ['status' => 'active'],
            '/v1/billing_portal/sessions' => ['id' => 'bps_journey', 'object' => 'billing_portal.session', 'customer' => 'cus_journey', 'livemode' => false, 'url' => 'https://billing.stripe.com/p/session/journey'],
            '/v1/checkout/sessions', '/v1/checkout/sessions/cs_journey' => $session($request->method() !== 'POST'),
            '/v1/subscriptions/sub_journey' => [
                'id' => 'sub_journey', 'object' => 'subscription', 'status' => 'active', 'livemode' => false,
                'customer' => 'cus_journey', 'latest_invoice' => 'in_journey', 'current_period_end' => now()->addMonth()->timestamp,
                'metadata' => ['purchase_id' => $intent()['id']],
                'items' => ['data' => [['quantity' => 1, 'price' => ['currency' => 'usd', 'unit_amount' => 29900, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]],
            ],
            '/v1/invoices/in_journey' => [
                'id' => 'in_journey', 'object' => 'invoice', 'status' => 'paid', 'paid' => true, 'livemode' => false,
                'currency' => 'usd', 'customer' => 'cus_journey', 'subscription' => 'sub_journey', 'payment_intent' => 'pi_journey',
                'subtotal' => 79800, 'total' => 79800, 'amount_paid' => 79800, 'amount_remaining' => 0,
                'status_transitions' => ['paid_at' => now()->timestamp],
            ],
            '/v1/payment_intents/pi_journey' => [
                'id' => 'pi_journey', 'status' => 'succeeded', 'amount_received' => 79800, 'currency' => 'usd', 'customer' => 'cus_journey', 'livemode' => false,
                'latest_charge' => ['id' => 'ch_journey', 'amount_refunded' => 0, 'disputed' => false],
            ],
            default => null,
        };

        return Http::response($result ?? ['error' => 'Unexpected fixture request'], $result === null ? 404 : 200);
    });
    $this->post(route('self-service.purchase.checkout', ['tenant' => $tenant->slug]), [
        'branches' => $quote['branches'], 'quote_hash' => $quote['quote_hash'], 'terms_accepted' => 1,
    ])->assertRedirect('https://checkout.stripe.com/c/pay/journey');
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeFalse();
    $this->post(route('platform.build.save'), ['branches' => ['customers']])->assertRedirect(route('platform.build'));
    $pendingPage = $this->get(route('self-service.purchase', ['tenant' => $tenant->slug]))->assertOk()->assertDontSeeText('Change template');
    expect($pendingPage->viewData('quote')['quote_hash'])->toBe($quote['quote_hash'])
        ->and($pendingPage->viewData('selected'))->toContain('managed_website');

    $payload = json_encode(['id' => 'evt_journey_paid', 'type' => 'checkout.session.completed', 'livemode' => false,
        'created' => now()->timestamp, 'data' => ['object' => $session(true)]], JSON_THROW_ON_ERROR);
    $time = now()->timestamp;
    $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_journey');
    $deliver = fn () => $this->call('POST', route('billing.webhooks.stripe-events'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
    $deliver()->assertOk();
    $providerCalls = Http::recorded()->count();
    $deliver()->assertOk();
    expect(Http::recorded()->count())->toBe($providerCalls);
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeTrue()
        ->and(TenantBillingReceipt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(TenantBillingFulfillment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
    $this->get(route('self-service.purchase', ['tenant' => $tenant->slug]))->assertOk()->assertSeeText('Build your website');
    $this->get(route('managed-website.index', ['tenant' => $tenant->slug]))->assertOk();

    // Prepare actual draft content through the same services as the editor.
    // A fictional .test email avoids an external DNS dependency in this test.
    $websites = app(ManagedWebsiteService::class);
    $site = $websites->createSite($tenant, $user);
    $websites->applyTheme($site, 'collins-electric', $user);
    $site->refresh();
    app(WebsitePilotService::class)->saveSetup($tenant, $site, ['contact_name' => $tenant->name, 'contact_email' => $user->email, 'theme_key' => 'collins-electric'], $user);
    $this->post(route('managed-website.publish', ['tenant' => $tenant->slug]))->assertRedirect()->assertSessionHasNoErrors();
    expect(TenantSite::query()->where('tenant_id', $tenant->id)->first()->public_enabled)->toBeTrue();
    $this->get('https://'.$tenant->slug.'.theeverbranch.com/')->assertOk()->assertSee('data-testid="managed-site"', false);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST' && parse_url($request->url(), PHP_URL_PATH) === '/v1/checkout/sessions')->count())->toBe(1);
    \App\Models\TenantBillingSubscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['current_period_ends_at' => now()->subMinute()]);
    $this->get(route('self-service.purchase', ['tenant' => $tenant->slug]))->assertOk()
        ->assertSeeText('Manage billing')->assertDontSeeText('Continue to payment')->assertDontSeeText('Build your website');
    $this->post(route('self-service.purchase.portal', ['tenant' => $tenant->slug]))->assertRedirect('https://billing.stripe.com/p/session/journey');
    expect(TenantBillingReceipt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});
