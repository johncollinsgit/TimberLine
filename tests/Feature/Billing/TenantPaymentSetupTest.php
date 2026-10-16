<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Services\Billing\TenantPayPalConnectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    config()->set('logging.default', 'null');
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Payment Pilot', 'slug' => 'payment-pilot']);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct']);
    TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'enabled_override' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    config()->set('tenant_payments.setup_enabled', true);
    config()->set('tenant_payments.tenant_slugs', [$this->tenant->slug]);
    config()->set('tenant_payments.paypal', ['enabled' => true, 'environment' => 'sandbox', 'partner_approved' => false,
        'client_id' => 'fictional-client', 'client_secret' => 'fictional-secret', 'partner_id' => 'PARTNER12345', 'bn_code' => 'fictional-bn']);
    config()->set('services.stripe.secret', 'sk_test_fictional');
    config()->set('services.stripe.account_id', 'acct_platformfictional');
    config()->set('commercial.billing_readiness.tenant_payments', ['enabled' => true, 'tenant_slugs' => [$this->tenant->slug]]);
});

function paymentSetupUrl($test, string $path = ''): string
{
    return '/payments'.$path.'?tenant='.$test->tenant->slug;
}

function paypalSetupSeed($test): IntegrationConnection
{
    Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'fictional-access']),
        '*/v2/customer/partner-referrals' => Http::response(['links' => [['rel' => 'action_url', 'href' => 'https://www.sandbox.paypal.com/signup']]], 201)]);
    $test->actingAs($test->owner)->post(paymentSetupUrl($test, '/paypal/start'), ['share_data_consent' => '1'])->assertRedirect('https://www.sandbox.paypal.com/signup');

    return app(TenantPayPalConnectionService::class)->connection($test->tenant);
}

function paypalSetupEvidence(IntegrationConnection $connection, array $changes = []): array
{
    return array_replace_recursive([
        'tracking_id' => json_decode($connection->external_account_secret, true)['tracking_id'], 'merchant_id' => 'MERCHANT12345',
        'payments_receivable' => true, 'primary_email_confirmed' => true,
        'oauth_integrations' => [['oauth_third_party' => [['partner_client_id' => 'fictional-client', 'scopes' => [
            'https://uri.paypal.com/services/payments/payment/authcapture', 'https://uri.paypal.com/services/payments/refund',
        ]]]]],
    ], $changes);
}

it('renders one payment setup page without contacting providers or claiming PayPal checkout is active', function () {
    $this->actingAs($this->owner)->get(paymentSetupUrl($this))->assertOk()->assertSeeText('Activate card payments')
        ->assertSeeText('Connect PayPal')->assertSeeText('PayPal checkout is not active yet.')
        ->assertHeader('Cache-Control');
    expect($this->get(paymentSetupUrl($this))->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    Http::assertNothingSent();
});

it('fails closed for disabled rollout, inactive members and employees before provider calls', function () {
    config()->set('tenant_payments.setup_enabled', false);
    $this->actingAs($this->owner)->get(paymentSetupUrl($this))->assertNotFound();
    config()->set('tenant_payments.setup_enabled', true);
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'employee']);
    $this->post(paymentSetupUrl($this, '/paypal/start'), ['share_data_consent' => '1'])->assertForbidden();
    $this->post(paymentSetupUrl($this, '/stripe/session'))->assertForbidden();
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'owner', 'membership_active' => false]);
    expect(app(\App\Services\Billing\TenantPaymentSetupAccess::class)->allows($this->tenant, $this->owner))->toBeFalse();
    Http::assertNothingSent();
});

it('requires the tenant allowlist and a canonical enabled Branch', function () {
    config()->set('tenant_payments.tenant_slugs', []);
    $this->actingAs($this->owner)->get(paymentSetupUrl($this))->assertNotFound();
    config()->set('tenant_payments.tenant_slugs', [$this->tenant->slug]);
    TenantModuleState::where('tenant_id', $this->tenant->id)->update(['enabled_override' => false]);
    $this->get(paymentSetupUrl($this))->assertNotFound();
    Http::assertNothingSent();
});

it('requires explicit sharing consent and protects callback state', function () {
    $this->actingAs($this->owner)->post(paymentSetupUrl($this, '/paypal/start'))->assertSessionHasErrors('share_data_consent');
    $this->get(paymentSetupUrl($this, '/paypal/return').'&setup_state=forged&permissionsGranted=true&merchantIdInPayPal=ATTACKER')->assertForbidden();
    Http::assertNothingSent();
});

