<?php

use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingSubscription;
use App\Models\TenantCommercialOverride;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\Billing\SelfServiceStandardPurchaseService;
use App\Services\Billing\StripeWebhookIngestService;
use Illuminate\Support\Facades\Http;

function standardBuyer(): array
{
    $tenant = Tenant::query()->create(['name' => 'Northline Works', 'slug' => 'northline-works']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct', 'source' => 'self_service_registration', 'metadata' => ['self_service_registration' => true]]);
    $actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $actor->tenants()->attach($tenant->id, ['role' => 'owner']);

    return [$tenant, $actor];
}

function standardIntent(Tenant $tenant): array
{
    return (array) data_get(TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()->billing_mapping, 'self_service_purchase');
}

function standardSession(array $intent, bool $paid = true): array
{
    return ['id' => 'cs_standard', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/fixture',
        'mode' => 'subscription', 'status' => $paid ? 'complete' : 'open', 'payment_status' => $paid ? 'paid' : 'unpaid',
        'client_reference_id' => $intent['id'], 'currency' => 'usd', 'amount_subtotal' => 79800, 'livemode' => false,
        'customer' => $paid ? 'cus_standard' : null, 'invoice' => $paid ? 'in_standard' : null, 'subscription' => $paid ? 'sub_standard' : null,
        'metadata' => ['purpose' => 'self_service_standard', 'purchase_id' => $intent['id'], 'quote_hash' => $intent['quote']['quote_hash'], 'tenant_id' => (string) $intent['quote']['tenant_id']],
    ];
}

function standardProvider(Tenant $tenant, array $overrides = []): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(function ($request) use ($tenant, $overrides) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v1/account') {
            return Http::response(['id' => $overrides['account'] ?? 'acct_standard', 'charges_enabled' => true]);
        }
        if ($path === '/v1/tax/settings') {
            return Http::response(['status' => $overrides['tax_status'] ?? 'active']);
        }
        $intent = standardIntent($tenant);
        if ($path === '/v1/checkout/sessions' || $path === '/v1/checkout/sessions/cs_standard') {
            return Http::response([...standardSession($intent, $request->method() !== 'POST'), ...($overrides['session'] ?? [])]);
        }
        if ($path === '/v1/subscriptions/sub_standard') {
            return Http::response([
                'id' => 'sub_standard', 'object' => 'subscription', 'status' => $overrides['subscription_status'] ?? 'active', 'livemode' => false,
                'customer' => 'cus_standard', 'latest_invoice' => $overrides['latest_invoice'] ?? 'in_standard', 'current_period_end' => now()->addMonth()->timestamp,
                'metadata' => ['purchase_id' => $intent['id']],
                'items' => ['data' => [['quantity' => 1, 'price' => ['currency' => 'usd', 'unit_amount' => 29900, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]],
            ]);
        }
        if ($path === '/v1/invoices/in_standard') {
            return Http::response([
                'id' => 'in_standard', 'object' => 'invoice', 'status' => 'paid', 'paid' => true, 'livemode' => false,
                'currency' => 'usd', 'customer' => 'cus_standard', 'subscription' => 'sub_standard', 'payment_intent' => 'pi_standard',
                'subtotal' => 79800, 'total' => 79800, 'amount_paid' => 79800, 'amount_remaining' => 0, 'status_transitions' => ['paid_at' => now()->timestamp],
                ...($overrides['invoice'] ?? []),
            ]);
        }
        if ($path === '/v1/invoices/in_renewal') {
            return Http::response([
                'id' => 'in_renewal', 'object' => 'invoice', 'livemode' => false, 'status' => 'paid', 'paid' => true, 'currency' => 'usd', 'customer' => 'cus_standard', 'subscription' => 'sub_standard',
                'subtotal' => 29900, 'total' => 29900, 'amount_paid' => 29900, 'payment_intent' => 'pi_renewal',
                ...($overrides['renewal_invoice'] ?? []),
            ]);
        }
        if ($path === '/v1/charges/ch_renewal') {
            return Http::response([
                'id' => 'ch_renewal', 'object' => 'charge', 'livemode' => false, 'customer' => 'cus_standard',
                'invoice' => 'in_renewal', 'payment_intent' => 'pi_renewal', 'amount_refunded' => 0, 'disputed' => false,
                ...($overrides['renewal_charge'] ?? []),
            ]);
        }
        if ($path === '/v1/payment_intents/pi_renewal') {
            return Http::response(['id' => 'pi_renewal', 'status' => 'succeeded', 'amount_received' => 29900, 'currency' => 'usd', 'customer' => 'cus_standard', 'livemode' => false, 'latest_charge' => ['id' => 'ch_renewal', 'amount_refunded' => 0, 'disputed' => false, ...($overrides['renewal_charge'] ?? [])]]);
        }
        if ($path === '/v1/payment_intents/pi_standard') {
            return Http::response([
                'id' => 'pi_standard', 'status' => 'succeeded', 'amount_received' => 79800, 'currency' => 'usd', 'customer' => 'cus_standard', 'livemode' => false,
                'latest_charge' => ['id' => 'ch_standard', 'amount_refunded' => 0, 'disputed' => false], ...($overrides['payment'] ?? []),
            ]);
        }

        return Http::response(['error' => 'unexpected path'], 404);
    });
}

