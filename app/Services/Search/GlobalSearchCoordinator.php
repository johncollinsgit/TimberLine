<?php

namespace App\Services\Search;

use App\Services\Search\Providers\ActionsSearchProvider;
use App\Services\Search\Providers\CustomersSearchProvider;
use App\Services\Search\Providers\EventsSearchProvider;
use App\Services\Search\Providers\ImportsSearchProvider;
use App\Services\Search\Providers\ModulesSearchProvider;
use App\Services\Search\Providers\NavigationSearchProvider;
use App\Services\Search\Providers\OrdersSearchProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GlobalSearchCoordinator
{
    /**
     * @var array<int,GlobalSearchProvider>
     */
    protected array $providers;

    public function __construct(
        CustomersSearchProvider $customers,
        OrdersSearchProvider $orders,
        EventsSearchProvider $events,
        ImportsSearchProvider $imports,
        ModulesSearchProvider $modules,
        NavigationSearchProvider $navigation,
        ActionsSearchProvider $actions
    ) {
        $this->providers = [$customers, $orders, $events, $imports, $modules, $navigation, $actions];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function search(string $query, array $context = []): array
    {
        $normalized = trim($query);
        $limit = is_numeric($context['limit'] ?? null) ? max(1, min(20, (int) $context['limit'])) : 10;

        /** @var Collection<int,array<string,mixed>> $results */
        $results = collect($this->providers)
            ->flatMap(function (GlobalSearchProvider $provider) use ($normalized, $context): array {
                try {
                    return $provider->search($normalized, $context);
                } catch (\Throwable $exception) {
                    Log::warning('search.provider_failed_closed', [
                        'provider' => $provider::class,
                        'tenant_id' => is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null,
                        'surface' => (string) ($context['surface'] ?? 'marketing'),
                        'message' => $exception->getMessage(),
                    ]);

                    return [];
                }
            })
            ->filter(function (array $row) use ($normalized): bool {
                if ($normalized === '') {
                    return true;
                }

                return (int) ($row['score'] ?? 0) > 0;
            })
            ->sortByDesc(fn (array $row): int => (int) ($row['score'] ?? 0))
            ->take($limit)
            ->values();

        $grouped = $results
            ->groupBy(fn (array $row): string => (string) ($row['type'] ?? 'other'))
            ->map(fn (Collection $rows): array => $rows->values()->all())
            ->all();

        return [
            'query' => $normalized,
            'total' => $results->count(),
            'results' => $results->all(),
            'groups' => $grouped,
            'empty_state' => $results->isEmpty()
                ? [
                    'title' => 'No exact match yet',
                    'subtitle' => 'Try a customer name, order number, module, workflow, or workspace destination.',
                ]
                : null,
        ];
    }
}
