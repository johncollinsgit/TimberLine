<?php

namespace App\Console\Commands;

use App\Models\Agreement;
use App\Models\SubscriptionAuthorization;
use App\Models\Tenant;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingOrder;
use App\Models\TenantBillingReceipt;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class EverbranchFrontYardFoodsReadiness extends Command
{
    protected $signature = 'everbranch:front-yard-foods-readiness
        {--require-paid : Require paid Stripe evidence, fulfillment, and client workspace access}
        {--require-live-gates : Require production live-billing gates in configuration}
        {--client-email= : Expected client workspace user email; defaults to the accepted signer email}';

    protected $description = 'Check whether the Front Yard Foods proposal checkout and workspace handoff are end-to-end ready.';

    public function handle(TenantModuleAccessResolver $moduleAccess): int
    {
        $requirePaid = (bool) $this->option('require-paid');
        $requireLiveGates = (bool) $this->option('require-live-gates');
        $failures = 0;

        $this->line('Front Yard Foods end-to-end readiness');
        $this->line('');

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->where('slug', 'front-yard-foods')->first();
        $failures += $this->check($tenant instanceof Tenant, 'Tenant exists', 'front-yard-foods tenant is present.');
        if (! $tenant) {
            $this->error('Readiness stopped: tenant is missing.');

            return self::FAILURE;
        }

        /** @var Agreement|null $agreement */
        $agreement = Agreement::withoutGlobalScopes()
            ->where('tenant_id', (int) $tenant->id)
            ->where('template_key', 'front_yard_foods_launch_partner')
            ->whereNull('parent_agreement_id')
            ->with(['currentVersion', 'acceptance', 'billingOrders.receipts'])
            ->latest('id')
            ->first();

        $failures += $this->check($agreement instanceof Agreement, 'Agreement exists', 'Canonical Front Yard Foods agreement has been prepared.');
        $failures += $this->check($agreement?->currentVersion !== null, 'Immutable version exists', 'The proposal has a current immutable agreement version.');
        $failures += $this->check(
            $agreement !== null
                && filled($agreement->public_token_encrypted)
                && filled($agreement->password_hash)
                && $agreement->access_revoked_at === null
                && ($agreement->access_expires_at === null || $agreement->access_expires_at->isFuture()),
            'Proposal link is sendable',
            'Password-protected Evergrove proposal access is rotated, unexpired, and not revoked.'
        );

        $acceptance = $agreement?->acceptance;
        $acceptedExactVersion = $agreement !== null
            && $acceptance !== null
            && (int) $agreement->current_version_id === (int) $acceptance->agreement_version_id;
        $failures += $this->check($acceptedExactVersion, 'Signature captured', 'Client acceptance is locked to the exact immutable version.');

        /** @var TenantBillingOrder|null $order */
        $order = $agreement
            ? TenantBillingOrder::withoutGlobalScopes()
                ->where('tenant_id', (int) $tenant->id)
                ->where('agreement_id', (int) $agreement->id)
                ->where('order_type', 'initial')
                ->with('receipts')
                ->latest('id')
                ->first()
            : null;

        $failures += $this->check($order instanceof TenantBillingOrder, 'Initial billing order exists', 'Acceptance created the server-priced billing order.');
        if ($order) {
            $failures += $this->check($this->hasOnlyExpectedFirstCheckoutLines($order), 'First checkout is exactly scoped', '$299 onboarding + $59/month service only; Shopify/Square/third-party costs excluded.');
            $failures += $this->check(
                in_array((string) $order->status, $requirePaid ? ['paid'] : ['authorized', 'checkout_pending', 'processing', 'paid'], true),
                'Billing order status is eligible',
                $requirePaid ? 'Stripe has confirmed the initial order as paid.' : 'Order is awaiting or moving through customer payment.'
            );
        }

        $authorization = $order?->subscription_authorization_id
            ? SubscriptionAuthorization::withoutGlobalScopes()->find((int) $order->subscription_authorization_id)
            : null;
        if ($requirePaid) {
            $failures += $this->check($authorization?->status === 'provider_verified', 'Subscription authorization verified', 'Stripe subscription evidence has verified the recurring authorization.');
            $failures += $this->check(
                $order !== null
                    && TenantBillingReceipt::withoutGlobalScopes()->where('tenant_id', (int) $tenant->id)->where('tenant_billing_order_id', (int) $order->id)->exists(),
                'Receipt mirrored',
                'Stripe invoice/receipt evidence is mirrored to the tenant billing ledger.'
            );
            $failures += $this->check(
                $authorization !== null
                    && TenantBillingFulfillment::withoutGlobalScopes()
                        ->where('tenant_id', (int) $tenant->id)
                        ->where('provider', 'stripe')
                        ->where('provider_subscription_reference', $authorization->provider_subscription_id)
                        ->whereIn('status', ['applied', 'noop'])
                        ->exists(),
                'Fulfillment audited',
                'A replay-safe fulfillment record exists after verified Stripe payment.'
            );
        }

        $clientEmail = strtolower(trim((string) ($this->option('client-email') ?: $acceptance?->signer_email)));
        $clientMembership = $clientEmail !== '' ? $this->activeTenantMembership($tenant, $clientEmail) : null;
        if ($order && $order->status !== 'paid') {
            $failures += $this->check($clientMembership === null, 'Client access blocked before payment', 'No accepted signer workspace membership exists before verified payment.');
        }
        if ($requirePaid) {
            $failures += $this->check(
                $clientMembership !== null,
                'Client workspace access is ready',
                $clientEmail !== '' ? "{$clientEmail} has active Front Yard Foods workspace membership." : 'Accepted signer has active Front Yard Foods workspace membership.'
            );
        }

        $requiredModules = ['customers', 'class_scheduling', 'plant_inventory', 'messaging', 'reporting'];
        $resolved = $moduleAccess->resolveForTenant((int) $tenant->id, $requiredModules);
        foreach ($requiredModules as $moduleKey) {
            $module = (array) data_get($resolved, "modules.{$moduleKey}", []);
            $failures += $this->check(
                (bool) ($module['enabled'] ?? false),
                'Module enabled: '.$moduleKey,
                $moduleKey === 'messaging'
                    ? 'Messaging is present for the workspace but remains provider/consent gated.'
                    : 'Required Front Yard Foods workspace module is accessible.'
            );
        }

        if ($requireLiveGates) {
            $failures += $this->liveGateFailures();
        }

        $this->line('');
        if ($failures > 0) {
            $this->error("Front Yard Foods is not end-to-end ready ({$failures} blocker(s)).");

            return self::FAILURE;
        }

        $this->info('Front Yard Foods end-to-end readiness checks passed.');

        return self::SUCCESS;
    }

    protected function check(bool $condition, string $label, string $detail): int
    {
        $this->line(sprintf('  %s %s — %s', $condition ? '<info>✓</info>' : '<fg=red>✗</>', $label, $detail));

        return $condition ? 0 : 1;
    }

    protected function hasOnlyExpectedFirstCheckoutLines(TenantBillingOrder $order): bool
    {
        /** @var Collection<int,array<string,mixed>> $payable */
        $payable = collect((array) $order->line_items)
            ->filter(fn (mixed $line): bool => is_array($line) && in_array((string) ($line['payment_timing'] ?? ''), ['due_on_acceptance', 'recurring_current'], true))
            ->values();

        if ($payable->count() !== 2 || (int) $order->authorized_subtotal_cents !== 35800) {
            return false;
        }

        $hasOnboarding = $payable->contains(fn (array $line): bool => ($line['key'] ?? null) === 'everbranch_onboarding'
            && (int) ($line['amount_cents'] ?? 0) === 29900
            && ($line['frequency'] ?? null) === 'one_time');
        $hasLaunchPartner = $payable->contains(fn (array $line): bool => ($line['key'] ?? null) === 'everbranch_launch_partner'
            && (int) ($line['amount_cents'] ?? 0) === 5900
            && ($line['frequency'] ?? null) === 'month');
        $hasForbiddenProviderCost = $payable->contains(function (array $line): bool {
            $category = strtolower(trim((string) ($line['cost_category'] ?? '')));

            return str_contains($category, 'shopify') || str_contains($category, 'square') || str_contains($category, 'third_party');
        });

        return $hasOnboarding && $hasLaunchPartner && ! $hasForbiddenProviderCost;
    }

    /** @return array<string,mixed>|null */
    protected function activeTenantMembership(Tenant $tenant, string $email): ?array
    {
        /** @var User|null $user */
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user || $user->is_active === false) {
            return null;
        }

        $membership = $user->tenants()->whereKey((int) $tenant->id)->first();
        if (! $membership) {
            return null;
        }

        return ['user_id' => (int) $user->id, 'role' => (string) $membership->pivot?->role];
    }

    protected function liveGateFailures(): int
    {
        $failures = 0;
        $allowedSlugs = array_values(array_filter(array_map(
            static fn (mixed $slug): string => strtolower(trim((string) $slug)),
            (array) config('commercial.billing_readiness.agreement_checkout.tenant_slugs', [])
        )));

        $checks = [
            ['Agreement checkout enabled', (bool) config('commercial.billing_readiness.agreement_checkout.enabled', false), 'Proposal checkout flag is on.'],
            ['Front Yard allowlisted explicitly', in_array('front-yard-foods', $allowedSlugs, true) && ! in_array('*', $allowedSlugs, true), 'front-yard-foods is listed and * is not used.'],
            ['Live Stripe account id', preg_match('/^acct_[A-Za-z0-9]+$/', (string) config('services.stripe.account_id')) === 1, 'STRIPE_ACCOUNT_ID is an acct_ value.'],
            ['Live Stripe publishable key', preg_match('/^pk_live_[A-Za-z0-9]+$/', (string) config('services.stripe.publishable_key')) === 1, 'STRIPE_KEY is a pk_live_ value.'],
            ['Live Stripe secret key', preg_match('/^sk_live_[A-Za-z0-9]+$/', (string) config('services.stripe.secret')) === 1, 'STRIPE_SECRET is an sk_live_ value.'],
            ['Production webhook secret', preg_match('/^whsec_[A-Za-z0-9]+$/', (string) config('services.stripe.webhook_secret')) === 1, 'STRIPE_WEBHOOK_SECRET is configured.'],
            ['Relay payout verified', (bool) config('commercial.billing_readiness.agreement_checkout.relay_payout_verified', false), 'Stripe payout destination has verified Relay evidence.'],
            ['Tax decision confirmed', (bool) config('commercial.billing_readiness.agreement_checkout.tax_decision_confirmed', false), 'Accountant taxability/registration decision is attached.'],
            ['Production mail enabled', strtolower((string) config('mail.default')) !== 'log', 'MAIL_MAILER is not log.'],
        ];

        foreach ($checks as [$label, $condition, $detail]) {
            $failures += $this->check((bool) $condition, $label, (string) $detail);
        }

        return $failures;
    }
}
