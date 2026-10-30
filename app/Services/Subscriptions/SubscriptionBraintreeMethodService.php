<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionBraintreeMethodService
{
    public function verify(int $tenantId, User $actor, int $agreementId, string $sourceOrderId, bool $persist = true): array
    {
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $access = app(SubscriptionReplacementAccess::class);
        $access->owner($tenantId, $actor, $row->member_id, 'setup');
        abort_unless($row->processor === 'braintree' && $row->status !== 'cancelled', 409);
        $access->member($row);
        $binding = $row->method_binding;
        $transport = app(SubscriptionBraintreeTransport::class);
        $context = $transport->context($tenantId, $binding);
        $method = $transport->verifyMethod($tenantId, $binding, $row->terms['currency']);
        abort_unless(in_array($method['type'] ?? null, ['card', 'paypal'], true)
            && (($method['type'] ?? null) !== 'paypal' || ($method['billing_agreement_present'] ?? null) === true), 409, 'The original reusable payment method or agreement needs review.');
        $transaction = $transport->transaction($tenantId, $binding, $binding['source_payment_reference']);
        $linked = $transport->methodTransaction($tenantId, $binding, $binding['source_payment_reference']);
        abort_unless(count($linked) === 1 && $linked[0] === $transaction && $transaction['id'] === $binding['source_payment_reference']
            && $transaction['customer_id'] === $binding['customer_id'] && $transaction['merchant_account_id'] === $binding['merchant_account_id']
            && $transaction['type'] === 'sale' && $transaction['status'] === 'settled'
            && $transaction['currency'] === $row->terms['currency'] && SubscriptionPilotShopifyBridge::cents($transaction['amount']) === $row->terms['amount_cents']
            && $transaction['refund_ids'] === [] && $transaction['dispute_count'] === 0
            && $transaction['refunded_transaction_id'] === null
            && $transaction['instrument_type'] === ($method['type'] === 'card' ? 'credit_card' : 'paypal_account'), 409, 'The original transaction does not prove this exact vaulted method and recurring amount.');
        $source = app(SubscriptionBillingShopifySource::class)->originalPayment($row, $sourceOrderId, $transaction['id']);
        abort_unless(abs(CarbonImmutable::parse($source['processed_at'])->timestamp - CarbonImmutable::parse($transaction['created_at'])->timestamp) <= 172800, 409, 'The original order and payment timing differ.');
        $access->owner($tenantId, $actor->fresh(), $row->member_id, 'setup');
        $access->member($row->fresh());
        abort_unless($transport->context($tenantId, $binding) === $context, 409);
        $proof = ['checked_at' => now()->toISOString(), 'terms_hash' => $row->terms_hash, 'method_type' => $method['type'],
            'last_four' => $method['last_four'], 'processor_context' => $context, 'source' => $source,
            'source_transaction_id' => $transaction['id'], 'provider_verified' => true, 'reauthorization_required' => false];
        if ($persist) {
            DB::transaction(function () use ($row, $actor, $proof, $context) {
                $current = SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
                app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor->fresh(), $row->member_id, 'setup');
                abort_unless($current->revision === $row->revision && $current->terms_hash === $row->terms_hash && $current->status !== 'cancelled'
                    && app(SubscriptionBraintreeTransport::class)->context($row->tenant_id, $current->method_binding) === $context, 409, 'The reviewed payment context changed during verification.');
                $before = ['method_verified' => $current->method_verified_at !== null];
                $evidence = $current->authorization_evidence;
                $evidence['status'] = $current->activated_at ? 'activated' : 'method_verified';
                $evidence['provider_verified'] = true;
                $evidence['provider_proof'] = $proof;
                $current->update(['method_verified_at' => now(), 'authorization_evidence' => $evidence]);
                SubscriptionBillingAction::create(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'request_uuid' => (string) Str::uuid(),
                    'kind' => 'method_verified', 'actor_type' => 'user', 'actor_reference' => (string) $actor->id, 'expected_revision' => $current->revision,
                    'request' => ['source_order_id' => $proof['source']['source_order_id'], 'terms_hash' => $current->terms_hash], 'before_state' => $before, 'after_state' => $proof]);
            });
        }

        return $proof;
    }
}
