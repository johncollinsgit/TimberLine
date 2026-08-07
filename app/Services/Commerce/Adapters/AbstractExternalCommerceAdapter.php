<?php

namespace App\Services\Commerce\Adapters;

use App\Services\Commerce\Contracts\ExternalCommerceAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

abstract class AbstractExternalCommerceAdapter implements ExternalCommerceAdapter
{
    /** @return array<int,string> */
    public function resources(): array
    {
        return ['catalog', 'inventory', 'customers', 'orders', 'fulfillment', 'content', 'consent'];
    }

    /** @param array<string,mixed> $payload @return array{external_id:string,external_parent_id:?string,source_updated_at:?string,snapshot:array<string,mixed>} */
    public function normalize(string $resource, array $payload): array
    {
        $id = $this->first($payload, $this->idPaths());
        abort_if(blank($id), 422, 'The source record has no stable identifier.');
        $snapshot = Arr::except($payload, ['access_token', 'refresh_token', 'authorization', 'api_key', 'consumer_key', 'consumer_secret', 'client_secret']);
        $snapshot['_normalized'] = [
            'provider' => $this->provider(),
            'resource' => $resource,
            'source_id' => (string) $id,
            'title' => (string) ($this->first($payload, $this->titlePaths()) ?? ''),
            'currency' => strtolower((string) ($this->first($payload, $this->currencyPaths()) ?? '')) ?: null,
        ];

        return [
            'external_id' => Str::limit((string) $id, 190, ''),
            'external_parent_id' => ($parent = $this->first($payload, $this->parentPaths())) === null ? null : Str::limit((string) $parent, 190, ''),
            'source_updated_at' => ($updated = $this->first($payload, $this->updatedPaths())) === null ? null : (string) $updated,
            'snapshot' => $snapshot,
        ];
    }

    /** @return array<int,string> */
    abstract protected function idPaths(): array;

    /** @return array<int,string> */
    abstract protected function updatedPaths(): array;

    /** @return array<int,string> */
    protected function parentPaths(): array
    {
        return ['product_id', 'parent_id', 'parentId'];
    }

    /** @return array<int,string> */
    protected function titlePaths(): array
    {
        return ['title', 'name', 'number'];
    }

    /** @return array<int,string> */
    protected function currencyPaths(): array
    {
        return ['currency', 'currency_code'];
    }

    /** @param array<string,mixed> $payload @param array<int,string> $paths */
    private function first(array $payload, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
