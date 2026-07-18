<?php

namespace App\Console\Commands;

use App\Models\Agreement;
use App\Models\SubscriptionAuthorization;
use App\Models\Tenant;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingOrder;
use App\Models\TenantBillingReceipt;
use App\Models\TenantDiscoveryProfile;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EverbranchFrontYardFoodsReadiness extends Command
{
    protected $signature = 'everbranch:front-yard-foods-readiness
        {--stage= : Readiness stage: pre-send, sandbox-paid, or post-payment}
        {--agreement-id= : Exact disposable agreement id required for sandbox-paid}
        {--require-paid : Require paid Stripe evidence, fulfillment, and client workspace access}
        {--require-live-gates : Require production live-billing gates in configuration}
        {--client-email= : Expected client workspace user email; defaults to the accepted signer email}';

    protected $description = 'Check whether the Front Yard Foods proposal checkout and workspace handoff are end-to-end ready.';

    public function handle(TenantModuleAccessResolver $moduleAccess): int
    {
        $requestedStage = Str::lower(trim((string) $this->option('stage')));
        $stage = $requestedStage !== '' ? $requestedStage : ((bool) $this->option('require-paid') ? 'post-payment' : 'pre-send');
        if (! in_array($stage, ['pre-send', 'sandbox-paid', 'post-payment'], true)) {
            $this->error('Invalid --stage. Use pre-send, sandbox-paid, or post-payment.');

            return self::INVALID;
        }
        if ((bool) $this->option('require-paid') && $stage !== 'post-payment') {
            $this->error('--require-paid is a compatibility alias for --stage=post-payment and cannot be combined with another stage.');

            return self::INVALID;
        }
        $agreementId = (int) $this->option('agreement-id');
        if ($stage === 'sandbox-paid' && $agreementId < 1) {
            $this->error('--stage=sandbox-paid requires a positive --agreement-id.');

            return self::INVALID;
        }
        if ($stage !== 'sandbox-paid' && $agreementId > 0) {
            $this->error('--agreement-id is only valid with --stage=sandbox-paid.');

            return self::INVALID;
        }
        if ($stage === 'sandbox-paid' && (bool) $this->option('require-live-gates')) {
            $this->error('Sandbox validation cannot require or use live billing gates.');

            return self::INVALID;
        }

        $requirePaid = in_array($stage, ['sandbox-paid', 'post-payment'], true);
        $requireLiveGates = (bool) $this->option('require-live-gates');
        $failures = 0;

        $this->line('Front Yard Foods end-to-end readiness: '.$stage);
        $this->line('');

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->where('slug', 'front-yard-foods')->first();
        $failures += $this->check($tenant instanceof Tenant, 'Tenant exists', 'front-yard-foods tenant is present.');
        if (! $tenant) {
            $this->error('Readiness stopped: tenant is missing.');

            return self::FAILURE;
        }

        /** @var Agreement|null $agreement */
        $agreement = $stage === 'sandbox-paid'
            ? Agreement::withoutGlobalScopes()
                ->where('tenant_id', (int) $tenant->id)
                ->whereKey($agreementId)
                ->where('agreement_type', Agreement::TYPE_SANDBOX_VALIDATION)
                ->where('template_key', Agreement::TEMPLATE_FRONT_YARD_SANDBOX_VALIDATION)
                ->whereNull('parent_agreement_id')
                ->with(['currentVersion', 'acceptance', 'billingOrders.receipts'])
                ->first()
            : Agreement::withoutGlobalScopes()
                ->where('tenant_id', (int) $tenant->id)
                ->where('agreement_type', Agreement::TYPE_FRONT_YARD_CLIENT_SERVICES)
                ->where('template_key', Agreement::TEMPLATE_FRONT_YARD_CLIENT_SERVICES)
                ->whereNull('parent_agreement_id')
                ->with(['currentVersion', 'acceptance', 'billingOrders.receipts'])
                ->latest('id')
                ->first();

        $failures += $this->check($agreement instanceof Agreement, 'Agreement exists', $stage === 'sandbox-paid' ? 'The exact disposable sandbox agreement exists.' : 'The canonical Front Yard Foods client agreement exists.');
        $failures += $this->check($agreement?->currentVersion !== null, 'Immutable version exists', 'The proposal has a current immutable agreement version.');
        if ($stage === 'pre-send') {
            $failures += $this->check(
                $agreement !== null
                    && in_array($agreement->status, ['sent', 'viewed'], true)
                    && filled($agreement->public_token_encrypted)
                    && filled($agreement->password_hash)
                    && $agreement->access_revoked_at === null
                    && ($agreement->access_expires_at === null || $agreement->access_expires_at->isFuture()),
                'Proposal link is sendable',
                'The real password-protected proposal is unsigned, unexpired, and not revoked.'
            );
        }

        $acceptance = $agreement?->acceptance;
        $acceptedExactVersion = $agreement !== null
            && $acceptance !== null
            && (int) $agreement->current_version_id === (int) $acceptance->agreement_version_id;
        if ($stage === 'pre-send') {
            $failures += $this->check($acceptance === null, 'Real agreement remains unsigned', 'Sandbox testing did not consume Laura’s client agreement.');
        } else {
            $failures += $this->check($acceptedExactVersion, 'Signature captured', 'Acceptance is locked to the exact immutable version.');
        }

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

        if ($stage === 'pre-send') {
            $failures += $this->check($order === null, 'No client billing order yet', 'The real agreement has not created a charge authorization before Laura signs.');
        } else {
            $failures += $this->check($order instanceof TenantBillingOrder, 'Initial billing order exists', 'Acceptance created the server-priced billing order.');
        }
        if ($order) {
            $expectedValidation = $stage === 'sandbox-paid';
            $failures += $this->check(
                data_get($order->metadata, 'validation_only') === $expectedValidation,
                'Billing lane matches stage',
                $expectedValidation ? 'The order is permanently marked validation-only.' : 'The real client order is not marked as validation-only.'
            );
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
            if ($stage === 'sandbox-paid') {
                $receipt = $order?->receipts->firstWhere('status', 'paid');
                $failures += $this->check(
                    $receipt !== null
                        && (int) $receipt->subtotal_amount_cents === 35800
                        && (int) $receipt->tax_amount_cents === 0
                        && (int) $receipt->total_amount_cents === 35800,
                    'Sandbox invoice totals verified',
                    'Stripe test invoice is exactly $358.00 with zero sandbox tax.'
                );
                $failures += $this->check(
                    data_get($order?->metadata, 'schedule_status') === 'configured' && filled($order?->provider_schedule_id),
                    'Promotional schedule verified',
                    'The six-cycle promotional schedule and standard-rate phase were configured in Stripe test mode.'
                );
            }
        }

        $clientEmail = strtolower(trim((string) ($this->option('client-email')
            ?: $acceptance?->signer_email
            ?: TenantDiscoveryProfile::withoutGlobalScopes()->where('tenant_id', (int) $tenant->id)->value('support_email'))));
        $clientMembership = $clientEmail !== '' ? $this->activeTenantMembership($tenant, $clientEmail) : null;
        if ($stage === 'pre-send' || ($order && $order->status !== 'paid')) {
            $failures += $this->check($clientMembership === null, 'Client access blocked before payment', 'No accepted signer workspace membership exists before verified payment.');
        }
        if ($stage === 'post-payment') {
            $failures += $this->check(
                $clientMembership !== null,
                'Client workspace access is ready',
                $clientEmail !== '' ? "{$clientEmail} has active Front Yard Foods workspace membership." : 'Accepted signer has active Front Yard Foods workspace membership.'
            );
        }

        if ($stage === 'pre-send') {
            $failures += $this->sandboxEvidenceFailures($tenant);
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

        if ($stage === 'pre-send' || $requireLiveGates) {
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

    protected function sandboxEvidenceFailures(Tenant $tenant): int
    {
        $failures = 0;
        /** @var Agreement|null $sandbox */
        $sandbox = Agreement::withoutGlobalScopes()
            ->where('tenant_id', (int) $tenant->id)
            ->where('agreement_type', Agreement::TYPE_SANDBOX_VALIDATION)
            ->where('template_key', Agreement::TEMPLATE_FRONT_YARD_SANDBOX_VALIDATION)
            ->whereNull('parent_agreement_id')
            ->with(['acceptance', 'billingOrders.receipts', 'billingOrders.authorization'])
            ->latest('id')
            ->first();
        $order = $sandbox?->billingOrders->sortByDesc('id')->first();
        $authorization = $order?->authorization;
        $receipt = $order?->receipts->firstWhere('status', 'paid');
        $fulfillment = $authorization?->provider_subscription_id
            ? TenantBillingFulfillment::withoutGlobalScopes()
                ->where('tenant_id', (int) $tenant->id)
                ->where('provider', 'stripe')
                ->where('provider_subscription_reference', $authorization->provider_subscription_id)
                ->where('desired_plan_key', 'validation_only')
                ->where('status', 'noop')
                ->first()
            : null;

        $failures += $this->check(
            $sandbox !== null
                && $sandbox->acceptance !== null
                && (int) $sandbox->current_version_id === (int) $sandbox->acceptance->agreement_version_id
                && $order !== null
                && data_get($order->metadata, 'validation_only') === true
                && $this->hasOnlyExpectedFirstCheckoutLines($order),
            'Disposable sandbox agreement verified',
            'A separate immutable validation agreement proved the exact server-priced checkout.'
        );
        $failures += $this->check(
            $order?->status === 'paid'
                && $receipt !== null
                && (int) $receipt->subtotal_amount_cents === 35800
                && (int) $receipt->tax_amount_cents === 0
                && (int) $receipt->total_amount_cents === 35800
                && data_get($order?->metadata, 'schedule_status') === 'configured'
                && $fulfillment !== null,
            'Sandbox payment evidence retained',
            'Paid invoice, receipt, promotional schedule, and validation-only noop audit are preserved.'
        );
        $failures += $this->check(
            $authorization?->status === 'canceled'
                && data_get($authorization?->metadata, 'last_provider_event_type') === 'customer.subscription.deleted',
            'Sandbox subscription canceled',
            'The disposable Stripe test subscription was canceled after evidence capture.'
        );

        return $failures;
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