function startStandard(Tenant $tenant, User $actor, array $branches = ['managed_website']): array
{
    $service = app(SelfServiceStandardPurchaseService::class);
    $quote = $service->quote($tenant, $actor, $branches);

    return $service->checkout($tenant, $actor, $branches, ['terms_accepted' => true, 'quote_hash' => $quote['quote_hash']]);
}

function confirmStandard(Tenant $tenant, string $type = 'checkout.session.completed', string $id = 'evt_standard', array $changes = []): array
{
    $event = ['id' => $id, 'type' => $type, 'livemode' => false, 'created' => now()->timestamp,
        'data' => ['object' => standardSession(standardIntent($tenant))], ...$changes];
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $time = now()->timestamp;
    $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_standard');

    return app(StripeWebhookIngestService::class)->ingest($payload, $signature);
}

beforeEach(function (): void {
    config()->set('self_service_purchase.enabled', true);
    config()->set('self_service_purchase.webhook_verified', true);
    config()->set('services.stripe.secret', 'sk_test_standard_fixture');
    config()->set('services.stripe.account_id', 'acct_standard');
    config()->set('services.stripe.webhook_secret', 'whsec_standard');
    config()->set('services.stripe.api_base', 'https://api.stripe.com');
});

test('an inactive owner membership cannot quote or resume a pending purchase', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    $original = standardIntent($tenant);
    $actor->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake();
    $service = app(SelfServiceStandardPurchaseService::class);
    expect(fn () => $service->quote($tenant, $actor, ['managed_website']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $service->checkout($tenant, $actor, ['managed_website'], ['terms_accepted' => true, 'quote_hash' => $original['quote']['quote_hash']]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(standardIntent($tenant)['id'])->toBe($original['id'])
        ->and(TenantBillingFulfillment::withoutGlobalScopes()->count())->toBe(1);
    Http::assertNothingSent();
});

test('standard checkout is server priced and repeat attempts keep one durable purchase', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    expect(startStandard($tenant, $actor)['ok'])->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['line_items[0][price_data][unit_amount]'] === 49900 && $request['line_items[1][price_data][unit_amount]'] === 29900 && $request['automatic_tax[enabled]'] === 'true');
    standardProvider($tenant, ['session' => ['status' => 'open', 'payment_status' => 'unpaid', 'subscription' => null, 'invoice' => null]]);
    expect(startStandard($tenant, $actor)['ok'])->toBeTrue();
    expect(TenantBillingFulfillment::withoutGlobalScopes()->count())->toBe(1)
        ->and(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeFalse()
        ->and(TenantModuleEntitlement::withoutGlobalScopes()->count())->toBe(0);
});

test('only signed and authoritative provider payment confirms access once', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    expect(app(StripeWebhookIngestService::class)->ingest('{}', 'invalid')['status_code'])->toBe(400);
    expect(confirmStandard($tenant)['ok'])->toBeTrue();
    expect(confirmStandard($tenant)['message'])->toBe('duplicate');
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeTrue()
        ->and(TenantModuleEntitlement::withoutGlobalScopes()->where('module_key', 'managed_website')->value('enabled_status'))->toBe('enabled')
        ->and(TenantBillingReceipt::withoutGlobalScopes()->count())->toBe(1);
    expect(TenantAccessProfile::query()->where('tenant_id', $tenant->id)->first()->metadata['self_service_registration'])->toBeTrue();
});

