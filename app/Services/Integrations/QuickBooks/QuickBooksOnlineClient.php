<?php

namespace App\Services\Integrations\QuickBooks;

use App\Models\IntegrationConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class QuickBooksOnlineClient
{
    public function __construct(
        protected IntegrationConnection $connection,
        protected string $baseUrl,
        protected int $minorVersion = 75
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function all(string $entity, int $pageSize = 100): array
    {
        $results = [];
        $start = 1;

        do {
            $page = $this->query(sprintf('select * from %s startposition %d maxresults %d', $entity, $start, $pageSize));
            $items = (array) data_get($page, 'QueryResponse.'.$entity, []);

            foreach ($items as $item) {
                if (is_array($item)) {
                    $results[] = $item;
                }
            }

            $start += $pageSize;
        } while (count($items) === $pageSize);

        return $results;
    }

    /** @return array<string,mixed> */
    public function query(string $query): array
    {
        $realmId = $this->realmId();

        return $this->request()
            ->get("/v3/company/{$realmId}/query", [
                'query' => $query,
                'minorversion' => $this->minorVersion,
            ])
            ->throw()
            ->json();
    }

    public function realmId(): string
    {
        $realmId = trim((string) data_get($this->connection->metadata, 'realm_id', $this->connection->external_account_id));
        abort_if($realmId === '', 422, 'QuickBooks connection is missing a realm/company id.');

        return $realmId;
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->withToken((string) $this->connection->access_token)
            ->timeout(30)
            ->retry(2, 250);
    }
}
