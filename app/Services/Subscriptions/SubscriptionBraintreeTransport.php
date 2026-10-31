<?php

namespace App\Services\Subscriptions;

use App\Models\IntegrationConnection;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\User;
use Braintree\Gateway;
use Braintree\TransactionSearch;
use Illuminate\Support\Facades\DB;

/** Isolated official SDK, fixed operations, no retries or global credential fallback. */
class SubscriptionBraintreeTransport
{
    public function context(int $tenantId, array $binding): array
    {
        [$credentials, $connection] = $this->resolve($tenantId, $binding);

        return ['connection_id' => $connection->id, 'credential_revision' => $binding['credential_revision'],
            'credential_fingerprint' => SubscriptionEvidence::digest((string) $connection->access_token),
            'merchant_fingerprint' => hash('sha256', $credentials['environment'].'|'.$credentials['merchant_id'].'|'.$credentials['merchant_account_id']),
            'environment' => $credentials['environment'], 'merchant_account_id' => $credentials['merchant_account_id'],
            'currency' => data_get($connection->metadata, 'currency')];
    }

    public function verifyMethod(int $tenantId, array $binding, string $currency): array
    {
        return $this->call($tenantId, $binding, function (Gateway $gateway) use ($binding, $currency) {
            $account = $gateway->merchantAccount()->find($binding['merchant_account_id']);
            $customer = $gateway->customer()->find($binding['customer_id']);
            $method = $gateway->paymentMethod()->find($binding['payment_method_token']);
            abort_unless($account->id === $binding['merchant_account_id'] && $account->status === 'active' && $account->currencyIsoCode === $currency
                && $customer->id === $binding['customer_id'] && $method->customerId === $binding['customer_id']
                && $method->token === $binding['payment_method_token'], 409);
            $type = match (get_class($method)) {
                \Braintree\CreditCard::class => 'card', \Braintree\PayPalAccount::class => 'paypal', default => 'unsupported',
            };
            abort_unless(in_array($type, ['card', 'paypal'], true) && ! ($method->revokedAt ?? null)
                && ! ($method->grantorDetails ?? null) && ! ($method->granteeDetails ?? null)
                && ($type !== 'card' || $method->expired === false), 409);
            foreach (($method->subscriptions ?? []) as $subscription) {
                abort_unless(in_array(strtolower((string) $subscription->status), ['canceled', 'cancelled', 'expired'], true), 409);
            }

            return ['type' => $type, 'last_four' => $type === 'card' ? (string) $method->last4 : null,
                'billing_agreement_present' => $type === 'paypal' ? filled($method->billingAgreementId ?? null) : null];
        });
    }

    public function transaction(int $tenantId, array $binding, string $id): array
    {
        abort_unless(preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $id), 422);