test('mismatched account environment or unpaid evidence never grants access', function (array $providerChanges, array $eventChanges): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    standardProvider($tenant, $providerChanges);
    expect(confirmStandard($tenant, changes: $eventChanges)['ok'])->toBeFalse();
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeFalse()
        ->and(TenantModuleEntitlement::withoutGlobalScopes()->count())->toBe(0);
})->with([
    [['account' => 'acct_other'], []],
    [[], ['livemode' => true]],
    [[], ['account' => 'acct_connected']],
    [['invoice' => ['paid_out_of_band' => true]], []],
    [['payment' => ['status' => 'processing']], []],
    [['session' => ['amount_subtotal' => 1]], []],
]);

test('payment lifecycle preserves historical purchase but stops new publishing after cancellation', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    confirmStandard($tenant);
    standardProvider($tenant, ['subscription_status' => 'canceled']);
    expect(confirmStandard($tenant, 'customer.subscription.deleted', 'evt_cancel')['ok'])->toBeTrue();
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeFalse()
        ->and(app(SelfServicePurchaseEligibility::class)->hasVerifiedPurchase($tenant))->toBeTrue();
});

test('renewal refreshes paid coverage without duplicating the setup or receipt', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    confirmStandard($tenant);
    TenantBillingSubscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['current_period_ends_at' => now()->subMinute()]);
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeFalse();
    standardProvider($tenant, ['latest_invoice' => 'in_renewal']);
    expect(confirmStandard($tenant, 'invoice.paid', 'evt_renewal')['ok'])->toBeTrue();
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeTrue()
        ->and(TenantBillingReceipt::withoutGlobalScopes()->count())->toBe(1);
});

test('renewal refunds and disputes without copied metadata promptly suspend new paid access', function (string $type, array $chargeChanges): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    confirmStandard($tenant);
    standardProvider($tenant, ['latest_invoice' => 'in_renewal']);
    confirmStandard($tenant, 'invoice.paid', 'evt_paid_renewal', ['data' => ['object' => ['id' => 'in_renewal', 'object' => 'invoice', 'subscription' => 'sub_standard', 'customer' => 'cus_standard']]]);
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeTrue();

    standardProvider($tenant, ['latest_invoice' => 'in_renewal', 'renewal_charge' => $chargeChanges]);
    $object = $type === 'charge.refunded'
        ? ['id' => 'ch_renewal', 'object' => 'charge', 'invoice' => 'in_renewal', 'payment_intent' => 'pi_renewal', 'customer' => 'cus_standard']
        : ['id' => 'dp_renewal', 'object' => 'dispute', 'charge' => 'ch_renewal', 'payment_intent' => 'pi_renewal'];
    expect(confirmStandard($tenant, $type, 'evt_renewal_reversed', ['data' => ['object' => $object]])['ok'])->toBeTrue()
        ->and(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeFalse()
        ->and(app(SelfServicePurchaseEligibility::class)->hasVerifiedPurchase($tenant))->toBeTrue()
        ->and(standardIntent($tenant)['status'])->toBe('suspended')
        ->and(TenantBillingReceipt::withoutGlobalScopes()->count())->toBe(1);
    Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
})->with([
    ['charge.refunded', ['amount_refunded' => 29900]],
    ['charge.dispute.created', ['disputed' => true]],
]);

