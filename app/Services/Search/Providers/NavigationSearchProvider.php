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

        foreach ($this->searchableNavigationItems((array) ($nav['items'] ?? [])) as $entry) {
            $title = (string) ($entry['title'] ?? '');
            $url = (string) ($entry['url'] ?? '#');
            $icon = (string) ($entry['icon'] ?? 'rectangle-stack');
            $subtitle = (string) ($entry['subtitle'] ?? '');
            $badge = (string) ($entry['badge'] ?? 'Page');
            $subtype = (string) ($entry['subtype'] ?? 'page');
            $meta = is_array($entry['meta'] ?? null) ? (array) $entry['meta'] : [];
            $haystacks = is_array($entry['haystacks'] ?? null) ? (array) $entry['haystacks'] : [$title];
            $scoreBase = is_numeric($entry['score_base'] ?? null) ? (int) $entry['score_base'] : 140;

            $score = $this->matchScore($normalized, $haystacks, $scoreBase);
            if ($normalized !== '' && $score === 0) {
                continue;
            }

            $results[] = $this->result([
                'type' => 'navigation',
                'subtype' => $subtype,
                'title' => $title !== '' ? $title : 'Page',
                'subtitle' => $subtitle,
                'url' => $url,
                'badge' => $badge,
                'score' => $score ?: 100,
                'icon' => $icon,
                'meta' => $meta,
            ]);
        }

        return $results;
    }

    /**
     * Flatten top-level navigation and ONE level of children into searchable entries.
     * Explicitly ignores any deeper nesting (no grandchildren).
     *
     * @param  array<int,mixed>  $items
     * @return array<int,array<string,mixed>>
     */
    protected function searchableNavigationItems(array $items): array
    {
        $entries = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $parentLabel = trim((string) ($item['label'] ?? ''));
            $parentHref = trim((string) ($item['href'] ?? ''));
            if ($parentLabel === '' || $parentHref === '') {
                continue;
            }

            $parentIcon = (string) ($item['icon'] ?? 'rectangle-stack');
            $parentAliases = $this->aliasesForNavigationItem($item);

            $entries[] = [
                'subtype' => 'page',
                'title' => $parentLabel,
                'subtitle' => 'Jump to a primary workspace section.',
                'url' => $parentHref,
                'badge' => 'Page',
                'icon' => $parentIcon,
                'haystacks' => array_values(array_filter(array_unique([
                    $parentLabel,
                    ...$parentAliases,
                ]))),
                'score_base' => 140,
                'meta' => [
                    'nav_kind' => 'parent',
                    'nav_key' => (string) ($item['key'] ?? ''),
                ],
            ];

            $children = (array) ($item['children'] ?? []);
            foreach ($children as $child) {
                if (! is_array($child)) {
                    continue;
                }

                $childLabel = trim((string) ($child['label'] ?? ''));
                $childHref = trim((string) ($child['href'] ?? ''));
                if ($childLabel === '' || $childHref === '') {
                    continue;
                }

                $childIcon = (string) ($child['icon'] ?? $parentIcon);
                $childAliases = $this->aliasesForNavigationItem($child, $item);

                $entries[] = [
                    'subtype' => 'section',
                    'title' => $childLabel,
                    'subtitle' => $parentLabel.' workspace',
                    'url' => $childHref,
                    'badge' => $parentLabel,
                    'icon' => $childIcon,
                    // Child title match should dominate; parent label is context only.
                    'haystacks' => array_values(array_filter(array_unique([
                        $childLabel,
                        $parentLabel,
                        ...$childAliases,
                    ]))),
                    'score_base' => 126,
                    'meta' => [
                        'nav_kind' => 'child',
                        'nav_key' => (string) ($child['key'] ?? ''),
                        'parent_key' => (string) ($item['key'] ?? ''),
                        'parent_label' => $parentLabel,
                    ],
                ];
            }
        }

        return $entries;
    }

    /**
     * Returns legacy workflow aliases that should match a navigation item.
     * Aliases are used ONLY for matching/scoring; they are never displayed.
     *
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>|null  $parent
     * @return array<int,string>
     */
    protected function aliasesForNavigationItem(array $item, ?array $parent = null): array
    {
        $key = strtolower(trim((string) ($item['key'] ?? '')));
        $parentKey = $parent ? strtolower(trim((string) ($parent['key'] ?? ''))) : null;

        $aliases = [];

        // Parent aliases (keep short + intentional).
        if ($parentKey === null && $key === 'production') {
            $aliases = [
                'operations',
                'ops',
            ];
        }

        // Child aliases under Production.
        if ($parentKey === 'production') {
            $aliases = match ($key) {
                'shipping' => ['shipping room'],
                'pouring' => ['pouring room'],
                'retail-plan' => ['retail plan', 'retail planner', 'retail planning'],
                'markets' => ['market lists', 'market pour list', 'market pour lists'],
                default => [],
            };
        }

        return array_values(array_filter(array_map(static function (string $alias): string {
            return trim($alias);
        }, $aliases), static fn (string $alias): bool => $alias !== ''));
    }
}
