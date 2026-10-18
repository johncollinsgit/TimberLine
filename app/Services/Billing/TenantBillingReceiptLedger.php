<?php

namespace App\Services\Billing;

use App\Models\SubscriptionAuthorization;
use App\Models\Tenant;
use App\Models\TenantBillingOrder;
use App\Models\TenantBillingReceipt;
use App\Models\TenantDirectInvoice;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TenantBillingReceiptLedger
{
    /** @param array<string,mixed> $receipt */
    public function recordVerifiedProviderReceipt(Tenant $tenant, string $provider, array $receipt, ?SubscriptionAuthorization $authorization = null, ?TenantBillingOrder $billingOrder = null, ?TenantDirectInvoice $directInvoice = null): TenantBillingReceipt
    {
        return DB::transaction(function () use ($tenant, $provider, $receipt, $authorization, $billingOrder, $directInvoice) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            return $this->recordReceipt($tenant, $provider, $receipt, $authorization, $billingOrder, $directInvoice);
        });
    }

    protected function recordReceipt(Tenant $tenant, string $provider, array $receipt, ?SubscriptionAuthorization $authorization, ?TenantBillingOrder $billingOrder, ?TenantDirectInvoice $directInvoice): TenantBillingReceipt
    {
        $provider = strtolower(trim($provider));
        $providerReceiptId = trim((string) ($receipt['provider_receipt_id'] ?? ''));
        if ($provider === '' || $providerReceiptId === '') {
            throw new InvalidArgumentException('A provider and provider receipt ID are required.');
        }
        if ($authorization && (int) $authorization->tenant_id !== (int) $tenant->id) {
            throw new InvalidArgumentException('The subscription authorization does not belong to this tenant.');
        }
        if ($billingOrder && (int) $billingOrder->tenant_id !== (int) $tenant->id) {
            throw new InvalidArgumentException('The billing order does not belong to this tenant.');
        }
        if ($directInvoice && (int) $directInvoice->tenant_id !== (int) $tenant->id) {
            throw new InvalidArgumentException('The direct invoice does not belong to this tenant.');
        }
        $existing = TenantBillingReceipt::query()->where('provider', $provider)->where('provider_receipt_id', $providerReceiptId)->first();
        if ($existing && (int) $existing->tenant_id !== (int) $tenant->id) {
            throw new InvalidArgumentException('The provider receipt is already bound to another tenant.');
        }
        $subtotal = max(0, (int) ($receipt['subtotal_amount_cents'] ?? 0));
        $tax = max(0, (int) ($receipt['tax_amount_cents'] ?? 0));
        $total = (int) ($receipt['total_amount_cents'] ?? ($subtotal + $tax));
        if ($total !== $subtotal + $tax) {
            throw new InvalidArgumentException('Provider receipt total must equal subtotal plus tax.');
        }

        $metadata = (array) ($receipt['metadata'] ?? []);
        unset($metadata['usage_statement'], $metadata['usage_notice']);
        $previousNotice = data_get($existing?->metadata, 'usage_notice');
        if (is_array($previousNotice)) {
            $metadata['usage_notice'] = $previousNotice;
        } elseif (config('commercial.billing_readiness.usage_receipts_enabled', false) && $provider === 'stripe' && ($receipt['status'] ?? '') === 'paid' && filled($receipt['source_event_id'] ?? null)) {
            $metadata['usage_notice'] = ['status' => 'pending', 'created_at' => now()->toIso8601String()];
        }
        $previousStatement = data_get($existing?->metadata, 'usage_statement');
        if (is_array($previousStatement)) {
            $metadata['usage_statement'] = $previousStatement;
        } elseif (strtolower((string) ($receipt['status'] ?? '')) === 'paid' && ! empty($receipt['paid_at'])) {
            $cutoff = now();
            $periodStart = filled($receipt['billing_period_starts_at'] ?? null) ? \Illuminate\Support\Carbon::parse($receipt['billing_period_starts_at']) : $cutoff->copy()->startOfMonth();
            $periodEnd = filled($receipt['billing_period_ends_at'] ?? null) ? \Illuminate\Support\Carbon::parse($receipt['billing_period_ends_at'])->min($cutoff) : $cutoff;
            // Advance invoices can cover a future service period. Their attached
            // usage statement is current actuals, never a claim about future usage.
            if ($periodStart->greaterThan($periodEnd)) {
                $periodStart = $cutoff->copy()->startOfMonth();
                $periodEnd = $cutoff;
            }
            $metadata['usage_statement'] = app(TenantUsageStatementService::class)->snapshot($tenant, $periodStart, $periodEnd);
        }

        return TenantBillingReceipt::query()->updateOrCreate(
            ['provider' => $provider, 'provider_receipt_id' => $providerReceiptId],
            [
                'tenant_id' => (int) $tenant->id,
                'tenant_billing_order_id' => $billingOrder?->id ?? $existing?->tenant_billing_order_id,
                'tenant_direct_invoice_id' => $directInvoice?->id ?? $existing?->tenant_direct_invoice_id,
                'subscription_authorization_id' => $authorization?->id ?? $existing?->subscription_authorization_id,
                'provider_subscription_id' => $receipt['provider_subscription_id'] ?? null,
                'invoice_number' => $receipt['invoice_number'] ?? null,
                'status' => strtolower(trim((string) ($receipt['status'] ?? 'open'))),
                'currency' => strtoupper(trim((string) ($receipt['currency'] ?? 'USD'))),
                'subtotal_amount_cents' => $subtotal,
                'tax_amount_cents' => $tax,
                'total_amount_cents' => $total,
                'provider_calculated_tax' => true,
                'tax_jurisdiction' => $receipt['tax_jurisdiction'] ?? null,
                'billing_period_starts_at' => $receipt['billing_period_starts_at'] ?? null,
                'billing_period_ends_at' => $receipt['billing_period_ends_at'] ?? null,
                'billed_at' => $receipt['billed_at'] ?? null,
                'paid_at' => $receipt['paid_at'] ?? null,
                'hosted_invoice_url' => $this->safeProviderUrl($receipt['hosted_invoice_url'] ?? null),
                'receipt_url' => $this->safeProviderUrl($receipt['receipt_url'] ?? null),
                'source_event_id' => $receipt['source_event_id'] ?? null,
                'metadata' => $metadata,
            ]
        );
    }

    protected function safeProviderUrl(mixed $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidArgumentException('Provider receipt links must use a valid HTTPS URL.');
        }

        return $url;
    }
}
