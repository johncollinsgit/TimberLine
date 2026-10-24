<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Illuminate\Support\Facades\Http;

/** Fixed-origin, original-account requests. No implicit retries or redirects. */
final class StripeTerminalClient
{
    private ?string $verifiedPlatform = null;

    public function __construct(private PosStore $store) {}

    public function request(object $connection, string $method, string $path, array $payload = [], ?string $key = null): array
    {
        $this->store->outsideTransaction();
        $frozen = $this->store->decrypt($connection->credentials);
        $secret = config('pos_live.stripe_secret');
        $live = $connection->environment === 'production';
        if (($connection->provider ?? null) !== 'stripe_terminal' || ! is_string($secret)
            || ! str_starts_with($secret, $live ? 'sk_live_' : 'sk_test_')
            || ($frozen['platform_account'] ?? null) !== config('pos_live.stripe_platform_account')
            || ! preg_match('/^acct_[A-Za-z0-9]+$/D', $connection->merchant_id)
            || ! preg_match('#^/v1/[A-Za-z0-9_/-]+$#D', $path)
            || ! in_array($connection->environment, ['sandbox', 'production'], true)) {
            throw new PosException('stripe_original_account_unavailable', 503);
        }
        $this->verifyPlatform($secret);
        $http = Http::baseUrl('https://api.stripe.com')->asForm()->withBasicAuth($secret, '')
            ->withHeaders(['Stripe-Account' => $connection->merchant_id, 'Stripe-Version' => config('pos_live.stripe_api_version')])
            ->withoutRedirecting()->connectTimeout(5)->timeout(20);
        if ($key !== null) {
            $http = $http->withHeaders(['Idempotency-Key' => $key]);
        }
        $response = $method === 'GET' ? $http->get($path, $payload) : $http->post($path, $payload);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new PosException('stripe_operation_unconfirmed', 503);
        }

        return $response->json();
    }

    public function verify(object $connection, bool $save = true): void
    {
        $this->store->outsideTransaction();
        $this->verifyPlatform((string) config('pos_live.stripe_secret'));
        $account = $this->request($connection, 'GET', '/v1/account');
        $location = $this->request($connection, 'GET', '/v1/terminal/locations/'.$connection->provider_location_id);
        if (($account['id'] ?? null) !== $connection->merchant_id || ($account['type'] ?? null) !== 'standard'
            || ($account['country'] ?? null) !== 'US' || ($account['charges_enabled'] ?? null) !== true
            || ($account['payouts_enabled'] ?? null) !== true || ($account['capabilities']['card_payments'] ?? null) !== 'active'
            || ($location['id'] ?? null) !== $connection->provider_location_id || ($location['address']['country'] ?? null) !== 'US'
            || ($location['livemode'] ?? null) !== ($connection->environment === 'production')) {
            throw new PosException('stripe_merchant_not_ready', 503);
        }
        if ($save) {
            $this->store->rows('connections', (int) $connection->tenant_id)->where('id', $connection->id)->update(['verified_at' => now()]);
        }
    }

    private function verifyPlatform(string $secret): void
    {
        $fingerprint = hash('sha256', $secret.':'.config('pos_live.stripe_platform_account'));
        if ($this->verifiedPlatform === $fingerprint) {
            return;
        }
        $response = Http::withBasicAuth($secret, '')->withoutRedirecting()->connectTimeout(5)->timeout(20)->get('https://api.stripe.com/v1/account');
        if (! $response->successful() || $response->json('id') !== config('pos_live.stripe_platform_account')) {
            throw new PosException('stripe_platform_identity_mismatch');
        }
        $this->verifiedPlatform = $fingerprint;
    }

    public function reader(object $connection, string $id, bool $requireIdle = false): array
    {
        if (! preg_match('/^tmr_[A-Za-z0-9]+$/D', $id)) {
            throw new PosException('invalid_reader');
        }
        $reader = $this->request($connection, 'GET', '/v1/terminal/readers/'.$id);
        $types = $connection->environment === 'production' ? ['stripe_s710'] : ['simulated_wisepos_e', 'simulated_stripe_s710', 'stripe_s710'];
        if (($reader['id'] ?? null) !== $id || ($reader['location'] ?? null) !== $connection->provider_location_id
            || ($reader['livemode'] ?? null) !== ($connection->environment === 'production')
            || ! in_array($reader['device_type'] ?? null, $types, true)) {
            throw new PosException('stripe_reader_identity_mismatch');
        }
        if ($requireIdle && (($reader['status'] ?? null) !== 'online' || ($reader['action']['status'] ?? null) === 'in_progress')) {
            throw new PosException('stripe_reader_busy_or_offline');
        }

        return $reader;
    }
}