test('renewal charge routing cannot cross the authenticated customer and subscription relationship', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    confirmStandard($tenant);
    $other = Tenant::query()->create(['name' => 'Unrelated Customer', 'slug' => 'unrelated-renewal']);
    $otherIntent = [...standardIntent($tenant), 'id' => 'unrelated-purchase', 'subscription_id' => 'sub_other', 'customer_id' => 'cus_other'];
    TenantCommercialOverride::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'billing_mapping' => ['self_service_purchase' => $otherIntent]]);
    standardProvider($tenant, ['renewal_invoice' => ['subscription' => 'sub_other']]);
    $result = confirmStandard($tenant, 'charge.refunded', 'evt_mismatched_renewal', ['data' => ['object' => [
        'id' => 'ch_renewal', 'object' => 'charge', 'invoice' => 'in_renewal', 'payment_intent' => 'pi_renewal', 'customer' => 'cus_standard',
    ]]]);
    expect($result['ok'])->toBeFalse()
        ->and(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeTrue()
        ->and(standardIntent($other))->toBe($otherIntent)
        ->and(StripeWebhookEvent::query()->where('event_id', 'evt_mismatched_renewal')->exists())->toBeFalse();
    Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
});

test('renewal charge lookup never uses connected events or the wrong platform account', function (string $mismatch): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    confirmStandard($tenant);
    standardProvider($tenant, $mismatch === 'account' ? ['account' => 'acct_other'] : []);
    $changes = ['data' => ['object' => ['id' => 'ch_renewal', 'object' => 'charge', 'invoice' => 'in_renewal', 'customer' => 'cus_standard', 'payment_intent' => 'pi_renewal']]];
    if ($mismatch === 'connected') {
        $changes['account'] = 'acct_connected';
    } elseif ($mismatch === 'mode') {
        $changes['livemode'] = true;
    }
    confirmStandard($tenant, 'charge.refunded', 'evt_untrusted_renewal', $changes);
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeTrue();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/charges/'));
})->with(['account', 'connected', 'mode']);

test('paid owners add included branches without a second checkout', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    confirmStandard($tenant);
    Http::preventStrayRequests();
    $keys = app(SelfServiceStandardPurchaseService::class)->activateIncludedBranches($tenant, $actor, ['field_service']);
    expect($keys)->toContain('field_service', 'customers')
        ->and(TenantModuleEntitlement::withoutGlobalScopes()->where('module_key', 'field_service')->value('enabled_status'))->toBe('enabled')
        ->and(TenantBillingFulfillment::withoutGlobalScopes()->count())->toBe(1);
});

test('legacy accounts retain their existing agreement and mapping', function (): void {
    [$tenant, $actor] = standardBuyer();
    TenantAccessProfile::query()->where('tenant_id', $tenant->id)->update(['metadata' => []]);
    TenantCommercialOverride::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'billing_mapping' => ['stripe' => ['customer_reference' => 'cus_legacy']]]);
    standardProvider($tenant);
    expect(fn () => startStandard($tenant, $actor))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()->billing_mapping)->toBe(['stripe' => ['customer_reference' => 'cus_legacy']]);
});

test('a provider confirmed expired unpaid checkout can be replaced without accepting a late old event', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    $old = standardIntent($tenant);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(function ($request) use ($tenant, $old) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v1/account') {
            return Http::response(['id' => 'acct_standard', 'charges_enabled' => true]);
        }
        if ($path === '/v1/tax/settings') {
            return Http::response(['status' => 'active']);
        }
        if ($path === '/v1/checkout/sessions/cs_standard') {
            return Http::response([...standardSession($old, false), 'status' => 'expired']);
        }
        if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') {
            return Http::response([...standardSession(standardIntent($tenant), false), 'id' => 'cs_replacement']);
        }

        return Http::response([], 404);
    });
    expect(startStandard($tenant, $actor)['ok'])->toBeTrue();
    $current = standardIntent($tenant);
    expect($current['id'])->not->toBe($old['id'])
        ->and($current['session_id'])->toBe('cs_replacement')
        ->and(TenantBillingFulfillment::withoutGlobalScopes()->whereKey($old['fulfillment_id'])->value('status'))->toBe('expired');
    $late = confirmStandard($tenant, changes: ['data' => ['object' => standardSession($old)]]);
    expect($late['ok'])->toBeFalse()
        ->and(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeFalse();
});

