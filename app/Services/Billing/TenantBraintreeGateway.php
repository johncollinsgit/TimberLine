<?php

namespace App\Services\Billing;

use Braintree\Gateway;

/** Fixed read operations only. No verification hold, payment, vault write or retry. */
class TenantBraintreeGateway
{
    public function account(array $credentials, string $accountId): array
    {
        try {
            $account = $this->gateway($credentials)->merchantAccount()->find($accountId);

            return ['id' => $account->id, 'status' => $account->status, 'currency' => $account->currencyIsoCode];
        } catch (\Throwable) {
            abort(502, 'Braintree account verification failed. Check the selected merchant, account and credentials.');
        }
    }

    public function vaultedMethod(array $credentials, string $customerId, string $paymentToken): array
    {
        try {
            $gateway = $this->gateway($credentials);
            $customer = $gateway->customer()->find($customerId);
            $method = $gateway->paymentMethod()->find($paymentToken);
            abort_unless($customer->id === $customerId && $method->customerId === $customerId && $method->token === $paymentToken, 409);
            $type = match (get_class($method)) {
                \Braintree\CreditCard::class => 'card',
                \Braintree\PayPalAccount::class => 'paypal',
                default => 'unsupported',
            };

            return ['customer_verified' => true, 'type' => $type,
                'last_four' => $type === 'card' ? (string) $method->last4 : null,
                'expired' => $type === 'card' ? (bool) $method->expired : null];
        } catch (\Throwable) {
            abort(502, 'The selected Braintree customer and payment method could not be verified together.');
        }
    }

    private function gateway(array $credentials): Gateway
    {
        abort_unless(in_array($credentials['environment'] ?? null, ['sandbox', 'production'], true), 422);

        return new Gateway(['environment' => $credentials['environment'], 'merchantId' => $credentials['merchant_id'],
            'publicKey' => $credentials['public_key'], 'privateKey' => $credentials['private_key'], 'timeout' => 15]);
    }
}
