<?php

namespace App\Services\Search;

interface GlobalSearchProvider
{
    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, array $context = []): array;
}