test('unknown provider outcomes retain the original attempt and recover the same idempotency key', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    Http::swap(new \Illuminate\Http\Client\Factory);
    $keys = [];
    $attempts = 0;
    Http::fake(function ($request) use ($tenant, &$keys, &$attempts) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v1/account') {
            return Http::response(['id' => 'acct_standard', 'charges_enabled' => true]);
        }
        if ($path === '/v1/tax/settings') {
            return Http::response(['status' => 'active']);
        }
        if ($path === '/v1/checkout/sessions') {
            $keys[] = $request->header('Idempotency-Key')[0];
            if (++$attempts === 1) {
                throw new \RuntimeException('Lost provider response');
            }

            return Http::response(standardSession(standardIntent($tenant), false));
        }

        return Http::response([], 404);
    });
    expect(startStandard($tenant, $actor)['ok'])->toBeFalse();
    $id = standardIntent($tenant)['id'];
    expect(startStandard($tenant, $actor)['ok'])->toBeTrue();
    expect(standardIntent($tenant)['id'])->toBe($id)
        ->and(count(array_unique($keys)))->toBe(1)
        ->and(TenantBillingFulfillment::withoutGlobalScopes()->count())->toBe(1);
});

test('retained signed receipts retry after transient provider verification failure', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    standardProvider($tenant, ['account' => 'acct_other']);
    expect(confirmStandard($tenant)['ok'])->toBeFalse();
    expect(StripeWebhookEvent::query()->where('event_id', 'evt_standard')->value('status'))->toBe('received_self_service');
    standardProvider($tenant);
    $this->artisan('billing:reconcile-self-service')->assertSuccessful();
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeTrue();
});

test('public included branches remain selectable without activating hidden prerequisites', function (): void {
    [$tenant, $actor] = standardBuyer();
    $service = app(SelfServiceStandardPurchaseService::class);
    foreach (app(\App\Services\Tenancy\TenantModuleCatalogService::class)->publicCatalogPayload()['modules'] as $module) {
        $quote = $service->quote($tenant, $actor, [$module['key']]);
        expect($quote['branches'])->toContain($module['key']);
    }
    expect($service->quote($tenant, $actor, ['documents'])['branches'])->not->toContain('uploads');
    expect(fn () => $service->quote($tenant, $actor, ['uploads']))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('the scheduled recovery refreshes an expired paid period from original signed evidence', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    confirmStandard($tenant);
    TenantBillingSubscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['current_period_ends_at' => now()->subMinute()]);
    standardProvider($tenant, ['latest_invoice' => 'in_renewal']);
    $this->artisan('billing:reconcile-self-service')->assertSuccessful();
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeTrue()
        ->and(StripeWebhookEvent::query()->count())->toBe(1)
        ->and(TenantBillingFulfillment::withoutGlobalScopes()->count())->toBe(1);
});

test('pending checkout keeps its accepted price and terms when the public offer changes', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    $original = standardIntent($tenant);
    config()->set('module_catalog.offers.standard.setup_cents', 59900);
    config()->set('module_catalog.offers.standard.monthly_cents', 39900);
    config()->set('module_catalog.offers.standard.version', 'new-offer');
    config()->set('self_service_purchase.terms_version', 'new-terms');
    $service = app(SelfServiceStandardPurchaseService::class);
    $review = $service->quoteForReview($tenant, $actor, ['field_service']);
    expect($review)->toBe($original['quote'])
        ->and($review['subtotal_cents'])->toBe(79800);
    standardProvider($tenant, ['session' => ['status' => 'open', 'payment_status' => 'unpaid', 'subscription' => null, 'invoice' => null]]);
    $result = $service->checkout($tenant, $actor, $review['branches'], ['terms_accepted' => true, 'quote_hash' => $review['quote_hash']]);
    expect($result['ok'])->toBeTrue()
        ->and(standardIntent($tenant)['id'])->toBe($original['id']);
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
});

