<?php

namespace App\Services\Billing;

use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingRefund;
use App\Models\User;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use DomainException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class LandlordTransactionRefundService
{
    public function __construct(private LandlordOperatorActionAuditService $audit) {}

    public function refund(TenantBillingReceipt $receipt, int $amountCents, string $reason, ?string $note, string $idempotencyKey, ?User $actor): TenantBillingRefund
    {
        $receipt->loadMissing(['billingOrder', 'directInvoice']);
        $amountCents = max(0, $amountCents);
        $reason = in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true) ? $reason : 'requested_by_customer';
        $paymentIntent = trim((string) ($receipt->billingOrder?->provider_payment_intent_id ?? $receipt->directInvoice?->provider_payment_intent_id));

        if (strtolower((string) $receipt->provider) !== 'stripe' || $paymentIntent === '') {
            throw new DomainException('Only Stripe payments with a verified payment intent can be refunded here.');
        }
        if (strtolower((string) $receipt->status) !== 'paid') {
            throw new DomainException('Only completed payments can be refunded.');
        }
        if ($amountCents < 1) {
            throw new DomainException('Enter a refund amount greater than zero.');
        }

        $existing = TenantBillingRefund::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $alreadyRefunded = (int) TenantBillingRefund::query()
            ->where('tenant_billing_receipt_id', $receipt->id)
            ->whereIn('status', ['pending', 'succeeded'])
            ->sum('amount_cents');
        $remaining = max(0, (int) $receipt->total_amount_cents - $alreadyRefunded);
        if ($amountCents > $remaining) {
            throw new DomainException('That amount exceeds the remaining refundable balance of $'.number_format($remaining / 100, 2).'.');
        }

        $refund = TenantBillingRefund::query()->create([
            'tenant_id' => $receipt->tenant_id,
            'tenant_billing_receipt_id' => $receipt->id,
            'tenant_billing_order_id' => $receipt->tenant_billing_order_id,
            'tenant_direct_invoice_id' => $receipt->tenant_direct_invoice_id,
            'requested_by_user_id' => $actor?->id,
            'provider' => 'stripe',
            'provider_payment_intent_id' => $paymentIntent,
            'amount_cents' => $amountCents,
            'currency' => strtoupper((string) $receipt->currency),
            'reason' => $reason,
            'note' => $note,
            'idempotency_key' => $idempotencyKey,
        ]);

        $response = $this->stripeRequest()->withHeaders(['Idempotency-Key' => 'operator-refund-'.$idempotencyKey])->post($this->apiBase().'/v1/refunds', [
            'payment_intent' => $paymentIntent,
            'amount' => $amountCents,
            'reason' => $reason,
            'metadata[everbranch_refund_id]' => (string) $refund->id,
            'metadata[tenant_id]' => (string) $receipt->tenant_id,
        ]);
        $payload = is_array($response->json()) ? $response->json() : [];
        if ($response->failed() || trim((string) ($payload['id'] ?? '')) === '') {
            $message = trim((string) data_get($payload, 'error.message', '')) ?: 'Stripe could not create this refund.';
            $refund->forceFill(['status' => 'failed', 'metadata' => ['stripe_status' => $response->status(), 'error' => $message]])->save();
            $this->audit->record((int) $receipt->tenant_id, $actor?->id, 'tenant_billing.refund.create', 'failed', 'tenant_billing_receipt', $receipt->id, ['amount_cents' => $amountCents, 'stripe_status' => $response->status()]);

            throw new DomainException($message);
        }

        $status = strtolower(trim((string) ($payload['status'] ?? 'pending')));
        $refund->forceFill([
            'provider_refund_id' => (string) $payload['id'],
            'status' => in_array($status, TenantBillingRefund::STATUSES, true) ? $status : 'pending',
            'processed_at' => in_array($status, ['succeeded', 'canceled'], true) ? now() : null,
            'metadata' => ['stripe_status' => $status, 'charge' => $payload['charge'] ?? null],
        ])->save();

        $this->updateLocalPaymentState($receipt, $refund);
        $this->audit->record((int) $receipt->tenant_id, $actor?->id, 'tenant_billing.refund.create', 'success', 'tenant_billing_refund', $refund->id, ['receipt_id' => $receipt->id, 'amount_cents' => $amountCents, 'provider_refund_id' => $refund->provider_refund_id]);

        return $refund;
    }

    private function updateLocalPaymentState(TenantBillingReceipt $receipt, TenantBillingRefund $refund): void
    {
        if ($refund->status !== 'succeeded') {
            return;
        }
        $totalRefunded = (int) TenantBillingRefund::query()
            ->where('tenant_billing_receipt_id', $receipt->id)
            ->where('status', 'succeeded')
            ->sum('amount_cents');
        $fullyRefunded = $totalRefunded >= (int) $receipt->total_amount_cents;

        if ($receipt->billingOrder) {
            $metadata = (array) $receipt->billingOrder->metadata;
            $receipt->billingOrder->forceFill([
                'status' => $fullyRefunded ? 'refunded' : $receipt->billingOrder->status,
                'refunded_at' => $fullyRefunded ? now() : $receipt->billingOrder->refunded_at,
                'metadata' => [...$metadata, 'operator_refunded_amount_cents' => $totalRefunded],
            ])->save();
        }
        if ($receipt->directInvoice) {
            $metadata = (array) $receipt->directInvoice->metadata;
            $receipt->directInvoice->forceFill([
                'status' => $fullyRefunded ? 'refunded' : $receipt->directInvoice->status,
                'refunded_at' => $fullyRefunded ? now() : $receipt->directInvoice->refunded_at,
                'metadata' => [...$metadata, 'operator_refunded_amount_cents' => $totalRefunded],
            ])->save();
        }
        $receipt->forceFill(['metadata' => [...(array) $receipt->metadata, 'operator_refunded_amount_cents' => $totalRefunded]])->save();
    }

    private function stripeRequest(): PendingRequest
    {
        $secret = trim((string) config('services.stripe.secret'));
        if (! str_starts_with($secret, 'sk_test_') && ! str_starts_with($secret, 'sk_live_')) {
            throw new DomainException('Stripe is not configured for refunds.');
        }

        return Http::asForm()->acceptJson()->timeout(max(5, (int) config('services.stripe.timeout', 20)))->retry(1, 250, throw: false)->withBasicAuth($secret, '');
    }

    private function apiBase(): string
    {
        return rtrim((string) config('services.stripe.api_base', 'https://api.stripe.com'), '/');
    }
}
