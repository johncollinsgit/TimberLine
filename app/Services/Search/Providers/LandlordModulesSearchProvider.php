<?php

namespace App\Services\Search\Providers;

use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\LandlordSearchProvider;
use Illuminate\Support\Str;

class LandlordModulesSearchProvider implements LandlordSearchProvider
{
    use BuildsSearchResults;

    public function search(string $query, array $context = []): array
    {
        $normalized = trim($query);

        return collect((array) config('module_catalog.modules', []))
            ->filter(fn (mixed $definition): bool => is_array($definition))
            ->map(function (array $definition, string $moduleKey) use ($normalized): array {
                $title = trim((string) ($definition['display_name'] ?? Str::headline($moduleKey)));
                $status = strtolower(trim((string) ($definition['status'] ?? 'disabled')));
                $marketState = strtoupper(trim((string) ($definition['market_state'] ?? 'INTERNAL_ONLY')));
                $billing = strtolower(trim((string) ($definition['billing_mode'] ?? 'unavailable')));

                return $this->result([
                    'type' => 'module',
                    'subtype' => 'catalog_definition',
                    'title' => $title,
                    'subtitle' => Str::headline($status).' • '.Str::headline(strtolower($marketState)).' • '.Str::headline($billing),
                    'url' => route('landlord.commercial.index', ['module' => $moduleKey]).'#modules',
                    'badge' => 'Catalog',
                    'score' => $this->matchScore($normalized, [
                        $title,
                        $moduleKey,
                        $definition['description'] ?? '',
                        $status,
                        $marketState,
                        $billing,
                    ], 230),
                    'icon' => 'squares-plus',
                    'meta' => [
                        'module_key' => $moduleKey,
                        'control_plane_only' => true,
                    ],
                ]);
            })
            ->filter(fn (array $result): bool => $normalized === '' ? ($result['score'] ?? 0) >= 230 : ($result['score'] ?? 0) > 0)
            ->sortByDesc('score')
            ->take($normalized === '' ? 2 : 6)
            ->values()
            ->all();
    }
}
