<?php

namespace App\Services\Search\Concerns;

trait BuildsSearchResults
{
    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    protected function result(array $attributes): array
    {
        return array_merge([
            'type' => 'navigation',
            'subtype' => 'page',
            'title' => '',
            'subtitle' => '',
            'url' => null,
            'action' => null,
            'badge' => null,
            'score' => 0,
            'icon' => 'magnifying-glass',
            'meta' => [],
        ], $attributes);
    }

    protected function matchScore(string $query, array $haystacks, int $base = 200): int
    {
        $normalizedQuery = strtolower(trim($query));
        if ($normalizedQuery === '') {
            return $base;
        }

        foreach ($haystacks as $index => $haystack) {
            $normalized = strtolower(trim((string) $haystack));
            if ($normalized === '') {
                continue;
            }

            if ($normalized === $normalizedQuery) {
                return $base + 120 - ($index * 5);
            }

            if (str_starts_with($normalized, $normalizedQuery)) {
                return $base + 80 - ($index * 5);
            }

            if (str_contains($normalized, $normalizedQuery)) {
                return $base + 40 - ($index * 5);
            }
        }

        return 0;
    }
}
