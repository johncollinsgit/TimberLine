<?php

use App\Models\Tenant;
use App\Models\TenantPaymentAccount;
use App\Services\Billing\TenantPaymentsReadinessService;

beforeEach(function (): void {
    config()->set('services.stripe.secret', 'sk_test_connect');
    config()->set('commercial.billing_readiness.tenant_payments', [
        'enabled' => false,
        'tenant_slugs' => [],
        'connect_webhooks_verified' => false,
        'automatic_tax_enabled' => false,
        'tax_decision_confirmed' => false,
    ]);
});

test('Everbranch Billing is a catalog-visible but locked Branch by default', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    $resolved = app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->resolveForTenant($tenant->id, ['everbranch_billing']);
    $module = $resolved['modules']['everbranch_billing'];

    expect(config('module_catalog.modules.everbranch_billing.display_name'))->toBe('Everbranch Billing')
        ->and(config('module_catalog.modules.everbranch_billing.visibility.app_store'))->toBeTrue()
        ->and($module['enabled'])->toBeFalse()
        ->and($module['reason'])->toBe('contact_sales_required');
});

test('customer payment collection fails closed until global, tenant, and Connect-account gates pass', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    $service = app(TenantPaymentsReadinessService::class);

    expect($service->canStartOnboarding($tenant))->toBeFalse()
        ->and($service->canCollectCustomerPayments($tenant))->toBeFalse();

    config()->set('commercial.billing_readiness.tenant_payments.enabled', true);
    config()->set('commercial.billing_readiness.tenant_payments.tenant_slugs', ['acme']);
    expect($service->canStartOnboarding($tenant))->toBeTrue()
        ->and($service->canCollectCustomerPayments($tenant))->toBeFalse();

    TenantPaymentAccount::query()->create([
        'tenant_id' => $tenant->id,
        'provider_account_id' => 'acct_tenant',
        'status' => 'ready',
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
    ]);

    expect($service->canCollectCustomerPayments($tenant))->toBeTrue();
});

test('live tenant payments require independently verified Connect webhooks', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    config()->set('services.stripe.secret', 'sk_live_connect');
    config()->set('commercial.billing_readiness.tenant_payments.enabled', true);
    config()->set('commercial.billing_readiness.tenant_payments.tenant_slugs', ['acme']);

    $result = app(TenantPaymentsReadinessService::class)->readinessFor($tenant, false);
    expect($result['ready'])->toBeFalse()->and($result['blockers'])->toContain('Stripe Connect webhooks have not been verified.');
});
