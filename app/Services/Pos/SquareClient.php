<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class SquareClient
{
    public function __construct(private PosStore $store) {}

    public function connection(int $tenant, string $id): object
    {
        $row = $this->store->rows('connections', $tenant)->where('id', $id)->first();
        if (! $row) {
            throw new PosException('original_merchant_unavailable', 503);
        }

        return $row;
    }

    public function request(object $connection): PendingRequest
    {
        $this->store->outsideTransaction();
        $credentials = $this->store->decrypt($connection->credentials);
        if (! in_array($connection->environment, ['sandbox', 'production'], true)
            || empty($credentials['access_token']) || empty($credentials['expires_at'])
            || strtotime($credentials['expires_at']) <= time()) {
            throw new PosException('square_authorization_expired', 503);
        }
        $origin = $connection->environment === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';

        return Http::baseUrl($origin)->withToken($credentials['access_token'])->acceptJson()
            ->withHeaders(['Square-Version' => config('pos_live.square_api_version')])->connectTimeout(5)->timeout(20);
    }

    public function get(object $connection, string $path, array $query = []): array
    {
        $response = $this->request($connection)->get($path, $query);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new PosException('square_retrieval_unavailable', 503);
        }

        return $response->json();
    }

    /** No blind network retry: callers retain intent and reconcile uncertainty. */
    public function post(object $connection, string $path, array $payload): array
    {
        $response = $this->request($connection)->post($path, $payload);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new PosException('square_operation_unconfirmed', 503);
        }

        return $response->json();
    }

    public function verify(object $connection, bool $save = true): void
    {
        $location = $this->get($connection, '/v2/locations/'.rawurlencode($connection->provider_location_id))['location'] ?? [];
        $merchant = $this->get($connection, '/v2/merchants/'.rawurlencode($connection->merchant_id))['merchant'] ?? [];
        if (($location['id'] ?? null) !== $connection->provider_location_id || ($location['merchant_id'] ?? null) !== $connection->merchant_id
            || ($location['status'] ?? null) !== 'ACTIVE' || ($location['currency'] ?? null) !== 'USD'
            || ($location['country'] ?? null) !== 'US' || ! in_array('CREDIT_CARD_PROCESSING', $location['capabilities'] ?? [], true)
            || ($merchant['id'] ?? null) !== $connection->merchant_id || ($merchant['status'] ?? null) !== 'ACTIVE') {
            throw new PosException('square_merchant_not_ready', 503);
        }
        if ($save) {
            $this->store->rows('connections', (int) $connection->tenant_id)->where('id', $connection->id)->update(['verified_at' => now()]);
        }
    }
}
