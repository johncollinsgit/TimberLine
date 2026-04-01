<?php

namespace App\Services\Search\Providers;

use App\Services\Dashboard\UnifiedDashboardService;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\GlobalSearchProvider;
use Illuminate\Http\Request;

class ActionsSearchProvider implements GlobalSearchProvider
{
    use BuildsSearchResults;

    public function __construct(
        protected UnifiedDashboardService $dashboardService
    ) {
    }

    public function search(string $query, array $context = []): array
    {
        $request = $context['request'] ?? request();
        if (! $request instanceof Request) {
            $request = request();
        }

        $dashboard = $this->dashboardService->forRequest($request, $context['user'] ?? $request->user());
        $normalized = trim($query);

        return collect((array) ($dashboard['next_actions'] ?? []))
            ->filter(function (array $action) use ($normalized): bool {
                if ($normalized === '') {
                    return true;
                }

                return $this->matchScore($normalized, [
                    (string) ($action['label'] ?? ''),
                    (string) ($action['description'] ?? ''),
                ]) > 0;
            })
            ->take(5)
            ->map(function (array $action) use ($normalized): array {
                return $this->result([
                    'type' => 'action',
                    'subtype' => 'workflow',
                    'title' => (string) ($action['label'] ?? 'Action'),
                    'subtitle' => (string) ($action['description'] ?? ''),
                    'url' => $action['href'] ?? null,
                    'action' => $action['intent'] ?? null,
                    'badge' => 'Action',
                    'score' => $this->matchScore($normalized, [
                        (string) ($action['label'] ?? ''),
                        (string) ($action['description'] ?? ''),
                    ], 220),
                    'icon' => 'bolt',
                ]);
            })
            ->all();
    }
}
