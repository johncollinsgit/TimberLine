<?php

namespace App\Services\Billing;

use App\Mail\TenantUsageReceiptMail;
use App\Models\TenantBillingReceipt;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Receipt metadata is a durable outbox. Ambiguous mail results require reconciliation, never a blind resend. */
class TenantUsageReceiptService
{
    public function dispatchPending(int $limit = 50): int
    {
        if (! config('commercial.billing_readiness.usage_receipts_enabled', false)
            || in_array(config('mail.default'), ['log', 'array'], true)) {
            return 0;
        }
        $sent = 0;
        $ids = TenantBillingReceipt::withoutGlobalScopes()->stripePaymentConfirmed(true)
            ->where('metadata->usage_notice->status', 'pending')->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $claimed = DB::transaction(function () use ($id) {
                $receipt = TenantBillingReceipt::withoutGlobalScopes()->stripePaymentConfirmed(true)->whereKey($id)->lockForUpdate()->first();
                if (! $receipt || data_get($receipt->metadata, 'usage_notice.status') !== 'pending') {
                    return null;
                }
                $recipient = $this->recipient($receipt);
                if (! $recipient || ! is_array(data_get($receipt->metadata, 'usage_statement'))) {
                    $this->mark($receipt, 'blocked_recipient_or_statement');

                    return null;
                }
                $this->mark($receipt, 'dispatching');

                return [$receipt, $recipient];
            });
            if (! $claimed) {
                continue;
            }
            [$receipt, $recipient] = $claimed;
            try {
                Mail::to($recipient)->send(new TenantUsageReceiptMail($receipt));
                $status = 'sent';
                $sent++;
            } catch (\Throwable) {
                $status = 'needs_reconciliation';
            }
            DB::transaction(function () use ($id, $status) {
                $receipt = TenantBillingReceipt::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->firstOrFail();
                $this->mark($receipt, $status);
            });
        }

        return $sent;
    }

    protected function recipient(TenantBillingReceipt $receipt): ?string
    {
        $direct = $receipt->directInvoice;
        $order = $receipt->billingOrder;
        $authorization = $receipt->authorization;
        $email = null;
        if ($direct && (int) $direct->tenant_id === (int) $receipt->tenant_id && $direct->status === 'paid'
            && $direct->provider_invoice_id === $receipt->provider_receipt_id) {
            $email = $direct->customer_email;
        } elseif ($order && (int) $order->tenant_id === (int) $receipt->tenant_id && $order->status === 'paid') {
            $acceptance = $order->acceptance;
            if ($acceptance && (int) $acceptance->tenant_id === (int) $receipt->tenant_id
                && (int) $acceptance->agreement_id === (int) $order->agreement_id
                && (int) $acceptance->agreement_version_id === (int) $order->agreement_version_id) {
                $email = $acceptance->signer_email;
            }
        } elseif ($authorization && (int) $authorization->tenant_id === (int) $receipt->tenant_id) {
            $acceptance = $authorization->acceptance;
            if ($acceptance && (int) $acceptance->tenant_id === (int) $receipt->tenant_id
                && (int) $acceptance->agreement_id === (int) $authorization->agreement_id
                && (int) $acceptance->agreement_version_id === (int) $authorization->agreement_version_id) {
                $email = $acceptance->signer_email;
            }
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    protected function mark(TenantBillingReceipt $receipt, string $status): void
    {
        $metadata = (array) $receipt->metadata;
        $before = data_get($metadata, 'usage_notice.status');
        $metadata['usage_notice'] = [...(array) ($metadata['usage_notice'] ?? []), 'status' => $status, 'updated_at' => now()->toIso8601String()];
        $receipt->forceFill(['metadata' => $metadata])->save();
        app(LandlordOperatorActionAuditService::class)->record(tenantId: $receipt->tenant_id, actorUserId: null,
            actionType: 'billing_usage_receipt_delivery', targetType: 'tenant_billing_receipt', targetId: $receipt->id,
            beforeState: ['status' => $before], afterState: ['status' => $status], result: ['billing_impact_cents' => 0]);
    }
}
