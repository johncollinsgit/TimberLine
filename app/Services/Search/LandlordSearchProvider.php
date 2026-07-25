<?php

namespace App\Services\Search;

interface LandlordSearchProvider
{
    /**
     * Search Everbranch control-plane records only.
     *
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, array $context = []): array;
}
