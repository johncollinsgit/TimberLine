<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantPaymentAccount;

/**
 * Safety boundary for the customer-to-tenant Stripe Connect lane.
 *
 * This never authorizes the platform's direct-invoice lane. A tenant must pass
 * every gate below before Connect onboarding or customer collection is exposed.
 */
class TenantPaymentsReadinessService
{
    /** @return array{ready:bool, blockers:array<int,string>, account:?TenantPaymentAccount} */
    public function readinessFor(Tenant $tenant, bool $requireConnectedAccount = true): array
    {
        $blockers = [];
        $settings = (array) config('commercial.billing_readiness.tenant_payments', []);

        if (! (bool) ($settings['enabled'] ?? false)) {
            $blockers[] = 'Everbranch Billing is locked globally.';
        }

        $allowed = array_values(array_filter(array_map('strval', (array) ($settings['tenant_slugs'] ?? []))));
        if (! in_array('*', $allowed, true) && ! in_array((string) $tenant->slug, $allowed, true)) {
            $blockers[] = 'Everbranch Billing is not approved for this workspace.';
        }

        $secret = trim((string) config('services.stripe.secret'));
        if (! str_starts_with($secret, 'sk_test_') && ! str_starts_with($secret, 'sk_live_')) {
            $blockers[] = 'Stripe is not configured.';
        }
        if (str_starts_with($secret, 'sk_live_') && ! (bool) ($settings['connect_webhooks_verified'] ?? false)) {
            $blockers[] = 'Stripe Connect webhooks have not been verified.';
        }
        if ((bool) ($settings['automatic_tax_enabled'] ?? false) && ! (bool) ($settings['tax_decision_confirmed'] ?? false)) {
            $blockers[] = 'The tenant-payment tax decision has not been confirmed.';
        }

        // Do not rely on a relationship cached before hosted onboarding or a
        // webhook update; collection readiness must see the current database row.
        $account = $tenant->paymentAccount()->first();
        if ($requireConnectedAccount && ! $account?->isReady()) {
            $blockers[] = 'The tenant has not completed verified Stripe Connect onboarding and payouts.';
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers, 'account' => $account];
    }

    public function canStartOnboarding(Tenant $tenant): bool
    {
        return $this->readinessFor($tenant, requireConnectedAccount: false)['ready'];
    }

    public function canCollectCustomerPayments(Tenant $tenant): bool
    {
        return $this->readinessFor($tenant)['ready'];
    }
}
