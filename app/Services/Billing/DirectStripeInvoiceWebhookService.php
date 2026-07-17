<?php

namespace App\Services\Billing;

use App\Models\TenantDirectInvoice;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Carbon;

class DirectStripeInvoiceWebhookService
{
    public function __construct(protected TenantBillingReceiptLedger $receipts, protected LandlordOperatorActionAuditService $audit) {}

    /** @param array<string,mixed> $object @param array<string,mixed> $metadata */
    public function handle(int $tenantId, string $eventId, string $eventType, array $object, array $metadata): bool
    {
        $invoice = $this->resolveInvoice($tenantId, $object, $metadata);
        if (! $invoice) {
            return false;
        }
        $invoice->loadMissing('tenant');
        $status = (string) $invoice->status;
        $updates = [
            'last_provider_event_id' => $eventId,
            'last_provider_event_type' => $eventType,
            'provider_customer_id' => filled($object['customer'] ?? null) ? (string) $object['customer'] : $invoice->provider_customer_id,
            'provider_payment_intent_id' => filled($object['payment_intent'] ?? null) ? (string) $object['payment_intent'] : $invoice->provider_payment_intent_id,
        ];

        if ($eventType === 'invoice.finalized') {
            $status = 'open';
            $updates['finalized_at'] = $this->timestamp(data_get($object, 'status_transitions.finalized_at')) ?? now();
        } elseif ($eventType === 'invoice.sent') {
            $status = 'open';
            $updates['sent_at'] = $this->timestamp(data_get($object, 'status_transitions.finalized_at')) ?? $invoice->sent_at ?? now();
        } elseif (in_array($eventType, ['invoice.paid', 'invoice.payment_succeeded'], true)) {
            $status = 'paid';
            $updates['paid_at'] = $this->timestamp(data_get($object, 'status_transitions.paid_at')) ?? now();
        } elseif ($eventType === 'invoice.payment_failed' && $status !== 'paid') {
            $status = 'payment_failed';
            $updates['failed_at'] = now();
        } elseif ($eventType === 'invoice.finalization_failed' && ! in_array($status, ['paid', 'void'], true)) {
            $status = 'send_failed';
            $updates['failed_at'] = now();
            $updates['metadata'] = [...(array) $invoice->metadata, 'last_finalization_error' => data_get($object, 'last_finalization_error.message')];
        } elseif ($eventType === 'invoice.marked_uncollectible' && $status !== 'paid') {
            $status = 'uncollectible';
        } elseif ($eventType === 'invoice.voided' && $status !== 'paid') {
            $status = 'void';
            $updates['voided_at'] = now();
        } elseif ($eventType === 'charge.refunded') {
            $amount = (int) ($object['amount'] ?? 0);
            $refunded = (int) ($object['amount_refunded'] ?? 0);
            if ((bool) ($object['refunded'] ?? false) || ($amount > 0 && $refunded >= $amount)) {
                $status = 'refunded';
                $updates['refunded_at'] = now();
            }
        } elseif ($eventType === 'charge.dispute.created') {
            $updates['metadata'] = [...(array) $invoice->metadata, 'dispute_status' => 'open', 'dispute_id' => $object['id'] ?? null];
        }

        if (($object['object'] ?? '') === 'invoice') {
            $tax = max(0, (int) ($object['tax'] ?? collect((array) ($object['total_tax_amounts'] ?? []))->sum('amount')));
            $total = max(0, (int) ($object['total'] ?? 0));
            $updates = [...$updates,
                'provider_invoice_number' => $object['number'] ?? $invoice->provider_invoice_number,
                'provider_tax_cents' => $tax,
                'provider_total_cents' => $total,
                'provider_amount_due_cents' => max(0, (int) ($object['amount_due'] ?? 0)),
                'hosted_invoice_url' => $this->safeUrl($object['hosted_invoice_url'] ?? null) ?? $invoice->hosted_invoice_url,
                'invoice_pdf_url' => $this->safeUrl($object['invoice_pdf'] ?? null) ?? $invoice->invoice_pdf_url,
            ];
            $this->receipts->recordVerifiedProviderReceipt($invoice->tenant, 'stripe', [
                'provider_receipt_id' => (string) $object['id'],
                'invoice_number' => $object['number'] ?? null,
                'status' => (string) ($object['status'] ?? $status),
                'currency' => strtoupper((string) ($object['currency'] ?? $invoice->currency)),
                'subtotal_amount_cents' => max(0, $total - $tax),
                'tax_amount_cents' => $tax,
                'total_amount_cents' => $total,
                'billed_at' => $this->timestamp($object['created'] ?? null),
                'paid_at' => $status === 'paid' ? ($updates['paid_at'] ?? now()) : null,
                'hosted_invoice_url' => $object['hosted_invoice_url'] ?? null,
                'receipt_url' => $object['invoice_pdf'] ?? null,
                'source_event_id' => $eventId,
                'metadata' => ['purpose' => 'direct_invoice', 'automatic_tax' => $object['automatic_tax'] ?? null],
            ], directInvoice: $invoice);
        }

        $updates['status'] = $status;
        $invoice->forceFill($updates)->save();
        $this->audit->record((int) $invoice->tenant_id, null, 'tenant_billing.direct_invoice.webhook', targetType: 'tenant_direct_invoice', targetId: $invoice->id, context: ['event_id' => $eventId, 'event_type' => $eventType, 'invoice_status' => $status]);

        return true;
    }

    /** @param array<string,mixed> $object @param array<string,mixed> $metadata */
    protected function resolveInvoice(int $tenantId, array $object, array $metadata): ?TenantDirectInvoice
    {
        $query = TenantDirectInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId);
        $invoiceId = (int) ($metadata['direct_invoice_id'] ?? 0);
        if ($invoiceId > 0) {
            return $query->whereKey($invoiceId)->first();
        }
        $providerInvoiceId = ($object['object'] ?? '') === 'invoice' ? trim((string) ($object['id'] ?? '')) : trim((string) ($object['invoice'] ?? ''));
        if ($providerInvoiceId !== '') {
            return $query->where('provider_invoice_id', $providerInvoiceId)->first();
        }
        $paymentIntent = trim((string) ($object['payment_intent'] ?? ''));

        return $paymentIntent !== '' ? $query->where('provider_payment_intent_id', $paymentIntent)->first() : null;
    }

    protected function timestamp(mixed $value): ?Carbon
    {
        return is_numeric($value) && (int) $value > 0 ? Carbon::createFromTimestamp((int) $value) : null;
    }

    protected function safeUrl(mixed $url): ?string
    {
        $url = trim((string) $url);

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }
}
