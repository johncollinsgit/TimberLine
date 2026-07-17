<?php

namespace App\Services\Billing;

use App\Models\TenantBillingOrder;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Carbon;

class AgreementStripeWebhookService
{
    public function __construct(protected TenantBillingReceiptLedger $receipts, protected LandlordOperatorActionAuditService $audit) {}

    /** @param array<string,mixed> $object @param array<string,mixed> $metadata */
    public function handle(int $tenantId, string $eventId, string $eventType, array $object, array $metadata): bool
    {
        $order = $this->resolveOrder($tenantId, $object, $metadata);
        if (! $order) {
            return false;
        }
        $order->loadMissing(['tenant', 'authorization']);

        $customer = trim((string) ($object['customer'] ?? ''));
        $subscription = trim((string) ($object['subscription'] ?? ''));
        if (($object['object'] ?? '') === 'subscription') {
            $subscription = trim((string) ($object['id'] ?? ''));
        }
        $paymentIntent = trim((string) ($object['payment_intent'] ?? ''));
        $invoice = ($object['object'] ?? '') === 'invoice' ? trim((string) ($object['id'] ?? '')) : trim((string) ($object['invoice'] ?? ''));
        $isInitialInvoice = $invoice !== '' && (! filled($order->provider_invoice_id) || $invoice === $order->provider_invoice_id);
        $status = (string) $order->status;
        $updates = [
            'provider_customer_id' => $customer !== '' ? $customer : $order->provider_customer_id,
            'provider_subscription_id' => $subscription !== '' ? $subscription : $order->provider_subscription_id,
            'provider_payment_intent_id' => $paymentIntent !== '' && ! filled($order->provider_payment_intent_id) ? $paymentIntent : $order->provider_payment_intent_id,
            'provider_invoice_id' => $invoice !== '' && ! filled($order->provider_invoice_id) ? $invoice : $order->provider_invoice_id,
            'last_provider_event_id' => $eventId, 'last_provider_event_type' => $eventType,
        ];
        $paymentStatus = strtolower(trim((string) ($object['payment_status'] ?? '')));
        if ($eventType === 'checkout.session.completed') {
            $updates['provider_checkout_session_id'] = (string) ($object['id'] ?? $order->provider_checkout_session_id);
            if (in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
                $status = 'paid';
                $updates['paid_at'] = now();
            } else {
                $status = 'processing';
                $updates['processing_at'] = now();
            }
        } elseif (in_array($eventType, ['checkout.session.async_payment_succeeded', 'invoice.paid', 'invoice.payment_succeeded'], true)) {
            $status = 'paid';
            $updates['paid_at'] = now();
        } elseif (in_array($eventType, ['checkout.session.async_payment_failed', 'invoice.payment_failed'], true) && $status !== 'paid') {
            $status = 'failed';
            $updates['failed_at'] = now();
        } elseif ($eventType === 'checkout.session.expired' && ! in_array($status, ['paid', 'refunded'], true)) {
            $status = 'expired';
        } elseif ($eventType === 'invoice.voided' && $status !== 'paid') {
            $status = 'void';
        } elseif ($eventType === 'charge.refunded') {
            $fullyRefunded = (bool) ($object['refunded'] ?? false)
                || ((int) ($object['amount'] ?? 0) > 0 && (int) ($object['amount_refunded'] ?? 0) >= (int) ($object['amount'] ?? 0));
            $belongsToInitialCharge = ($invoice !== '' && $invoice === $order->provider_invoice_id)
                || ($invoice === '' && $paymentIntent !== '' && $paymentIntent === $order->provider_payment_intent_id);
            if ($fullyRefunded && $belongsToInitialCharge) {
                $status = 'refunded';
                $updates['refunded_at'] = now();
            } else {
                $updates['metadata'] = [...(array) $order->metadata, 'latest_non_initial_or_partial_refund_amount_cents' => (int) ($object['amount_refunded'] ?? 0)];
            }
        } elseif ($eventType === 'refund.created') {
            $updates['metadata'] = [...(array) $order->metadata, 'last_refund_id' => $object['id'] ?? null, 'last_refund_amount_cents' => (int) ($object['amount'] ?? 0)];
        }
        if ($eventType === 'charge.dispute.created') {
            $updates['metadata'] = [...(array) $order->metadata, 'dispute_status' => 'open', 'dispute_id' => $object['id'] ?? null];
        }
        $updates['status'] = $status;
        $order->forceFill($updates)->save();

        $authorization = $order->authorization;
        if ($authorization) {
            $authorizationStatus = (string) $authorization->status;
            if ($status === 'paid') {
                $authorizationStatus = $subscription !== '' || filled($order->provider_subscription_id) ? 'provider_verified' : 'payment_verified';
            }
            if (in_array($eventType, ['checkout.session.async_payment_failed', 'invoice.payment_failed'], true)) {
                $authorizationStatus = 'payment_failed';
            }
            if ($status === 'refunded') {
                $authorizationStatus = 'refunded';
            }
            if ($eventType === 'charge.dispute.created') {
                $authorizationStatus = 'disputed';
            }
            if (in_array($eventType, ['customer.subscription.created', 'customer.subscription.updated'], true)) {
                $providerStatus = strtolower(trim((string) ($object['status'] ?? '')));
                if (in_array($providerStatus, ['active', 'trialing'], true) && $order->status === 'paid') {
                    $authorizationStatus = 'provider_verified';
                } elseif ($providerStatus !== '') {
                    $authorizationStatus = $providerStatus;
                }
            }
            if ($eventType === 'customer.subscription.deleted') {
                $authorizationStatus = 'canceled';
            }
            $authorization->forceFill([
                'provider' => 'stripe', 'billing_lane' => 'stripe_direct', 'status' => $authorizationStatus,
                'provider_subscription_id' => $subscription !== '' ? $subscription : $authorization->provider_subscription_id,
                'last_reconciled_at' => now(), 'metadata' => [...(array) $authorization->metadata, 'last_provider_event_id' => $eventId, 'last_provider_event_type' => $eventType],
            ])->save();
        }

        if (($object['object'] ?? '') === 'invoice') {
            $tax = max(0, (int) ($object['tax'] ?? collect((array) ($object['total_tax_amounts'] ?? []))->sum('amount')));
            $total = max(0, (int) ($object['total'] ?? 0));
            if ($isInitialInvoice) {
                $order->forceFill(['provider_tax_cents' => $tax, 'provider_total_cents' => $total])->save();
            }
            $paidAt = (int) data_get($object, 'status_transitions.paid_at', 0);
            $this->receipts->recordVerifiedProviderReceipt($order->tenant, 'stripe', [
                'provider_receipt_id' => (string) $object['id'], 'provider_subscription_id' => $subscription !== '' ? $subscription : $order->provider_subscription_id,
                'invoice_number' => $object['number'] ?? null, 'status' => (string) ($object['status'] ?? ($status === 'paid' ? 'paid' : 'open')),
                'currency' => strtoupper((string) ($object['currency'] ?? $order->currency)), 'subtotal_amount_cents' => max(0, $total - $tax),
                'tax_amount_cents' => $tax, 'total_amount_cents' => $total, 'billed_at' => $this->timestamp($object['created'] ?? null),
                'paid_at' => $paidAt > 0 ? Carbon::createFromTimestamp($paidAt) : null,
                'hosted_invoice_url' => $object['hosted_invoice_url'] ?? null, 'receipt_url' => $object['invoice_pdf'] ?? null,
                'source_event_id' => $eventId, 'metadata' => ['stripe_subtotal_cents' => (int) ($object['subtotal'] ?? 0), 'automatic_tax' => $object['automatic_tax'] ?? null],
            ], $authorization, $order);
        }

        $this->audit->record((int) $order->tenant_id, null, 'tenant_billing.agreement_webhook', status: 'success', targetType: 'tenant_billing_order', targetId: $order->id, context: ['event_id' => $eventId, 'event_type' => $eventType, 'order_status' => $status]);

        return true;
    }

