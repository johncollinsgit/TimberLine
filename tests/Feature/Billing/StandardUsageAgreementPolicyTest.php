<?php

use App\Models\AgreementAcceptance;
use App\Models\SubscriptionAuthorization;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingOrder;
use App\Models\TenantBillingSubscription;
use App\Services\Agreements\AgreementManagementService;
use App\Services\Billing\StandardUsageAgreementPolicy;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;

test('only an exact accepted paid and fulfilled Standard version supplies frozen messaging prices', function () {
    $tenant = Tenant::create(['name' => 'Standard Example', 'slug' => 'standard-usage-example']);
    $other = Tenant::create(['name' => 'Legacy Example', 'slug' => 'legacy-usage-example']);
    $agreement = app(AgreementManagementService::class)->prepareStandard($tenant, null);
    $agreement->update(['status' => 'active', 'accepted_at' => now()]);
    $acceptance = AgreementAcceptance::create(['agreement_id' => $agreement->id, 'agreement_version_id' => $agreement->current_version_id,
        'tenant_id' => $tenant->id, 'signer_legal_name' => 'Example Owner', 'signer_title' => 'Owner', 'signer_email' => 'owner@example.invalid',
        'electronic_signature_value' => 'Example Owner', 'authorized_to_bind' => true, 'accepted_scope' => true,
        'accepted_pricing' => true, 'accepted_subscription' => true, 'accepted_hourly_rate' => true, 'accepted_termination' => true,
        'electronic_consent' => true, 'accepted_at' => now(), 'evidence_hash' => hash('sha256', 'standard-usage-fixture')]);
    $authorization = SubscriptionAuthorization::create(['tenant_id' => $tenant->id, 'agreement_id' => $agreement->id,
        'agreement_version_id' => $agreement->current_version_id, 'agreement_acceptance_id' => $acceptance->id,
        'billing_lane' => 'stripe_direct', 'provider' => 'stripe', 'purchase_key' => 'everbranch.standard',
        'status' => 'provider_verified', 'provider_subscription_id' => 'sub_standard_usage', 'authorized_at' => now(),
        'metadata' => ['canonical_plan_key' => 'starter']]);
    TenantBillingSubscription::create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'sub_standard_usage', 'purchase_key' => 'everbranch.standard', 'status' => 'active']);
    TenantBillingOrder::create(['tenant_id' => $tenant->id, 'agreement_id' => $agreement->id, 'agreement_version_id' => $agreement->current_version_id,
        'agreement_acceptance_id' => $acceptance->id, 'subscription_authorization_id' => $authorization->id,
        'order_type' => 'initial', 'status' => 'paid', 'line_items' => [], 'authorized_at' => now(), 'paid_at' => now()]);
    $policy = app(StandardUsageAgreementPolicy::class);
    expect($policy->forTenant($tenant->id))->toBeNull(); // No audited access fulfillment yet.
    TenantBillingFulfillment::create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'sub_standard_usage',
        'state_hash' => hash('sha256', 'standard-usage'), 'desired_plan_key' => 'starter', 'desired_addon_keys' => [], 'status' => 'applied', 'attempted_at' => now(), 'applied_at' => now()]);
    config()->set('module_catalog.offers.standard.usage_policy.messaging_rates_micros.email', 9999);
    expect($policy->forTenant($tenant->id)['overage_rates_micros']['email'])->toBe(1490)->and($policy->forTenant($other->id))->toBeNull();
    $usage = app(TenantMessagingUsageService::class);
    $usage->fund($tenant->id, 25000000, 'paid-fixture');
    $reservation = $usage->reserve($tenant->id, 'email', 1000, 'standard-mail-fixture', 'sendgrid');
    expect($reservation['amount_micros'])->toBe(1490000);
    $usage->settle($tenant->id, 'standard-mail-fixture');
    TenantAccessAddon::create(['tenant_id' => $tenant->id, 'addon_key' => 'messaging_usage', 'enabled' => true,
        'source' => 'existing_contract', 'metadata' => ['overage_rates_micros' => ['email' => 5000], 'included_units' => ['email' => 0], 'pricing_version' => 'retained-contract']]);
    expect($usage->reserve($tenant->id, 'email', 10, 'retained-mail-fixture', 'sendgrid')['amount_micros'])->toBe(50000);
    $authorization->update(['status' => 'canceled']);
    expect($policy->forTenant($tenant->id))->toBeNull();
});
