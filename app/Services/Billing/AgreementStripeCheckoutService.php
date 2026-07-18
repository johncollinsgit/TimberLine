<?php

namespace App\Services\Billing;

use App\Models\Agreement;
use App\Models\TenantBillingOrder;
use App\Models\TenantCommercialOverride;
use App\Models\TenantDirectInvoice;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class AgreementStripeCheckoutService
{
    public function __construct(protected LandlordOperatorActionAuditService $audit) {}

    /** @return array{ok:bool,url:?string,session_id:?string,message:?string} */
    public function create(TenantBillingOrder $order, string $proposalToken): array
    {
        $order->loadMissing(['tenant', 'agreement.currentVersion', 'acceptance', 'authorization']);
        $blocker = $this->readinessBlocker($order);
        if ($blocker !== null) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => $blocker];
        }
        if ((int) $order->agreement_version_id !== (int) $order->agreement->current_version_id
            || (int) $order->agreement_version_id !== (int) $order->acceptance->agreement_version_id
            || ! in_array($order->agreement->status, ['active', 'termination_pending'], true)) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'The accepted agreement version is not eligible for payment.'];
        }
        if (in_array($order->status, ['paid', 'refunded', 'void'], true)) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'This billing order is no longer payable.'];
        }

        $lines = collect((array) $order->line_items);
        $currentRecurring = $lines->first(fn (mixed $line): bool => is_array($line) && ($line['payment_timing'] ?? '') === 'recurring_current');
        $payable = $lines->filter(fn (mixed $line): bool => is_array($line) && in_array($line['payment_timing'] ?? '', ['due_on_acceptance', 'recurring_current'], true))->values();
        if ($payable->isEmpty()) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'This agreement has no authorized charges due now.'];
        }

        $mode = is_array($currentRecurring) ? 'subscription' : 'payment';
        $returnUrl = route('proposals.show', ['token' => $proposalToken], true);
        $customerId = $this->reusableCustomerId($order);
        $payload = [
            'mode' => $mode,
            'success_url' => $returnUrl.'?payment=return&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $returnUrl.'?payment=cancel',
            'client_reference_id' => 'billing-order-'.(int) $order->id,
            'billing_address_collection' => 'required',
            'tax_id_collection[enabled]' => 'true',
            'payment_method_types[0]' => 'card',
            'payment_method_types[1]' => 'us_bank_account',
            'saved_payment_method_options[payment_method_save]' => 'enabled',
            'saved_payment_method_options[payment_method_remove]' => 'enabled',
            'automatic_tax[enabled]' => $this->automaticTaxEnabled() ? 'true' : 'false',
        ];
        if ($customerId !== null) {
            $payload['customer'] = $customerId;
        } else {
            $payload['customer_email'] = (string) $order->acceptance->signer_email;
            if ($mode === 'payment') {
                $payload['customer_creation'] = 'always';
            }
        }
        if ($mode === 'payment') {
            $payload['invoice_creation[enabled]'] = 'true';
            $payload['payment_intent_data[setup_future_usage]'] = 'on_session';
        }

        $metadata = [
            'purpose' => 'agreement_checkout',
            'tenant_id' => (string) ((int) $order->tenant_id),
            'billing_order_id' => (string) ((int) $order->id),
            'agreement_id' => (string) ((int) $order->agreement_id),
            'agreement_version_id' => (string) ((int) $order->agreement_version_id),
            'subscription_authorization_id' => (string) ((int) $order->subscription_authorization_id),
            'purchase_key' => (string) ($order->authorization?->purchase_key ?? ''),
            'checkout_plan_key' => (string) data_get($order->authorization?->metadata, 'canonical_plan_key', ''),
            'validation_only' => data_get($order->metadata, 'validation_only') === true ? '1' : '0',
        ];
        foreach (array_filter($metadata, fn (string $value): bool => $value !== '') as $key => $value) {
            $payload['metadata['.$key.']'] = $value;
            if ($mode === 'subscription') {
                $payload['subscription_data[metadata]['.$key.']'] = $value;
            } else {
                $payload['payment_intent_data[metadata]['.$key.']'] = $value;
            }
        }

        foreach ($payable as $index => $line) {
            $payload['line_items['.$index.'][price_data][currency]'] = strtolower((string) $order->currency);
            $payload['line_items['.$index.'][price_data][unit_amount]'] = (int) $line['amount_cents'];
            $payload['line_items['.$index.'][price_data][product_data][name]'] = substr((string) $line['label'], 0, 250);
            $payload['line_items['.$index.'][price_data][product_data][metadata][billing_order_id]'] = (string) $order->id;
            $payload['line_items['.$index.'][price_data][product_data][metadata][line_key]'] = (string) $line['key'];
            if (($line['frequency'] ?? '') === 'month') {
                $payload['line_items['.$index.'][price_data][recurring][interval]'] = 'month';
            }
            $payload['line_items['.$index.'][quantity]'] = (int) ($line['quantity'] ?? 1);
        }

        $response = $this->stripeRequest()->withHeaders([
            'Idempotency-Key' => 'agreement-order-'.(int) $order->id.'-v1',
        ])->post($this->apiBase().'/v1/checkout/sessions', $payload);
        $json = is_array($response->json()) ? $response->json() : [];
        if ($response->failed()) {
            $message = trim((string) data_get($json, 'error.message', '')) ?: 'Stripe checkout could not be created.';
            $this->audit->record((int) $order->tenant_id, null, 'tenant_billing.agreement_checkout.create', status: 'failed', targetType: 'tenant_billing_order', targetId: $order->id, context: ['stripe_status' => $response->status(), 'stripe_error' => $message]);

            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => $message];
        }

        $sessionId = trim((string) ($json['id'] ?? ''));
        $url = trim((string) ($json['url'] ?? ''));
        if ($sessionId === '' || $url === '' || ! str_starts_with($url, 'https://')) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Stripe returned an invalid checkout session.'];
        }
        $order->forceFill([
            'status' => 'checkout_pending', 'provider_checkout_session_id' => $sessionId, 'provider_customer_id' => $customerId,
            'checkout_started_at' => now(), 'metadata' => [...(array) $order->metadata, 'checkout_mode' => $mode, 'automatic_tax_enabled' => $this->automaticTaxEnabled(), 'saved_payment_method_collection' => true],
        ])->save();
        $this->audit->record((int) $order->tenant_id, null, 'tenant_billing.agreement_checkout.create', status: 'success', targetType: 'tenant_billing_order', targetId: $order->id, context: ['stripe_checkout_session_id' => $sessionId, 'mode' => $mode]);

        return ['ok' => true, 'url' => $url, 'session_id' => $sessionId, 'message' => null];
    }

    public function availableFor(TenantBillingOrder $order): bool
    {
        return $this->readinessBlocker($order->loadMissing(['tenant', 'agreement'])) === null;
    }

    protected function reusableCustomerId(TenantBillingOrder $order): ?string
    {
        if ($order->agreement?->agreement_type === Agreement::TYPE_SANDBOX_VALIDATION) {
            return null;
        }

        $email = trim(strtolower((string) $order->acceptance?->signer_email));
        $candidates = [
            $order->provider_customer_id,
            $email !== '' ? TenantBillingOrder::withoutGlobalScopes()
                ->where('tenant_id', $order->tenant_id)
                ->whereHas('agreement', fn ($query) => $query->where('agreement_type', '!=', Agreement::TYPE_SANDBOX_VALIDATION))
                ->whereNotNull('provider_customer_id')
                ->whereHas('acceptance', fn ($query) => $query->whereRaw('LOWER(signer_email) = ?', [$email]))
                ->latest('id')
                ->value('provider_customer_id') : null,
            $email !== '' ? TenantDirectInvoice::withoutGlobalScopes()
                ->where('tenant_id', $order->tenant_id)
                ->whereRaw('LOWER(customer_email) = ?', [$email])
                ->whereNotNull('provider_customer_id')
                ->latest('id')
                ->value('provider_customer_id') : null,
            data_get(TenantCommercialOverride::query()->where('tenant_id', $order->tenant_id)->first()?->billing_mapping, 'stripe.customer_reference'),
        ];

        foreach ($candidates as $candidate) {
            $customerId = trim((string) $candidate);
            if (str_starts_with($customerId, 'cus_')) {
                return $customerId;
            }
        }

        return null;
    }

    protected function readinessBlocker(TenantBillingOrder $order): ?string
    {
        if (! (bool) config('commercial.billing_readiness.agreement_checkout.enabled', false)) {
            return 'Agreement checkout is not enabled yet.';
        }
        $allowed = (array) config('commercial.billing_readiness.agreement_checkout.tenant_slugs', []);
        if (! in_array('*', $allowed, true) && ! in_array((string) $order->tenant?->slug, $allowed, true)) {
            return 'Agreement checkout is not enabled for this workspace yet.';
        }
        $secret = trim((string) config('services.stripe.secret', ''));
        if (! str_starts_with($secret, 'sk_test_') && ! str_starts_with($secret, 'sk_live_')) {
            return 'Stripe is not configured.';
        }
        $sandboxAgreement = $order->agreement?->agreement_type === Agreement::TYPE_SANDBOX_VALIDATION;
        $validationSnapshot = data_get($order->metadata, 'validation_only') === true;
        if ($sandboxAgreement !== $validationSnapshot) {
            return 'The agreement and billing-order validation modes do not match.';
        }
        $validationOnly = $sandboxAgreement;
        if ($validationOnly && str_starts_with($secret, 'sk_live_')) {
            return 'Sandbox validation agreements cannot use live Stripe credentials.';
        }
        if (! $validationOnly && app()->environment('production') && str_starts_with($secret, 'sk_test_')) {
            return 'Client agreements cannot use Stripe test credentials on production.';
        }
        if (str_starts_with($secret, 'sk_live_')) {
            if (! filled(config('services.stripe.webhook_secret'))) {
                return 'The live Stripe webhook secret is not configured.';
            }
            if (! (bool) config('commercial.billing_readiness.agreement_checkout.tax_decision_confirmed', false)) {
                return 'The required tax decision has not been confirmed.';
            }
            if (! (bool) config('commercial.billing_readiness.agreement_checkout.relay_payout_verified', false)) {
                return 'Stripe payouts to Relay have not been verified.';
            }
        }

        return null;
    }

    protected function automaticTaxEnabled(): bool
    {
        return (bool) config('commercial.billing_readiness.agreement_checkout.automatic_tax_enabled', false)
            && (bool) config('commercial.billing_readiness.agreement_checkout.tax_decision_confirmed', false);
    }

    protected function stripeRequest(): PendingRequest
    {
        return Http::asForm()->acceptJson()->timeout(max(5, (int) config('services.stripe.timeout', 20)))->retry(1, 250, throw: false)->withBasicAuth((string) config('services.stripe.secret'), '');
    }

    protected function apiBase(): string
    {
        return rtrim((string) config('services.stripe.api_base', 'https://api.stripe.com'), '/');
    }
}
