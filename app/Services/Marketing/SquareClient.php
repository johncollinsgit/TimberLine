<?php

namespace App\Services\Marketing;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class SquareClient
{
    public function __construct(
        protected ?string $accessToken = null,
        protected ?string $baseUrl = null
    ) {
        $this->accessToken = $this->accessToken ?: (string) config('marketing.square.access_token');
        $this->baseUrl = rtrim((string) ($this->baseUrl ?: config('marketing.square.base_url')), '/');
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,cursor:?string}
     */
    public function fetchCustomers(?string $cursor = null, int $limit = 100): array
    {
        $response = $this->request()->get($this->url('/v2/customers'), array_filter([
            'cursor' => $cursor,
            'limit' => min(max($limit, 1), 100),
            'sort_field' => 'CREATED_AT',
            'sort_order' => 'ASC',
        ]));

        $response->throw();
        $json = $response->json() ?: [];

        return [
            'items' => is_array($json['customers'] ?? null) ? $json['customers'] : [],
            'cursor' => is_string($json['cursor'] ?? null) ? $json['cursor'] : null,
        ];
    }

    /**
     * @param array<int,string> $locationIds
     * @return array{items:array<int,array<string,mixed>>,cursor:?string}
     */
    public function searchOrders(
        ?string $cursor = null,
        int $limit = 100,
        ?CarbonInterface $since = null,
        array $locationIds = []
    ): array {
        $query = [];
        if ($since) {
            $query['filter'] = [
                'date_time_filter' => [
                    'closed_at' => [
                        'start_at' => $since->toIso8601String(),
                    ],
                ],
            ];
        }

        $body = array_filter([
            'cursor' => $cursor,
            'limit' => min(max($limit, 1), 500),
            'location_ids' => $locationIds !== [] ? $locationIds : null,
            'query' => $query !== [] ? $query : null,
            'return_entries' => false,
        ], fn ($value) => $value !== null);

        $response = $this->request()->post($this->url('/v2/orders/search'), $body);
        $response->throw();
        $json = $response->json() ?: [];

        return [
            'items' => is_array($json['orders'] ?? null) ? $json['orders'] : [],
            'cursor' => is_string($json['cursor'] ?? null) ? $json['cursor'] : null,
        ];
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,cursor:?string}
     */
    public function fetchPayments(?string $cursor = null, int $limit = 100, ?CarbonInterface $since = null): array
    {
        $query = array_filter([
            'cursor' => $cursor,
            'limit' => min(max($limit, 1), 100),
            'begin_time' => $since?->toIso8601String(),
            'sort_order' => 'ASC',
        ]);

        $response = $this->request()->get($this->url('/v2/payments'), $query);
        $response->throw();
        $json = $response->json() ?: [];

        return [
            'items' => is_array($json['payments'] ?? null) ? $json['payments'] : [],
            'cursor' => is_string($json['cursor'] ?? null) ? $json['cursor'] : null,
        ];
    }

    protected function request(): PendingRequest
    {
        return Http::withToken($this->accessToken)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(3, 200, throw: false);
    }

    protected function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }
}
