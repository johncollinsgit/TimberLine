<?php

namespace App\Services\Search;

use App\Services\Search\Providers\LandlordModulesSearchProvider;
use App\Services\Search\Providers\LandlordNavigationSearchProvider;
use App\Services\Search\Providers\LandlordRequestsSearchProvider;
use App\Services\Search\Providers\LandlordTenantsSearchProvider;
use App\Services\Search\Providers\LandlordTicketsSearchProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LandlordSearchCoordinator
{
    /**
     * @var array<int,LandlordSearchProvider>
     */
    protected array $providers;

    public function __construct(
        LandlordTenantsSearchProvider $tenants,
        LandlordTicketsSearchProvider $tickets,
        LandlordRequestsSearchProvider $requests,
        LandlordModulesSearchProvider $modules,
        LandlordNavigationSearchProvider $navigation,
    ) {
        $this->providers = [$tenants, $tickets, $requests, $modules, $navigation];
    }

    /**
     * This coordinator intentionally knows nothing about tenant operational
     * search providers. That separation is a backend security boundary.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function search(string $query, array $context = []): array
    {
        $normalized = trim($query);
        $limit = is_numeric($context['limit'] ?? null) ? max(1, min(20, (int) $context['limit'])) : 12;

        /** @var Collection<int,array<string,mixed>> $results */
        $results = collect($this->providers)
            ->flatMap(function (LandlordSearchProvider $provider) use ($normalized, $context): array {
                try {
                    return $provider->search($normalized, $context);
                } catch (\Throwable $exception) {
                    Log::warning('landlord_search.provider_failed_closed', [
                        'provider' => $provider::class,
                        'message' => $exception->getMessage(),
                    ]);

                    return [];
                }
            })
            ->filter(fn (array $row): bool => $normalized === '' || (int) ($row['score'] ?? 0) > 0)
            ->sortByDesc(fn (array $row): int => (int) ($row['score'] ?? 0))
            ->take($limit)
            ->values();

        return [
            'query' => $normalized,
            'context' => 'landlord',
            'total' => $results->count(),
            'results' => $results->all(),
            'groups' => $results
                ->groupBy(fn (array $row): string => (string) ($row['type'] ?? 'control plane'))
                ->map(fn (Collection $rows): array => $rows->values()->all())
                ->all(),
            'empty_state' => $results->isEmpty()
                ? [
                    'title' => 'No control-plane match',
                    'subtitle' => 'Try a workspace name, setup state, plan, module, request, or admin destination.',
                ]
                : null,
        ];
    }
}
