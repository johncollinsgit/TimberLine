<?php

namespace App\Services\Search\Providers;

use App\Services\Navigation\UnifiedAppNavigationService;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\GlobalSearchProvider;
use Illuminate\Http\Request;

class NavigationSearchProvider implements GlobalSearchProvider
{
    use BuildsSearchResults;

    public function __construct(
        protected UnifiedAppNavigationService $navigationService
    ) {
    }

    public function search(string $query, array $context = []): array
    {
        $request = $context['request'] ?? request();
        if (! $request instanceof Request) {
            $request = request();
        }

        $nav = $this->navigationService->build($request, $context['user'] ?? $request->user());
        $normalized = trim($query);
        $results = [];

        foreach ((array) ($nav['items'] ?? []) as $item) {
            $score = $this->matchScore($normalized, [(string) ($item['label'] ?? '')], 140);
            if ($normalized !== '' && $score === 0) {
                continue;
            }

            $results[] = $this->result([
                'type' => 'navigation',
                'subtype' => 'page',
                'title' => (string) ($item['label'] ?? 'Page'),
                'subtitle' => 'Jump to a primary workspace section.',
                'url' => (string) ($item['href'] ?? '#'),
                'badge' => 'Page',
                'score' => $score ?: 100,
                'icon' => (string) ($item['icon'] ?? 'rectangle-stack'),
            ]);
        }

        return $results;
    }
}
