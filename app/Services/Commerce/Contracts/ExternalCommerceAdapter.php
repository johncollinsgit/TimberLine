<?php

namespace App\Services\Commerce\Contracts;

interface ExternalCommerceAdapter
{
    public function provider(): string;

    /** @return array<int,string> */
    public function resources(): array;

    /** @param array<string,mixed> $payload @return array{external_id:string,external_parent_id:?string,source_updated_at:?string,snapshot:array<string,mixed>} */
    public function normalize(string $resource, array $payload): array;
}