test('pending checkout cannot be rebound to another owner or changed branch choices', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    $quote = standardIntent($tenant)['quote'];
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $other->tenants()->attach($tenant->id, ['role' => 'owner']);
    $service = app(SelfServiceStandardPurchaseService::class);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake();
    expect(fn () => $service->quoteForReview($tenant, $other, ['managed_website']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $service->checkout($tenant, $actor, ['field_service'], ['terms_accepted' => true, 'quote_hash' => $quote['quote_hash']]))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(TenantBillingFulfillment::withoutGlobalScopes()->count())->toBe(1);
    Http::assertNothingSent();
});

function standardPortalProvider(array $changes = []): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(function ($request) use ($changes) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v1/account') {
            return Http::response(['id' => $changes['account'] ?? 'acct_standard']);
        }
        if ($path === '/v1/billing_portal/sessions') {
            return Http::response([
                'id' => 'bps_standard', 'object' => 'billing_portal.session', 'livemode' => false,
                'customer' => 'cus_standard', 'url' => 'https://billing.stripe.com/p/session/standard', ...($changes['session'] ?? []),
            ]);
        }

        return Http::response([], 404);
    });
}

test('the original owner opens platform billing controls when new purchases are paused', function (): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    confirmStandard($tenant);
    config()->set('self_service_purchase.enabled', false);
    config()->set('self_service_purchase.webhook_verified', false);
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    $override = TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
    $mapping = $override->billing_mapping;
    $mapping['stripe']['customer_reference'] = 'cus_unrelated_legacy';
    $override->update(['billing_mapping' => $mapping]);
    standardPortalProvider();
    $service = app(SelfServiceStandardPurchaseService::class);
    expect($service->createBillingPortal($tenant, $actor)['ok'])->toBeTrue();
    expect($service->createBillingPortal($tenant, $actor)['ok'])->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/billing_portal/sessions')
        && $request['customer'] === 'cus_standard'
        && $request['return_url'] === 'https://app.theeverbranch.com/workspace/purchase?tenant=northline-works');
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST' && ! str_contains($request->url(), '/billing_portal/sessions'));
    expect(TenantBillingFulfillment::withoutGlobalScopes()->count())->toBe(1)
        ->and(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeTrue();
});

test('portal access requires a verified historical purchase and its original owner', function (): void {
    [$tenant, $actor] = standardBuyer();
    Http::fake();
    $service = app(SelfServiceStandardPurchaseService::class);
    expect(fn () => $service->createBillingPortal($tenant, $actor))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    confirmStandard($tenant);
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $other->tenants()->attach($tenant->id, ['role' => 'owner']);
    standardPortalProvider();
    expect(fn () => $service->createBillingPortal($tenant, $other))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('portal refuses customer injection and mismatched platform accounts', function (string $change): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    confirmStandard($tenant);
    if ($change === 'customer') {
        $override = TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $mapping = $override->billing_mapping;
        $mapping['self_service_purchase']['customer_id'] = 'cus_injected';
        $override->update(['billing_mapping' => $mapping]);
    } elseif ($change === 'configuration') {
        config()->set('services.stripe.account_id', 'acct_other');
    }
    standardPortalProvider($change === 'provider' ? ['account' => 'acct_other'] : []);
    expect(app(SelfServiceStandardPurchaseService::class)->createBillingPortal($tenant, $actor)['ok'])->toBeFalse();
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
})->with(['customer', 'configuration', 'provider']);

test('portal only accepts the original customer and secure Stripe hosted URLs', function (array $sessionChanges): void {
    [$tenant, $actor] = standardBuyer();
    standardProvider($tenant);
    startStandard($tenant, $actor);
    confirmStandard($tenant);
    standardPortalProvider(['session' => $sessionChanges]);
    expect(app(SelfServiceStandardPurchaseService::class)->createBillingPortal($tenant, $actor)['ok'])->toBeFalse();
})->with([
    [['url' => 'https://billing.stripe.com.attacker.test/session']],
    [['url' => 'http://billing.stripe.com/session']],
    [['url' => 'https://attacker@billing.stripe.com/session']],
    [['url' => 'https://billing.stripe.com:9999/session']],
    [['customer' => 'cus_other']],
    [['livemode' => true]],
]);