        return $this->call($tenantId, $binding, fn (Gateway $gateway) => $this->normalize($gateway->transaction()->find($id)));
    }

    /** Filters link a source transaction to its current vaulted token without comparing emails. */
    public function methodTransaction(int $tenantId, array $binding, string $id): array
    {
        abort_unless(preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $id), 422);

        return $this->search($tenantId, $binding, [TransactionSearch::id()->is($id), TransactionSearch::paymentMethodToken()->is($binding['payment_method_token'])]);
    }

    public function cycleTransactions(SubscriptionBillingCycle $cycle): array
    {
        return $this->search($cycle->tenant_id, $cycle->method_binding, [TransactionSearch::orderId()->is($cycle->provider_request['orderId'])]);
    }

    public function window(int $tenantId, array $binding, \DateTimeInterface $from, \DateTimeInterface $until): array
    {
        abort_unless($until->getTimestamp() > $from->getTimestamp() && $until->getTimestamp() - $from->getTimestamp() <= 172800, 422);

        return $this->search($tenantId, $binding, [TransactionSearch::createdAt()->between($from, $until), TransactionSearch::type()->is('sale')]);
    }

    public function sale(SubscriptionBillingCycle $cycle): array
    {
        abort_unless(DB::transactionLevel() === 0, 409, 'A new Braintree payment must start outside an enclosing transaction.');
        abort_unless($cycle->processor === 'braintree' && $cycle->payment_status === 'sending' && $cycle->payment_started_at
            && $cycle->payment_started_at->gt(now()->subMinutes(2)) && ! $cycle->provider_reference, 409, 'The original durable Braintree attempt is required.');
        $expected = self::payload($cycle);
        abort_unless(SubscriptionEvidence::canonical($cycle->provider_request) === SubscriptionEvidence::canonical($expected), 409, 'The original Braintree request differs from the cycle terms.');
        DB::transaction(function () use ($cycle, $expected) {
            $agreement = SubscriptionBillingAgreement::query()->forTenantId($cycle->tenant_id)->whereKey($cycle->agreement_id)->lockForUpdate()->firstOrFail();
            $current = SubscriptionBillingCycle::query()->forTenantId($cycle->tenant_id)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            app(SubscriptionBraintreeExecutionService::class)->ready($agreement, User::findOrFail($agreement->activated_by));
            $member = app(SubscriptionReplacementAccess::class)->member($agreement);
            abort_unless($member->billing_owner === 'everbranch' && $agreement->status === 'active' && $agreement->activated_at && $agreement->method_verified_at
                && $agreement->revision === $current->agreement_revision && $agreement->next_billing_date?->toDateString() === $current->cycle_date->toDateString()
                && $agreement->method_binding === $current->method_binding && $current->processor === 'braintree'
                && $current->payment_status === 'sending' && $current->payment_started_at && ! $current->provider_reference
                && $current->payment_started_at->gt(now()->subMinutes(2))
                && \Carbon\CarbonImmutable::parse($current->source_evidence['checked_at'])->gt(now()->subMinute())
                && SubscriptionEvidence::canonical($current->provider_request) === SubscriptionEvidence::canonical($expected)
                && ! data_get($current->reconciliation, 'dispatch_started_at'), 409, 'The original Braintree dispatch has already started or changed.');
            $current->update(['reconciliation' => array_replace($current->reconciliation ?? [], ['dispatch_started_at' => now()->toISOString()])]);
        });

        return $this->call($cycle->tenant_id, $cycle->method_binding, function (Gateway $gateway) use ($expected) {
            $result = $gateway->transaction()->sale($expected);
            abort_unless(is_bool($result->success ?? null), 502);
            if (isset($result->transaction)) {
                return ['transaction' => $this->normalize($result->transaction), 'request_rejected' => false];
            }
            abort_unless($result->success === false && $result instanceof \Braintree\Result\Error, 502);

            return ['transaction' => null, 'request_rejected' => true];
        });
    }

    public static function payload(SubscriptionBillingCycle $cycle): array
    {
        return ['amount' => SubscriptionPilotShopifyBridge::decimal($cycle->amount_cents),
            'merchantAccountId' => $cycle->method_binding['merchant_account_id'], 'paymentMethodToken' => $cycle->method_binding['payment_method_token'],
            'orderId' => 'ebsub-'.$cycle->public_id, 'transactionSource' => 'recurring', 'options' => ['submitForSettlement' => true]];
    }

    private function search(int $tenantId, array $binding, array $criteria): array
    {
        return $this->call($tenantId, $binding, function (Gateway $gateway) use ($binding, $criteria) {
            $collection = $gateway->transaction()->search(array_merge([TransactionSearch::customerId()->is($binding['customer_id']),
                TransactionSearch::merchantAccountId()->in([$binding['merchant_account_id']])], $criteria));
            $count = $collection->maximumCount();
            abort_unless(is_int($count) && $count >= 0 && $count <= 20, 409, 'Braintree search coverage exceeds the reviewed bound.');
            $rows = [];
            foreach ($collection as $transaction) {
                $row = $this->normalize($transaction);
                abort_unless($row['customer_id'] === $binding['customer_id'] && $row['merchant_account_id'] === $binding['merchant_account_id']
                    && ! isset($rows[$row['id']]), 409);
                $rows[$row['id']] = $row;
            }
            abort_unless(count($rows) === $count, 409, 'Braintree search coverage is incomplete.');

            return array_values($rows);
        });
    }

    private function call(int $tenantId, array $binding, callable $operation): array
    {
        [$credentials, $connection] = $this->resolve($tenantId, $binding);
        $fingerprint = SubscriptionEvidence::digest((string) $connection->access_token);
        try {
            $result = $operation($this->gateway($credentials));
        } catch (\Throwable) {
            abort(502, 'The original Braintree operation needs verification or reconciliation.');
        }
        [, $current] = $this->resolve($tenantId, $binding);
        abort_unless(hash_equals($fingerprint, SubscriptionEvidence::digest((string) $current->access_token)), 409, 'The original Braintree credential changed during the operation.');

        return $result;
    }

    protected function gateway(array $credentials): Gateway
    {
        return new Gateway(['environment' => $credentials['environment'], 'merchantId' => $credentials['merchant_id'],
            'publicKey' => $credentials['public_key'], 'privateKey' => $credentials['private_key'], 'timeout' => 15]);
    }

    private function resolve(int $tenantId, array $binding): array
    {
        abort_unless(($binding['processor'] ?? null) === 'braintree' && in_array($binding['environment'] ?? null, ['production', 'sandbox'], true)
            && is_int($binding['connection_id'] ?? null), 409, 'The original Braintree binding is unavailable.');
        $connection = IntegrationConnection::query()->forTenant($tenantId)->where('provider', 'braintree')->where('status', 'connected')
            ->where('metadata->purpose', 'merchant_payment_verification')->findOrFail($binding['connection_id']);
        foreach (['environment', 'merchant_id', 'merchant_account_id', 'credential_revision'] as $field) {
            abort_unless(is_string($binding[$field] ?? null) && $binding[$field] === data_get($connection->metadata, $field), 409, 'The original Braintree connection identity changed.');
        }
        try {
            $credentials = json_decode($connection->access_token, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(409, 'The original Braintree credential needs review.');
        }
        abort_unless(is_array($credentials) && count($credentials) === 5
            && $connection->external_account_id === $binding['environment'].'|'.$binding['merchant_id'].'|'.$binding['merchant_account_id'], 409);
        foreach (['environment', 'merchant_id', 'merchant_account_id'] as $field) {
            abort_unless(($credentials[$field] ?? null) === $binding[$field], 409);
        }
        foreach (['public_key', 'private_key'] as $field) {
            abort_unless(is_string($credentials[$field] ?? null) && preg_match('/\A[A-Za-z0-9_-]{8,200}\z/', $credentials[$field]), 409);
        }

        return [$credentials, $connection];
    }

    private function normalize(object $transaction): array
    {
        $at = $transaction->createdAt ?? null;
        abort_unless(is_string($transaction->id ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $transaction->id)
            && $at instanceof \DateTimeInterface && is_string($transaction->amount ?? null) && is_string($transaction->status ?? null)
            && is_string($transaction->type ?? null) && is_string($transaction->currencyIsoCode ?? null), 409);

        return ['id' => $transaction->id, 'type' => $transaction->type ?? null, 'status' => $transaction->status ?? null,
            'amount' => $transaction->amount ?? null, 'currency' => $transaction->currencyIsoCode ?? null,
            'merchant_account_id' => $transaction->merchantAccountId ?? null, 'customer_id' => $transaction->customerDetails->id ?? null,
            'order_id' => $transaction->orderId ?? null, 'created_at' => $at->format(DATE_ATOM),
            'instrument_type' => $transaction->paymentInstrumentType ?? null, 'recurring' => $transaction->recurring ?? null,
            'refund_ids' => $transaction->refundIds ?? [], 'dispute_count' => count($transaction->disputes ?? []),
            'refunded_transaction_id' => $transaction->refundedTransactionId ?? null];
    }
}