    /** @param array<string,mixed> $object @param array<string,mixed> $metadata */
    protected function resolveOrder(int $tenantId, array $object, array $metadata): ?TenantBillingOrder
    {
        $orderId = (int) ($metadata['billing_order_id'] ?? data_get($object, 'parent.subscription_details.metadata.billing_order_id', data_get($object, 'subscription_details.metadata.billing_order_id', 0)));
        $query = TenantBillingOrder::withoutGlobalScopes()->where('tenant_id', $tenantId);
        if ($orderId > 0) {
            return $query->whereKey($orderId)->first();
        }
        $subscription = trim((string) ($object['subscription'] ?? (($object['object'] ?? '') === 'subscription' ? ($object['id'] ?? '') : '')));
        if ($subscription !== '') {
            return $query->where('provider_subscription_id', $subscription)->first();
        }
        $paymentIntent = trim((string) ($object['payment_intent'] ?? ''));
        if ($paymentIntent !== '') {
            return $query->where('provider_payment_intent_id', $paymentIntent)->first();
        }
        $id = trim((string) ($object['id'] ?? ''));
        if (str_starts_with($id, 'cs_')) {
            return $query->where('provider_checkout_session_id', $id)->first();
        }
        if (str_starts_with($id, 'in_')) {
            return $query->where('provider_invoice_id', $id)->first();
        }

        return null;
    }

    protected function timestamp(mixed $value): ?Carbon
    {
        return is_numeric($value) && (int) $value > 0 ? Carbon::createFromTimestamp((int) $value) : null;
    }
}
