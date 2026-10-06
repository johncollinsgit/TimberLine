<?php

namespace App\Services\Integrations\Bouncie;

use App\Models\IntegrationConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class BouncieApiClient
{
    public function __construct(
        private readonly IntegrationConnection $connection,
        private readonly string $apiBase,
    ) {}

    /** @return array{id?:string,email?:string,name?:string} */
    public function user(): array
    {
        return $this->objectResponse($this->request()->get($this->url('user'))->throw()->json());
    }

    /** @return list<array<string,mixed>> */
    public function vehicles(): array
    {
        $all = [];
        $limit = 100;
        for ($skip = 0; $skip < 5000; $skip += $limit) {
            $page = $this->request()->get($this->url('vehicles'), ['limit' => $limit, 'skip' => $skip])->throw()->json();
            $page = is_array($page) ? array_values(array_filter($page, 'is_array')) : [];
            array_push($all, ...$page);
            if (count($page) < $limit) {
                break;
            }
        }

        return $all;
    }

    private function request(): PendingRequest
    {
        // Bouncie explicitly requires the raw access token without a Bearer prefix.
        return Http::acceptJson()
            ->withHeaders(['Authorization' => (string) $this->connection->access_token])
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 250, throw: false);
    }

    private function url(string $path): string
    {
        return rtrim($this->apiBase, '/').'/'.ltrim($path, '/');
    }

    /** @return array<string,mixed> */
    private function objectResponse(mixed $payload): array
    {
        return is_array($payload) ? $payload : [];
    }
}
