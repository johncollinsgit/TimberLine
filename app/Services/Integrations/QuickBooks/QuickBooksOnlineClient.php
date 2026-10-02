<?php

namespace App\Services\Integrations\QuickBooks;

use App\Models\IntegrationConnection;
use Carbon\CarbonInterface;
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
        return $this->allMatching($entity, null, $pageSize);
    }

    /**
     * QuickBooks name-list queries only return active records by default. Customer
     * reconciliation must explicitly request both states so an inactive customer
     * does not silently disappear from Everbranch.
     *
     * @return array<int,array<string,mixed>>
     */
    public function allCustomers(int $pageSize = 100): array
    {
        return $this->allMatching('Customer', 'Active IN (true, false)', $pageSize);
    }

    /** @return array<int,array<string,mixed>> */
    public function allSince(string $entity, CarbonInterface $since, int $pageSize = 100): array
    {
        $timestamp = $since->copy()->utc()->format('Y-m-d\TH:i:sP');

        return $this->allMatching($entity, "MetaData.LastUpdatedTime > '{$timestamp}'", $pageSize);
    }

    /** @return array<int,array<string,mixed>> */
    public function allCustomersSince(CarbonInterface $since, int $pageSize = 100): array
    {
        $timestamp = $since->copy()->utc()->format('Y-m-d\TH:i:sP');

        return $this->allMatching(
            'Customer',
            "Active IN (true, false) AND MetaData.LastUpdatedTime > '{$timestamp}'",
            $pageSize
        );
    }

    /** @return array<int,array<string,mixed>> */
    protected function allMatching(string $entity, ?string $where, int $pageSize): array
    {
        $results = [];
        $start = 1;
        $filter = $where ? ' where '.$where : '';

        do {
            $page = $this->query(sprintf('select * from %s%s startposition %d maxresults %d', $entity, $filter, $start, $pageSize));
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

    public function attachmentDownloadUrl(string $attachableId): string
    {
        $realmId = $this->realmId();

        return trim((string) $this->request()
            ->get("/v3/company/{$realmId}/download/".rawurlencode($attachableId), [
                'minorversion' => $this->minorVersion,
            ])
            ->throw()
            ->body(), "\" \n\r\t");
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

    /** @return array<string,mixed> */
    public function invoiceWithPaymentLink(string $invoiceId): array
    {
        $realmId = $this->realmId();

        return $this->request()
            ->get("/v3/company/{$realmId}/invoice/".rawurlencode($invoiceId), [
                'include' => 'invoiceLink',
                'minorversion' => $this->minorVersion,
            ])
            ->throw()
            ->json() ?? [];
    }

    /** @return array<string,mixed> */
    public function report(string $report, array $parameters = []): array
    {
        $realmId = $this->realmId();

        return $this->request()
            ->get("/v3/company/{$realmId}/reports/{$report}", $parameters + [
                'minorversion' => $this->minorVersion,
            ])
            ->throw()
            ->json();
    }

    /** @param array<string,mixed> $invoice */
    public function createInvoice(array $invoice, string $requestId): array
    {
        return $this->request()->post('/v3/company/'.$this->realmId().'/invoice?'.http_build_query([
            'minorversion' => $this->minorVersion,
            'requestid' => $requestId,
        ]), $invoice)->throw()->json();
    }

    public function sendInvoice(string $invoiceId, string $recipient): array
    {
        return $this->request()->withBody('', 'application/octet-stream')
            ->post('/v3/company/'.$this->realmId().'/invoice/'.rawurlencode($invoiceId).'/send?'.http_build_query([
                'sendTo' => $recipient,
                'minorversion' => $this->minorVersion,
            ]))->throw()->json();
    }

    public function realmId(): string
    {
        $realmId = trim((string) ($this->connection->external_account_secret
            ?: data_get($this->connection->metadata, 'realm_id', $this->connection->external_account_id)));
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