it('connects PayPal only from server-owned tracking and verified partner permissions', function () {
    $connection = paypalSetupSeed($this);
    $state = session('tenant_payments.paypal.'.$this->tenant->id);
    expect($state)->toHaveKeys(['state_hash', 'user_id', 'expires']);
    $tracking = json_decode($connection->external_account_secret, true)['tracking_id'];
    expect(DB::table('integration_connections')->where('id', $connection->id)->value('external_account_secret'))->not->toContain($tracking);
    expect($connection->toArray())->not->toHaveKey('external_account_secret');
    Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'fictional-access']),
        '*/merchant-integrations*' => Http::response(paypalSetupEvidence($connection))]);
    $this->post(paymentSetupUrl($this, '/paypal/refresh'), ['merchant_id' => 'ATTACKER'])->assertRedirect();
    expect($connection->fresh()->status)->toBe('connected');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/merchant-integrations?tracking_id='.$tracking));
    expect(TenantPaymentAccount::count())->toBe(0);
});

it('rejects unverified email, forged tracking, disabled receivables and wrong partner grants', function ($change) {
    $connection = paypalSetupSeed($this);
    Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'fictional-access']),
        '*/merchant-integrations*' => Http::response(paypalSetupEvidence($connection, $change))]);
    $this->post(paymentSetupUrl($this, '/paypal/refresh'))->assertRedirect();
    expect($connection->fresh()->status)->toBe('pending');
})->with([
    'email' => [['primary_email_confirmed' => false]],
    'tracking' => [['tracking_id' => 'not-our-tracking']],
    'receivables' => [['payments_receivable' => false]],
    'partner' => [['oauth_integrations' => [['oauth_third_party' => [['partner_client_id' => 'someone-else']]]]]],
]);

it('keeps sandbox accounts separate and requires platform approval for live PayPal', function () {
    $connection = paypalSetupSeed($this);
    config()->set('tenant_payments.paypal.environment', 'live');
    $service = app(TenantPayPalConnectionService::class);
    expect($service->configured())->toBeFalse()->and($service->connection($this->tenant))->toBeNull();
    $this->postJson(paymentSetupUrl($this, '/paypal/start'), ['share_data_consent' => '1'])->assertStatus(423);
    config()->set('tenant_payments.paypal.partner_approved', true);
    expect($service->configured())->toBeTrue();
    config()->set('tenant_payments.paypal.environment', 'wrong');
    expect($service->configured())->toBeFalse();
    expect($connection->fresh()->status)->toBe('pending');
});

it('ignores PayPal callback success flags and consumes only a valid owner session return', function () {
    $connection = paypalSetupSeed($this);
    $referral = Http::recorded(fn ($request) => str_ends_with($request->url(), '/v2/customer/partner-referrals'))->first()[0];
    $return = $referral['partner_config_override']['return_url'].'&permissionsGranted=true&merchantIdInPayPal=ATTACKER';
    Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'fictional-access']),
        '*/merchant-integrations*' => Http::response(paypalSetupEvidence($connection, ['payments_receivable' => false]))]);
    $this->get($return)->assertRedirect();
    expect($connection->fresh()->status)->toBe('pending');
    $this->get($return)->assertForbidden();
});

it('does not let an older PayPal check overwrite a newer disconnected result', function () {
    $connection = paypalSetupSeed($this);
    Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'fictional-access']),
        '*/merchant-integrations*' => function () use ($connection) {
            $current = $connection->fresh();
            $secret = json_decode($current->external_account_secret, true);
            $secret['check_id'] = 'a-newer-verification';
            $current->update(['external_account_secret' => json_encode($secret), 'status' => 'pending', 'last_synced_at' => now()]);

            return Http::response(paypalSetupEvidence($connection));
        }]);
    $this->postJson(paymentSetupUrl($this, '/paypal/refresh'))->assertConflict();
    expect($connection->fresh()->status)->toBe('pending');
});

it('does not expose another tenants payment setup even to a platform operator without membership', function () {
    $other = Tenant::create(['name' => 'Other Workspace', 'slug' => 'another-payment-workspace']);
    config()->set('tenant_payments.tenant_slugs', [$this->tenant->slug, $other->slug]);
    $operator = User::factory()->create(['role' => 'platform_admin', 'is_active' => true, 'email_verified_at' => now()]);
    expect(app(\App\Services\Billing\TenantPaymentSetupAccess::class)->allows($other, $operator))->toBeFalse();
    $this->actingAs($this->owner)->post('/payments/paypal/refresh?tenant='.$other->slug)->assertForbidden();
    Http::assertNothingSent();
});

it('does not follow a provider-supplied redirect to an unknown host', function () {
    Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'fictional-access']),
        '*/v2/customer/partner-referrals' => Http::response(['links' => [['rel' => 'action_url', 'href' => 'https://attacker.example/signup']]], 201)]);
    $this->actingAs($this->owner)->postJson(paymentSetupUrl($this, '/paypal/start'), ['share_data_consent' => '1'])->assertStatus(502);
});

