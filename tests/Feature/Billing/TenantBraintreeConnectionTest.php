<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Services\Billing\TenantBraintreeConnectionService;
use App\Services\Billing\TenantBraintreeGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional Braintree merchant', 'slug' => 'braintree-test']);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct']);
    TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'enabled_override' => true]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    config()->set('tenant_payments.setup_enabled', true);
    config()->set('tenant_payments.tenant_slugs', [$this->tenant->slug]);
    config()->set('tenant_payments.braintree', ['setup_enabled' => true, 'tenant_ids' => [$this->tenant->id]]);
    config()->set('services.stripe.secret', 'sk_test_fictional');
    config()->set('services.stripe.account_id', 'acct_fictional');
    config()->set('commercial.billing_readiness.tenant_payments', ['enabled' => true, 'tenant_slugs' => [$this->tenant->slug]]);
    $this->input = ['environment' => 'sandbox', 'merchant_id' => 'fictionalmerchant', 'merchant_account_id' => 'fictionalUSD',
        'braintree_public_key' => 'fictional_public_key', 'password' => 'private_canary_987654', 'label' => 'Fictional business', 'reviewed' => '1'];
    $this->gateway = Mockery::mock(TenantBraintreeGateway::class);
    $this->app->instance(TenantBraintreeGateway::class, $this->gateway);
    $this->service = app(TenantBraintreeConnectionService::class);
});

function braintreeAccountRead($case, array $changes = []): void
{
    $case->gateway->shouldReceive('account')->andReturn(array_replace(['id' => 'fictionalUSD', 'status' => 'active', 'currency' => 'USD'], $changes));
}

test('Braintree connects encrypted credentials without replacing Stripe or authorizing collection', function () {
    braintreeAccountRead($this);
    $stripe = TenantPaymentAccount::create(['tenant_id' => $this->tenant->id, 'provider' => 'stripe_connect', 'provider_account_id' => 'acct_retained', 'status' => 'restricted']);
    $connection = $this->service->connect($this->tenant, $this->owner, $this->input);
    $again = $this->service->connect($this->tenant, $this->owner, $this->input);
    expect($connection->status)->toBe('connected')->and($again->id)->toBe($connection->id)
        ->and(data_get($connection->metadata, 'collection_enabled'))->toBeFalse()
        ->and(data_get($connection->metadata, 'vault_migration_enabled'))->toBeFalse()
        ->and($stripe->fresh()->provider_account_id)->toBe('acct_retained')
        ->and(DB::table('integration_connections')->where('id', $connection->id)->value('access_token'))->not->toContain('private_canary_987654')
        ->and(json_encode($connection))->not->toContain('private_canary_987654', 'fictional_public_key');
    Http::assertNothingSent();
});

test('Braintree setup rejects disabled rollout and inactive ownership before provider reads', function () {
    $this->gateway->shouldNotReceive('account');
    config()->set('tenant_payments.braintree.setup_enabled', false);
    expect(fn () => $this->service->connect($this->tenant, $this->owner, $this->input))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config()->set('tenant_payments.braintree.setup_enabled', true);
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'employee']);
    expect(fn () => $this->service->connect($this->tenant, $this->owner, $this->input))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(IntegrationConnection::count())->toBe(0);
});

test('Braintree account mismatch and suspended accounts cannot be saved', function (array $change) {
    braintreeAccountRead($this, $change);
    expect(fn () => $this->service->connect($this->tenant, $this->owner, $this->input))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(IntegrationConnection::count())->toBe(0);
})->with([[['id' => 'different_account']], [['status' => 'suspended']]]);

test('Braintree refresh and vault inspection cannot select another tenants connection', function () {
    braintreeAccountRead($this);
    $connection = $this->service->connect($this->tenant, $this->owner, $this->input);
    $other = Tenant::create(['name' => 'Other business', 'slug' => 'braintree-other']);
    TenantAccessProfile::create(['tenant_id' => $other->id, 'plan_key' => 'starter', 'operating_mode' => 'direct']);
    TenantModuleState::create(['tenant_id' => $other->id, 'module_key' => 'managed_website', 'enabled_override' => true]);
    $this->owner->tenants()->attach($other, ['role' => 'owner', 'membership_active' => true]);
    config()->set('tenant_payments.tenant_slugs', [$this->tenant->slug, $other->slug]);
    config()->set('tenant_payments.braintree.tenant_ids', [$this->tenant->id, $other->id]);
    $this->gateway->shouldNotReceive('vaultedMethod');
    expect(fn () => $this->service->inspectVault($other, $this->owner, $connection->id, 'customer123', 'token123'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(fn () => $this->service->refresh($other, $this->owner, $connection->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('Braintree vault proof stays masked and never becomes source-stop or charge permission', function () {
    braintreeAccountRead($this);
    $connection = $this->service->connect($this->tenant, $this->owner, $this->input);
    $this->gateway->shouldReceive('vaultedMethod')->once()->with(Mockery::type('array'), 'customer123', 'private_token123')
        ->andReturn(['customer_verified' => true, 'type' => 'paypal', 'last_four' => null, 'expired' => null]);
    $proof = $this->service->inspectVault($this->tenant, $this->owner, $connection->id, 'customer123', 'private_token123');
    expect($proof['collection_enabled'])->toBeFalse()->and($proof['source_billing_stopped'])->toBeFalse()
        ->and(json_encode($proof))->not->toContain('private_token123', 'private_canary_987654');
});

test('Braintree credential changes cannot silently rewrite an existing connection', function () {
    braintreeAccountRead($this);
    $connection = $this->service->connect($this->tenant, $this->owner, $this->input);
    expect(fn () => $this->service->connect($this->tenant, $this->owner, array_replace($this->input, ['password' => 'different_private_key'])))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(json_decode($connection->fresh()->access_token, true)['private_key'])->toBe($this->input['password']);
});

test('Braintree setup screen is offline and never renders stored credentials', function () {
    braintreeAccountRead($this);
    $this->service->connect($this->tenant, $this->owner, $this->input);
    $this->actingAs($this->owner)->get('/payments?tenant='.$this->tenant->slug)->assertOk()
        ->assertSeeText('Use your existing Braintree account')->assertSeeText('Customer collection is not enabled.')
        ->assertDontSee('private_canary_987654')->assertDontSee('fictional_public_key');
    Http::assertNothingSent();
});

test('Braintree credential validation never flashes either API key to the session', function () {
    $this->gateway->shouldNotReceive('account');
    $this->actingAs($this->owner)->from('/payments?tenant='.$this->tenant->slug)
        ->post('/payments/braintree/connect?tenant='.$this->tenant->slug, array_replace($this->input, ['reviewed' => false]))
        ->assertSessionHasErrors('reviewed');
    expect(session('_old_input.password'))->toBeNull()->and(session('_old_input.braintree_public_key'))->toBeNull();
});
