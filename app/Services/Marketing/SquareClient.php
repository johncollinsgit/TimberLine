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
    public function fetchCustomers(?string $cursor = null, int $limit = 100, ?CarbonInterface $since = null): array
    {
        $response = $since ? $this->request()->post($this->url('/v2/customers/search'), array_filter([
            'cursor' => $cursor, 'limit' => min(max($limit, 1), 100),
            'query' => ['filter' => ['updated_at' => ['start_at' => $since->toIso8601String()]], 'sort' => ['field' => 'CREATED_AT', 'order' => 'ASC']],
        ])) : $this->request()->get($this->url('/v2/customers'), array_filter([
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

    public function retrieveMerchant(string $id): array
    {
        return $this->request()->get($this->url('/v2/merchants/'.rawurlencode($id)))->throw()->json('merchant') ?? [];
    }

    public function retrieveLocation(string $id): array
    {
        return $this->request()->get($this->url('/v2/locations/'.rawurlencode($id)))->throw()->json('location') ?? [];
    }

    public function fetchCards(?string $cursor = null): array
    {
        $result = $this->request()->get($this->url('/v2/cards'), array_filter(['cursor' => $cursor, 'include_disabled' => 'true']))->throw()->json();

        return ['items' => $result['cards'] ?? [], 'cursor' => $result['cursor'] ?? null];
    }

    /**
     * @param  array<int,string>  $locationIds
     * @return array{items:array<int,array<string,mixed>>,cursor:?string}
     */
    public function searchOrders(
        ?string $cursor = null,
        int $limit = 100,
        ?CarbonInterface $since = null,
        array $locationIds = [],
        string $dateField = 'closed_at'
    ): array {
        $query = [];
        if ($since) {
            abort_unless(in_array($dateField, ['closed_at', 'updated_at'], true), 422);
            $query['filter'] = [
                'date_time_filter' => [
                    $dateField => [
                        'start_at' => $since->toIso8601String(),
                    ],
                ],
            ];
            if ($dateField === 'closed_at') {
                $query['filter']['state_filter'] = ['states' => ['COMPLETED', 'CANCELED']];
            }
            $query['sort'] = ['sort_field' => strtoupper($dateField), 'sort_order' => 'ASC'];
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
    public function fetchPayments(?string $cursor = null, int $limit = 100, ?CarbonInterface $since = null, ?string $locationId = null, bool $updated = false): array
    {
        $query = array_filter([
            'cursor' => $cursor,
            'limit' => min(max($limit, 1), 100),
            'begin_time' => $updated ? '2013-01-01T00:00:00Z' : $since?->toIso8601String(),
            'updated_at_begin_time' => $updated ? $since?->toIso8601String() : null,
            'sort_field' => $updated ? 'UPDATED_AT' : null,
            'sort_order' => 'ASC',
            'location_id' => $locationId,
        ]);

        $response = $this->request()->get($this->url('/v2/payments'), $query);
        $response->throw();
        $json = $response->json() ?: [];

        return [
            'items' => is_array($json['payments'] ?? null) ? $json['payments'] : [],
            'cursor' => is_string($json['cursor'] ?? null) ? $json['cursor'] : null,
        ];
    }

    /** Each original connection location has its own provider cursor. Never silently read only the main location. */
    public function fetchLocationPayments(?string $cursor, int $limit, ?CarbonInterface $since, array $locations, bool $updated = false): array
    {
        $locations = array_values(array_unique(array_filter($locations, fn ($id) => is_string($id) && $id !== '')));
        sort($locations);
        abort_unless($locations, 409, 'Review the original Square locations before importing payments.');
        if (count($locations) === 1) {
            return $this->fetchPayments($cursor, $limit, $since, $locations[0], $updated);
        }
        $binding = hash('sha256', json_encode([$locations, $since?->toIso8601String(), $updated], JSON_THROW_ON_ERROR));
        $index = 0;
        $provider = null;
        if ($cursor !== null) {
            try {
                if (! str_starts_with($cursor, 'everbranch-locations-v1.')) {
                    throw new \RuntimeException;
                }
                $decoded = json_decode(base64_decode(substr($cursor, 24), true) ?: '', true, 16, JSON_THROW_ON_ERROR);
                if (($decoded['binding'] ?? null) !== $binding || ! is_int($decoded['index'] ?? null) || ! isset($locations[$decoded['index']])
                    || (($decoded['provider'] ?? null) !== null && ! is_string($decoded['provider']))) {
                    throw new \RuntimeException;
                }
                $index = $decoded['index'];
                $provider = $decoded['provider'] ?? null;
            } catch (\Throwable) {
                abort(422, 'The payment cursor belongs to different original locations. Review its saved import run.');
            }
        }
        $page = $this->fetchPayments($provider, $limit, $since, $locations[$index], $updated);
        if ($page['cursor'] === null) {
            $index++;
        }
        $next = $page['cursor'] !== null || isset($locations[$index])
            ? 'everbranch-locations-v1.'.base64_encode(json_encode(['binding' => $binding, 'index' => $index, 'provider' => $page['cursor']], JSON_THROW_ON_ERROR)) : null;

        return ['items' => $page['items'], 'cursor' => $next];
    }

    public function retrieveSourceObject(string $kind, string $id): array
    {
        $plural = match ($kind) {
            'customer' => 'customers', 'order' => 'orders', 'payment' => 'payments', default => throw new \InvalidArgumentException('Unknown Square source kind.')
        };

        return $this->request()->get($this->url('/v2/'.$plural.'/'.rawurlencode($id)))->throw()->json($kind) ?? [];
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
        return $this->baseUrl.'/'.ltrim($path, '/');
    }
}
