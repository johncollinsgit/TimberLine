<?php

namespace App\Services\Subscriptions;

use App\Models\Tenant;
use App\Models\TenantPaymentAccount;
use Illuminate\Support\Facades\Http;

/** Direct merchant requests only. Payloads/keys must already be durably frozen. */
class SubscriptionPilotStripeTransport
{
    public const API_VERSION = '2024-06-20';

    public function merchant(int $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        abort_unless(app(\App\Services\Billing\TenantPaymentsReadinessService::class)->canStartOnboarding($tenant), 423, 'Merchant setup is not available.');
        $account = app(\App\Services\Billing\TenantStripeConnectionService::class)->refresh($tenant);
        abort_unless($account->provider === 'stripe_connect' && $account->routingMode() === 'direct' && $account->isReady(), 423, 'Complete merchant verification and payouts first.');
        $merchant = ['account_id' => $account->provider_account_id, 'platform_account_id' => (string) config('services.stripe.account_id'),
            'livemode' => str_starts_with((string) config('services.stripe.secret'), 'sk_live_')];
        abort_unless(data_get($account->metadata, 'setup_fingerprint') === hash('sha256', $merchant['platform_account_id'].'|'.($merchant['livemode'] ? 'live' : 'test')), 409, 'The original Stripe platform or environment requires review.');
        $this->verifyMerchant($tenantId, $merchant);

        return $merchant;
    }

    public function verifyMerchant(int $tenantId, array $merchant, bool $requireReady = true): void
    {
        abort_unless(preg_match('/^acct_[A-Za-z0-9]+$/D', $merchant['account_id'] ?? '')
            && ($merchant['platform_account_id'] ?? '') === (string) config('services.stripe.account_id')
            && ($merchant['livemode'] ?? null) === str_starts_with((string) config('services.stripe.secret'), 'sk_live_'), 409, 'The original merchant environment changed.');
        $local = TenantPaymentAccount::query()->forTenantId($tenantId)->firstOrFail();
        abort_unless($local->provider_account_id === $merchant['account_id'] && $local->routingMode() === 'direct'
            && (! $requireReady || $local->isReady()), 409, 'The original merchant connection changed.');
        $platform = $this->request(null, 'get', '/account');
        abort_unless(($platform['id'] ?? null) === $merchant['platform_account_id'], 409, 'The Stripe platform identity changed.');
        $remote = $this->request(null, 'get', '/accounts/'.$merchant['account_id']);
        abort_unless(($remote['id'] ?? null) === $merchant['account_id'] && ($remote['type'] ?? null) === 'standard'
            && (! $requireReady || (($remote['charges_enabled'] ?? false) === true && ($remote['payouts_enabled'] ?? false) === true))
            && (data_get($local->metadata, 'ownership_verified_via') === 'oauth' || data_get($remote, 'metadata.tenant_id') === (string) $tenantId), 423, 'Stripe merchant verification needs review.');
    }

    public function read(array $merchant, string $kind, string $id, array $parameters = []): array
    {
        $path = $this->path($kind, $id);
        abort_unless($parameters === [] || ($kind === 'payment_intent' && $parameters === ['expand' => ['latest_charge']]), 422);

        return $this->request($merchant['account_id'], 'get', $path, $parameters);
    }

    public function create(array $merchant, string $kind, string $key, array $payload): array
    {
        abort_unless(in_array($kind, ['customer', 'setup_session', 'payment_intent'], true) && preg_match('/^[a-zA-Z0-9_-]{20,100}$/D', $key), 422);

        return $this->request($merchant['account_id'], 'post', $this->path($kind), $payload, $key);
    }

    public function cancel(array $merchant, string $paymentId, string $key): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{20,100}$/D', $key), 422);

        return $this->request($merchant['account_id'], 'post', $this->path('payment_intent', $paymentId).'/cancel',
            ['cancellation_reason' => 'requested_by_customer'], $key);
    }

    public function refunds(array $merchant, string $paymentId): array
    {
        $this->path('payment_intent', $paymentId);

        return $this->request($merchant['account_id'], 'get', '/refunds', ['payment_intent' => $paymentId, 'limit' => 100]);
    }

    public function customerPayments(array $merchant, string $customerId, int $from, int $until): array
    {
        $this->path('customer', $customerId);
        abort_unless($from > 0 && $until > $from && $until - $from <= 172800, 422);
        $result = $this->request($merchant['account_id'], 'get', '/payment_intents', ['customer' => $customerId, 'created' => ['gte' => $from, 'lt' => $until], 'limit' => 100]);
        abort_unless(isset($result['data']) && is_array($result['data']) && array_is_list($result['data']) && ($result['has_more'] ?? null) === false, 409, 'Stripe payment coverage is incomplete.');
        foreach ($result['data'] as $payment) {
            abort_unless(($payment['object'] ?? null) === 'payment_intent'
                && ($payment['customer'] ?? null) === $customerId && ($payment['livemode'] ?? null) === $merchant['livemode']
                && is_int($payment['created'] ?? null) && $payment['created'] >= $from && $payment['created'] < $until, 409, 'Stripe returned a different customer or billing window.');
        }

        return $result['data'];
    }

    private function path(string $kind, ?string $id = null): string
    {
        $paths = ['customer' => ['customers', 'cus_'], 'setup_session' => ['checkout/sessions', 'cs_'], 'setup_intent' => ['setup_intents', 'seti_'], 'payment_intent' => ['payment_intents', 'pi_'], 'payment_method' => ['payment_methods', 'pm_']];
        abort_unless(isset($paths[$kind]), 422);
        [$collection,$prefix] = $paths[$kind];
        if ($id !== null) {
            abort_unless(str_starts_with($id, $prefix) && preg_match('/^[A-Za-z0-9_]{6,150}$/D', $id), 422);
        }

        return '/'.$collection.($id !== null ? '/'.$id : '');
    }

    private function request(?string $account, string $method, string $path, array $payload = [], ?string $key = null): array
    {
        $secret = (string) config('services.stripe.secret');
        abort_unless(preg_match('/^sk_(?:live|test)_[A-Za-z0-9]+$/D', $secret), 423);
        $headers = ['Stripe-Version' => self::API_VERSION, 'Accept' => 'application/json'];
        if ($account !== null) {
            $headers['Stripe-Account'] = $account;
        }
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }
        // No automatic retries: callers reconcile the same persisted request.
        $response = Http::asForm()->withBasicAuth($secret, '')->withHeaders($headers)->withoutRedirecting()
            ->connectTimeout(5)->timeout(20)->{$method}('https://api.stripe.com/v1'.$path, $payload);
        abort_unless(strlen($response->body()) <= 2097152, 502, 'Stripe response requires review.');
        $data = $response->json();
        // Card errors can carry the original intent. Return only that object so
        // required authentication/declines cannot create a replacement payment.
        if (! $response->successful() && is_array(data_get($data,'error.payment_intent'))) {
            return $data['error']['payment_intent'];
        }
        abort_unless($response->successful() && is_array($data) && ! isset($data['error']),502,'Stripe request needs reconciliation.');

        return $data;
    }
}