it('lets local PayPal disconnect win over a pending status read', function () {
    $connection = paypalSetupSeed($this);
    Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'fictional-access']),
        '*/merchant-integrations*' => function () use ($connection) {
            app(TenantPayPalConnectionService::class)->disconnect($this->tenant, $this->owner);

            return Http::response(paypalSetupEvidence($connection));
        }]);
    $this->postJson(paymentSetupUrl($this, '/paypal/refresh'))->assertConflict();
    expect($connection->fresh()->status)->toBe('disconnected');
});

it('freezes a Stripe create request so changed emails do not break retries after a timeout', function () {
    $payloads = [];
    $keys = [];
    Http::fake(['https://api.stripe.com/v1/accounts' => function ($request) use (&$payloads, &$keys) {
        $payloads[] = $request->data();
        $keys[] = $request->header('Idempotency-Key')[0];

        return count($payloads) === 1 ? Http::failedConnection() : Http::response(['id' => 'acct_fictional']);
    }, 'https://api.stripe.com/v1/account_links' => Http::response(['url' => 'https://connect.stripe.com/setup/example'])]);
    $this->actingAs($this->owner)->postJson(paymentSetupUrl($this, '/stripe/start'), ['email' => 'first@example.com'])->assertStatus(502);
    config()->set('services.stripe.secret', 'sk_test_rotatedfictional');
    $this->post(paymentSetupUrl($this, '/stripe/start'), ['email' => 'changed@example.com'])->assertRedirect('https://connect.stripe.com/setup/example');
    expect($payloads[0])->toBe($payloads[1])->and($keys[0])->toBe($keys[1]);
    expect($this->tenant->paymentAccount()->first()->provider_account_id)->toBe('acct_fictional');
    $attempt = IntegrationConnection::where('provider', 'tenant_stripe_onboarding')->first();
    expect($attempt->getRawOriginal('external_account_secret'))->not->toContain('first@example.com');
});

it('explains missing Connect activation without displaying the provider response', function () {
    Http::fake(['https://api.stripe.com/v1/accounts' => Http::response(['error' => ['message' => 'You can only create new accounts if you have signed up for Connect. secret-debug-detail']], 400)]);
    $this->actingAs($this->owner)->postJson(paymentSetupUrl($this, '/stripe/start'), ['email' => 'owner@example.com'])
        ->assertStatus(502)->assertJsonPath('message', 'Everbranch must activate Stripe Connect before your business can connect. Your email address is not the problem.')
        ->assertDontSee('secret-debug-detail');
});

it('stops old ambiguous Stripe account requests before the idempotency window expires', function () {
    Http::fake(['https://api.stripe.com/v1/accounts' => Http::failedConnection()]);
    $this->actingAs($this->owner)->postJson(paymentSetupUrl($this, '/stripe/start'), ['email' => 'owner@example.com'])->assertStatus(502);
    IntegrationConnection::where('provider', 'tenant_stripe_onboarding')->update(['created_at' => now()->subHours(24)]);
    Http::fake();
    $this->postJson(paymentSetupUrl($this, '/stripe/start'), ['email' => 'owner@example.com'])->assertConflict();
    Http::assertNothingSent();
});

it('returns an owner-scoped noncacheable Stripe embedded session without storing the client secret', function () {
    config()->set('tenant_payments.stripe_embedded_enabled', true);
    config()->set('services.stripe.publishable_key', 'pk_test_fictional');
    Http::fake(['https://api.stripe.com/v1/accounts' => Http::response(['id' => 'acct_fictional']),
        'https://api.stripe.com/v1/account_sessions' => Http::response(['client_secret' => 'fictional-session-secret'])]);
    $this->actingAs($this->owner)->postJson(paymentSetupUrl($this, '/stripe/session'))->assertOk()
        ->assertJsonPath('client_secret', 'fictional-session-secret')->assertHeader('Cache-Control');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/account_sessions') && $request['account'] === 'acct_fictional'
        && $request['components']['account_onboarding']['enabled'] === 'true');
    expect(json_encode(IntegrationConnection::first()->toArray()))->not->toContain('fictional-session-secret');
});

it('verifies Stripe ownership before updating readiness', function () {
    TenantPaymentAccount::create(['tenant_id' => $this->tenant->id, 'provider' => 'stripe_connect', 'provider_account_id' => 'acct_fictional', 'status' => 'onboarding']);
    Http::fake(['https://api.stripe.com/v1/accounts/acct_fictional' => Http::response(['id' => 'acct_fictional', 'charges_enabled' => true, 'payouts_enabled' => true, 'metadata' => ['tenant_id' => 'someone-else']])]);
    $this->actingAs($this->owner)->postJson(paymentSetupUrl($this, '/stripe/refresh'))->assertStatus(502);
    expect($this->tenant->paymentAccount()->first()->status)->toBe('onboarding');
});
