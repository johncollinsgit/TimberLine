<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Support\Facades\Schema;

class TenantBillingNextStepResolver
{
    public function __construct(
        protected LandlordCommercialConfigService $commercialConfigService,
        protected TenantHostBuilder $hostBuilder,
    ) {
    }

    /**
     * @param  array{
     *   preferred_plan_key:?string,
     *   addons_interest:array<int,string>,
     *   source:?string,
     *   captured_at:?string,
     *   access_request_id:?int
     * }  $billingInterest
     * @return array{
     *   mode:string,
     *   title:string,
     *   description:string,
     *   cta_label:?string,
     *   cta_url:?string,
     *   cta_route:?array{name:string,method:string},
     *   reason:?string,
     *   readiness:array<string,mixed>
     * }
     */
    public function resolveForTenantId(?int $tenantId, array $billingInterest): array
    {
        if ($tenantId === null || $tenantId < 1) {
            return $this->unavailable('missing_tenant', $billingInterest, []);
        }

        if (! Schema::hasTable('tenants')) {
            return $this->unavailable('tenant_table_missing', $billingInterest, []);
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return $this->unavailable('unknown_tenant', $billingInterest, []);
        }

        $preferredPlanKey = strtolower(trim((string) ($billingInterest['preferred_plan_key'] ?? '')));
        $addonsInterest = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($billingInterest['addons_interest'] ?? [])
        ), static fn (string $value): bool => $value !== ''));

        $checkoutActive = (bool) config('commercial.billing_readiness.checkout_active', false);
        $lifecycleMutationsEnabled = (bool) config('commercial.billing_readiness.lifecycle_mutations_enabled', false);
        $hostedBillingEnabled = $checkoutActive || $lifecycleMutationsEnabled;

        $stripeSecret = trim((string) config('services.stripe.secret', ''));
        $stripeSecretConfigured = $stripeSecret !== '';
        $stripeSecretFormatValid = $stripeSecretConfigured && str_starts_with($stripeSecret, 'sk_');
        $stripeConfigured = $stripeSecretConfigured && $stripeSecretFormatValid;

        $readiness = [
            'hosted_billing_enabled' => $hostedBillingEnabled,
            'checkout_active' => $checkoutActive,
            'lifecycle_mutations_enabled' => $lifecycleMutationsEnabled,
            'stripe_secret_configured' => $stripeSecretConfigured,
            'stripe_secret_format_valid' => $stripeSecretFormatValid,
        ];

        $billingMapping = [];
        if (Schema::hasTable('tenant_commercial_overrides')) {
            $commercialProfile = $this->commercialConfigService->tenantCommercialProfile($tenantId);
            $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
                ? (array) $commercialProfile['billing_mapping']
                : [];
        }
        $stripeCustomerReference = trim((string) data_get($billingMapping, 'stripe.customer_reference', ''));
        $stripeSubscriptionReference = trim((string) data_get($billingMapping, 'stripe.subscription_reference', ''));
        $actionRequired = (bool) data_get($billingMapping, 'stripe.action_required', false);
        $billingConfirmedAt = trim((string) data_get($billingMapping, 'stripe.billing_confirmed_at', ''));
        $checkoutCompletedAt = trim((string) data_get($billingMapping, 'stripe.checkout_completed_at', ''));
        $subscriptionStatus = strtolower(trim((string) data_get($billingMapping, 'stripe.subscription_status', '')));
        $subscriptionInactive = in_array($subscriptionStatus, ['canceled', 'unpaid', 'incomplete_expired'], true);
        $fulfillmentStatus = strtolower(trim((string) data_get($billingMapping, 'stripe.fulfillment.status', '')));
        $fulfilled = in_array($fulfillmentStatus, ['fulfilled', 'noop'], true);

        $readiness['stripe_customer_reference_present'] = $stripeCustomerReference !== '';
        $readiness['stripe_subscription_reference_present'] = $stripeSubscriptionReference !== '';

        if ($actionRequired) {
            return [
                'mode' => 'action_required',
                'title' => 'Action required',
                'description' => 'Billing needs attention before access can be fully activated. Update your payment method or contact the team.',
                'cta_label' => $stripeConfigured && $hostedBillingEnabled && $stripeCustomerReference !== '' ? 'Open billing portal' : 'Talk to sales',
                'cta_url' => $stripeConfigured && $hostedBillingEnabled && $stripeCustomerReference !== '' ? null : route('platform.contact', ['intent' => 'billing']),
                'cta_route' => $stripeConfigured && $hostedBillingEnabled && $stripeCustomerReference !== '' ? ['name' => 'billing.portal', 'method' => 'post'] : null,
                'reason' => 'stripe_action_required',
                'readiness' => $readiness,
            ];
        }

        $billingConfirmed = $billingConfirmedAt !== '' || $checkoutCompletedAt !== '';

        if ($subscriptionInactive) {
            $readiness['subscription_status'] = $subscriptionStatus;

            if ($stripeConfigured && $hostedBillingEnabled && $preferredPlanKey !== '' && $this->planKeyAllowed($preferredPlanKey)) {
                return [
                    'mode' => 'hosted_checkout',
                    'title' => 'Restart billing',
                    'description' => 'Billing is inactive. Start a new hosted checkout to reactivate billing for your selected tier and add-ons.',
                    'cta_label' => 'Restart secure checkout',
                    'cta_url' => null,
                    'cta_route' => ['name' => 'billing.checkout', 'method' => 'post'],
                    'reason' => 'subscription_inactive',
                    'readiness' => $readiness,
                ];
            }

            return [
                'mode' => 'landlord_follow_up',
                'title' => 'Billing inactive',
                'description' => 'Billing is inactive for this tenant. Contact the team to restore access.',
                'cta_label' => 'Talk to sales',
                'cta_url' => route('platform.contact', ['intent' => 'billing']),
                'cta_route' => null,
                'reason' => 'subscription_inactive',
                'readiness' => $readiness,
            ];
        }

        if ($stripeConfigured && $hostedBillingEnabled && $billingConfirmed && (bool) config('commercial.billing_readiness.lifecycle_mutations_enabled', false)) {
            if (! $fulfilled) {
                return [
                    'mode' => 'billing_confirmed_pending_fulfillment',
                    'title' => 'Activating access',
                    'description' => 'Billing is confirmed. Access is being activated now—refresh in a moment.',
                    'cta_label' => null,
                    'cta_url' => null,
                    'cta_route' => null,
                    'reason' => 'billing_confirmed_pending_fulfillment',
                    'readiness' => $readiness,
                ];
            }
        }

        if ($stripeConfigured && $hostedBillingEnabled && $stripeCustomerReference !== '' && $stripeSubscriptionReference !== '') {
            return [
                'mode' => 'billing_portal',
                'title' => 'Manage billing',
                'description' => 'Open the hosted billing portal to manage payment methods and invoices.',
                'cta_label' => 'Open billing portal',
                'cta_url' => null,
                'cta_route' => ['name' => 'billing.portal', 'method' => 'post'],
                'reason' => 'existing_stripe_customer',
                'readiness' => $readiness,
            ];
        }

        if ($preferredPlanKey === '' && $addonsInterest === []) {
            return $this->unavailable('no_interest_captured', $billingInterest, $readiness);
        }

        if (! $stripeConfigured || ! $hostedBillingEnabled) {
            return [
                'mode' => 'landlord_follow_up',
                'title' => 'Billing comes next',
                'description' => 'Billing and payment-method setup are handled with the team right now. Your plan/add-on interest is saved, and we will follow up to activate billing when ready.',
                'cta_label' => 'Talk to sales',
                'cta_url' => route('platform.contact', ['intent' => 'billing']),
                'cta_route' => null,
                'reason' => ! $stripeConfigured ? 'stripe_not_configured' : 'hosted_billing_disabled',
                'readiness' => $readiness,
            ];
        }

        if ($preferredPlanKey === '') {
            return [
                'mode' => 'landlord_follow_up',
                'title' => 'Billing comes next',
                'description' => 'A preferred tier is required before starting hosted billing. Your request is approved and we can finalize billing with you.',
                'cta_label' => 'Talk to sales',
                'cta_url' => route('platform.contact', ['intent' => 'billing']),
                'cta_route' => null,
                'reason' => 'missing_preferred_plan',
                'readiness' => $readiness,
            ];
        }

        if (! $this->planKeyAllowed($preferredPlanKey)) {
            return $this->unavailable('unknown_plan_key', $billingInterest, $readiness);
        }

        $eligibleAddons = $this->eligibleAddonsForPlan($preferredPlanKey);
        $addonsInterest = array_values(array_filter(
            $addonsInterest,
            static fn (string $addonKey): bool => in_array($addonKey, $eligibleAddons, true)
        ));

        $stripeMapping = (array) config('commercial.stripe_mapping', []);
        $tierMap = is_array($stripeMapping['tiers'] ?? null) ? (array) $stripeMapping['tiers'] : [];
        $addonMap = is_array($stripeMapping['addons'] ?? null) ? (array) $stripeMapping['addons'] : [];

        $tierRow = is_array($tierMap[$preferredPlanKey] ?? null) ? (array) $tierMap[$preferredPlanKey] : [];
        $tierLookupKey = trim((string) ($tierRow['recurring_price_lookup_key'] ?? ''));

        if ($tierLookupKey === '') {
            return $this->unavailable('missing_stripe_tier_mapping', $billingInterest, $readiness);
        }

        foreach ($addonsInterest as $addonKey) {
            $addonRow = is_array($addonMap[$addonKey] ?? null) ? (array) $addonMap[$addonKey] : [];
            if (trim((string) ($addonRow['recurring_price_lookup_key'] ?? '')) === '') {
                return $this->unavailable('missing_stripe_addon_mapping', $billingInterest, [
                    ...$readiness,
                    'missing_addon_key' => $addonKey,
                ]);
            }
        }

        $preferredHost = filled($tenant->slug ?? null) ? $this->hostBuilder->hostForSlug((string) $tenant->slug) : null;
        $readiness['tenant_host'] = $preferredHost;

        return [
            'mode' => 'hosted_checkout',
            'title' => 'Continue to billing',
            'description' => 'Open a hosted Stripe checkout to start billing for your selected tier and add-ons. No in-app plan changes occur until billing is confirmed.',
            'cta_label' => 'Continue to secure checkout',
            'cta_url' => null,
            'cta_route' => ['name' => 'billing.checkout', 'method' => 'post'],
            'reason' => 'hosted_checkout_ready',
            'readiness' => $readiness,
        ];
    }

    protected function unavailable(string $reason, array $billingInterest, array $readiness): array
    {
        return [
            'mode' => 'unavailable',
            'title' => 'Billing unavailable',
            'description' => 'Billing is not available for this tenant yet. Contact sales for next steps.',
            'cta_label' => 'Talk to sales',
            'cta_url' => route('platform.contact', ['intent' => 'billing']),
            'cta_route' => null,
            'reason' => $reason,
            'readiness' => $readiness,
        ];
    }

    protected function planKeyAllowed(string $planKey): bool
    {
        $key = strtolower(trim($planKey));
        if ($key === '') {
            return false;
        }

        return array_key_exists($key, (array) config('module_catalog.plans', []));
    }

    /**
     * @return array<int,string>
     */
    protected function eligibleAddonsForPlan(string $planKey): array
    {
        $plan = is_array(config('module_catalog.plans.'.strtolower(trim($planKey)))) ? (array) config('module_catalog.plans.'.strtolower(trim($planKey))) : [];
        $eligible = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($plan['eligible_addons'] ?? [])
        ), static fn (string $value): bool => $value !== ''));

        if ($eligible !== []) {
            return array_values(array_unique($eligible));
        }

        return array_keys((array) config('module_catalog.addons', []));
    }
}
